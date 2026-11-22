<?php
namespace YangSheep\TPAddons\Modules\TranslatorAccess;

use YangSheep\TPAddons\Modules\YSTPAddonsModuleInterface;

defined( 'ABSPATH' ) || exit;

class YSTPAddonsTranslatorAccess implements YSTPAddonsModuleInterface {
    public const CAPABILITY = 'translate_strings';

    public function boot(): void {
        if ( class_exists( '\TRP_IN_Translator_Accounts' ) ) {
            return;
        }
        add_action( 'admin_init', [ $this, 'ensure_role' ] );
        add_action( 'admin_menu', [ $this, 'add_editor_link' ] );
        add_filter( 'trp_view_as_values', [ $this, 'add_preview_roles' ] );
        add_filter( 'trp_editor_nonces', [ $this, 'add_preview_nonces' ] );
        add_filter( 'trp_temporary_change_current_user_role', [ $this, 'preview_role' ], 10, 2 );
    }

    public static function capability( string $capability ): string {
        if ( $capability !== 'manage_options' || current_user_can( 'manage_options' ) ) {
            return $capability;
        }
        return self::CAPABILITY;
    }

    public function ensure_role(): void {
        if ( current_user_can( 'manage_options' ) && ! get_role( 'translator' ) ) {
            add_role( 'translator', __( '翻譯者', 'ys-translatepress-addons' ), [ 'read' => true, self::CAPABILITY => true ] );
        }
    }

    public function add_editor_link(): void {
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }

        add_menu_page(
            __( '翻譯網站', 'ys-translatepress-addons' ),
            __( '翻譯網站', 'ys-translatepress-addons' ),
            self::CAPABILITY,
            'ys-tp-translate-site',
            [ $this, 'render_editor_link' ],
            'dashicons-translation'
        );
    }

    public function render_editor_link(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( '權限不足。', 'ys-translatepress-addons' ), '', [ 'response' => 403 ] );
        }

        printf(
            '<div class="wrap"><h1>%s</h1><p><a class="button button-primary" href="%s">%s</a></p></div>',
            esc_html__( '翻譯網站', 'ys-translatepress-addons' ),
            esc_url( add_query_arg( 'trp-edit-translation', 'true', home_url( '/' ) ) ),
            esc_html__( '開啟翻譯編輯器', 'ys-translatepress-addons' )
        );
    }

    private function preview_roles(): array {
        if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && is_super_admin() ) ) {
            return [];
        }

        // 僅預覽公開前台常用角色，避免選到管理員或權限不明的自訂角色。
        return array_intersect_key( wp_roles()->roles, array_flip( [ 'subscriber', 'customer', 'contributor', 'author', 'editor', 'shop_manager', 'translator' ] ) );
    }

    public function add_preview_roles( array $values ): array {
        foreach ( $this->preview_roles() as $slug => $role ) {
            $values[ $role['name'] ] = $slug;
        }

        return $values;
    }

    public function add_preview_nonces( array $nonces ): array {
        foreach ( $this->preview_roles() as $slug => $role ) {
            $nonces[ $slug ] = wp_create_nonce( 'trp_view_as' . $slug . get_current_user_id() );
        }

        return $nonces;
    }

    public function preview_role( \WP_User $user, string $slug ): \WP_User {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' || wp_doing_ajax() || is_admin() ) {
            return $user;
        }

        $role = $this->preview_roles()[ $slug ] ?? null;
        if ( ! $role ) {
            return $user;
        }

        // 僅變更本次前台預覽的角色，保留帳號及資料庫權限。
        $preview = clone $user;
        $preview->roles = [ $slug ];
        $preview->caps = [ $slug => true ];
        $preview->allcaps = $role['capabilities'] ?? [];
        return $preview;
    }
}
