<?php
/**
 * 模組：逐語言字型
 *
 * 依 TranslatePress 當前語言，為前台套用不同的字型（font-family）。
 * 解決「全站使用 CJK 字型（如 Noto Sans TC）時，其他語系的拉丁標點過大／字寬異常」等問題。
 *
 * 機制：
 * - TranslatePress 於 <body> 加上 `translatepress-{locale}` class、於 <html> 加上 `lang`。
 *   本模組據此輸出 scoped CSS，只覆寫「當前語言」的字型，不影響其他語言。
 * - 覆寫套用於 body 與常見文字／標題／表單元素（刻意排除 span／i／圖示元素，避免破壞圖示字型）。
 * - 可另指定同步覆寫的 CSS 變數（如某些主題或頁面建構器以 `--font-*` 變數控制字型）。
 * - 選用 Google 字型時自動載入（與 Blocksy 相同的 fonts.googleapis.com 來源）；系統字型堆疊零載入。
 *
 * 設定（存自訂表）：
 * - font_{locale}       每語言的字型 token（空＝不覆寫）
 * - fontcustom_{locale} token=custom 時的自訂 font-family
 * - font_vars           另同步覆寫的 CSS 變數（逗號／空白分隔，套用於所有已設定語言）
 *
 * @package YangSheep\TPAddons\Modules\LanguageFonts
 * @since   0.18.0
 */

namespace YangSheep\TPAddons\Modules\LanguageFonts;

use YangSheep\TPAddons\Modules\YSTPAddonsModuleInterface;
use YangSheep\TPAddons\Support\YSTPAddonsTP;
use YangSheep\TPAddons\Database\YSTPAddonsSettingsRepo;

defined( 'ABSPATH' ) || exit;

class YSTPAddonsLanguageFonts implements YSTPAddonsModuleInterface {

    /** 覆寫字型的元素（刻意排除 span／i／div／圖示，避免破壞圖示字型的字符顯示） */
    private const TARGET_ELEMENTS = 'h1,h2,h3,h4,h5,h6,p,a,ul,ol,li,dl,dt,dd,blockquote,cite,q,figcaption,caption,table,th,td,label,legend,button,input,select,textarea,.entry-title,.entry-content,.wp-block-heading';

    public function boot(): void {
        if ( ! is_admin() ) {
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ], 20 );
        }
    }

    /* ───────────── 字型目錄 ───────────── */

    /**
     * 字型目錄（token => 資料）
     *
     * stack：CSS font-family 值；google：需載入的 Google 字型家族名（空＝零載入）。
     *
     * @return array<string, array{label:string, stack:string, google:string, group:string}>
     */
    public static function catalog(): array {
        $g = static function ( string $family, string $extra_stack = 'sans-serif' ): array {
            return [
                'label'  => $family,
                'stack'  => '"' . $family . '", ' . $extra_stack,
                'google' => $family,
                'group'  => 'google',
            ];
        };

        return array_merge(
            [
                'system_sans' => [
                    'label'  => __( '系統無襯線（拉丁語系建議，零載入）', 'ys-translatepress-addons' ),
                    'stack'  => 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, "Helvetica Neue", sans-serif',
                    'google' => '',
                    'group'  => 'system',
                ],
                'system_serif' => [
                    'label'  => __( '系統襯線', 'ys-translatepress-addons' ),
                    'stack'  => 'Georgia, Cambria, "Times New Roman", Times, serif',
                    'google' => '',
                    'group'  => 'system',
                ],
                'system_mono' => [
                    'label'  => __( '系統等寬', 'ys-translatepress-addons' ),
                    'stack'  => 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
                    'google' => '',
                    'group'  => 'system',
                ],
            ],
            [
                'g_inter'         => $g( 'Inter' ),
                'g_roboto'        => $g( 'Roboto' ),
                'g_open_sans'     => $g( 'Open Sans' ),
                'g_lato'          => $g( 'Lato' ),
                'g_montserrat'    => $g( 'Montserrat' ),
                'g_poppins'       => $g( 'Poppins' ),
                'g_nunito_sans'   => $g( 'Nunito Sans' ),
                'g_source_sans_3' => $g( 'Source Sans 3' ),
                'g_work_sans'     => $g( 'Work Sans' ),
                'g_raleway'       => $g( 'Raleway' ),
                'g_dm_sans'       => $g( 'DM Sans' ),
                'g_mulish'        => $g( 'Mulish' ),
                'g_ibm_plex_sans' => $g( 'IBM Plex Sans' ),
                'g_figtree'       => $g( 'Figtree' ),
                'g_merriweather'  => $g( 'Merriweather', 'serif' ),
                'g_playfair'      => [ 'label' => 'Playfair Display', 'stack' => '"Playfair Display", serif', 'google' => 'Playfair Display', 'group' => 'google' ],
            ],
            [
                'g_noto_sans_tc'  => [ 'label' => 'Noto Sans TC', 'stack' => '"Noto Sans TC", sans-serif', 'google' => 'Noto Sans TC', 'group' => 'cjk' ],
                'g_noto_sans_sc'  => [ 'label' => 'Noto Sans SC', 'stack' => '"Noto Sans SC", sans-serif', 'google' => 'Noto Sans SC', 'group' => 'cjk' ],
                'g_noto_sans_jp'  => [ 'label' => 'Noto Sans JP', 'stack' => '"Noto Sans JP", sans-serif', 'google' => 'Noto Sans JP', 'group' => 'cjk' ],
                'g_noto_sans_kr'  => [ 'label' => 'Noto Sans KR', 'stack' => '"Noto Sans KR", sans-serif', 'google' => 'Noto Sans KR', 'group' => 'cjk' ],
                'g_noto_serif_tc' => [ 'label' => 'Noto Serif TC', 'stack' => '"Noto Serif TC", serif', 'google' => 'Noto Serif TC', 'group' => 'cjk' ],
            ]
        );
    }

    /** 分組標籤（供設定頁 optgroup） */
    public static function groups(): array {
        return [
            'system' => __( '系統字型（零載入）', 'ys-translatepress-addons' ),
            'google' => __( 'Google 字型（自動載入）', 'ys-translatepress-addons' ),
            'cjk'    => __( 'CJK 字型', 'ys-translatepress-addons' ),
        ];
    }

    /**
     * 解析 token → [font-family 字串, 需載入的 Google 家族名]
     *
     * @return array{0:string,1:string}
     */
    public static function resolve( string $token, string $custom = '' ): array {
        if ( 'custom' === $token ) {
            return [ trim( $custom ), '' ];
        }
        $catalog = self::catalog();
        if ( isset( $catalog[ $token ] ) ) {
            return [ $catalog[ $token ]['stack'], $catalog[ $token ]['google'] ];
        }
        return [ '', '' ];
    }

    /* ───────────── 前台輸出 ───────────── */

    /**
     * 依當前語言載入字型並輸出 scoped CSS
     */
    public function enqueue_frontend(): void {
        $locale = YSTPAddonsTP::current_language();
        if ( '' === $locale ) {
            return;
        }
        $lc    = strtolower( $locale );
        $token = (string) YSTPAddonsSettingsRepo::get( 'font_' . $lc, '' );
        if ( '' === $token ) {
            return; // 此語言未設定 → 不覆寫（沿用網站預設）
        }

        [ $stack, $google ] = self::resolve( $token, (string) YSTPAddonsSettingsRepo::get( 'fontcustom_' . $lc, '' ) );
        if ( '' === $stack ) {
            return;
        }

        // Google 字型：自動載入（與 Blocksy 相同來源）
        if ( '' !== $google ) {
            $url = 'https://fonts.googleapis.com/css2?family='
                . str_replace( '%20', '+', rawurlencode( $google ) )
                . ':wght@400;500;600;700&display=swap';
            wp_enqueue_style( 'ys-tp-lang-font', $url, [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        }

        wp_register_style( 'ys-tp-lang-fonts-inline', false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        wp_enqueue_style( 'ys-tp-lang-fonts-inline' );
        wp_add_inline_style( 'ys-tp-lang-fonts-inline', $this->build_css( $locale, $stack ) );
    }

    /**
     * 產生 scoped CSS（僅作用於當前語言的 body class）
     */
    private function build_css( string $locale, string $stack ): string {
        // locale 僅來自 TranslatePress 已發佈語言碼（英數與底線），仍以白名單淨化為 class 安全字元
        $class = 'translatepress-' . preg_replace( '/[^A-Za-z0-9_]/', '', $locale );
        $scope = 'body.' . $class;
        $font  = $this->sanitize_stack( $stack );
        if ( '' === $font ) {
            return '';
        }

        $css  = $scope . '{font-family:' . $font . ' !important}';
        $css .= $scope . ' :is(' . self::TARGET_ELEMENTS . '){font-family:' . $font . ' !important}';

        // 同步覆寫的 CSS 變數（如頁面建構器以 --font-* 控制字型）
        foreach ( $this->extra_vars() as $var ) {
            $css .= $scope . '{' . $var . ':' . $font . '}';
        }
        return $css;
    }

    /**
     * 另同步覆寫的 CSS 變數清單（白名單：僅 --開頭、英數與連字號）
     *
     * @return string[]
     */
    private function extra_vars(): array {
        $raw = (string) YSTPAddonsSettingsRepo::get( 'font_vars', '' );
        if ( '' === trim( $raw ) ) {
            return [];
        }
        $out = [];
        foreach ( preg_split( '/[\s,]+/', $raw ) as $v ) {
            $v = trim( $v );
            if ( '' !== $v && preg_match( '/^--[A-Za-z0-9-]+$/', $v ) ) {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * 淨化 font-family 字串（允許字母數字、空白、逗號、引號、連字號；移除可破壞 CSS 的字元）
     */
    private function sanitize_stack( string $stack ): string {
        $stack = trim( $stack );
        // 移除大括號／分號／角括號等可跳脫宣告的字元
        $stack = preg_replace( '/[{};<>\\\\]/', '', $stack );
        return is_string( $stack ) ? trim( $stack ) : '';
    }
}
