<?php

namespace YangSheep\TPAddons\Modules\MacTranslation;

use YangSheep\TPAddons\Support\YSTPAddonsModules;

defined( 'ABSPATH' ) || exit;

final class YSTPAddonsMacSchedule {

    private const SETTINGS = 'ys_tp_mac_schedule';
    private const REPORT = 'ys_tp_mac_schedule_report';
    private const QUEUE = 'ys_tp_mac_visit_';
    private const QUEUE_SLOTS = 8;

    public const INTERVALS = [ 5, 15, 30, 60 ];
    public const PHASES = [ 'idle', 'scanning', 'complete', 'error', 'paused' ];

    public static function settings(): array {
        $saved = (array) get_option( self::SETTINGS, [] );

        return [
            'enabled'  => ( $saved['enabled'] ?? false ) === true,
            'auto_write' => ( $saved['auto_write'] ?? false ) === true,
            'minutes'  => in_array( $saved['minutes'] ?? 0, self::INTERVALS, true ) ? $saved['minutes'] : 15,
            'revision' => (string) ( $saved['revision'] ?? '' ),
        ];
    }

    public static function ready(): bool {
        $machine = (array) get_option( 'trp_machine_translation_settings', [] );
        return defined( 'YS_TP_MAC_RELAY' ) && YS_TP_MAC_RELAY === true
            && ( $machine['machine-translation'] ?? '' ) !== 'yes'
            && ! YSTPAddonsModules::is_enabled( 'ai_translate' );
    }

    public static function save( bool $enabled, int $minutes, bool $auto_write = false ): bool {
        if ( ! in_array( $minutes, self::INTERVALS, true ) || ( $enabled && ! self::ready() ) ) {
            return false;
        }
        $saved = self::settings();
        $auto_write = $enabled && $auto_write;
        if ( $saved['enabled'] === $enabled && $saved['minutes'] === $minutes && $saved['auto_write'] === $auto_write ) {
            return true;
        }
        $next = [ 'enabled' => $enabled, 'auto_write' => $auto_write, 'minutes' => $minutes, 'revision' => wp_generate_uuid4() ];
        return update_option( self::SETTINGS, $next, false ) || self::settings() === $next;
    }

    public static function allows_post( int $id ): bool {
        return $id > 0 && ( ! defined( 'YS_TP_MAC_PUBLIC_POSTS' )
            || in_array( $id, (array) YS_TP_MAC_PUBLIC_POSTS, true ) );
    }

    public static function enqueue_public_visit(): void {
        global $TRP_LANGUAGE;
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' || $_GET || $_COOKIE || is_user_logged_in()
            || ! is_singular() || is_preview() || is_feed() || is_embed() || is_404()
            || ( $_SERVER['HTTP_X_YS_TRANSLATION_SCAN'] ?? '' ) === '1' ) {
            return;
        }
        $post = get_queried_object();
        if ( ! $post instanceof \WP_Post || ! self::allows_post( $post->ID ) || $post->post_type === 'attachment'
            || $post->post_status !== 'publish' || $post->post_password !== '' || ! is_post_publicly_viewable( $post )
            || ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) ) {
            return;
        }
        $settings = (array) get_option( 'trp_settings', [] );
        if ( ! is_string( $TRP_LANGUAGE ) || $TRP_LANGUAGE === ( $settings['default-language'] ?? '' )
            || ! in_array( $TRP_LANGUAGE, (array) ( $settings['publish-languages'] ?? [] ), true ) ) {
            return;
        }
        $schedule = self::settings();
        if ( ! $schedule['enabled'] || ! $schedule['auto_write'] || ! self::ready() ) {
            return;
        }
        $slot = crc32( $post->ID . ':' . $TRP_LANGUAGE ) % self::QUEUE_SLOTS;
        $key = self::QUEUE . $slot;
        // 同站最多八個待檢查項目，訪客請求不儲存網址、內容、Cookie 或身分。
        if ( get_transient( $key ) === false ) {
            set_transient( $key, [ 'post' => $post->ID, 'target' => $TRP_LANGUAGE, 'revision' => $schedule['revision'],
                'event' => wp_generate_uuid4() ], 5 * MINUTE_IN_SECONDS );
        }
    }

    public static function public_visits( string $revision ): array {
        $visits = [];
        for ( $slot = 0; $slot < self::QUEUE_SLOTS; $slot++ ) {
            $visit = get_transient( self::QUEUE . $slot );
            if ( is_array( $visit ) && ( $visit['revision'] ?? '' ) === $revision ) {
                $visits[] = $visit;
            }
        }
        return $visits;
    }

    public static function last_report(): array {
        return (array) get_option( self::REPORT, [] );
    }

    public static function report( array $request ): bool {
        $phase = $request['phase'] ?? '';
        if ( ! in_array( $phase, self::PHASES, true ) ) {
            return false;
        }
        $report = [ 'phase' => $phase, 'seen' => time() ];
        foreach ( [ 'posts', 'previews', 'failed', 'written' ] as $key ) {
            $value = $request[ $key ] ?? 0;
            if ( ! is_int( $value ) || $value < 0 || $value > 10000 ) {
                return false;
            }
            $report[ $key ] = $value;
        }
        $previous = self::last_report();
        if ( $phase === 'idle' ) {
            // 尚未到翻譯時間，只更新連線時間，保留上次執行結果。
            $report = array_replace( $report, $previous, [ 'seen' => $report['seen'] ] );
        } elseif ( in_array( $phase, [ 'complete', 'error' ], true ) ) {
            $report['finished'] = $report['seen'];
        } elseif ( isset( $previous['finished'] ) ) {
            $report['finished'] = $previous['finished'];
        }
        return update_option( self::REPORT, $report, false ) || self::last_report() === $report;
    }
}
