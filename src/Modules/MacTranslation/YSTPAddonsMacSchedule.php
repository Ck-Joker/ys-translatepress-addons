<?php

namespace YangSheep\TPAddons\Modules\MacTranslation;

defined( 'ABSPATH' ) || exit;

final class YSTPAddonsMacSchedule {

    private const SETTINGS = 'ys_tp_mac_schedule';
    private const REPORT = 'ys_tp_mac_schedule_report';
    private const QUEUE = 'ys_tp_mac_visit_';
    private const QUEUE_SLOTS = 8;
    private const UPDATE_META = '_ys_tp_mac_update_';

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
            && ( $machine['machine-translation'] ?? '' ) !== 'yes';
    }

    public static function save( bool $enabled, int $minutes, bool $auto_write = false ): bool {
        if ( ! in_array( $minutes, self::INTERVALS, true ) || ( $enabled && ! self::ready() ) ) {
            return false;
        }
        $saved = self::settings();
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
        if ( defined( 'YS_TP_MAC_GATE_LOCALES' ) && YS_TP_MAC_GATE_LOCALES && defined( 'YS_TP_MAC_GATE_SINCE' )
            && ! YSTPAddonsMacRelease::pending( $post->ID, $TRP_LANGUAGE ) ) {
            return;
        }
        self::enqueue( $post->ID, [ $TRP_LANGUAGE ], 'visit' );
    }

    public static function enqueue_published_post( string $status, string $previous, \WP_Post $post ): void {
        if ( $status !== 'publish' || $previous === 'publish' || ! self::allows_post( $post->ID )
            || $post->post_type === 'attachment' || $post->post_password !== '' || ! is_post_publicly_viewable( $post ) ) {
            return;
        }
        if ( function_exists( 'wc_get_page_id' ) && in_array( $post->ID, array_map( 'wc_get_page_id', [ 'cart', 'checkout', 'myaccount' ] ), true ) ) {
            return;
        }
        $settings = (array) get_option( 'trp_settings', [] );
        $targets = array_filter( (array) ( $settings['publish-languages'] ?? [] ),
            static fn( $locale ): bool => is_string( $locale ) && $locale !== ( $settings['default-language'] ?? '' ) );
        self::enqueue( $post->ID, $targets, 'publish' );
    }

    public static function enqueue_updated_post( int $id, \WP_Post $post, bool $update, ?\WP_Post $previous ): void {
        if ( ! $update || ! $previous || $previous->post_status !== 'publish' || $previous->post_password !== ''
            || $post->post_status !== 'publish' || $post->post_password !== '' || ! self::allows_post( $id )
            || ! in_array( $post->post_type, [ 'post', 'page' ], true ) || ! is_post_publicly_viewable( $post ) ) {
            return;
        }
        if ( [ $post->post_title, $post->post_excerpt, $post->post_content ]
            === [ $previous->post_title, $previous->post_excerpt, $previous->post_content ] ) {
            return;
        }
        if ( function_exists( 'wc_get_page_id' ) && in_array( $id, array_map( 'wc_get_page_id', [ 'cart', 'checkout', 'myaccount' ] ), true ) ) {
            return;
        }
        $schedule = self::settings();
        if ( ! $schedule['enabled'] || ! $schedule['auto_write'] || ! self::ready() ) {
            return;
        }
        foreach ( self::update_locales() as $locale ) {
            if ( ! YSTPAddonsMacRelease::pending( $id, $locale ) ) {
                update_post_meta( $id, self::UPDATE_META . $locale, $id . ':' . wp_generate_uuid4() );
            }
        }
    }

    private static function update_locales(): array {
        if ( ! defined( 'YS_TP_MAC_UPDATE_PAGES' ) || YS_TP_MAC_UPDATE_PAGES !== true
            || ! defined( 'YS_TP_MAC_GATE_LOCALES' ) || ! defined( 'YS_TP_MAC_GATE_SINCE' ) ) {
            return [];
        }
        $settings = (array) get_option( 'trp_settings', [] );
        return array_intersect( (array) YS_TP_MAC_GATE_LOCALES, (array) ( $settings['publish-languages'] ?? [] ) );
    }

    public static function page_updates( array $recent_events ): array {
        global $wpdb;
        $keys = array_map( static fn( $locale ) => self::UPDATE_META . $locale, self::update_locales() );
        if ( ! $keys ) {
            return [];
        }
        $allowed = defined( 'YS_TP_MAC_PUBLIC_POSTS' ) ? array_map( 'intval', (array) YS_TP_MAC_PUBLIC_POSTS ) : [];
        $scope = defined( 'YS_TP_MAC_PUBLIC_POSTS' ) ? ' AND p.ID IN (' . implode( ',', $allowed ?: [ 0 ] ) . ')' : '';
        $keys_sql = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        $recent_events = array_filter( array_slice( $recent_events, 0, 8 ), static fn( $event ) => is_string( $event ) && wp_is_uuid( $event, 4 ) );
        $recent_sql = $recent_events
            ? " AND SUBSTRING_INDEX(m.meta_value, ':', -1) NOT IN (" . implode( ',', array_fill( 0, count( $recent_events ), '%s' ) ) . ')' : '';
        $updates = [];
        foreach ( (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
            WHERE m.meta_key IN ($keys_sql) AND p.post_status = 'publish' AND p.post_password = ''
            AND p.post_type IN ('post', 'page') AND m.meta_value LIKE CONCAT(p.ID, CHAR(58,37))
            $scope $recent_sql ORDER BY m.meta_id ASC LIMIT 8", array_merge( $keys, $recent_events )
        ) ) as $row ) {
            $id = (int) $row->ID;
            $locale = substr( $row->meta_key, strlen( self::UPDATE_META ) );
            $event = substr( $row->meta_value, strlen( (string) $id ) + 1 );
            if ( self::is_page_update( $id, $locale, $event ) ) {
                $updates[] = [ 'post' => $id, 'target' => $locale, 'event' => $event, 'trigger' => 'update' ];
            }
        }
        return $updates;
    }

    public static function is_page_update( int $id, string $locale, $event ): bool {
        return is_string( $event ) && wp_is_uuid( $event, 4 )
            && in_array( $locale, self::update_locales(), true )
            && get_post_meta( $id, self::UPDATE_META . $locale, true ) === $id . ':' . $event
            && ! YSTPAddonsMacRelease::pending( $id, $locale );
    }

    public static function complete_page_update( int $id, string $locale, string $event ): bool {
        // 只清除本次完成的版本，保留翻譯過程中再次儲存的工作。
        return delete_post_meta( $id, self::UPDATE_META . $locale, $id . ':' . $event );
    }

    private static function enqueue( int $id, array $targets, string $trigger ): void {
        $schedule = self::settings();
        if ( ! $schedule['enabled'] || ! $schedule['auto_write'] || ! self::ready() ) {
            return;
        }
        foreach ( $targets as $target ) {
            $key = self::QUEUE . ( crc32( $id . ':' . $target ) % self::QUEUE_SLOTS );
            // 同站最多八個待檢查項目，只儲存公開內容 ID 與語系。
            $queued = get_transient( $key );
            if ( $queued === false || ( $trigger === 'publish' && ( $queued['trigger'] ?? '' ) !== 'publish' ) ) {
                set_transient( $key, [ 'post' => $id, 'target' => $target, 'revision' => $schedule['revision'],
                    'event' => wp_generate_uuid4(), 'trigger' => $trigger ], 5 * MINUTE_IN_SECONDS );
            }
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
