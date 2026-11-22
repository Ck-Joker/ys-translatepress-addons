<?php
/**
 * 沿用既有翻譯設定
 *
 * 偵測既有的翻譯網址 slug 與選單語言資料（其他 TranslatePress 多語外掛留下的格式），
 * 轉為本外掛欄位並啟用對應模組，讓停用衝突外掛後功能無縫延續。
 *
 * @package YangSheep\TPAddons\Support
 * @since   0.8.0
 */

namespace YangSheep\TPAddons\Support;

use YangSheep\TPAddons\Database\YSTPAddonsSettingsRepo;
use YangSheep\TPAddons\Modules\Seo\YSTPAddonsSlugTranslator;
use YangSheep\TPAddons\Modules\MenuLanguage\YSTPAddonsMenuLanguage;

defined( 'ABSPATH' ) || exit;

class YSTPAddonsMigration {

    /**
     * 執行遷移
     *
     * @return array{slug:int, menu:int, modules:string[]}
     */
    public static function run(): array {
        $report = [ 'slug' => 0, 'menu' => 0, 'modules' => [] ];

        // ── 翻譯 slug ──
        if ( self::has_pro_slug_data() ) {
            YSTPAddonsModules::set_status( 'seo', true );
            YSTPAddonsSettingsRepo::set( 'seo_slug_enabled', 1 );
            $report['modules'][] = 'seo';
            $report['slug']      = self::migrate_slugs();
            YSTPAddonsSlugTranslator::refresh_base_pairs();
            YSTPAddonsSlugTranslator::flush_map();
        }

        // ── 選單語言 ──
        $menu = self::migrate_menus();
        if ( $menu > 0 ) {
            YSTPAddonsModules::set_status( 'menu_language', true );
            $report['modules'][] = 'menu_language';
            $report['menu']      = $menu;
        }

        update_option( 'ys_tp_migrated_at', time() );
        return $report;
    }

    /* ───────────── 偵測 ───────────── */

    public static function has_pro_slug_data(): bool {
        global $wpdb;
        $tt = $wpdb->prefix . 'trp_slug_translations';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tt ) ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$tt` WHERE translated <> ''" ) > 0 ) {
                return true;
            }
        }
        $meta = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_trp\\_translated\\_slug\\_%' AND meta_value <> ''"
        );
        return $meta > 0;
    }

    /** url-slug（zh）→ locale（zh_TW） */
    private static function slug_to_locale_map(): array {
        $map   = [];
        $slugs = (array) ( get_option( 'trp_settings' )['url-slugs'] ?? [] );
        foreach ( $slugs as $locale => $slug ) {
            if ( is_string( $slug ) && '' !== $slug ) {
                $map[ $slug ] = $locale;
            }
        }
        return $map;
    }

    /* ───────────── 遷移 slug ───────────── */

    private static function migrate_slugs(): int {
        global $wpdb;
        $count    = 0;
        $valid    = array_keys( YSTPAddonsTP::languages() );
        $slug2loc = self::slug_to_locale_map();

        // 1. 資料表
        $ot = $wpdb->prefix . 'trp_slug_originals';
        $tt = $wpdb->prefix . 'trp_slug_translations';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tt ) ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                "SELECT so.original AS original, so.type AS type, st.translated AS translated, st.language AS lang
                 FROM `$tt` st INNER JOIN `$ot` so ON so.id = st.original_id
                 WHERE st.translated <> ''"
            );
            foreach ( (array) $rows as $r ) {
                $locale = self::normalize_locale( (string) $r->lang, $slug2loc, $valid );
                if ( '' === $locale ) {
                    continue;
                }
                if ( self::migrate_typed_slug( (string) $r->type, (string) $r->original, $locale, (string) $r->translated ) ) {
                    $count++;
                }
            }
        }

        // 2. meta（_trp_translated_slug_{X}）
        $metas = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key LIKE '\\_trp\\_translated\\_slug\\_%' AND meta_value <> ''"
        );
        foreach ( (array) $metas as $m ) {
            $x      = substr( (string) $m->meta_key, strlen( '_trp_translated_slug_' ) );
            $locale = self::normalize_locale( $x, $slug2loc, $valid );
            if ( '' === $locale ) {
                continue;
            }
            if ( self::set_our_slug( (int) $m->post_id, $locale, (string) $m->meta_value ) ) {
                $count++;
            }
        }

        return $count;
    }

    private static function migrate_typed_slug( string $type, string $original, string $locale, string $translated ): bool {
        global $wpdb;
        if ( $type === 'post' ) {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status IN ('publish','private') LIMIT 2",
                $original
            ) );
            return count( $ids ) === 1 && self::set_our_slug( (int) $ids[0], $locale, $translated );
        }
        if ( $type === 'term' ) {
            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE slug = %s LIMIT 2", $original ) );
            return count( $ids ) === 1 && self::set_our_slug( (int) $ids[0], $locale, $translated, 'term' );
        }
        if ( $type !== 'taxonomy' && $type !== 'post-type-base' ) {
            return false; // 未分類資料保留在原表，由既有相容讀取處理。
        }

        $is_taxonomy = $type === 'taxonomy';
        $objects = $is_taxonomy ? YSTPAddonsSlugTranslator::base_taxonomies() : YSTPAddonsSlugTranslator::base_post_types();
        $matches = [];
        foreach ( $objects as $object ) {
            $base = $is_taxonomy ? YSTPAddonsSlugTranslator::taxonomy_base( $object ) : YSTPAddonsSlugTranslator::post_type_base( $object );
            if ( rawurldecode( $base ) === rawurldecode( $original ) ) {
                $matches[] = $object->name;
            }
        }
        if ( count( $matches ) !== 1 ) {
            return false;
        }
        $key = YSTPAddonsSlugTranslator::base_key( $is_taxonomy ? 'tax' : 'pt', $matches[0], $locale );
        $clean = sanitize_title( $translated );
        return $clean !== '' && YSTPAddonsSettingsRepo::get( $key, '' ) === '' && YSTPAddonsSettingsRepo::set( $key, $clean );
    }

    /** 將 PRO 的 language（可能是 url-slug 或 locale）正規化為 locale */
    private static function normalize_locale( string $lang, array $slug2loc, array $valid ): string {
        if ( in_array( $lang, $valid, true ) ) {
            return $lang; // 已是 locale
        }
        if ( isset( $slug2loc[ $lang ] ) ) {
            return $slug2loc[ $lang ]; // url-slug → locale
        }
        return '';
    }

    /** 寫入本外掛 slug meta（僅在尚未設定時，避免覆蓋使用者既有設定） */
    private static function set_our_slug( int $object_id, string $locale, string $translated, string $kind = 'post' ): bool {
        $key = YSTPAddonsSlugTranslator::META_PREFIX . $locale;
        if ( '' !== (string) get_metadata( $kind, $object_id, $key, true ) ) {
            return false; // 已有本外掛設定，不覆蓋
        }
        $clean = sanitize_title( $translated );
        if ( '' === $clean ) {
            return false;
        }
        return (bool) add_metadata( $kind, $object_id, $key, $clean, true );
    }

    /* ───────────── 遷移選單語言 ───────────── */

    private static function migrate_menus(): int {
        global $wpdb;
        $count = 0;
        $valid = array_keys( YSTPAddonsTP::languages() );

        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_trp_menu_languages' AND meta_value <> ''"
        );
        foreach ( (array) $rows as $r ) {
            $raw = (string) $r->meta_value;
            if ( 'trp_nbol_all_languages' === $raw ) {
                continue; // 全語言＝不限制，無需遷移
            }
            // 本外掛已設定則略過
            $existing = get_post_meta( (int) $r->post_id, YSTPAddonsMenuLanguage::META_KEY, true );
            if ( ! empty( $existing ) ) {
                continue;
            }
            $codes = array_values( array_intersect(
                array_filter( array_map( 'trim', explode( ',', $raw ) ) ),
                $valid
            ) );
            if ( $codes ) {
                update_post_meta( (int) $r->post_id, YSTPAddonsMenuLanguage::META_KEY, $codes );
                $count++;
            }
        }
        return $count;
    }
}
