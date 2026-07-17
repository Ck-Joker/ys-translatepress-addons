<?php
/**
 * 模組：選單語言控制
 *
 * 在「外觀 → 選單」每個項目加入「顯示語言」設定，前台依當前語言過濾選單項目。
 *
 * 後台 UI：wp_nav_menu_item_custom_fields
 * 儲存：    wp_update_nav_menu_item → post meta _ys_tp_menu_languages
 * 前台過濾：wp_get_nav_menu_items（含子項目層級串連隱藏）
 *
 * @package YangSheep\TPAddons\Modules\MenuLanguage
 * @since   0.2.0
 */

namespace YangSheep\TPAddons\Modules\MenuLanguage;

use YangSheep\TPAddons\Modules\YSTPAddonsModuleInterface;
use YangSheep\TPAddons\Support\YSTPAddonsTP;

defined( 'ABSPATH' ) || exit;

class YSTPAddonsMenuLanguage implements YSTPAddonsModuleInterface {

    /** @var string 選單項目語言設定的 meta key */
    public const META_KEY = '_ys_tp_menu_languages';

    public function boot(): void {
        if ( is_admin() ) {
            add_action( 'wp_nav_menu_item_custom_fields', [ $this, 'render_fields' ], 10, 2 );
            add_action( 'wp_update_nav_menu_item', [ $this, 'save_fields' ], 10, 3 );
            add_action( 'admin_print_footer_scripts-nav-menus.php', [ $this, 'inline_js' ] );
            // 選單項目標題列的語言小國旗（比照內容列表「語言」欄視覺）
            add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_flags' ] );
            add_action( 'admin_head-nav-menus.php', [ $this, 'flags_css' ] );
        } else {
            add_filter( 'wp_get_nav_menu_items', [ $this, 'filter_items' ], 20, 3 );
        }
    }

    /**
     * 「外觀 → 選單」載入項目標題列小國旗腳本
     *
     * 每個選單項目的收合標題列顯示各語言旗幟：該語言會顯示＝彩色＋綠勾、
     * 被隱藏＝灰階（與內容列表「語言」欄一致），免展開逐項檢查。
     *
     * @param string $hook 當前後台頁 hook
     */
    public function enqueue_flags( $hook ): void {
        if ( 'nav-menus.php' !== $hook ) {
            return;
        }
        $languages = YSTPAddonsTP::languages();
        if ( count( $languages ) < 2 ) {
            return;
        }

        $config = [ 'languages' => [] ];
        foreach ( $languages as $code => $name ) {
            $config['languages'][ $code ] = [
                'name' => $name . '（' . __( '顯示', 'ys-translatepress-addons' ) . '）',
                'off'  => $name . '（' . __( '不顯示', 'ys-translatepress-addons' ) . '）',
                'flag' => YSTPAddonsTP::flag_url( $code ),
            ];
        }

        wp_enqueue_script(
            'ys-tp-menu-flags',
            YS_TP_PLUGIN_URL . 'assets/js/ys-tp-menu-flags.js',
            [ 'jquery' ],
            YS_TP_VERSION,
            true
        );
        wp_localize_script( 'ys-tp-menu-flags', 'ysTPMenuFlags', $config );
    }

    /**
     * 選單項目標題列小國旗樣式（僅 nav-menus.php）
     */
    public function flags_css(): void {
        if ( count( YSTPAddonsTP::languages() ) < 2 ) {
            return;
        }
        echo '<style>'
            . '.ys-tp-menu-flags{display:inline-flex;gap:6px;margin-left:10px;align-items:center;vertical-align:middle;}'
            . '.ys-tp-mf{position:relative;display:inline-flex;line-height:0;}'
            . '.ys-tp-mf img{border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.12);display:block;}'
            . '.ys-tp-mf.is-off img{filter:grayscale(1);opacity:.35;}'
            . '.ys-tp-mf-ck{position:absolute;right:-4px;bottom:-4px;width:10px;height:10px;background:#46b450;border-radius:50%;border:1.5px solid #fff;box-shadow:0 1px 2px rgba(0,0,0,.2);}'
            . '.ys-tp-mf-ck::after{content:"";position:absolute;left:2.5px;top:1px;width:2.5px;height:4.5px;border:solid #fff;border-width:0 1.5px 1.5px 0;transform:rotate(45deg);}'
            . '</style>';
    }

    /**
     * 選單編輯器內的「所有語言」連動腳本
     */
    public function inline_js(): void {
        ?>
        <script>
        ( function ( $ ) {
            function sync( $all ) {
                var $langs = $all.closest( 'p' ).find( '.ys-tp-menu-langs' );
                var on = $all.is( ':checked' );
                $langs.css( 'opacity', on ? .45 : 1 );
                $langs.find( 'input' ).prop( 'disabled', on );
            }
            $( document ).on( 'change', '.ys-tp-menu-all', function () { sync( $( this ) ); } );
            $( '.ys-tp-menu-all' ).each( function () { sync( $( this ) ); } );
        } )( jQuery );
        </script>
        <?php
    }

    /**
     * 後台：在每個選單項目渲染語言勾選欄位
     *
     * @param int      $item_id 選單項目 ID
     * @param \WP_Post $item    選單項目
     */
    public function render_fields( $item_id, $item ): void {
        $languages = YSTPAddonsTP::languages();
        if ( empty( $languages ) ) {
            return;
        }

        $saved = get_post_meta( $item_id, self::META_KEY, true );
        $saved = is_array( $saved ) ? $saved : [];

        // 有效狀態：本外掛未設定時沿用既有（PRO）選單語言欄位——與前台 filter_items 一致。
        // 🔴 checkbox 必須反映「有效狀態」而非只看本外掛 meta：否則沿用中的項目會顯示
        // 「所有語言」勾選（與前台實際過濾不符），標題列旗幟（讀 checkbox）也跟著錯。
        // 使用者儲存選單時會將沿用值寫入本外掛 meta（顯性化，與 slug 欄位預填慣例一致）。
        $effective = $saved ?: $this->pro_menu_languages( (int) $item_id );
        $all       = empty( $effective ); // 未設定＝所有語言

        wp_nonce_field( 'ys_tp_menu_' . $item_id, 'ys_tp_menu_nonce_' . $item_id );
        printf(
            '<span class="ys-tp-menu-langdata" data-langs="%s" hidden></span>',
            esc_attr( implode( ',', $effective ) )
        );
        ?>
        <p class="field-ys-tp-menu-languages description description-wide">
            <label><strong><?php esc_html_e( '顯示語言（多語增強）', 'ys-translatepress-addons' ); ?></strong></label><br />
            <label style="display:inline-block;margin:4px 14px 4px 0;">
                <input type="checkbox" class="ys-tp-menu-all" data-item="<?php echo esc_attr( (string) $item_id ); ?>"
                    name="ys_tp_menu_all[<?php echo esc_attr( (string) $item_id ); ?>]" value="1" <?php checked( $all ); ?> />
                <?php esc_html_e( '所有語言', 'ys-translatepress-addons' ); ?>
            </label>
            <span class="ys-tp-menu-langs" style="<?php echo $all ? 'opacity:.45;' : ''; ?>">
                <?php foreach ( $languages as $code => $name ) : ?>
                    <label style="display:inline-block;margin:4px 14px 4px 0;">
                        <input type="checkbox"
                            name="ys_tp_menu_languages[<?php echo esc_attr( (string) $item_id ); ?>][]"
                            value="<?php echo esc_attr( $code ); ?>"
                            <?php checked( ! $all && in_array( $code, $effective, true ) ); ?> />
                        <?php echo esc_html( $name ); ?>
                    </label>
                <?php endforeach; ?>
            </span>
        </p>
        <?php
    }

    /**
     * 後台：儲存選單項目語言設定
     *
     * @param int $menu_id          選單 ID
     * @param int $menu_item_db_id  選單項目 ID
     */
    public function save_fields( $menu_id, $menu_item_db_id ): void {
        if ( ! current_user_can( 'edit_theme_options' ) ) {
            return;
        }
        $nonce_field = 'ys_tp_menu_nonce_' . $menu_item_db_id;
        if ( empty( $_POST[ $nonce_field ] ) ||
            ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ $nonce_field ] ) ), 'ys_tp_menu_' . $menu_item_db_id ) ) {
            return;
        }

        $all = ! empty( $_POST['ys_tp_menu_all'][ $menu_item_db_id ] );

        if ( $all ) {
            delete_post_meta( $menu_item_db_id, self::META_KEY );
            return;
        }

        $selected = [];
        if ( isset( $_POST['ys_tp_menu_languages'][ $menu_item_db_id ] ) && is_array( $_POST['ys_tp_menu_languages'][ $menu_item_db_id ] ) ) {
            $valid    = array_keys( YSTPAddonsTP::languages() );
            $selected = array_values( array_intersect(
                array_map( 'sanitize_text_field', wp_unslash( $_POST['ys_tp_menu_languages'][ $menu_item_db_id ] ) ),
                $valid
            ) );
        }

        if ( empty( $selected ) ) {
            // 取消勾選「所有語言」卻又沒選任何語言 → 視為所有語言（避免整個項目消失）
            delete_post_meta( $menu_item_db_id, self::META_KEY );
        } else {
            update_post_meta( $menu_item_db_id, self::META_KEY, $selected );
        }
    }

    /**
     * 前台：依當前語言過濾選單項目（含子項目層級串連隱藏）
     *
     * @param array $items 選單項目
     * @return array
     */
    public function filter_items( $items, $menu = null, $args = null ) {
        if ( is_admin() || empty( $items ) || ! is_array( $items ) ) {
            return $items;
        }

        $current = YSTPAddonsTP::current_language();
        $removed = [];

        // 第一輪：依語言設定移除
        foreach ( $items as $key => $item ) {
            $langs = get_post_meta( $item->ID, self::META_KEY, true );
            // 相容：本外掛未設定時，沿用既有（PRO）選單語言欄位
            if ( empty( $langs ) ) {
                $langs = $this->pro_menu_languages( (int) $item->ID );
            }
            if ( ! empty( $langs ) && is_array( $langs ) && ! in_array( $current, $langs, true ) ) {
                $removed[ (int) $item->ID ] = true;
                unset( $items[ $key ] );
            }
        }

        // 後續：串連移除「父項目已被移除」的子項目，直到穩定
        if ( ! empty( $removed ) ) {
            do {
                $changed = false;
                foreach ( $items as $key => $item ) {
                    $parent = (int) $item->menu_item_parent;
                    if ( $parent && isset( $removed[ $parent ] ) ) {
                        $removed[ (int) $item->ID ] = true;
                        unset( $items[ $key ] );
                        $changed = true;
                    }
                }
            } while ( $changed );
        }

        return $items;
    }

    /**
     * 相容：讀取既有（PRO）選單語言欄位 `_trp_menu_languages`
     *
     * PRO 格式為逗號分隔語言碼字串；'trp_nbol_all_languages' 代表所有語言（不限制）。
     *
     * @return string[] 語言碼陣列（空＝不限制）
     */
    private function pro_menu_languages( int $item_id ): array {
        $raw = get_post_meta( $item_id, '_trp_menu_languages', true );
        if ( empty( $raw ) || ! is_string( $raw ) || 'trp_nbol_all_languages' === $raw ) {
            return [];
        }
        return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
    }
}
