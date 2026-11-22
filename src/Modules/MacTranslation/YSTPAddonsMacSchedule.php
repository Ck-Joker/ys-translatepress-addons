<?php

namespace YangSheep\TPAddons\Modules\MacTranslation;

use YangSheep\TPAddons\Support\YSTPAddonsModules;

defined( 'ABSPATH' ) || exit;

final class YSTPAddonsMacSchedule {

    private const SETTINGS = 'ys_tp_mac_schedule';
    private const REPORT = 'ys_tp_mac_schedule_report';

    public const INTERVALS = [ 5, 15, 30, 60 ];
    public const PHASES = [ 'idle', 'scanning', 'complete', 'error', 'paused' ];

    public static function settings(): array {
        $saved = (array) get_option( self::SETTINGS, [] );

        return [
            'enabled'  => ( $saved['enabled'] ?? false ) === true,
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

    public static function save( bool $enabled, int $minutes ): bool {
        if ( ! in_array( $minutes, self::INTERVALS, true ) || ( $enabled && ! self::ready() ) ) {
            return false;
        }
        $saved = self::settings();
        if ( $saved['enabled'] === $enabled && $saved['minutes'] === $minutes ) {
            return true;
        }
        $next = [ 'enabled' => $enabled, 'minutes' => $minutes, 'revision' => wp_generate_uuid4() ];
        return update_option( self::SETTINGS, $next, false ) || self::settings() === $next;
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
