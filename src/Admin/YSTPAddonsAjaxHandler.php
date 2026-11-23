<?php
/**
 * 後台 AJAX 處理器
 *
 * - ys_tp_save_settings  ：通用設定儲存（存自訂資料表）
 * - ys_tp_toggle_module  ：啟用／停用功能模組
 *
 * @package YangSheep\TPAddons\Admin
 * @since   0.1.0
 */

namespace YangSheep\TPAddons\Admin;

use YangSheep\TPAddons\Database\YSTPAddonsSettingsRepo;
use YangSheep\TPAddons\Support\YSTPAddonsModules;
use YangSheep\TPAddons\Modules\MacTranslation\YSTPAddonsMacSchedule;
use YangSheep\TPAddons\Support\YSTPAddonsMigration;
use YangSheep\TPAddons\Support\YSTPAddonsCompat;

defined( 'ABSPATH' ) || exit;

class YSTPAddonsAjaxHandler {

    public function __construct() {
        add_action( 'wp_ajax_ys_tp_save_settings', [ $this, 'save_settings' ] );
        add_action( 'wp_ajax_ys_tp_toggle_module', [ $this, 'toggle_module' ] );
        add_action( 'wp_ajax_ys_tp_migrate', [ $this, 'migrate' ] );
    }

    /**
     * 共用安全檢查（nonce + 權限）
     */
    private function guard(): void {
        if ( ! check_ajax_referer( 'ys_tp_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => __( '安全驗證失敗', 'ys-translatepress-addons' ) ], 403 );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( '權限不足', 'ys-translatepress-addons' ) ], 403 );
        }
    }

    /**
     * 遞迴清理輸入
     *
     * @param mixed $data
     * @return mixed
     */
    private function clean( $data ) {
        if ( is_array( $data ) ) {
            return array_map( [ $this, 'clean' ], $data );
        }
        return sanitize_text_field( $data );
    }

    /**
     * 儲存設定（AJAX）
     */
    public function save_settings(): void {
        $this->guard();

        // 取原始值（已 unslash，尚未清理）以便依欄位型別分別清理
        $raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] )
            ? wp_unslash( $_POST['settings'] )
            : [];

        if ( empty( $raw ) ) {
            wp_send_json_error( [ 'message' => __( '沒有收到設定資料', 'ys-translatepress-addons' ) ] );
        }

        $saved_keys = [];
        foreach ( $raw as $key => $value ) {
            $safe_key = sanitize_key( $key );
            if ( '' === $safe_key || str_starts_with( $safe_key, 'ai_' ) ) {
                continue;
            }

            if ( is_array( $value ) ) {
                $clean = $this->clean( $value );
            } else {
                $clean = sanitize_text_field( (string) $value );
            }

            YSTPAddonsSettingsRepo::set( $safe_key, $clean );
            $saved_keys[] = $safe_key;
        }

        /**
         * 設定已儲存（供模組據此失效快取，如內容語言規則的隱藏清單）
         *
         * @param string[] $saved_keys 本次儲存的鍵名
         */
        do_action( 'ys_tp_settings_saved', $saved_keys );

        wp_send_json_success( [ 'message' => __( '已儲存', 'ys-translatepress-addons' ) ] );
    }

    /**
     * 啟用／停用模組（AJAX）
     */
    public function toggle_module(): void {
        $this->guard();

        $module  = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
        $enabled = isset( $_POST['enabled'] ) ? (bool) absint( $_POST['enabled'] ) : false;

        if ( $module === YSTPAddonsMacAdmin::MODULE ) {
            if ( ! current_user_can( YSTPAddonsMacAdmin::CAPABILITY ) ) {
                wp_send_json_error( [ 'message' => __( '只有網站管理員可以操作 Mac 翻譯。', 'ys-translatepress-addons' ) ], 403 );
            }
            $settings = YSTPAddonsMacSchedule::settings();
            if ( ! YSTPAddonsMacSchedule::save( $enabled, $settings['minutes'], $settings['auto_write'] ) ) {
                wp_send_json_error( [ 'message' => __( '無法更新 Mac 翻譯設定。請到設定頁檢查連線與翻譯引擎。', 'ys-translatepress-addons' ) ] );
            }
            wp_send_json_success( [ 'message' => $enabled
                ? __( 'Mac ChatGPT 翻譯已啟用', 'ys-translatepress-addons' )
                : __( 'Mac ChatGPT 翻譯已暫停', 'ys-translatepress-addons' ) ] );
        }
        $meta = YSTPAddonsModules::get( $module );
        if ( ! $meta ) {
            wp_send_json_error( [ 'message' => __( '未知的模組', 'ys-translatepress-addons' ) ] );
        }

        YSTPAddonsModules::set_status( $module, $enabled );

        wp_send_json_success( [
            'message' => $enabled
                ? __( '模組已啟用', 'ys-translatepress-addons' )
                : __( '模組已停用', 'ys-translatepress-addons' ),
            'module'  => $module,
            'enabled' => $enabled,
            'page'    => (string) ( $meta['page'] ?? '' ),
        ] );
    }

    /**
     * 沿用既有翻譯設定（AJAX）
     *
     * 偵測既有的翻譯網址 slug 與選單語言資料，轉為本外掛欄位並啟用對應模組。
     */
    public function migrate(): void {
        $this->guard();

        $report  = YSTPAddonsMigration::run();
        $summary = YSTPAddonsCompat::format_report( $report );

        wp_send_json_success( [
            'message' => '' !== $summary
                ? sprintf(
                    /* translators: %s: 沿用結果摘要 */
                    __( '已沿用既有設定：%s', 'ys-translatepress-addons' ),
                    $summary
                )
                : __( '未偵測到可沿用的既有翻譯網址 slug 或選單語言設定。', 'ys-translatepress-addons' ),
            'report'  => $report,
        ] );
    }
}
