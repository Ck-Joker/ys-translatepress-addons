<?php
/**
 * 翻譯網址 slug（URL rewrite）
 *
 * 涵蓋四種網址組成部分（比照 TranslatePress SEO 的 slug 節點類型）：
 * - 文章／頁面／自訂內容單頁 slug：post meta `_ys_tp_slug_{locale}`
 * - 分類項目 slug：term meta `_ys_tp_slug_{locale}`
 * - 分類基底（如 category／news-cat）：設定 `slug_base_tax_{taxonomy}_{lang}`
 * - 內容類型基底（如 products／news）：設定 `slug_base_pt_{post_type}_{lang}`
 *
 * 機制：
 * - Outbound：掛 trp_get_url_for_language，逐路徑段落把原始 slug 換成翻譯 slug
 * - Inbound：於 parse_request 之前改寫 $_SERVER['REQUEST_URI']，把翻譯 slug 換回原始
 *            slug 讓 WordPress 原生解析（故無須註冊額外 rewrite rules）
 * - 對照表：每語言一張「原始 ⇄ 翻譯」對照表並以 transient 快取，避免逐段查詢資料庫；
 *            內容／分類存檔與設定變更時失效
 *
 * 作為 SEO 模組的可開關子功能（seo_slug_enabled）。
 *
 * @package YangSheep\TPAddons\Modules\Seo
 * @since   0.5.0
 */

namespace YangSheep\TPAddons\Modules\Seo;

use YangSheep\TPAddons\Support\YSTPAddonsTP;
use YangSheep\TPAddons\Database\YSTPAddonsSettingsRepo;

defined( 'ABSPATH' ) || exit;

class YSTPAddonsSlugTranslator {

    /** meta key 前綴（後接 locale，如 _ys_tp_slug_zh_TW）；post meta 與 term meta 共用 */
    public const META_PREFIX = '_ys_tp_slug_';

    /** 對照表快取前綴 */
    public const AUTO_SLUG_HOOK = 'ys_tp_translate_post_slug';

    private const CACHE_PREFIX = 'ys_tp_slugmap_';

    /**
     * 基底對照的持久化設定鍵（array: locale => [原始基底 => 翻譯基底]）
     *
     * 🔴 inbound 於 plugins_loaded priority 2 執行，**此時自訂分類／內容類型尚未註冊**
     * （它們在 init 才註冊），當下呼叫 get_taxonomies()／get_post_types() 只拿得到內建項目。
     * 故基底對照改為「後台存檔時（init 之後）算好並持久化」，inbound 只讀此設定即可，
     * 不依賴註冊時機，也避免殘缺的對照表被寫進快取。
     */
    private const BASE_PAIRS_KEY = 'slug_base_pairs';

    /** @var array<string, array<string, array<string, string>>> 單一請求內的對照表快取 */
    private static array $map_cache = [];

    public function boot(): void {
        add_action( 'transition_post_status', [ $this, 'schedule_auto_slugs' ], 10, 3 );
        add_action( self::AUTO_SLUG_HOOK, [ $this, 'translate_post_slugs' ] );
        if ( is_admin() ) {
            add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
            add_action( 'save_post', [ $this, 'save_meta' ], 10, 2 );
            // 分類項目 slug（taxonomy 於 admin_init 已註冊完畢）
            add_action( 'admin_init', [ $this, 'register_term_fields' ] );
            add_action( 'edited_term', [ $this, 'save_term_slugs' ], 10, 3 );
            // 基底 slug 設定變更 → 重算持久化基底對照 + 失效對照表
            add_action( 'ys_tp_settings_saved', [ $this, 'on_settings_saved' ] );
            // 升級／首次啟用時補算一次（此時 init 已完成，可安全列舉分類與內容類型）
            add_action( 'admin_init', [ $this, 'maybe_seed_base_pairs' ], 20 );
        } else {
            // Outbound：TranslatePress 產生語言 URL 時，把原始 slug 換成翻譯 slug
            add_filter( 'trp_get_url_for_language', [ $this, 'outbound_translate' ], 10, 3 );
            add_filter( 'get_canonical_url', [ $this, 'canonical_url' ], 20 );
            add_filter( 'wpseo_canonical', [ $this, 'canonical_url' ], 20 );
            add_filter( 'rank_math/frontend/canonical', [ $this, 'canonical_url' ], 20 );
        }
        // Inbound 由主檔在 plugins_loaded priority 2（早於 TranslatePress）呼叫 run_inbound()
    }

    public function schedule_auto_slugs( string $status, string $old_status, \WP_Post $post ): void {
        if ( $status !== 'publish' || $old_status === 'publish' || ! $this->auto_slug_enabled() ) {
            return;
        }
        if ( ! is_post_type_viewable( get_post_type_object( $post->post_type ) ) || $post->post_type === 'attachment' || $post->post_password !== '' ) {
            return;
        }
        $args = [ $post->ID ];
        if ( ! wp_next_scheduled( self::AUTO_SLUG_HOOK, $args ) ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::AUTO_SLUG_HOOK, $args );
        }
    }

    private function auto_slug_enabled(): bool {
        $machine = (array) get_option( 'trp_machine_translation_settings', [] );
        return ! class_exists( '\TRP_IN_Seo_Pack' )
            && (int) YSTPAddonsSettingsRepo::get( 'seo_auto_slug_enabled', 0 ) === 1
            && ( $machine['translation-engine'] ?? '' ) === 'google_translate_v2'
            && ( $machine['machine-translation'] ?? '' ) === 'yes';
    }

    public function translate_post_slugs( int $post_id ): void {
        global $wpdb;

        $post = get_post( $post_id );
        if ( ! $this->auto_slug_enabled() || ! $post || $post->post_status !== 'publish' || $post->post_password !== ''
            || $post->post_type === 'attachment' || ! is_post_type_viewable( get_post_type_object( $post->post_type ) ) ) {
            return;
        }

        $source = YSTPAddonsTP::default_language();
        $translator = \TRP_Translate_Press::get_trp_instance()->get_component( 'machine_translator' );
        if ( ! $translator || ! $translator->is_available() ) {
            return;
        }

        // 同一次發布建立固定網址，之後重跑不覆蓋人工設定或既有網址。
        $slug = $post->post_name;
        $text = trim( str_replace( '-', ' ', rawurldecode( $slug ) ) );
        if ( $text === '' ) {
            return;
        }

        foreach ( YSTPAddonsTP::published_language_codes() as $locale ) {
            $meta_key = self::META_PREFIX . $locale;
            if ( $locale === $source || metadata_exists( 'post', $post_id, $meta_key ) || self::legacy_slug_for_original( $slug, $locale ) !== '' ) {
                continue;
            }

            try {
                $translations = $translator->translate( [ $text ], $locale, $source, 'ys_tp_slug' );
            } finally {
                if ( method_exists( $translator, 'release_locks' ) ) {
                    $translator->release_locks();
                }
            }

            $translated = sanitize_title( wp_strip_all_tags( (string) ( $translations[ $text ] ?? '' ) ) );
            if ( $translated === '' || $translated === $slug ) {
                continue;
            }

            $lock = 'ys_tp_slug_' . md5( $wpdb->prefix . $locale );
            if ( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock ) ) !== '1' ) {
                continue;
            }
            try {
                $current = $wpdb->get_row( $wpdb->prepare(
                    "SELECT post_name, post_status, post_password FROM {$wpdb->posts} WHERE ID = %d",
                    $post_id
                ) );
                if ( ! $current || $current->post_status !== 'publish' || $current->post_password !== '' || $current->post_name !== $slug ) {
                    continue;
                }
                $translated = $this->make_unique( $translated, $locale, $post_id, 'post' );
                if ( $translated !== '' && add_post_meta( $post_id, $meta_key, $translated, true ) ) {
                    self::flush_map();
                }
            } finally {
                $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
            }
        }
    }

    /**
     * Inbound 入口（主檔早期 hook 呼叫）
     *
     * 必須早於 TranslatePress 處理 URL，故不走模組 boot（boot 在 plugins_loaded 11）。
     */
    public static function run_inbound(): void {
        ( new self() )->inbound_rewrite();
    }

    /* ───────────── 語言 slug 對應 ───────────── */

    /** url-slug（zh）=> locale（zh_TW） */
    private function slug_to_locale(): array {
        $map   = [];
        $slugs = YSTPAddonsTP::settings()['url-slugs'] ?? [];
        foreach ( YSTPAddonsTP::published_language_codes() as $code ) {
            $s = $slugs[ $code ] ?? '';
            if ( is_string( $s ) && '' !== $s ) {
                $map[ $s ] = $code;
            }
        }
        return $map;
    }

    /** locale → url-slug（zh_TW → zh） */
    private static function locale_url_slug( string $locale ): string {
        $slug = YSTPAddonsTP::settings()['url-slugs'][ $locale ] ?? '';
        return is_string( $slug ) ? $slug : '';
    }

    /* ───────────── 基底 slug（分類／內容類型） ───────────── */

    /** 設定鍵：基底 slug（🔴 sanitize_key 會小寫，故鍵一律小寫） */
    public static function base_key( string $kind, string $name, string $locale ): string {
        return 'slug_base_' . $kind . '_' . strtolower( $name ) . '_' . strtolower( $locale );
    }

    /** 讀取基底 slug 設定值 */
    public static function base_setting( string $kind, string $name, string $locale ): string {
        $val = YSTPAddonsSettingsRepo::get( self::base_key( $kind, $name, $locale ), '' );
        return is_string( $val ) ? sanitize_title( $val ) : '';
    }

    /** 分類的原始基底（rewrite slug；無則空） */
    public static function taxonomy_base( \WP_Taxonomy $tax ): string {
        if ( is_array( $tax->rewrite ) && ! empty( $tax->rewrite['slug'] ) ) {
            return (string) $tax->rewrite['slug'];
        }
        return '';
    }

    /** 內容類型的原始基底（rewrite slug 優先，其次 has_archive 字串；無則空） */
    public static function post_type_base( \WP_Post_Type $pt ): string {
        if ( is_array( $pt->rewrite ) && ! empty( $pt->rewrite['slug'] ) ) {
            return (string) $pt->rewrite['slug'];
        }
        if ( is_string( $pt->has_archive ) && '' !== $pt->has_archive ) {
            return $pt->has_archive;
        }
        return '';
    }

    /** 可設定基底的分類（有 rewrite 基底且可見於後台） */
    public static function base_taxonomies(): array {
        $out = [];
        foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
            if ( '' !== self::taxonomy_base( $tax ) ) {
                $out[] = $tax;
            }
        }
        return $out;
    }

    /** 可設定基底的內容類型 */
    public static function base_post_types(): array {
        $out = [];
        foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) {
            if ( 'attachment' === $pt->name ) {
                continue;
            }
            if ( '' !== self::post_type_base( $pt ) ) {
                $out[] = $pt;
            }
        }
        return $out;
    }

    /* ───────────── 對照表（每語言，含快取） ───────────── */

    /**
     * 取得某語言的「原始 ⇄ 翻譯」slug 對照表
     *
     * @return array{out: array<string,string>, in: array<string,string>}
     */
    public static function map( string $locale ): array {
        if ( isset( self::$map_cache[ $locale ] ) ) {
            return self::$map_cache[ $locale ];
        }
        $cached = get_transient( self::CACHE_PREFIX . md5( $locale ) );
        if ( is_array( $cached ) && isset( $cached['out'], $cached['in'] ) ) {
            self::$map_cache[ $locale ] = $cached;
            return $cached;
        }

        $map = self::build_map( $locale );
        set_transient( self::CACHE_PREFIX . md5( $locale ), $map, DAY_IN_SECONDS );
        self::$map_cache[ $locale ] = $map;
        return $map;
    }

    /**
     * 建立對照表
     *
     * 優先序（同一原始 slug 只取第一個來源）：基底 → 分類項目 → 文章 → 既有（PRO）欄位。
     * 基底為結構性段落故最優先；其餘依內容層級。
     *
     * @return array{out: array<string,string>, in: array<string,string>}
     */
    private static function build_map( string $locale ): array {
        global $wpdb;
        $out      = [];
        $in       = [];
        $meta_key = self::META_PREFIX . $locale;

        /**
         * 🔴 編碼形式：WordPress 將非 ASCII 的 slug 以 percent-encoded 形式存於
         * post_name／term.slug（如「新聞」存成 %e6%96%b0%e8%81%9e）。網址段落則兩種
         * 形式都可能出現，故：
         * - out 以「解碼形式」與「資料庫原形」雙鍵登錄，兩種段落都比得到
         * - in 的值保留「資料庫原形」，寫回網址時不再編碼（避免 % 被二次編碼成 %25）
         */
        $add = static function ( $original_raw, $translated ) use ( &$out, &$in ) {
            $original_raw = (string) $original_raw;
            $translated   = (string) $translated;
            if ( '' === $original_raw || '' === $translated ) {
                return;
            }
            $decoded = rawurldecode( $original_raw );
            if ( $original_raw === $translated || $decoded === $translated ) {
                return; // 與原始相同＝無需改寫
            }
            if ( ! isset( $out[ $decoded ] ) ) {
                $out[ $decoded ] = $translated;
            }
            if ( $original_raw !== $decoded && ! isset( $out[ $original_raw ] ) ) {
                $out[ $original_raw ] = $translated;
            }
            if ( ! isset( $in[ $translated ] ) ) {
                $in[ $translated ] = $original_raw;
            }
            $translated_decoded = rawurldecode( $translated );
            if ( ! isset( $in[ $translated_decoded ] ) ) {
                $in[ $translated_decoded ] = $original_raw;
            }
        };

        // 1+2. 基底（分類／內容類型）：讀持久化對照，不依賴 CPT 註冊時機（見 BASE_PAIRS_KEY）
        foreach ( self::base_pairs( $locale ) as $original => $translated ) {
            $add( $original, $translated );
        }

        // 3. 分類項目 slug
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.slug AS original, tm.meta_value AS translated
             FROM {$wpdb->termmeta} tm
             INNER JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
             WHERE tm.meta_key = %s AND tm.meta_value <> ''",
            $meta_key
        ) );
        foreach ( (array) $rows as $r ) {
            $add( $r->original, $r->translated );
        }

        // 4. 文章／頁面／自訂內容 slug
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.post_name AS original, pm.meta_value AS translated
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND pm.meta_value <> ''
             AND p.post_status IN ('publish','private')",
            $meta_key
        ) );
        foreach ( (array) $rows as $r ) {
            $add( $r->original, $r->translated );
        }

        // 5. 相容：既有（其他 TranslatePress 多語外掛）的翻譯 slug — 僅補未涵蓋者
        foreach ( self::legacy_pairs( $locale ) as $original => $translated ) {
            $add( $original, $translated );
        }

        return [ 'out' => $out, 'in' => $in ];
    }

    /**
     * 既有（PRO）翻譯 slug 來源：資料表 + post meta
     *
     * @return array<string,string> original => translated
     */
    private static function legacy_pairs( string $locale ): array {
        global $wpdb;
        $pairs    = [];
        $url_slug = self::locale_url_slug( $locale );

        $ot = $wpdb->prefix . 'trp_slug_originals';
        $tt = $wpdb->prefix . 'trp_slug_translations';
        if ( self::table_exists( $ot ) && self::table_exists( $tt ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT so.original AS original, st.translated AS translated
                 FROM `$tt` st INNER JOIN `$ot` so ON so.id = st.original_id
                 WHERE st.language IN (%s, %s) AND st.translated <> ''",
                $url_slug,
                $locale
            ) );
            foreach ( (array) $rows as $r ) {
                if ( ! isset( $pairs[ $r->original ] ) ) {
                    $pairs[ (string) $r->original ] = (string) $r->translated;
                }
            }
        }

        $keys = self::legacy_meta_keys( $locale, $url_slug );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.post_name AS original, pm.meta_value AS translated
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key IN (%s, %s, %s, %s) AND pm.meta_value <> ''
             AND p.post_status IN ('publish','private')",
            $keys[0], $keys[1], $keys[2], $keys[3]
        ) );
        foreach ( (array) $rows as $r ) {
            if ( ! isset( $pairs[ $r->original ] ) ) {
                $pairs[ (string) $r->original ] = (string) $r->translated;
            }
        }

        return $pairs;
    }

    /** 既有翻譯 slug 的 meta key（人工 + 自動，locale 與 url-slug 兩格式） */
    private static function legacy_meta_keys( string $locale, string $url_slug ): array {
        return [
            '_trp_translated_slug_' . $locale,
            '_trp_translated_slug_' . $url_slug,
            '_trp_automatically_translated_slug_' . $locale,
            '_trp_automatically_translated_slug_' . $url_slug,
        ];
    }

    private static function table_exists( string $table ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    /**
     * 取得某語言的基底對照（持久化；inbound 早期可安全讀取）
     *
     * @return array<string,string> 原始基底 => 翻譯基底
     */
    public static function base_pairs( string $locale ): array {
        $all = YSTPAddonsSettingsRepo::get( self::BASE_PAIRS_KEY, [] );
        if ( ! is_array( $all ) ) {
            return [];
        }
        $pairs = $all[ $locale ] ?? [];
        return is_array( $pairs ) ? $pairs : [];
    }

    /**
     * 重算並持久化基底對照（必須於 init 之後呼叫——需要分類／內容類型已註冊）
     */
    public static function refresh_base_pairs(): void {
        $all = [];
        foreach ( YSTPAddonsTP::published_language_codes() as $locale ) {
            $pairs = [];
            foreach ( self::base_taxonomies() as $tax ) {
                $t = self::base_setting( 'tax', $tax->name, $locale );
                $b = self::taxonomy_base( $tax );
                if ( '' !== $t && '' !== $b && $t !== $b ) {
                    $pairs[ $b ] = $t;
                }
            }
            foreach ( self::base_post_types() as $pt ) {
                $t = self::base_setting( 'pt', $pt->name, $locale );
                $b = self::post_type_base( $pt );
                if ( '' !== $t && '' !== $b && $t !== $b ) {
                    $pairs[ $b ] = $t;
                }
            }
            if ( ! empty( $pairs ) ) {
                $all[ $locale ] = $pairs;
            }
        }
        YSTPAddonsSettingsRepo::set( self::BASE_PAIRS_KEY, $all );
    }

    /**
     * 若已有基底設定卻尚無持久化對照（如自舊版升級），補算一次
     */
    public function maybe_seed_base_pairs(): void {
        $stored = YSTPAddonsSettingsRepo::get( self::BASE_PAIRS_KEY, null );
        if ( null !== $stored ) {
            return;
        }
        self::refresh_base_pairs();
        self::flush_map();
    }

    /** 失效所有語言的對照表快取 */
    public static function flush_map(): void {
        foreach ( YSTPAddonsTP::published_language_codes() as $code ) {
            delete_transient( self::CACHE_PREFIX . md5( $code ) );
        }
        self::$map_cache = [];
    }

    /**
     * 設定存檔後：基底 slug 變更時失效對照表
     *
     * @param string[] $keys
     */
    public function on_settings_saved( $keys ): void {
        foreach ( (array) $keys as $k ) {
            if ( is_string( $k ) && 0 === strpos( $k, 'slug_base_' ) ) {
                self::refresh_base_pairs(); // 於 AJAX（init 之後）重算持久化基底對照
                self::flush_map();
                return;
            }
        }
    }

    /* ───────────── Outbound ───────────── */

    /**
     * @param string $new_url  TP 已產生的語言 URL
     * @param string $url      原始 URL
     * @param string $language 目標語言 locale
     */
    public function outbound_translate( $new_url, $url = '', $language = '' ) {
        if ( '' === $language || $language === YSTPAddonsTP::default_language() || empty( $new_url ) ) {
            return $new_url;
        }
        $parts = wp_parse_url( $new_url );
        $path  = $parts['path'] ?? '';
        if ( '' === $path || '/' === $path ) {
            return $new_url;
        }

        $map = self::map( $language )['out'];
        if ( empty( $map ) ) {
            return $new_url;
        }

        $segments = explode( '/', trim( $path, '/' ) );
        $changed  = false;
        foreach ( $segments as $i => $seg ) {
            if ( '' === $seg ) {
                continue;
            }
            // 段落可能為資料庫原形（percent-encoded）或已解碼，兩種都比對
            $hit = $map[ $seg ] ?? ( $map[ rawurldecode( $seg ) ] ?? null );
            if ( null !== $hit ) {
                $segments[ $i ] = rawurlencode( rawurldecode( (string) $hit ) );
                $changed        = true;
            }
        }
        if ( ! $changed ) {
            return $new_url;
        }

        $new_path = '/' . implode( '/', $segments ) . '/';
        return str_replace( $path, $new_path, $new_url );
    }

    public function canonical_url( $url ) {
        $language = YSTPAddonsTP::current_language();
        $home = wp_parse_url( (string) get_option( 'home' ) );
        if ( ! is_string( $url ) || $language === YSTPAddonsTP::default_language() ) {
            return $url;
        }

        $target = wp_parse_url( $url );
        if ( empty( $target['host'] ) || ( $home['host'] ?? '' ) !== $target['host']
            || ( $home['port'] ?? null ) !== ( $target['port'] ?? null ) ) {
            return $url;
        }

        return YSTPAddonsTP::url_for_language( $language, $url );
    }

    /* ───────────── Inbound ───────────── */

    public function inbound_rewrite(): void {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron()
            || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
            || empty( $_SERVER['REQUEST_URI'] ) ) {
            return;
        }

        $uri  = wp_unslash( $_SERVER['REQUEST_URI'] );
        $path = (string) wp_parse_url( $uri, PHP_URL_PATH );
        if ( '' === $path ) {
            return;
        }

        // 去掉子目錄安裝的 home path
        $home_path = trim( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ), '/' );
        $work      = trim( $path, '/' );
        if ( '' !== $home_path && ( $work === $home_path || str_starts_with( $work, $home_path . '/' ) ) ) {
            $work = trim( substr( $work, strlen( $home_path ) ), '/' );
        }
        if ( '' === $work ) {
            return;
        }

        $segments = explode( '/', $work );

        // 第一段是否為語言 url-slug
        $lang_map = $this->slug_to_locale();
        $prefix   = $segments[0];
        if ( ! isset( $lang_map[ $prefix ] ) ) {
            return; // 非語言前綴（預設語言或非多語路徑）
        }
        $locale = $lang_map[ $prefix ];
        if ( $locale === YSTPAddonsTP::default_language() ) {
            return;
        }

        $map = self::map( $locale )['in'];
        if ( empty( $map ) ) {
            return;
        }

        // 反查：翻譯 slug → 原始 slug（語言前綴之後的段落）
        // 對照表的值已是資料庫／網址原形，直接寫回不再編碼（避免 % 二次編碼）
        $changed = false;
        for ( $i = 1, $n = count( $segments ); $i < $n; $i++ ) {
            if ( '' === $segments[ $i ] ) {
                continue;
            }
            $hit = $map[ $segments[ $i ] ] ?? ( $map[ rawurldecode( $segments[ $i ] ) ] ?? null );
            if ( null !== $hit ) {
                $segments[ $i ] = (string) $hit;
                $changed        = true;
            }
        }

        if ( $changed ) {
            $new_path = '/' . ( '' !== $home_path ? $home_path . '/' : '' ) . implode( '/', $segments ) . '/';
            $query    = (string) wp_parse_url( $uri, PHP_URL_QUERY );
            $_SERVER['REQUEST_URI'] = $new_path . ( '' !== $query ? '?' . $query : '' );
            // 部分 PHP runtime 另提供原始 PATH_INFO，WordPress 會優先用它比對規則。
            if ( ( $_SERVER['PATH_INFO'] ?? '' ) === $path ) {
                $_SERVER['PATH_INFO'] = $new_path;
            }
        }
    }

    /* ───────────── 唯一性 ───────────── */

    /**
     * 確保翻譯 slug 在同語言內唯一（衝突時加 -2、-3…）
     *
     * 比對範圍：同語言已存在的翻譯 slug（文章＋分類＋基底）與所有原始 slug
     * （post_name／term slug／基底），避免 inbound 反查誤判。
     *
     * @param string $exclude_kind post|term
     */
    private function make_unique( string $slug, string $locale, int $object_id, string $exclude_kind ): string {
        global $wpdb;
        $meta_key  = self::META_PREFIX . $locale;
        $candidate = $slug;
        $suffix    = 2;

        while ( $suffix <= 50 ) {
            $clash = 0;

            // 同語言其他文章的翻譯 slug
            $clash += (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s AND post_id <> %d",
                $meta_key,
                $candidate,
                'post' === $exclude_kind ? $object_id : 0
            ) );
            // 同語言其他分類的翻譯 slug
            $clash += (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s AND term_id <> %d",
                $meta_key,
                $candidate,
                'term' === $exclude_kind ? $object_id : 0
            ) );
            // 原始 slug（文章）
            $clash += (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_name = %s AND ID <> %d AND post_status IN ('publish','private')",
                $candidate,
                'post' === $exclude_kind ? $object_id : 0
            ) );
            // 原始 slug（分類）
            $clash += (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->terms} WHERE slug = %s AND term_id <> %d",
                $candidate,
                'term' === $exclude_kind ? $object_id : 0
            ) );

            if ( 0 === $clash && ! self::is_reserved_base( $candidate ) ) {
                return $candidate;
            }
            $candidate = $slug . '-' . $suffix;
            $suffix++;
        }
        return '';
    }

    /** 是否撞到分類／內容類型的原始基底 */
    private static function is_reserved_base( string $slug ): bool {
        foreach ( self::base_taxonomies() as $tax ) {
            if ( self::taxonomy_base( $tax ) === $slug ) {
                return true;
            }
        }
        foreach ( self::base_post_types() as $pt ) {
            if ( self::post_type_base( $pt ) === $slug ) {
                return true;
            }
        }
        return false;
    }

    /* ───────────── 後台：文章 Meta Box ───────────── */

    public function add_meta_box(): void {
        $types = get_post_types( [ 'public' => true ], 'names' );
        unset( $types['attachment'] );
        foreach ( $types as $pt ) {
            add_meta_box(
                'ys_tp_slug',
                __( '多語網址 slug（多語增強）', 'ys-translatepress-addons' ),
                [ $this, 'render_meta_box' ],
                $pt,
                'normal',
                'low'
            );
        }
    }

    public function render_meta_box( $post ): void {
        $default = YSTPAddonsTP::default_language();
        $langs   = YSTPAddonsTP::languages();
        wp_nonce_field( 'ys_tp_slug_' . $post->ID, 'ys_tp_slug_nonce' );
        ?>
        <p class="description" style="margin:.2em 0 1em;">
            <?php
            printf(
                /* translators: %s: 原始 slug */
                esc_html__( '為各語言設定自訂網址 slug（留空＝沿用原始 slug「%s」）。', 'ys-translatepress-addons' ),
                esc_html( $post->post_name )
            );
            ?>
        </p>
        <table class="form-table" role="presentation"><tbody>
        <?php foreach ( $langs as $code => $name ) :
            if ( $code === $default ) {
                continue;
            }
            $val       = (string) get_post_meta( $post->ID, self::META_PREFIX . $code, true );
            $inherited = ( '' === $val ) ? self::legacy_slug_for_original( (string) $post->post_name, $code ) : '';
            ?>
            <tr>
                <th style="width:180px;"><label for="ys-tp-slug-<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?> <code><?php echo esc_html( $code ); ?></code></label></th>
                <td>
                    <input type="text" id="ys-tp-slug-<?php echo esc_attr( $code ); ?>" name="ys_tp_slug[<?php echo esc_attr( $code ); ?>]"
                        value="<?php echo esc_attr( '' !== $val ? $val : $inherited ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $post->post_name ); ?>" />
                    <?php if ( '' === $val && '' !== $inherited ) : ?>
                        <p class="description" style="margin:4px 0 0;color:#5b9a8b;"><?php esc_html_e( '沿用自既有設定，儲存後即由本外掛接管。', 'ys-translatepress-addons' ); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php
    }

    /** 由原始 slug 取既有（PRO）翻譯 slug（供欄位預填沿用） */
    private static function legacy_slug_for_original( string $original, string $locale ): string {
        if ( '' === $original ) {
            return '';
        }
        $pairs = self::legacy_pairs( $locale );
        return $pairs[ $original ] ?? '';
    }

    public function save_meta( $post_id, $post ): void {
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( empty( $_POST['ys_tp_slug_nonce'] ) ||
            ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ys_tp_slug_nonce'] ) ), 'ys_tp_slug_' . $post_id ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $this->save_slug_fields( (int) $post_id, 'post' );
        self::flush_map();
    }

    /* ───────────── 後台：分類項目 slug ───────────── */

    /**
     * 逐公開分類掛編輯欄位（admin_init：taxonomy 已註冊完畢）
     */
    public function register_term_fields(): void {
        if ( count( YSTPAddonsTP::languages() ) < 2 ) {
            return;
        }
        foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
            if ( ! $tax->show_ui ) {
                continue;
            }
            add_action( "{$tax->name}_edit_form_fields", [ $this, 'render_term_fields' ], 20 );
        }
    }

    /**
     * 分類編輯頁的「多語網址 slug」欄位
     *
     * @param \WP_Term $term
     */
    public function render_term_fields( $term ): void {
        $default = YSTPAddonsTP::default_language();
        $langs   = YSTPAddonsTP::languages();
        wp_nonce_field( 'ys_tp_term_slug', 'ys_tp_term_slug_nonce' );
        foreach ( $langs as $code => $name ) {
            if ( $code === $default ) {
                continue;
            }
            $val = (string) get_term_meta( (int) $term->term_id, self::META_PREFIX . $code, true );
            ?>
            <tr class="form-field ys-tp-term-slug">
                <th scope="row">
                    <label for="ys-tp-term-slug-<?php echo esc_attr( $code ); ?>">
                        <?php
                        printf(
                            /* translators: %s: 語言名稱 */
                            esc_html__( '網址 slug（%s）', 'ys-translatepress-addons' ),
                            esc_html( $name )
                        );
                        ?>
                    </label>
                </th>
                <td>
                    <input type="text" id="ys-tp-term-slug-<?php echo esc_attr( $code ); ?>"
                        name="ys_tp_slug[<?php echo esc_attr( $code ); ?>]"
                        value="<?php echo esc_attr( $val ); ?>" size="40"
                        placeholder="<?php echo esc_attr( $term->slug ); ?>" />
                    <p class="description"><?php esc_html_e( '留空＝該語言沿用原始 slug。', 'ys-translatepress-addons' ); ?></p>
                </td>
            </tr>
            <?php
        }
    }

    /**
     * 儲存分類項目的翻譯 slug
     *
     * @param int    $term_id
     * @param int    $tt_id
     * @param string $taxonomy
     */
    public function save_term_slugs( $term_id, $tt_id, $taxonomy ): void {
        if ( empty( $_POST['ys_tp_term_slug_nonce'] ) ||
            ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ys_tp_term_slug_nonce'] ) ), 'ys_tp_term_slug' ) ) {
            return;
        }
        $tax = get_taxonomy( (string) $taxonomy );
        if ( ! $tax || ! current_user_can( $tax->cap->edit_terms ) ) {
            return;
        }

        $this->save_slug_fields( (int) $term_id, 'term' );
        self::flush_map();
    }

    /**
     * 共用：由 $_POST['ys_tp_slug'] 逐語言寫入 post／term meta
     *
     * @param string $kind post|term
     */
    private function save_slug_fields( int $object_id, string $kind ): void {
        $input = isset( $_POST['ys_tp_slug'] ) && is_array( $_POST['ys_tp_slug'] )
            ? wp_unslash( $_POST['ys_tp_slug'] )
            : [];

        $default = YSTPAddonsTP::default_language();
        foreach ( YSTPAddonsTP::published_language_codes() as $code ) {
            if ( $code === $default ) {
                continue;
            }
            $meta_key = self::META_PREFIX . $code;
            $raw      = isset( $input[ $code ] ) ? sanitize_title( (string) $input[ $code ] ) : '';

            if ( '' === $raw ) {
                'post' === $kind
                    ? delete_post_meta( $object_id, $meta_key )
                    : delete_term_meta( $object_id, $meta_key );
                continue;
            }
            $unique = $this->make_unique( $raw, $code, $object_id, $kind );
            if ( $unique === '' ) {
                continue;
            }
            'post' === $kind
                ? update_post_meta( $object_id, $meta_key, $unique )
                : update_term_meta( $object_id, $meta_key, $unique );
        }
    }
}
