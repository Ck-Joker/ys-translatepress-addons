<?php
/**
 * 模組：內容語言規則（排除／指定顯示 + 智慧重導）
 *
 * - 每篇文章／頁面／CPT 可設定：所有語言顯示｜排除指定語言｜只在指定語言顯示
 * - 前台列表（封存／部落格／搜尋等）自動排除「當前語言被隱藏」的內容
 * - 若直接造訪被隱藏的單頁，依後台分別指定的 fallback 重導到「該語言」的對應頁
 *   （文章 → 該語言文章列表、頁面 → 該語言首頁，皆為當前語言版本而非預設語言）
 *
 * @package YangSheep\TPAddons\Modules\ContentRules
 * @since   0.2.0
 */

namespace YangSheep\TPAddons\Modules\ContentRules;

use YangSheep\TPAddons\Modules\YSTPAddonsModuleInterface;
use YangSheep\TPAddons\Support\YSTPAddonsTP;
use YangSheep\TPAddons\Database\YSTPAddonsSettingsRepo;

defined( 'ABSPATH' ) || exit;

class YSTPAddonsContentRules implements YSTPAddonsModuleInterface {

    public const META_MODE = '_ys_tp_content_lang_mode';  // all | exclude | include
    public const META_LIST = '_ys_tp_content_lang_list';  // array of language codes
    private const CACHE_PREFIX = 'ys_tp_hidden_';

    /** @var bool 防止 hidden_ids 內部查詢再觸發 exclude_from_lists 的無限遞迴（防禦性） */
    private static bool $resolving = false;

    public function boot(): void {
        // 類型層級規則存於設定表，存檔後需失效隱藏清單快取（管理端與前台皆掛）
        add_action( 'ys_tp_settings_saved', [ $this, 'on_settings_saved' ] );

        // 逐篇規則 meta 的任何變更(含 wp-cli / 程式化 update_post_meta,不經後台表單)與刪文
        // → 失效隱藏清單快取。save_meta 的 flush 只涵蓋後台表單提交;
        //   曾發生程式化批次設定規則後 transient 殘留舊值、規則整批不生效(2026-07-16)。
        add_action( 'added_post_meta', [ $this, 'on_meta_change' ], 10, 3 );
        add_action( 'updated_post_meta', [ $this, 'on_meta_change' ], 10, 3 );
        add_action( 'deleted_post_meta', [ $this, 'on_meta_change' ], 10, 3 );
        // 刪文必須掛 before_delete_post：core 刪文以 delete_metadata_by_mid 逐筆清 meta
        // （觸發 deleted_postmeta 而非 deleted_post_meta），到 deleted_post 時 meta 已不在，
        // metadata_exists 恆 false 而失去兜底（dev-ebs 實證）。
        add_action( 'before_delete_post', [ $this, 'on_post_delete' ] );

        if ( is_admin() ) {
            add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
            add_action( 'save_post', [ $this, 'save_meta' ], 10, 2 );
            // 列表語言可用性欄位（小國旗 + 綠勾）
            add_action( 'admin_init', [ $this, 'register_lang_columns' ] );
        } else {
            add_action( 'pre_get_posts', [ $this, 'exclude_from_lists' ] );
            add_action( 'template_redirect', [ $this, 'maybe_redirect' ] );
            // 供自訂列表(非 WP_Query／已存 ID 陣列／頁面建構器迴圈)顯式過濾用
            add_filter( 'ys_tp_filter_ids', [ $this, 'filter_ids_hook' ], 10, 2 );
            add_filter( 'ys_tp_is_hidden', [ $this, 'is_hidden_hook' ], 10, 2 );
        }
    }

    /* ───────────── 設定讀取 ───────────── */

    /** @return string[] 套用規則的 post types（依各別 content_pt_{type} 設定） */
    private function post_types(): array {
        $candidates = get_post_types( [ 'public' => true ], 'objects' );
        unset( $candidates['attachment'] );

        $result = [];
        foreach ( $candidates as $pt ) {
            if ( (int) YSTPAddonsSettingsRepo::get( 'content_pt_' . $pt->name, self::pt_default( $pt ) ) ) {
                $result[] = $pt->name;
            }
        }
        return $result ?: [ 'post', 'page' ];
    }

    /**
     * 內容類型是否「預設納入」多語管理
     *
     * 內建 post／page，以及有封存頁（has_archive）的公開自訂類型（如產品、最新消息）
     * 預設納入；頁面建構器等內部類型（無封存頁，如 Content Block／Stylebook）預設不納入。
     * 使用者仍可在設定頁逐類型開關覆寫。
     */
    public static function pt_default( \WP_Post_Type $pt ): int {
        return ( in_array( $pt->name, [ 'post', 'page' ], true ) || ! empty( $pt->has_archive ) ) ? 1 : 0;
    }

    /**
     * 內容類型「被隱藏時重導」的預設 fallback 目標
     *
     * 文章 → 該語言文章列表頁（post_archive）；頁面 → 該語言首頁（home）；
     * 有封存頁的自訂類型 → 該類型自身的封存頁（cpt_archive，如產品 → /product/、消息 → /news/）；
     * 其餘（無封存頁）→ 未指定（inherit，改用全域預設）。模板與 fallback_url 共用此邏輯，
     * 確保下拉預設值與實際重導一致。
     */
    public static function pt_fallback_default( string $post_type ): string {
        if ( 'post' === $post_type ) {
            return 'post_archive';
        }
        if ( 'page' === $post_type ) {
            return 'home';
        }
        $obj = get_post_type_object( $post_type );
        if ( $obj && ! empty( $obj->has_archive ) ) {
            return 'cpt_archive';
        }
        return 'inherit';
    }

    private function redirect_enabled(): bool {
        return (bool) (int) YSTPAddonsSettingsRepo::get( 'content_redirect_enabled', 1 );
    }

    /* ───────────── 後台 Meta Box ───────────── */

    public function register_meta_box(): void {
        foreach ( $this->post_types() as $pt ) {
            add_meta_box(
                'ys_tp_content_rules',
                __( '多語顯示規則', 'ys-translatepress-addons' ),
                [ $this, 'render_meta_box' ],
                $pt,
                'side',
                'default'
            );
        }
    }

    public function render_meta_box( $post ): void {
        $languages = YSTPAddonsTP::languages();
        $mode      = get_post_meta( $post->ID, self::META_MODE, true ) ?: 'all';
        $list      = get_post_meta( $post->ID, self::META_LIST, true );
        $list      = is_array( $list ) ? $list : [];

        wp_nonce_field( 'ys_tp_content_' . $post->ID, 'ys_tp_content_nonce' );
        ?>
        <div class="ys-tp-cr">
            <p style="margin:.2em 0 .6em;">
                <label><input type="radio" name="ys_tp_cr_mode" value="all" <?php checked( $mode, 'all' ); ?> /> <?php esc_html_e( '所有語言顯示', 'ys-translatepress-addons' ); ?></label><br />
                <label><input type="radio" name="ys_tp_cr_mode" value="exclude" <?php checked( $mode, 'exclude' ); ?> /> <?php esc_html_e( '排除以下語言', 'ys-translatepress-addons' ); ?></label><br />
                <label><input type="radio" name="ys_tp_cr_mode" value="include" <?php checked( $mode, 'include' ); ?> /> <?php esc_html_e( '只在以下語言顯示', 'ys-translatepress-addons' ); ?></label>
            </p>
            <div class="ys-tp-cr-langs" style="<?php echo 'all' === $mode ? 'display:none;' : ''; ?>padding:6px 0 2px;border-top:1px solid #eee;">
                <?php foreach ( $languages as $code => $name ) : ?>
                    <label style="display:block;margin:3px 0;">
                        <input type="checkbox" name="ys_tp_cr_list[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, $list, true ) ); ?> />
                        <?php echo esc_html( $name ); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="description" style="margin-top:6px;"><?php esc_html_e( '切換到被隱藏的語言時，此內容會從列表移除；若直接造訪則重導至設定的對應頁。', 'ys-translatepress-addons' ); ?></p>
        </div>
        <script>
        ( function () {
            var box = document.getElementById( 'ys_tp_content_rules' );
            if ( ! box ) return;
            box.addEventListener( 'change', function ( e ) {
                if ( e.target.name === 'ys_tp_cr_mode' ) {
                    box.querySelector( '.ys-tp-cr-langs' ).style.display = ( e.target.value === 'all' ) ? 'none' : 'block';
                }
            } );
        } )();
        </script>
        <?php
    }

    public function save_meta( $post_id, $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // 接受「完整編輯器（逐篇 nonce）」或「快速編輯（靜態 nonce）」二者之一
        $verified = false;
        if ( ! empty( $_POST['ys_tp_content_nonce'] )
            && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ys_tp_content_nonce'] ) ), 'ys_tp_content_' . $post_id ) ) {
            $verified = true;
        } elseif ( ! empty( $_POST['ys_tp_content_qe_nonce'] )
            && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ys_tp_content_qe_nonce'] ) ), 'ys_tp_content_quickedit' ) ) {
            $verified = true;
        }
        if ( ! $verified ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $mode = isset( $_POST['ys_tp_cr_mode'] ) ? sanitize_key( wp_unslash( $_POST['ys_tp_cr_mode'] ) ) : 'all';
        if ( ! in_array( $mode, [ 'all', 'exclude', 'include' ], true ) ) {
            $mode = 'all';
        }

        if ( 'all' === $mode ) {
            delete_post_meta( $post_id, self::META_MODE );
            delete_post_meta( $post_id, self::META_LIST );
        } else {
            $valid = array_keys( YSTPAddonsTP::languages() );
            $list  = [];
            if ( isset( $_POST['ys_tp_cr_list'] ) && is_array( $_POST['ys_tp_cr_list'] ) ) {
                $list = array_values( array_intersect(
                    array_map( 'sanitize_text_field', wp_unslash( $_POST['ys_tp_cr_list'] ) ),
                    $valid
                ) );
            }
            update_post_meta( $post_id, self::META_MODE, $mode );
            update_post_meta( $post_id, self::META_LIST, $list );
        }

        $this->flush_cache();
    }

    /* ───────────── 列表：語言可用性欄位 ───────────── */

    /**
     * 於各套用內容類型的列表加入「語言」欄（admin_init 時 CPT 已註冊完畢）
     */
    public function register_lang_columns(): void {
        if ( ! (int) YSTPAddonsSettingsRepo::get( 'content_lang_column', 1 ) ) {
            return;
        }
        foreach ( $this->post_types() as $pt ) {
            add_filter( "manage_edit-{$pt}_columns", [ $this, 'add_lang_column' ] );
            add_action( "manage_{$pt}_posts_custom_column", [ $this, 'render_lang_column' ], 10, 2 );
        }
        add_action( 'admin_head', [ $this, 'lang_column_css' ] );

        // 列表上方語言篩選下拉 + 套用
        add_action( 'restrict_manage_posts', [ $this, 'render_lang_filter' ] );
        add_action( 'pre_get_posts', [ $this, 'filter_list_by_lang' ] );

        // 快速編輯：於列表直接調整語言顯示規則
        add_action( 'quick_edit_custom_box', [ $this, 'render_quick_edit' ], 10, 2 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_quick_edit' ] );
    }

    /**
     * 快速編輯：輸出「多語顯示規則」欄位（所有／排除／只在 + 語言勾選）
     *
     * WordPress 不會自動預填自訂欄位，值由 ys-tp-quickedit.js 依列表列的 data 屬性填入。
     *
     * @param string $column_name
     * @param string $post_type
     */
    public function render_quick_edit( $column_name, $post_type ): void {
        if ( 'ys_tp_langs' !== $column_name || ! in_array( $post_type, $this->post_types(), true ) ) {
            return;
        }
        $languages = YSTPAddonsTP::languages();
        if ( count( $languages ) < 2 ) {
            return;
        }
        wp_nonce_field( 'ys_tp_content_quickedit', 'ys_tp_content_qe_nonce' );
        // 緊湊版：模式用下拉（欄位名與值同 radio 版，儲存端 save_meta 零改動）、語言勾選同列展開
        ?>
        <fieldset class="inline-edit-col-right ys-tp-qe">
            <div class="inline-edit-col">
                <label class="inline-edit-group ys-tp-qe-row" style="display:flex;align-items:center;flex-wrap:wrap;gap:6px 10px;">
                    <span class="title" style="width:auto;margin:0;"><?php esc_html_e( '多語顯示', 'ys-translatepress-addons' ); ?></span>
                    <select name="ys_tp_cr_mode">
                        <option value="all"><?php esc_html_e( '所有語言顯示', 'ys-translatepress-addons' ); ?></option>
                        <option value="exclude"><?php esc_html_e( '排除以下語言', 'ys-translatepress-addons' ); ?></option>
                        <option value="include"><?php esc_html_e( '只在以下語言顯示', 'ys-translatepress-addons' ); ?></option>
                    </select>
                    <span class="ys-tp-qe-langs" style="display:none;">
                        <?php foreach ( $languages as $code => $name ) : ?>
                            <label style="display:inline-block;margin:0 10px 0 0;vertical-align:middle;"><input type="checkbox" name="ys_tp_cr_list[]" value="<?php echo esc_attr( $code ); ?>" /> <?php echo esc_html( $name ); ?></label>
                        <?php endforeach; ?>
                    </span>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * 於套用類型的列表頁載入快速編輯 JS
     */
    public function enqueue_quick_edit( string $hook ): void {
        if ( 'edit.php' !== $hook ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, $this->post_types(), true ) ) {
            return;
        }
        wp_enqueue_script(
            'ys-tp-quickedit',
            YS_TP_PLUGIN_URL . 'assets/js/ys-tp-quickedit.js',
            [ 'jquery', 'inline-edit-post' ],
            YS_TP_VERSION,
            true
        );
    }

    /**
     * 列表上方輸出「語言」篩選下拉（顯示於／隱藏於 各語言）
     *
     * @param string $post_type
     */
    public function render_lang_filter( $post_type ): void {
        if ( ! in_array( $post_type, $this->post_types(), true ) ) {
            return;
        }
        $langs = YSTPAddonsTP::languages();
        if ( count( $langs ) < 2 ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $cur = isset( $_GET['ys_tp_lf'] ) ? sanitize_text_field( wp_unslash( $_GET['ys_tp_lf'] ) ) : '';

        // 標籤含「顯示於／隱藏於」前綴，收合時仍可辨識（避免兩組同名語言混淆）
        echo '<select name="ys_tp_lf" id="ys-tp-lf">';
        echo '<option value="">' . esc_html__( '全部語言', 'ys-translatepress-addons' ) . '</option>';
        echo '<optgroup label="' . esc_attr__( '顯示於', 'ys-translatepress-addons' ) . '">';
        foreach ( $langs as $code => $name ) {
            printf(
                '<option value="show_%s" %s>%s</option>',
                esc_attr( $code ),
                selected( $cur, 'show_' . $code, false ),
                esc_html__( '顯示於', 'ys-translatepress-addons' ) . ' ' . esc_html( $name )
            );
        }
        echo '</optgroup><optgroup label="' . esc_attr__( '隱藏於', 'ys-translatepress-addons' ) . '">';
        foreach ( $langs as $code => $name ) {
            printf(
                '<option value="hide_%s" %s>%s</option>',
                esc_attr( $code ),
                selected( $cur, 'hide_' . $code, false ),
                esc_html__( '隱藏於', 'ys-translatepress-addons' ) . ' ' . esc_html( $name )
            );
        }
        echo '</optgroup></select>';
    }

    /**
     * 依語言篩選列表主查詢
     */
    public function filter_list_by_lang( $query ): void {
        if ( ! is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $val = isset( $_GET['ys_tp_lf'] ) ? sanitize_text_field( wp_unslash( $_GET['ys_tp_lf'] ) ) : '';
        if ( ! preg_match( '/^(show|hide)_(.+)$/', $val, $m ) ) {
            return;
        }
        $pt = $query->get( 'post_type' );
        if ( is_array( $pt ) ) {
            return;
        }
        if ( '' === $pt ) {
            $pt = 'post';
        }
        if ( ! in_array( $pt, $this->post_types(), true ) ) {
            return;
        }

        $mode = $m[1];
        $lang = $m[2];
        if ( ! in_array( $lang, YSTPAddonsTP::published_language_codes(), true ) ) {
            return;
        }

        $hidden = $this->hidden_ids( $lang );
        if ( 'hide' === $mode ) {
            // 只顯示「在該語言被隱藏」的內容；無則強制無結果
            $query->set( 'post__in', ! empty( $hidden ) ? $hidden : [ 0 ] );
        } elseif ( ! empty( $hidden ) ) {
            // 顯示「在該語言可見」的內容＝排除被隱藏者
            $existing = (array) $query->get( 'post__not_in' );
            $query->set( 'post__not_in', array_values( array_unique( array_merge( $existing, $hidden ) ) ) );
        }
    }

    /**
     * 在「日期」欄前插入「語言」欄
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function add_lang_column( array $columns ): array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            if ( 'date' === $key ) {
                $new['ys_tp_langs'] = __( '語言', 'ys-translatepress-addons' );
            }
            $new[ $key ] = $label;
        }
        if ( ! isset( $new['ys_tp_langs'] ) ) {
            $new['ys_tp_langs'] = __( '語言', 'ys-translatepress-addons' );
        }
        return $new;
    }

    /**
     * 渲染語言欄：各語言小國旗，可見語言帶綠勾、被隱藏語言灰階
     */
    public function render_lang_column( $column, $post_id ): void {
        if ( 'ys_tp_langs' !== $column ) {
            return;
        }
        $langs = YSTPAddonsTP::languages();
        if ( empty( $langs ) ) {
            echo '—';
            return;
        }
        echo '<span class="ys-tp-langcol">';
        foreach ( $langs as $code => $name ) {
            $hidden = self::is_hidden( (int) $post_id, $code );
            $title  = $name . ( $hidden
                ? '（' . __( '不顯示', 'ys-translatepress-addons' ) . '）'
                : '（' . __( '顯示', 'ys-translatepress-addons' ) . '）' );
            printf(
                '<span class="ys-tp-lf %s" title="%s"><img src="%s" alt="" width="18" height="12" loading="lazy" />%s</span>',
                $hidden ? 'is-off' : 'is-on',
                esc_attr( $title ),
                esc_url( YSTPAddonsTP::flag_url( $code ) ),
                $hidden ? '' : '<i class="ys-tp-lf-ck" aria-hidden="true"></i>' // phpcs:ignore
            );
        }
        echo '</span>';

        // 供「快速編輯」JS 讀取目前規則以預填
        $mode = get_post_meta( (int) $post_id, self::META_MODE, true ) ?: 'all';
        $list = get_post_meta( (int) $post_id, self::META_LIST, true );
        $list = is_array( $list ) ? $list : [];
        printf(
            '<span class="ys-tp-lang-data" data-mode="%s" data-list="%s" style="display:none;"></span>',
            esc_attr( $mode ),
            esc_attr( implode( ',', $list ) )
        );
    }

    /**
     * 語言欄樣式（僅在套用類型的列表頁輸出）
     */
    public function lang_column_css(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'edit' !== $screen->base || ! in_array( $screen->post_type, $this->post_types(), true ) ) {
            return;
        }
        echo '<style>'
            . '.column-ys_tp_langs{width:118px;}'
            . '.ys-tp-langcol{display:inline-flex;flex-wrap:wrap;gap:7px;align-items:center;}'
            . '.ys-tp-lf{position:relative;display:inline-flex;line-height:0;}'
            . '.ys-tp-lf img{border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.12);}'
            . '.ys-tp-lf.is-off img{filter:grayscale(1);opacity:.35;}'
            . '.ys-tp-lf-ck{position:absolute;right:-5px;bottom:-5px;width:12px;height:12px;background:#46b450;border-radius:50%;border:1.5px solid #fff;box-shadow:0 1px 2px rgba(0,0,0,.2);}'
            . '.ys-tp-lf-ck::after{content:"";position:absolute;left:3.5px;top:1.5px;width:3px;height:5px;border:solid #fff;border-width:0 1.5px 1.5px 0;transform:rotate(45deg);}'
            . '</style>';
    }

    /* ───────────── 隱藏判斷 ───────────── */

    /**
     * 某內容在指定語言是否被隱藏
     *
     * 優先序：逐篇規則（有設定時）覆寫類型層級預設；逐篇未設定則套用該類型的類型層級規則。
     */
    public static function is_hidden( int $post_id, string $lang ): bool {
        $mode = get_post_meta( $post_id, self::META_MODE, true );
        if ( $mode && 'all' !== $mode ) {
            $list = get_post_meta( $post_id, self::META_LIST, true );
            return self::eval_rule( (string) $mode, is_array( $list ) ? $list : [], $lang );
        }
        // 逐篇未設定 → 套用該內容類型的類型層級規則
        return self::is_type_hidden( (string) get_post_type( $post_id ), $lang );
    }

    /**
     * 判斷 exclude／include 規則在某語言是否隱藏
     *
     * @param string[] $list
     */
    private static function eval_rule( string $mode, array $list, string $lang ): bool {
        if ( 'exclude' === $mode ) {
            return in_array( $lang, $list, true );
        }
        if ( 'include' === $mode ) {
            return ! in_array( $lang, $list, true );
        }
        return false;
    }

    /**
     * 類型層級：整個內容類型是否在某語言被隱藏
     *
     * 後台可逐「內容類型 × 語言」勾選（設定鍵 content_pt_hide_{type}_{lang}，lang 小寫），
     * 用於「一鍵關掉整個內容類型的多語顯示」。逐篇規則會覆寫此預設。
     */
    public static function is_type_hidden( string $post_type, string $lang ): bool {
        if ( '' === $post_type ) {
            return false;
        }
        $key = 'content_pt_hide_' . $post_type . '_' . strtolower( $lang );
        return (bool) (int) YSTPAddonsSettingsRepo::get( $key, 0 );
    }

    /**
     * 取得某語言要隱藏的 post IDs（含快取）
     *
     * @return int[]
     */
    private function hidden_ids( string $lang ): array {
        $cache_key = self::CACHE_PREFIX . md5( $lang );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;

        // (a) 逐篇規則（有 mode meta 的內容）
        $rows   = $wpdb->get_results(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '" . esc_sql( self::META_MODE ) . "'"
        );
        $hidden = [];
        $ruled  = [];
        foreach ( (array) $rows as $row ) {
            $pid           = (int) $row->post_id;
            $ruled[ $pid ] = true;
            $mode          = get_post_meta( $pid, self::META_MODE, true );
            if ( $mode && 'all' !== $mode ) {
                $list = get_post_meta( $pid, self::META_LIST, true );
                if ( self::eval_rule( (string) $mode, is_array( $list ) ? $list : [], $lang ) ) {
                    $hidden[] = $pid;
                }
            }
        }

        // (b) 類型層級：整個類型在此語言被關閉 → 該類型所有已發佈內容（無逐篇覆寫者）
        //     🔴 必須用直接 SQL 取 ID：WP_Query／get_posts 會觸發 pre_get_posts →
        //     exclude_from_lists → 再進 hidden_ids 造成無限遞迴（suppress_filters 不擋 pre_get_posts）。
        $hidden_types = [];
        foreach ( $this->post_types() as $pt ) {
            if ( self::is_type_hidden( $pt, $lang ) ) {
                $hidden_types[] = $pt;
            }
        }
        if ( ! empty( $hidden_types ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $hidden_types ), '%s' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $type_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ( {$placeholders} )",
                    $hidden_types
                )
            );
            foreach ( (array) $type_ids as $id ) {
                if ( ! isset( $ruled[ (int) $id ] ) ) {
                    $hidden[] = (int) $id;
                }
            }
        }

        $hidden = array_values( array_unique( array_map( 'intval', $hidden ) ) );
        set_transient( $cache_key, $hidden, DAY_IN_SECONDS );
        return $hidden;
    }

    /**
     * 逐篇規則 meta 變更(added/updated/deleted_post_meta)→ 失效隱藏清單快取
     *
     * @param int|int[] $meta_id  Meta ID(deleted 時為陣列)。
     * @param int       $post_id  Post ID。
     * @param string    $meta_key Meta key。
     */
    public function on_meta_change( $meta_id, $post_id, $meta_key ): void {
        if ( self::META_MODE === $meta_key || self::META_LIST === $meta_key ) {
            $this->flush_cache();
        }
    }

    /**
     * 刪文（before_delete_post，meta 尚在）→ 若該文有逐篇規則，失效隱藏清單快取
     */
    public function on_post_delete( $post_id ): void {
        if ( metadata_exists( 'post', (int) $post_id, self::META_MODE ) ) {
            $this->flush_cache();
        }
    }

    /**
     * 設定存檔後：影響隱藏清單的規則變動時失效快取
     *
     * 涵蓋「整個類型隱藏於某語言」(content_pt_hide_{type}_{lang}) 與
     * 「該類型是否套用規則」(content_pt_{type})——後者會改變 hidden_ids 掃描的類型集合，
     * 兩者皆以 content_pt 前綴起始，故一併偵測。
     *
     * @param string[] $keys
     */
    public function on_settings_saved( $keys ): void {
        foreach ( (array) $keys as $k ) {
            if ( is_string( $k ) && 0 === strpos( $k, 'content_pt' ) ) {
                $this->flush_cache();
                return;
            }
        }
    }

    /**
     * 公開：過濾掉「指定(或當前)語言被隱藏」的 post IDs。
     * 供自訂列表(非 WP_Query／已存 ID 陣列／頁面建構器迴圈)顯式呼叫。
     *
     * @param int[]       $ids
     * @param string|null $lang 預設當前語言
     * @return int[]
     */
    public static function filter_ids( array $ids, ?string $lang = null ): array {
        $lang = $lang ?: YSTPAddonsTP::current_language();
        return array_values( array_filter( $ids, static function ( $id ) use ( $lang ) {
            return ! self::is_hidden( (int) $id, $lang );
        } ) );
    }

    /** filter：apply_filters( 'ys_tp_filter_ids', int[] $ids, ?string $lang ) → 過濾後 IDs */
    public function filter_ids_hook( $ids, $lang = null ): array {
        if ( ! is_array( $ids ) ) {
            return [];
        }
        return self::filter_ids( $ids, ( is_string( $lang ) && '' !== $lang ) ? $lang : null );
    }

    /** filter：apply_filters( 'ys_tp_is_hidden', bool $hidden, int $post_id ) → 當前語言是否隱藏 */
    public function is_hidden_hook( $hidden, $post_id = 0 ): bool {
        return $hidden ? true : self::is_hidden( (int) $post_id, YSTPAddonsTP::current_language() );
    }

    public function flush_cache(): void {
        foreach ( YSTPAddonsTP::published_language_codes() as $code ) {
            delete_transient( self::CACHE_PREFIX . md5( $code ) );
        }
    }

    /* ───────────── 前台：列表排除 ───────────── */

    public function exclude_from_lists( $query ): void {
        if ( is_admin() || ! $query instanceof \WP_Query ) {
            return;
        }
        // 單頁不在此處理（交給 maybe_redirect）
        if ( $query->is_singular() ) {
            return;
        }
        // 防禦：hidden_ids 計算期間若有巢狀查詢，不再進入（避免遞迴）
        if ( self::$resolving ) {
            return;
        }

        $lang = YSTPAddonsTP::current_language();

        self::$resolving = true;
        $hidden = $this->hidden_ids( $lang );
        self::$resolving = false;

        if ( empty( $hidden ) ) {
            return;
        }

        $existing = (array) $query->get( 'post__not_in' );
        $query->set( 'post__not_in', array_values( array_unique( array_merge( $existing, $hidden ) ) ) );
    }

    /* ───────────── 前台：單頁智慧重導 ───────────── */

    public function maybe_redirect(): void {
        if ( ! $this->redirect_enabled() ) {
            return;
        }
        $lang = YSTPAddonsTP::current_language();

        // 內容類型封存頁：整個類型在此語言被關閉 → 依該類型 fallback 重導
        if ( is_post_type_archive() ) {
            $pt = get_query_var( 'post_type' );
            $pt = is_array( $pt ) ? (string) reset( $pt ) : (string) $pt;
            if ( '' !== $pt && in_array( $pt, $this->post_types(), true ) && self::is_type_hidden( $pt, $lang ) ) {
                $this->redirect_for( $pt, $lang, null );
            }
            return;
        }

        // 單頁；或「文章頁面」(page_for_posts，前台為 is_home 而非 is_singular)
        $post = null;
        if ( is_singular() ) {
            $obj  = get_queried_object();
            $post = ( $obj instanceof \WP_Post ) ? $obj : null;
        } elseif ( is_home() && ! is_front_page() ) {
            $pid = (int) get_option( 'page_for_posts' );
            if ( $pid ) {
                $post = get_post( $pid );
            }
        }

        if ( ! $post instanceof \WP_Post ) {
            return;
        }
        if ( ! in_array( $post->post_type, $this->post_types(), true ) ) {
            return;
        }
        if ( ! self::is_hidden( (int) $post->ID, $lang ) ) {
            return;
        }

        $this->redirect_for( $post->post_type, $lang, $post );
    }

    /**
     * 執行重導或 404（階梯：上層 → 類型 fallback → 全域預設）
     *
     * @param \WP_Post|null $post 有 post 時才嘗試「跳上層」
     */
    private function redirect_for( string $post_type, string $lang, ?\WP_Post $post ): void {
        $status = (string) YSTPAddonsSettingsRepo::get( 'content_redirect_status', '302' );

        // 404 模式：不重導，直接回 404（搜尋引擎據此移除該語言索引）
        if ( '404' === $status ) {
            $this->trigger_404();
            return;
        }

        $code   = ( '301' === $status ) ? 301 : 302;
        $target = '';

        // ① 上層偵測：跳到最近一個「該語言可見」的上層（頁面／階層式 CPT）
        if ( $post && (int) YSTPAddonsSettingsRepo::get( 'content_fb_parent', 1 ) ) {
            $ancestor = $this->nearest_visible_ancestor( $post, $lang );
            if ( $ancestor ) {
                $url = get_permalink( $ancestor );
                if ( $url ) {
                    $target = YSTPAddonsTP::url_for_language( $lang, $url );
                }
            }
        }

        // ② 無可用上層 → 該類型 fallback（未指定則 fallback_url 內轉用全域預設）
        if ( '' === $target ) {
            $target = $this->fallback_url( $post_type, $lang );
        }

        if ( '' === $target ) {
            return;
        }
        // 迴圈防護：目標與當前網址相同時不重導
        if ( untrailingslashit( $target ) === untrailingslashit( YSTPAddonsTP::current_url() ) ) {
            return;
        }
        wp_safe_redirect( $target, $code );
        exit;
    }

    /**
     * 沿 post_parent 往上找最近一個「已發佈且在該語言可見」的上層
     *
     * @return int 上層 post ID；0 表示沒有可用上層
     */
    private function nearest_visible_ancestor( \WP_Post $post, string $lang ): int {
        $pid   = (int) $post->post_parent;
        $guard = 0;
        while ( $pid && $guard < 20 ) {
            $parent = get_post( $pid );
            if ( ! $parent instanceof \WP_Post ) {
                break;
            }
            if ( 'publish' === $parent->post_status && ! self::is_hidden( $pid, $lang ) ) {
                return $pid;
            }
            $pid = (int) $parent->post_parent;
            $guard++;
        }
        return 0;
    }

    /**
     * 直接回 404（不重導）
     */
    private function trigger_404(): void {
        global $wp_query;
        if ( $wp_query instanceof \WP_Query ) {
            $wp_query->set_404();
        }
        status_header( 404 );
        nocache_headers();
        $template = get_404_template();
        if ( $template ) {
            include $template;
        }
        exit;
    }

    /**
     * 計算某 post type 在某語言的 fallback 目標 URL（當前語言版本）
     *
     * 每個 post type 可設定目標，或設為「未指定」改用全域預設跳轉頁。
     */
    private function fallback_url( string $post_type, string $lang ): string {
        // 每個內容類型各自的重導目標（content_fb_{type}）；預設值與設定頁下拉共用 pt_fallback_default
        $opt = (string) YSTPAddonsSettingsRepo::get( 'content_fb_' . $post_type, self::pt_fallback_default( $post_type ) );

        // 未指定 → 全域預設跳轉頁
        if ( '' === $opt || 'inherit' === $opt ) {
            $opt = (string) YSTPAddonsSettingsRepo::get( 'content_fb_global', 'home' );
        }

        $base = $this->resolve_target_base( $opt, $post_type );
        return YSTPAddonsTP::url_for_language( $lang, $base );
    }

    /**
     * 將 fallback 設定值解析為「預設語言」的基底 URL
     *
     * 支援：home｜post_archive｜page:{ID}｜cpt_archive
     */
    private function resolve_target_base( string $opt, string $post_type ): string {
        if ( 'home' === $opt ) {
            return home_url( '/' );
        }
        if ( 'post_archive' === $opt ) {
            $page_for_posts = (int) get_option( 'page_for_posts' );
            if ( $page_for_posts ) {
                $url = get_permalink( $page_for_posts );
                if ( $url ) {
                    return $url;
                }
            }
            return home_url( '/' );
        }
        if ( 'cpt_archive' === $opt ) {
            $url = get_post_type_archive_link( $post_type );
            return $url ?: home_url( '/' );
        }
        if ( 0 === strpos( $opt, 'page:' ) ) {
            $pid = (int) substr( $opt, 5 );
            $url = $pid ? get_permalink( $pid ) : '';
            return $url ?: home_url( '/' );
        }
        return home_url( '/' );
    }
}
