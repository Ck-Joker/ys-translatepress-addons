<?php

namespace YangSheep\TPAddons\Modules\MacTranslation;

defined( 'ABSPATH' ) || exit;

final class YSTPAddonsMacRelease {

    private const META = '_ys_tp_mac_released_';
    private const QUERY = 'ys_tp_mac_probe';

    public static function boot(): void {
        add_action( 'template_redirect', [ self::class, 'restrict_request' ], 1 );
        add_filter( 'trp_ls_shortcode_other_languages', [ self::class, 'available_languages' ] );
        add_filter( 'trp_ls_floating_other_languages', [ self::class, 'available_languages' ] );
        add_filter( 'wp_nav_menu_objects', [ self::class, 'available_menu_items' ], 100 );
        add_filter( 'trp-exclude-hreflang', [ self::class, 'exclude_hreflang' ] );
        add_filter( 'trp_allow_language_redirect', [ self::class, 'allow_language_redirect' ], 10, 2 );
    }

    private static function locales(): array {
        return defined( 'YS_TP_MAC_GATE_LOCALES' ) ? (array) YS_TP_MAC_GATE_LOCALES : [];
    }

    public static function pending( int $id, string $locale ): bool {
        if ( ! in_array( $locale, self::locales(), true ) || ! defined( 'YS_TP_MAC_GATE_SINCE' )
            || ! YSTPAddonsMacSchedule::allows_post( $id ) ) {
            return false;
        }
        $post = get_post( $id );
        $trp = (array) get_option( 'trp_settings', [] );
        if ( ! $post || ! in_array( $post->post_type, [ 'page', 'post' ], true ) || $post->post_status !== 'publish'
            || $post->post_password !== '' || ! is_post_publicly_viewable( $post )
            || ! str_starts_with( (string) ( $trp['default-language'] ?? '' ), 'zh_' ) || ! str_starts_with( $locale, 'en_' )
            || ! in_array( $locale, (array) ( $trp['publish-languages'] ?? [] ), true ) ) {
            return false;
        }
        $new_id = defined( 'YS_TP_MAC_GATE_MIN_ID' ) && $id > (int) YS_TP_MAC_GATE_MIN_ID;
        if ( ! $new_id && strtotime( $post->post_date_gmt . ' UTC' ) <= (int) YS_TP_MAC_GATE_SINCE ) {
            return false;
        }
        if ( function_exists( 'wc_get_page_id' ) && in_array( $id, array_map( 'wc_get_page_id', [ 'cart', 'checkout', 'myaccount' ] ), true ) ) {
            return false;
        }
        foreach ( get_post_meta( $id, self::META . $locale, false ) as $value ) {
            if ( is_string( $value ) && str_starts_with( $value, $id . ':' ) ) {
                return false;
            }
        }
        return true;
    }

    public static function available_languages( array $other ): array {
        if ( is_singular() ) {
            foreach ( self::locales() as $locale ) {
                if ( is_string( $locale ) && self::pending( get_queried_object_id(), $locale ) ) {
                    unset( $other[ $locale ] );
                }
            }
        }
        return $other;
    }

    public static function available_menu_items( array $items ): array {
        if ( ! is_singular() ) {
            return $items;
        }
        $id = get_queried_object_id();
        $blocked = [];
        foreach ( self::locales() as $locale ) {
            if ( is_string( $locale ) && self::pending( $id, $locale ) ) {
                $converter = \TRP_Translate_Press::get_trp_instance()->get_component( 'url_converter' );
                $blocked[] = wp_parse_url( $converter->get_url_for_language( $locale, get_permalink( $id ), '' ), PHP_URL_PATH );
            }
        }
        return array_filter( $items, static fn( $item ): bool => $item->object !== 'language_switcher'
            || ! in_array( wp_parse_url( $item->url, PHP_URL_PATH ), $blocked, true ) );
    }

    public static function exclude_hreflang( bool $exclude ): bool {
        if ( $exclude || ! is_singular() ) {
            return $exclude;
        }
        foreach ( self::locales() as $locale ) {
            if ( is_string( $locale ) && self::pending( get_queried_object_id(), $locale ) ) {
                return true;
            }
        }
        return false;
    }

    public static function allow_language_redirect( bool $allow, string $locale ): bool {
        return $allow && ( ! is_singular() || ! self::pending( get_queried_object_id(), $locale ) );
    }

    public static function snapshot( \WP_Post $post ): string {
        return hash( 'sha256', wp_json_encode( [ $post->ID, $post->post_name, $post->post_title, $post->post_excerpt,
            $post->post_content, $post->post_modified_gmt, $post->post_status, $post->post_password ] ) );
    }

    private static function signature( \WP_Post $post, string $locale, int $expires, array $schedule ): string {
        return hash_hmac( 'sha256', implode( ':', [ get_current_blog_id(), $locale, $expires, $schedule['revision'], self::snapshot( $post ) ] ),
            wp_salt( 'auth' ) );
    }

    public static function accepts_token( \WP_Post $post, string $locale, $token ): bool {
        $schedule = YSTPAddonsMacSchedule::settings();
        return $schedule['enabled'] && $schedule['auto_write'] && YSTPAddonsMacSchedule::ready()
            && is_string( $token ) && preg_match( '/^(\d{10})\.([a-f0-9]{64})$/D', $token, $parts )
            && (int) $parts[1] >= time() && (int) $parts[1] <= time() + 300
            && hash_equals( self::signature( $post, $locale, (int) $parts[1], $schedule ), $parts[2] );
    }

    public static function probe( \WP_Post $post, string $locale, string $url ): ?array {
        if ( ! self::pending( $post->ID, $locale ) ) {
            return null;
        }
        $expires = time() + 300;
        return [
            'url' => add_query_arg( self::QUERY, '1', $url ),
            'token' => $expires . '.' . self::signature( $post, $locale, $expires, YSTPAddonsMacSchedule::settings() ),
            'snapshot' => self::snapshot( $post ),
        ];
    }

    public static function restrict_request(): void {
        global $TRP_LANGUAGE;
        $post = get_queried_object();
        if ( ! is_singular() || ! $post instanceof \WP_Post || ! is_string( $TRP_LANGUAGE )
            || ! self::pending( $post->ID, $TRP_LANGUAGE ) ) {
            return;
        }
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();
        header( 'X-Robots-Tag: noindex', true );
        header( 'Referrer-Policy: no-referrer', true );

        $token = $_SERVER['HTTP_X_YS_TRANSLATION_PROBE'] ?? '';
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'GET' && ( $_GET[ self::QUERY ] ?? '' ) === '1'
            && self::accepts_token( $post, $TRP_LANGUAGE, $token ) ) {
            remove_action( 'template_redirect', 'redirect_canonical' );
            header( 'X-YS-Translation-Snapshot: ' . self::snapshot( $post ) );
            return;
        }

        header( 'Retry-After: 60', true );
        status_header( 503 );
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'HEAD' ) {
            $source = \TRP_Translate_Press::get_trp_instance()->get_component( 'url_converter' );
            $settings = (array) get_option( 'trp_settings', [] );
            $url = $source->get_url_for_language( $settings['default-language'], get_permalink( $post ), '' );
            header( 'Content-Type: text/html; charset=UTF-8', true );
            echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Translation in progress</title>'
                . '<main><h1>Translation in progress</h1><p><a href="' . esc_url( $url ) . '">Read the original page</a></p></main></html>';
        }
        exit;
    }

    public static function release( \WP_Post $post, string $locale, string $snapshot ): bool {
        global $wpdb;
        if ( ! self::pending( $post->ID, $locale ) || ! hash_equals( self::snapshot( $post ), $snapshot ) ) {
            return false;
        }
        $key = self::META . $locale;
        // 開放前再原子確認資料庫中的原文版本。已開放的既有頁面不回到等待狀態。
        $ok = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->postmeta} (post_id,meta_key,meta_value)
             SELECT p.ID,%s,%s FROM {$wpdb->posts} p
             WHERE p.ID=%d AND p.post_status='publish' AND p.post_password=''
             AND BINARY p.post_name=BINARY %s AND BINARY p.post_title=BINARY %s
             AND BINARY p.post_excerpt=BINARY %s AND BINARY p.post_content=BINARY %s AND p.post_modified_gmt=%s
             AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key=%s AND m.meta_value LIKE %s)",
            $key, $post->ID . ':' . $snapshot, $post->ID, $post->post_name, $post->post_title, $post->post_excerpt,
            $post->post_content, $post->post_modified_gmt, $key, $post->ID . ':%'
        ) );
        if ( $ok !== 1 ) {
            return false;
        }
        wp_cache_delete( $post->ID, 'post_meta' );
        if ( function_exists( 'sc_icg_cache_purge_url' ) ) {
            $url = get_permalink( $post );
            $converter = \TRP_Translate_Press::get_trp_instance()->get_component( 'url_converter' );
            sc_icg_cache_purge_url( $url );
            sc_icg_cache_purge_url( $converter->get_url_for_language( $locale, $url, '' ) );
        }
        return true;
    }
}
