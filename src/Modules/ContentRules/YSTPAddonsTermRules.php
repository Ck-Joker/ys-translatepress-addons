<?php
/**
 * 內容語言規則 — 分類（taxonomy term）層級
 *
 * 讓「分類」與文章對等地支援語言設置：
 * - 每個分類可設定：所有語言顯示｜排除指定語言｜只在指定語言顯示（term meta）
 * - 分類列表加「語言」欄（小國旗＋綠勾／灰階，與文章列表一致）＋ 緊湊快速編輯
 * - 前台造訪被隱藏語言的分類頁時，依「該分類所屬內容類型」的重導原則跳轉
 * - 前台分類清單（get_terms／分類雲等）自動排除當前語言被隱藏的分類
 * - 未逐項設定時，繼承類型層級：所屬（套用中的）內容類型全部在該語言隱藏 → 分類視同隱藏
 *
 * 套用範圍跟隨內容類型設定：public taxonomy 且掛在「已套用內容語言規則的 post type」上。
 *
 * @package YangSheep\TPAddons\Modules\ContentRules
 * @since   0.16.0
 */

namespace YangSheep\TPAddons\Modules\ContentRules;

use YangSheep\TPAddons\Support\YSTPAddonsTP;
use YangSheep\TPAddons\Database\YSTPAddonsSettingsRepo;

defined( 'ABSPATH' ) || exit;

class YSTPAddonsTermRules {

    public const META_MODE = '_ys_tp_term_lang_mode';  // all | exclude | include
    public const META_LIST = '_ys_tp_term_lang_list';  // array of language codes

    private YSTPAddonsContentRules $content_rules;

    public function __construct( YSTPAddonsContentRules $content_rules ) {
        $this->content_rules = $content_rules;
    }

    public function boot(): void {
        // 儲存：完整編輯頁與快速編輯最終都走 wp_update_term → edited_term（單一入口）
        add_action( 'edited_term', [ $this, 'save_term' ], 10, 3 );

        if ( is_admin() ) {
            // taxonomy 於 init 後才註冊完，欄位／表單 hook 需在 admin_init 逐 taxonomy 掛
            add_action( 'admin_init', [ $this, 'register_admin' ] );
        } else {
            add_action( 'template_redirect', [ $this, 'maybe_redirect' ] );
            add_filter( 'get_terms', [ $this, 'exclude_terms' ], 20, 2 );
        }
    }

    /* ───────────── 套用範圍 ───────────── */

    /**
     * 套用規則的 taxonomies：public 且掛在「已套用內容語言規則的 post type」上
     *
     * @return string[]
     */
    public static function applied_taxonomies(): array {
        $pts    = YSTPAddonsContentRules::applied_post_types();
        $result = [];
        foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
            if ( array_intersect( (array) $tax->object_type, $pts ) ) {
                $result[] = $tax->name;
            }
        }
        return $result;
    }

    /**
     * 分類所屬的「主要內容類型」（object_type 與套用類型的交集第一個）
     *
     * 用於決定被隱藏分類頁的重導原則（如 消息分類 → ys_news → 其封存頁）。
     */
    public static function primary_post_type( string $taxonomy ): string {
        $tax = get_taxonomy( $taxonomy );
        if ( $tax ) {
            $applied = array_values( array_intersect( (array) $tax->object_type, YSTPAddonsContentRules::applied_post_types() ) );
            if ( ! empty( $applied ) ) {
                return (string) $applied[0];
            }
        }
        return 'post';
    }

    /* ───────────── 隱藏判斷 ───────────── */

    /**
     * 某分類在指定語言是否被隱藏
     *
     * 逐項規則（term meta）優先；未設定則繼承類型層級——所屬（套用中的）
     * 內容類型「全部」在該語言隱藏時，分類頁已無可瀏覽內容，視同隱藏。
     */
    public static function is_hidden( int $term_id, string $lang ): bool {
        $mode = get_term_meta( $term_id, self::META_MODE, true );
        if ( $mode && 'all' !== $mode ) {
            $list = get_term_meta( $term_id, self::META_LIST, true );
            $list = is_array( $list ) ? $list : [];
            return ( 'exclude' === $mode )
                ? in_array( $lang, $list, true )
                : ! in_array( $lang, $list, true );
        }

        $term = get_term( $term_id );
        if ( ! $term instanceof \WP_Term ) {
            return false;
        }
        $tax = get_taxonomy( $term->taxonomy );
        if ( ! $tax ) {
            return false;
        }
        $applied = array_intersect( (array) $tax->object_type, YSTPAddonsContentRules::applied_post_types() );
        if ( empty( $applied ) ) {
            return false;
        }
        foreach ( $applied as $pt ) {
            if ( ! YSTPAddonsContentRules::is_type_hidden( (string) $pt, $lang ) ) {
                return false;
            }
        }
        return true;
    }

    /* ───────────── 前台 ───────────── */

    /**
     * 被隱藏語言的分類頁 → 依所屬內容類型的重導原則跳轉
     */
    public function maybe_redirect(): void {
        if ( ! is_category() && ! is_tag() && ! is_tax() ) {
            return;
        }
        if ( ! (int) YSTPAddonsSettingsRepo::get( 'content_redirect_enabled', 1 ) ) {
            return;
        }
        $term = get_queried_object();
        if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, self::applied_taxonomies(), true ) ) {
            return;
        }
        $lang = YSTPAddonsTP::current_language();
        if ( ! self::is_hidden( (int) $term->term_id, $lang ) ) {
            return;
        }
        $this->content_rules->redirect_for_type( self::primary_post_type( $term->taxonomy ), $lang );
    }

    /**
     * 前台分類清單排除：get_terms 結果移除「當前語言被隱藏」的分類
     *
     * 僅處理 WP_Term 物件清單（fields=all）；ids／names 等輕量查詢原樣返回。
     * 判斷只讀 term meta 與設定，不觸發巢狀 get_terms，無遞迴風險。
     *
     * @param array $terms
     * @param mixed $taxonomies
     * @return array
     */
    public function exclude_terms( $terms, $taxonomies = null ) {
        if ( is_admin() || empty( $terms ) || ! is_array( $terms ) ) {
            return $terms;
        }
        $applied = self::applied_taxonomies();
        if ( empty( $applied ) ) {
            return $terms;
        }
        $lang = YSTPAddonsTP::current_language();

        foreach ( $terms as $key => $term ) {
            if ( ! $term instanceof \WP_Term ) {
                return $terms; // 非物件清單（ids／names／count）不處理
            }
            if ( in_array( $term->taxonomy, $applied, true ) && self::is_hidden( (int) $term->term_id, $lang ) ) {
                unset( $terms[ $key ] );
            }
        }
        return array_values( $terms );
    }

    /* ───────────── 後台 ───────────── */

    /**
     * 逐套用 taxonomy 掛列表欄／編輯表單（admin_init：taxonomy 已註冊完畢）
     */
    public function register_admin(): void {
        $taxes = array_filter( self::applied_taxonomies(), static function ( $tax ) {
            $obj = get_taxonomy( $tax );
            return $obj && $obj->show_ui;
        } );
        if ( empty( $taxes ) || count( YSTPAddonsTP::languages() ) < 2 ) {
            return;
        }

        foreach ( $taxes as $tax ) {
            if ( (int) YSTPAddonsSettingsRepo::get( 'content_lang_column', 1 ) ) {
                add_filter( "manage_edit-{$tax}_columns", [ $this->content_rules, 'add_lang_column' ] );
                add_filter( "manage_{$tax}_custom_column", [ $this, 'render_lang_column' ], 10, 3 );
            }
            add_action( "{$tax}_edit_form_fields", [ $this, 'render_edit_fields' ], 10, 2 );
        }

        add_action( 'quick_edit_custom_box', [ $this, 'render_quick_edit' ], 10, 3 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_head', [ $this, 'column_css' ] );
    }

    /**
     * 分類列表「語言」欄（term 版為 filter，需 return 而非 echo）
     *
     * @param string $out
     * @param string $column
     * @param int    $term_id
     * @return string
     */
    public function render_lang_column( $out, $column, $term_id ) {
        if ( 'ys_tp_langs' !== $column ) {
            return $out;
        }
        $langs = YSTPAddonsTP::languages();
        if ( empty( $langs ) ) {
            return $out . '—';
        }

        $html = '<span class="ys-tp-langcol">';
        foreach ( $langs as $code => $name ) {
            $hidden = self::is_hidden( (int) $term_id, $code );
            $title  = $name . ( $hidden
                ? '（' . __( '不顯示', 'ys-translatepress-addons' ) . '）'
                : '（' . __( '顯示', 'ys-translatepress-addons' ) . '）' );
            $html  .= sprintf(
                '<span class="ys-tp-lf %s" title="%s"><img src="%s" alt="" width="18" height="12" loading="lazy" />%s</span>',
                $hidden ? 'is-off' : 'is-on',
                esc_attr( $title ),
                esc_url( YSTPAddonsTP::flag_url( $code ) ),
                $hidden ? '' : '<i class="ys-tp-lf-ck" aria-hidden="true"></i>'
            );
        }
        $html .= '</span>';

        // 供「快速編輯」JS 預填目前規則
        $mode = get_term_meta( (int) $term_id, self::META_MODE, true ) ?: 'all';
        $list = get_term_meta( (int) $term_id, self::META_LIST, true );
        $list = is_array( $list ) ? $list : [];
        $html .= sprintf(
            '<span class="ys-tp-lang-data" data-mode="%s" data-list="%s" style="display:none;"></span>',
            esc_attr( $mode ),
            esc_attr( implode( ',', $list ) )
        );

        return $out . $html;
    }

    /**
     * 語言欄樣式（分類列表頁）
     */
    public function column_css(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-tags' !== $screen->base || ! in_array( $screen->taxonomy, self::applied_taxonomies(), true ) ) {
            return;
        }
        echo '<style>' . YSTPAddonsContentRules::lang_column_css_rules() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * 共用的「多語顯示」欄位組（下拉＋語言勾選，與文章快速編輯的緊湊版一致）
     */
    private function render_controls( string $mode, array $list ): void {
        $languages = YSTPAddonsTP::languages();
        ?>
        <span class="ys-tp-qe" style="display:inline-flex;align-items:center;flex-wrap:wrap;gap:6px 10px;">
            <select name="ys_tp_cr_mode">
                <option value="all" <?php selected( $mode, 'all' ); ?>><?php esc_html_e( '所有語言顯示', 'ys-translatepress-addons' ); ?></option>
                <option value="exclude" <?php selected( $mode, 'exclude' ); ?>><?php esc_html_e( '排除以下語言', 'ys-translatepress-addons' ); ?></option>
                <option value="include" <?php selected( $mode, 'include' ); ?>><?php esc_html_e( '只在以下語言顯示', 'ys-translatepress-addons' ); ?></option>
            </select>
            <span class="ys-tp-qe-langs" style="<?php echo 'all' === $mode ? 'display:none;' : ''; ?>">
                <?php foreach ( $languages as $code => $name ) : ?>
                    <label style="display:inline-block;margin:0 10px 0 0;vertical-align:middle;"><input type="checkbox" name="ys_tp_cr_list[]" value="<?php echo esc_attr( $code ); ?>" <?php checked( 'all' !== $mode && in_array( $code, $list, true ) ); ?> /> <?php echo esc_html( $name ); ?></label>
                <?php endforeach; ?>
            </span>
        </span>
        <?php
    }

    /**
     * 分類完整編輯頁（term.php）的「多語顯示」欄位
     *
     * @param \WP_Term $term
     */
    public function render_edit_fields( $term ): void {
        if ( count( YSTPAddonsTP::languages() ) < 2 ) {
            return;
        }
        $mode = get_term_meta( (int) $term->term_id, self::META_MODE, true ) ?: 'all';
        $list = get_term_meta( (int) $term->term_id, self::META_LIST, true );
        $list = is_array( $list ) ? $list : [];
        ?>
        <tr class="form-field ys-tp-term-langs">
            <th scope="row"><label><?php esc_html_e( '多語顯示', 'ys-translatepress-addons' ); ?></label></th>
            <td>
                <?php
                wp_nonce_field( 'ys_tp_term_edit', 'ys_tp_term_nonce' );
                $this->render_controls( (string) $mode, $list );
                ?>
                <p class="description"><?php esc_html_e( '切換到被隱藏的語言時，此分類會從分類清單移除；直接造訪分類頁則依所屬內容類型的重導設定跳轉。', 'ys-translatepress-addons' ); ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * 分類列表「快速編輯」欄位（quick_edit_custom_box 於分類列表以 screen='edit-tags' 觸發）
     *
     * @param string $column_name
     * @param string $screen
     * @param string $taxonomy
     */
    public function render_quick_edit( $column_name, $screen, $taxonomy = '' ): void {
        if ( 'ys_tp_langs' !== $column_name || 'edit-tags' !== $screen
            || ! in_array( (string) $taxonomy, self::applied_taxonomies(), true ) ) {
            return;
        }
        if ( count( YSTPAddonsTP::languages() ) < 2 ) {
            return;
        }
        wp_nonce_field( 'ys_tp_term_quickedit', 'ys_tp_term_qe_nonce' );
        ?>
        <fieldset class="ys-tp-qe-wrap">
            <div class="inline-edit-col">
                <label class="inline-edit-group" style="display:flex;align-items:center;flex-wrap:wrap;gap:6px 10px;">
                    <span class="title" style="width:auto;margin:0;"><?php esc_html_e( '多語顯示', 'ys-translatepress-addons' ); ?></span>
                    <?php $this->render_controls( 'all', [] ); ?>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * 分類列表／編輯頁載入快速編輯 JS（與文章共用 ys-tp-quickedit.js）
     */
    public function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'edit-tags.php', 'term.php' ], true ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( (string) $screen->taxonomy, self::applied_taxonomies(), true ) ) {
            return;
        }
        wp_enqueue_script(
            'ys-tp-quickedit',
            YS_TP_PLUGIN_URL . 'assets/js/ys-tp-quickedit.js',
            [ 'jquery' ],
            YS_TP_VERSION,
            true
        );
    }

    /* ───────────── 儲存 ───────────── */

    /**
     * 儲存分類語言規則（完整編輯頁與快速編輯皆經 wp_update_term → edited_term）
     *
     * @param int    $term_id
     * @param int    $tt_id
     * @param string $taxonomy
     */
    public function save_term( $term_id, $tt_id, $taxonomy ): void {
        if ( ! in_array( (string) $taxonomy, self::applied_taxonomies(), true ) ) {
            return;
        }

        // 完整編輯頁（逐頁 nonce）或快速編輯（靜態 nonce）二者之一；無我們的欄位則不動 meta
        $verified = false;
        if ( ! empty( $_POST['ys_tp_term_nonce'] )
            && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ys_tp_term_nonce'] ) ), 'ys_tp_term_edit' ) ) {
            $verified = true;
        } elseif ( ! empty( $_POST['ys_tp_term_qe_nonce'] )
            && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ys_tp_term_qe_nonce'] ) ), 'ys_tp_term_quickedit' ) ) {
            $verified = true;
        }
        if ( ! $verified ) {
            return;
        }

        $tax = get_taxonomy( (string) $taxonomy );
        if ( ! $tax || ! current_user_can( $tax->cap->edit_terms ) ) {
            return;
        }

        $mode = isset( $_POST['ys_tp_cr_mode'] ) ? sanitize_key( wp_unslash( $_POST['ys_tp_cr_mode'] ) ) : 'all';
        if ( ! in_array( $mode, [ 'all', 'exclude', 'include' ], true ) ) {
            $mode = 'all';
        }

        if ( 'all' === $mode ) {
            delete_term_meta( (int) $term_id, self::META_MODE );
            delete_term_meta( (int) $term_id, self::META_LIST );
            return;
        }

        $valid = array_keys( YSTPAddonsTP::languages() );
        $list  = [];
        if ( isset( $_POST['ys_tp_cr_list'] ) && is_array( $_POST['ys_tp_cr_list'] ) ) {
            $list = array_values( array_intersect(
                array_map( 'sanitize_text_field', wp_unslash( $_POST['ys_tp_cr_list'] ) ),
                $valid
            ) );
        }
        update_term_meta( (int) $term_id, self::META_MODE, $mode );
        update_term_meta( (int) $term_id, self::META_LIST, $list );
    }
}
