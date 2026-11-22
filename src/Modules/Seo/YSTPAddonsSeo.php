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
            add_filter( 'wpseo_sitemap_urlset', [ $this, 'add_xhtml_namespace' ] );
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

        // 圖片替代文字 alt：預設「不」註冊獨立節點。
        // TranslatePress 免費版的圖片節點（image_src）本身在編輯器就以「新增媒體」欄位
        // 同時顯示並可翻譯 alt；若我們再註冊獨立的 img[alt] 節點，會與該媒體欄位重疊，
        // 導致該圖片的「新增媒體」上傳鈕消失。故預設關閉，需要時才由設定開啟。
        if ( (int) YSTPAddonsSettingsRepo::get( 'seo_image_alt', 0 ) ) {
            $accessors['image_alt'] = [
                'selector'  => 'img[alt]',
                'accessor'  => 'alt',
                'attribute' => true,
            ];
        }

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
        if ( \YangSheep\TPAddons\Modules\MacTranslation\YSTPAddonsMacRelease::pending( get_queried_object_id(), $lang ) ) {
            return;
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
        return $this->inject_alternates( (string) $output, is_array( $url ) ? $url : [] );
    }

    public function rankmath_sitemap_alternates( $output, $url ) {
        return $this->inject_alternates( (string) $output, is_array( $url ) ? $url : [] );
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
     * 在單一 <url> 區塊內插入各語言 xhtml:link alternate，並為每個非原始語言各補一筆獨立 <url>
     *
     * 邏輯比照 TranslatePress PRO 的 sitemap_add_language_urls：
     * - 每個發佈語言輸出 country（en-US）與 region-independent（en）hreflang（含去重）+ x-default
     * - 每個「非原始語言」各產生獨立 <url><loc>/en/…</loc>，使 sitemap 實際列出各語言網址
     *
     * 本外掛獨有：被「內容語言規則」隱藏某語言的內容，不為該語言輸出 alternate 或條目；
     * 若「原始語言」（原 <loc> 對應語言）本身被隱藏，則整筆原 <url> 不輸出（避免 sitemap
     * 指向必被重導／404 的網址）；全部語言皆被隱藏則整個 <url> 移除。
     *
     * 沿用 PRO 的 sitemap 過濾器契約（供 Multiple Domains 等外掛掛載）：
     * - trp_filter_url_sitemap_before_output（前處理 $url 資料）
     * - trp_add_language_url_to_sitemap（逐語言決定是否納入）
     * - trp_xml_sitemap_output_for_url（最終輸出；Multiple Domains 以此移除跨網域 <url>）
     *
     * @param string               $output SEO 外掛產生的單一 <url> XML 區塊
     * @param array<string, mixed> $url    URL 資料（loc／mod／images…）
     */
    private function inject_alternates( string $output, array $url ): string {
        $loc = (string) ( $url['loc'] ?? '' );
        if ( '' === $output || '' === $loc ) {
            return $output;
        }

        $settings = YSTPAddonsTP::settings();

        // 輸出前允許外掛調整 URL 資料（比照 PRO；loc 可能被改寫）
        $url = apply_filters( 'trp_filter_url_sitemap_before_output', $url );
        $url = is_array( $url ) ? $url : [];
        $loc = (string) ( $url['loc'] ?? '' );
        if ( '' === $loc ) {
            return $output;
        }

        $post_id = self::resolve_post_id( $loc );
        $term_id = $post_id ? 0 : self::resolve_term_id( $loc );
        $default = YSTPAddonsTP::default_language();
        $publish = YSTPAddonsTP::published_language_codes();

        // 原 <loc> 對應語言：一般為預設語言；若「預設語言使用子目錄」且首個發佈語言非預設，
        // SEO 外掛產生的 <loc> 會是首個發佈語言（比照 PRO）。可由 filter 覆寫（多網域外掛用）。
        $original_language = ( ( $settings['add-subdirectory-to-default-language'] ?? '' ) === 'yes' && isset( $publish[0] ) )
            ? (string) $publish[0]
            : $default;
        $original_language = (string) apply_filters( 'trp_sitemap_url_original_language', $original_language, $loc, $settings );

        // 收集要納入的語言：外掛 filter（比照 PRO，如 Multiple Domains 的 AIOSEO 過濾）
        // 與「內容語言規則」隱藏排除（本外掛獨有；內容與分類頁皆適用）皆在此套用
        $langs = [];
        foreach ( $publish as $code ) {
            if ( ! apply_filters( 'trp_add_language_url_to_sitemap', true, $code, $url, $output ) ) {
                continue;
            }
            if ( $post_id && ( self::is_hidden_in_language( $post_id, $code )
                || \YangSheep\TPAddons\Modules\MacTranslation\YSTPAddonsMacRelease::pending( $post_id, $code ) ) ) {
                continue;
            }
            if ( $term_id && self::is_term_hidden_in_language( $term_id, $code ) ) {
                continue;
            }
            $langs[] = $code;
        }
        // 全部語言皆被排除／隱藏 → 從 sitemap 移除整個 <url>
        if ( empty( $langs ) ) {
            return (string) apply_filters( 'trp_xml_sitemap_output_for_url', '', $output, $settings, '', '', $url );
        }

        $add_country = (bool) apply_filters( 'trp_add_country_hreflang_tags', true );
        $add_region  = (bool) apply_filters( 'trp_add_region_independent_hreflang_tags', true );

        // 複用原 <url> 的 lastmod / image 給各語言條目
        $lastmod = '';
        if ( preg_match( '#<lastmod>.*?</lastmod>#s', $output, $m ) ) {
            $lastmod = "\t\t" . trim( $m[0] ) . "\n";
        }
        $images = '';
        if ( preg_match_all( '#<image:image>.*?</image:image>#s', $output, $im ) ) {
            foreach ( $im[0] as $img ) {
                $images .= "\t\t" . trim( $img ) . "\n";
            }
        }

        $alternate         = '';
        $country_hreflangs = [];   // 已輸出的 country hreflang（供 region-independent 去重）
        $region_seen       = [];   // 已納入的 region-independent code
        $region_pending    = [];   // language => region-independent <link>（延後決定是否輸出）
        $other_lang_urls   = [];   // 各非原始語言的獨立 <url> 開頭（尾端稍後補 alternate）

        foreach ( $langs as $code ) {
            $href = YSTPAddonsTP::url_for_language( $code, $loc );

            if ( $add_country ) {
                $hreflang            = str_replace( '_', '-', self::strip_formality( $code ) );
                $hreflang            = (string) apply_filters( 'trp_hreflang', $hreflang, $code );
                $country_hreflangs[] = $hreflang;
                $alternate          .= self::hreflang_link( $hreflang, $href );
            }

            if ( $add_region ) {
                $ri = (string) apply_filters( 'trp_hreflang', (string) strtok( $code, '_' ), $code );
                if ( '' !== $ri && ! in_array( $ri, $region_seen, true ) ) {
                    $region_seen[]           = $ri;
                    $region_pending[ $code ] = self::hreflang_link( $ri, $href );
                }
            }

            if ( $code !== $original_language && '' !== $href ) {
                $other_lang_urls[] = "\t<url>\n\t\t<loc>" . esc_url( $href ) . "</loc>\n" . $lastmod . $images;
            }
        }

        // region-independent 去重：其 code 未出現在 country hreflang 清單時才輸出（比照 PRO）
        foreach ( $region_pending as $code => $link ) {
            $ri = (string) apply_filters( 'trp_hreflang', (string) strtok( $code, '_' ), $code );
            if ( ! in_array( $ri, $country_hreflangs, true ) ) {
                $alternate .= $link;
            }
        }

        // x-default（本外掛設定啟用時；指向語言須為可見）
        if ( (int) YSTPAddonsSettingsRepo::get( 'seo_xdefault_enabled', 0 ) ) {
            $xd = (string) YSTPAddonsSettingsRepo::get( 'seo_xdefault_lang', $default );
            if ( in_array( $xd, $langs, true ) ) {
                $alternate .= self::hreflang_link( 'x-default', YSTPAddonsTP::url_for_language( $xd, $loc ) );
            }
        }

        // 組出各語言獨立 <url>（每筆尾端補上相同 alternate + 收尾）
        // 注意：hreflang 開關全關（$alternate 為空）時仍須輸出各語言條目並套用
        // 隱藏規則（比照 PRO——條目與 hreflang 是兩件事），不可提前返回。
        $all_lang_urls = '';
        foreach ( $other_lang_urls as $u ) {
            $all_lang_urls .= $u . $alternate . "\t</url>\n";
        }

        // 原始語言（原 <loc>）被內容規則隱藏 → 不輸出原 <url>，只保留可見語言的獨立條目
        if ( ! in_array( $original_language, $langs, true ) ) {
            return (string) apply_filters( 'trp_xml_sitemap_output_for_url', $all_lang_urls, $output, $settings, $alternate, $all_lang_urls, $url );
        }

        if ( '' === $alternate && '' === $all_lang_urls ) {
            // 無 alternate 也無其他語言條目 → 內容不變，但仍走最終過濾器（比照 PRO 單一出口）
            $new_output = $output;
        } else {
            // 原始語言可見：於原 <url> 的 </url> 前插入 alternate，其後接各語言獨立條目
            $new_output = str_replace( '</url>', $alternate . "\t</url>\n" . $all_lang_urls, $output );

            // 子目錄預設語言／多網域：原 <loc> 需改寫為 original_language 的網址（比照 PRO；找不到則原樣）
            $translated_loc = YSTPAddonsTP::url_for_language( $original_language, $loc );
            if ( '' !== $translated_loc && $translated_loc !== $loc ) {
                $new_output = str_replace(
                    '<loc>' . $loc . '</loc>',
                    '<loc>' . esc_url( $translated_loc ) . '</loc>',
                    $new_output
                );
            }
        }

        // 最終輸出過濾器（比照 PRO；Multiple Domains 以此移除跨網域 <url> 區塊）
        return (string) apply_filters( 'trp_xml_sitemap_output_for_url', $new_output, $output, $settings, $alternate, $all_lang_urls, $url );
    }

    /** 產生單一 <xhtml:link> alternate 節點 */
    private static function hreflang_link( string $hreflang, string $href ): string {
        return "\t\t<xhtml:link rel=\"alternate\" hreflang=\"" . esc_attr( $hreflang ) . '" href="' . esc_url( $href ) . "\" />\n";
    }

    /**
     * 去除語言碼的 formality 後綴（優先用 TP url_converter，退回字串替換）
     */
    private static function strip_formality( string $code ): string {
        $converter = YSTPAddonsTP::component( 'url_converter' );
        if ( $converter && method_exists( $converter, 'strip_formality_from_language_code' ) ) {
            return (string) $converter->strip_formality_from_language_code( $code );
        }
        return str_replace( [ '_formal', '_informal' ], '', $code );
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

    /**
     * 某分類在某語言是否被「內容語言規則」隱藏（模組未啟用則永不隱藏）
     */
    private static function is_term_hidden_in_language( int $term_id, string $lang ): bool {
        if ( ! \YangSheep\TPAddons\Support\YSTPAddonsModules::is_enabled( 'content_rules' ) ) {
            return false;
        }
        return \YangSheep\TPAddons\Modules\ContentRules\YSTPAddonsTermRules::is_hidden( $term_id, $lang );
    }

    /**
     * 將 sitemap URL 解析為分類 term ID（無對應則 0）
     *
     * 對照表為「套用內容語言規則的 taxonomies」全部 term 的網址，於單一 sitemap
     * 請求內以 static 快取（分類數量級小，成本可忽略）。
     */
    private static function resolve_term_id( string $loc ): int {
        static $map = null;
        if ( null === $map ) {
            $map = [];
            if ( \YangSheep\TPAddons\Support\YSTPAddonsModules::is_enabled( 'content_rules' ) ) {
                $taxes = \YangSheep\TPAddons\Modules\ContentRules\YSTPAddonsTermRules::applied_taxonomies();
                foreach ( $taxes as $tax ) {
                    // ys_tp_no_filter：內部查詢，永不套用前台隱藏排除（對照表須含隱藏 term 才能判斷）
                    $ids = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'ids', 'ys_tp_no_filter' => true ] );
                    foreach ( (array) $ids as $tid ) {
                        $link = get_term_link( (int) $tid, $tax );
                        if ( is_string( $link ) && '' !== $link ) {
                            $map[ untrailingslashit( $link ) ] = (int) $tid;
                        }
                    }
                }
            }
        }
        return $map[ untrailingslashit( $loc ) ] ?? 0;
    }
}
