<?php

namespace YangSheep\TPAddons\Admin;

use YangSheep\TPAddons\Modules\MacTranslation\YSTPAddonsMacSchedule;

defined( 'ABSPATH' ) || exit;

final class YSTPAddonsMacAdmin {

    public const PAGE = 'ys-tp-mac-translation';
    public const CAPABILITY = 'ys_tp_manage_mac_translation';
    private const ACTION = 'ys_tp_save_mac_schedule';

    public function boot(): void {
        add_filter( 'map_meta_cap', [ $this, 'map_capability' ], 10, 3 );
        add_action( 'admin_post_' . self::ACTION, [ $this, 'save' ] );
    }

    public function map_capability( array $caps, string $cap, int $user_id ): array {
        if ( $cap !== self::CAPABILITY ) {
            return $caps;
        }
        $user = get_userdata( $user_id );
        if ( ! $user || ( ! in_array( 'administrator', $user->roles, true ) && ! ( is_multisite() && is_super_admin( $user_id ) ) ) ) {
            return [ 'do_not_allow' ];
        }
        return [ 'manage_options' ];
    }

    public function register_menu(): void {
        add_submenu_page(
            YSTPAddonsAdmin::MENU_SLUG,
            __( 'Mac ChatGPT 翻譯', 'ys-translatepress-addons' ),
            __( 'Mac ChatGPT 翻譯', 'ys-translatepress-addons' ),
            self::CAPABILITY,
            self::PAGE,
            [ $this, 'render' ]
        );
    }

    public function render(): void {
        $this->require_admin();
        $settings = YSTPAddonsMacSchedule::settings();
        $ready = YSTPAddonsMacSchedule::ready();
        $connected = defined( 'YS_TP_MAC_RELAY' ) && YS_TP_MAC_RELAY === true;
        $report = YSTPAddonsMacSchedule::last_report();
        $input = $_GET['schedule_result'] ?? '';
        $result = is_string( $input ) ? sanitize_key( wp_unslash( $input ) ) : '';
        $action = self::ACTION;
        include YS_TP_PLUGIN_DIR . 'templates/admin/mac_translation.php';
    }

    public function save(): void {
        $this->require_admin();
        check_admin_referer( self::ACTION );
        $enabled = ( $_POST['enabled'] ?? '' ) === '1';
        $minutes = filter_var( $_POST['minutes'] ?? '', FILTER_VALIDATE_INT );
        $ok = is_int( $minutes ) && YSTPAddonsMacSchedule::save( $enabled, $minutes );
        wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'schedule_result' => $ok ? 'saved' : 'blocked' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private function require_admin(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( '只有網站管理員可以操作 Mac 翻譯。', 'ys-translatepress-addons' ), '', [ 'response' => 403 ] );
        }
    }
}
