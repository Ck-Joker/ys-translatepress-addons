<?php
/**
 * 模組：SEO 增強
 *
 * 補強 TranslatePress 的多語 SEO：
 * - SEO 中繼資料翻譯（頁面標題、meta 描述、OG／Twitter 社群標籤、圖片 alt）
 * - hreflang 細控（地區標籤、地區無關標籤、x-default）
 * - Yoast／RankMath sitemap 加入語言 alternate（外掛存在時才掛載）
 *
 * 註：免費版 TranslatePress 已在 <head> 輸出基本 hreflang，本模組提供控制與補強。
 *
 * @package YangSheep\TPAddons\Modules\Seo
 * @since   0.4.0
 */

namespace YangSheep\TPAddons\Modules\Seo;

use YangSheep\TPAddons\Modules\YSTPAddonsModuleInterface;
use YangSheep\TPAddons\Support\YSTPAddonsTP;
use YangSheep\TPAddons\Database\YSTPAddonsSettingsRepo;

defined( 'ABSPATH' ) || exit;

class YSTPAddonsSeo implements YSTPAddonsModuleInterface {

    public function boot(): void {
        // SEO 中繼資料翻譯：讓 <title>、meta 描述、OG／Twitter 標籤、圖片 alt
        // 可在 TranslatePress 編輯器中逐語言翻譯（透過免費核心的 trp_node_accessors 機制）
        if ( (int) YSTPAddonsSettingsRepo::get( 'seo_meta_translate', 1 ) ) {
            add_filter( 'trp_node_accessors', [ $this, 'register_seo_node_accessors' ] );
        }

        // hreflang 地區／地區無關標籤開關（掛 TranslatePress 既有 filter）
        add_filter( 'trp_add_country_hreflang_tags', [ $this, 'filter_region_tags' ] );
        add_filter( 'trp_add_region_independent_hreflang_tags', [ $this, 'filter_region_independent_tags' ] );

        // 翻譯網址 slug（可開關）
        if ( (int) YSTPAddonsSettingsRepo::get( 'seo_slug_enabled', 0 ) ) {
            ( new YSTPAddonsSlugTranslator() )->boot();
        }

        if ( ! is_admin() ) {
            // 自訂 x-default（TP 預設關閉時由我們補上）
            add_action( 'wp_head', [ $this, 'output_xdefault' ], 1 );

            // sitemap alternate（外掛存在才掛）
            add_filter( 'wpseo_sitemap_url', [ $this, 'yoast_sitemap_alternates' ], 10, 2 );
            add_filter( 'rank_math/sitemap/url', [ $this, 'rankmath_sitemap_alternates' ], 10, 2 );

            // RankMath 的 <urlset> 預設未宣告 xhtml 命名空間；補上，否則注入的
            // <xhtml:link> 會讓整個子 sitemap XML 無效（unbound prefix）。init 後類型才註冊完。
            add_action( 'init', [ $this, 'register_rankmath_sitemap_ns' ], 20 );
        }
    }

    public function filter_region_tags( $default ) {
        return (bool) (int) YSTPAddonsSettingsRepo::get( 'seo_hreflang_region', 1 );
    }

    public function filter_region_independent_tags( $default ) {
        return (bool) (int) YSTPAddonsSettingsRepo::get( 'seo_hreflang_region_independent', 1 );
    }

    /* ───────────── SEO 中繼資料節點 ───────────── */

    /**
     * 註冊 SEO 中繼資料的「節點存取器」
     *
     * TranslatePress 核心以 `trp_node_accessors` filter 決定哪些 DOM 節點可被翻譯，
     * 預設不含 <head> 內的 SEO 中繼資料。此處補上頁面標題、meta 描述、社群分享標籤
     * 與圖片 alt，使其在翻譯編輯器中可逐語言翻譯，前台輸出時自動替換為翻譯版本。
     *
     * 🔑 節點型別名稱必須沿用核心約定的鍵（`page_title`／`meta_desc`／`meta_desc_img`），
     * 核心 `trp_node_type_categories`／`trp_node_type_descriptions` 會據此自動將其歸入
     * 編輯器「Meta Information」群組並逐一標示（Page Title／Description／OG Title／
     * OG Site Name／OG Image／OG Image Alt／Twitter…）。用自訂鍵則會落入一般「String List」。
     *
     * @param array<string, array<string, mixed>> $accessors
     * @return array<string, array<string, mixed>>
     */
    public function register_seo_node_accessors( $accessors ) {
        if ( ! is_array( $accessors ) ) {
            return $accessors;
        }

        // 頁面標題 <title> → 群組「Meta Information / Page Title」
        $accessors['page_title'] = [
            'selector'  => 'title',
            'accessor'  => 'innertext',
            'attribute' => false,
        ];

        // meta 描述與 OG／Twitter 文字標籤 → 各自於「Meta Information」標示
        $accessors['meta_desc'] = [
            'selector'  => 'meta[name="description"],meta[property="og:title"],meta[property="og:description"],meta[property="og:site_name"],meta[property="og:image:alt"],meta[name="twitter:title"],meta[name="twitter:description"],meta[name="twitter:image:alt"],meta[name="DC.Title"],meta[name="DC.Description"],meta[property="article:section"],meta[property="article:tag"]',
            'accessor'  => 'content',
            'attribute' => true,
        ];

        // 社群分享圖片網址 → 群組「Meta Information / OG Image」
        $accessors['meta_desc_img'] = [
            'selector'  => 'meta[property="og:image"],meta[property="og:image:secure_url"],meta[name="twitter:image"]',
            'accessor'  => 'content',
            'attribute' => true,
        ];

        // 圖片替代文字 alt（內容圖片，列於 String List）
        $accessors['image_alt'] = [
            'selector'  => 'img[alt]',
            'accessor'  => 'alt',
            'attribute' => true,
        ];

        return $accessors;
    }

    /* ───────────── x-default ───────────── */

    public function output_xdefault(): void {
        if ( ! (int) YSTPAddonsSettingsRepo::get( 'seo_xdefault_enabled', 0 ) ) {
            return;
        }
        // 若 TP 自身已啟用 x-default，避免重複輸出
        $tp_adv = YSTPAddonsTP::settings()['trp_advanced_settings']['enable_hreflang_xdefault'] ?? '';
        if ( $tp_adv && 'disabled' !== $tp_adv ) {
            return;
        }

        $lang = (string) YSTPAddonsSettingsRepo::get( 'seo_xdefault_lang', YSTPAddonsTP::default_language() );
        if ( ! in_array( $lang, YSTPAddonsTP::published_language_codes(), true ) ) {
            $lang = YSTPAddonsTP::default_language();
        }

        $url = YSTPAddonsTP::url_for_language( $lang, YSTPAddonsTP::current_url() );
        echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $url ) . '" />' . "\n";
    }

    /* ───────────── Sitemap alternates ───────────── */

    /**
     * 為 Yoast sitemap 的每個 <url> 加入語言 alternate
     *
     * @param string $output url XML 區塊
     * @param array  $url    url 資料
     */
    public function yoast_sitemap_alternates( $output, $url ) {
        $loc = is_array( $url ) ? ( $url['loc'] ?? '' ) : '';
        return $this->inject_alternates( (string) $output, (string) $loc );
    }

    public function rankmath_sitemap_alternates( $output, $url ) {
        $loc = is_array( $url ) ? ( $url['loc'] ?? '' ) : '';
        return $this->inject_alternates( (string) $output, (string) $loc );
    }

    /**
     * 為所有 RankMath sitemap 的 <urlset> 補上 xhtml 命名空間宣告
     *
     * 逐「sitemap 類型」掛 rank_math/sitemap/{type}_urlset（涵蓋各 public 內容類型、
     * 分類法與作者）。RankMath 未偵測到多語外掛時不會宣告 xhtml 前綴，故需補。
     */
    public function register_rankmath_sitemap_ns(): void {
        $types = array_merge(
            array_values( get_post_types( [ 'public' => true ], 'names' ) ),
            array_values( get_taxonomies( [ 'public' => true ], 'names' ) ),
            [ 'author' ]
        );
        foreach ( array_unique( $types ) as $type ) {
            add_filter( "rank_math/sitemap/{$type}_urlset", [ $this, 'add_xhtml_namespace' ] );
        }
    }

    /**
     * 在 <urlset> 開頭插入 xhtml 命名空間（已宣告則原樣返回）
     *
     * @param string $urlset urlset 開頭 XML
     * @return string
     */
    public function add_xhtml_namespace( $urlset ) {
        if ( ! is_string( $urlset ) || false !== strpos( $urlset, 'xmlns:xhtml' ) ) {
            return $urlset;
        }
        return preg_replace(
            '/<urlset\b/',
            '<urlset xmlns:xhtml="http://www.w3.org/1999/xhtml"',
            $urlset,
            1
        );
    }

    /**
     * 在單一 <url> 區塊內插入各語言 xhtml:link alternate
     *
     * 被「內容語言規則」排除該語言的內容，不輸出該語言的 alternate
     * （避免 sitemap 指向會被重導／404 的網址）。
     */
    private function inject_alternates( string $output, string $loc ): string {
        if ( '' === $output || '' === $loc ) {
            return $output;
        }
        $post_id = self::resolve_post_id( $loc );
        $links   = '';
        foreach ( YSTPAddonsTP::published_language_codes() as $code ) {
            if ( $post_id && self::is_hidden_in_language( $post_id, $code ) ) {
                continue;
            }
            $hreflang = str_replace( '_', '-', $code );
            $href     = YSTPAddonsTP::url_for_language( $code, $loc );
            $links   .= "\t\t<xhtml:link rel=\"alternate\" hreflang=\"" . esc_attr( $hreflang ) . '" href="' . esc_url( $href ) . "\" />\n";
        }
        if ( '' === $links ) {
            return $output;
        }
        // 在 </url> 前插入
        return str_replace( '</url>', $links . "\t</url>", $output );
    }

    /**
     * 將 sitemap URL 解析為 post ID
     *
     * url_to_postid 無法解析「文章頁面」(page_for_posts) 與靜態首頁 (page_on_front)，
     * 此處補上，讓這些頁面被內容規則隱藏某語言時，sitemap 也正確略過該語言 alternate。
     */
    private static function resolve_post_id( string $loc ): int {
        $id = url_to_postid( $loc );
        if ( $id ) {
            return $id;
        }
        $norm = untrailingslashit( $loc );
        foreach ( [ 'page_for_posts', 'page_on_front' ] as $opt ) {
            $pid = (int) get_option( $opt );
            if ( ! $pid ) {
                continue;
            }
            $url = get_permalink( $pid );
            if ( $url && untrailingslashit( $url ) === $norm ) {
                return $pid;
            }
        }
        return 0;
    }

    /**
     * 某內容在某語言是否被「內容語言規則」隱藏（模組未啟用則永不隱藏）
     */
    private static function is_hidden_in_language( int $post_id, string $lang ): bool {
        if ( ! \YangSheep\TPAddons\Support\YSTPAddonsModules::is_enabled( 'content_rules' ) ) {
            return false;
        }
        return \YangSheep\TPAddons\Modules\ContentRules\YSTPAddonsContentRules::is_hidden( $post_id, $lang );
    }
}
