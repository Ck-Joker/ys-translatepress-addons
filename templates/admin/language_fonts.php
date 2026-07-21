<?php
/**
 * 模組設定頁：逐語言字型
 *
 * @var array<string, mixed> $meta
 * @var bool                 $enabled
 * @var string               $key
 *
 * @package YangSheep\TPAddons
 */

defined( 'ABSPATH' ) || exit;

use YangSheep\TPAddons\Database\YSTPAddonsSettingsRepo;
use YangSheep\TPAddons\Support\YSTPAddonsTP;
use YangSheep\TPAddons\Modules\LanguageFonts\YSTPAddonsLanguageFonts;

$languages = YSTPAddonsTP::languages();
$default   = YSTPAddonsTP::default_language();
$catalog   = YSTPAddonsLanguageFonts::catalog();
$groups    = YSTPAddonsLanguageFonts::groups();
$font_vars = (string) YSTPAddonsSettingsRepo::get( 'font_vars', '' );

// 依分組整理目錄供 optgroup
$by_group = [];
foreach ( $catalog as $token => $data ) {
    $by_group[ $data['group'] ][ $token ] = $data['label'];
}
?>
<div class="wrap ystp-wrap">
    <h1 class="ystp-screen-reader">逐語言字型</h1>

    <div class="ystp-hero ystp-hero-sm">
        <div class="ystp-hero-main">
            <div class="ystp-hero-title"><span class="dashicons <?php echo esc_attr( $meta['icon'] ); ?>"></span> 逐語言字型</div>
            <div class="ystp-hero-sub"><?php echo esc_html( $meta['desc'] ); ?></div>
        </div>
        <div class="ystp-hero-side">
            <?php if ( $enabled ) : ?>
                <span class="ystp-pill ystp-pill-on"><span class="ystp-dot"></span>模組啟用中</span>
            <?php else : ?>
                <span class="ystp-pill ystp-pill-off"><span class="ystp-dot"></span>模組已停用</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( ! $enabled ) : ?>
        <div class="ystp-note ystp-note-warn">
            此模組目前為停用狀態。請至 <a href="<?php echo esc_url( admin_url( 'admin.php?page=ys-tp-addons' ) ); ?>">總覽</a> 啟用後生效。
        </div>
    <?php endif; ?>

    <div class="ystp-panel">
        <div class="ystp-panel-head"><span class="dashicons dashicons-info-outline"></span> 運作方式</div>
        <div class="ystp-panel-body">
            <p>為每個語言指定不同字型。系統會依 TranslatePress 的當前語言，只覆寫「該語言」前台的字型，不影響其他語言。</p>
            <ul style="margin:0 0 4px 18px;line-height:1.9;">
                <li>常見情境：全站使用中文字型（如 Noto Sans TC）時，英文等語系的<strong>標點符號會過大或字寬異常</strong>——為該語言改用拉丁字型即可解決。</li>
                <li><strong>系統字型</strong>零載入、速度最快；<strong>Google 字型</strong>會自動載入（與 Blocksy 相同來源）。</li>
                <li>字型套用於內文、標題與表單元素（刻意不覆寫圖示元素，避免破壞圖示顯示）。</li>
                <li>無需停用你在主題設定的字型——本設定只在指定語言時疊加覆寫。</li>
            </ul>
        </div>
    </div>

    <div class="ystp-panel">
        <div class="ystp-panel-head"><span class="dashicons dashicons-translation"></span> 各語言字型</div>
        <div class="ystp-panel-body ystp-form" data-form="fonts">
            <?php if ( count( $languages ) < 1 ) : ?>
                <p class="ystp-muted">尚未偵測到已發佈語言。</p>
            <?php else : ?>
                <div class="ystp-cr-types">
                    <?php foreach ( $languages as $code => $name ) :
                        $lc  = strtolower( $code );
                        $cur = (string) YSTPAddonsSettingsRepo::get( 'font_' . $lc, '' );
                        $ccustom = (string) YSTPAddonsSettingsRepo::get( 'fontcustom_' . $lc, '' );
                        ?>
                        <div class="ystp-cr-type">
                            <div class="ystp-cr-type-head">
                                <span class="ystp-cr-type-name"><?php echo esc_html( $name ); ?></span>
                                <code class="ystp-cr-type-slug"><?php echo esc_html( $code ); ?><?php echo $code === $default ? '｜預設' : ''; ?></code>
                            </div>
                            <div class="ystp-cr-type-body">
                                <div class="ystp-cr-type-row" style="flex-wrap:wrap;gap:8px 12px;">
                                    <select class="ys-tp-font-select" data-setting="font_<?php echo esc_attr( $lc ); ?>" data-lc="<?php echo esc_attr( $lc ); ?>" style="min-width:260px;">
                                        <option value="" <?php selected( $cur, '' ); ?>><?php esc_html_e( '網站預設（不覆寫）', 'ys-translatepress-addons' ); ?></option>
                                        <?php foreach ( $groups as $gkey => $glabel ) : ?>
                                            <?php if ( empty( $by_group[ $gkey ] ) ) { continue; } ?>
                                            <optgroup label="<?php echo esc_attr( $glabel ); ?>">
                                                <?php foreach ( $by_group[ $gkey ] as $token => $tlabel ) : ?>
                                                    <option value="<?php echo esc_attr( $token ); ?>" <?php selected( $cur, $token ); ?>><?php echo esc_html( $tlabel ); ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                        <option value="custom" <?php selected( $cur, 'custom' ); ?>><?php esc_html_e( '自訂 font-family…', 'ys-translatepress-addons' ); ?></option>
                                    </select>
                                    <input type="text" class="ys-tp-font-custom" data-setting="fontcustom_<?php echo esc_attr( $lc ); ?>"
                                        value="<?php echo esc_attr( $ccustom ); ?>"
                                        placeholder='"My Font", "Noto Sans TC", sans-serif'
                                        style="width:280px;<?php echo 'custom' === $cur ? '' : 'display:none;'; ?>" />
                                </div>
                                <div class="ys-tp-font-preview" data-lc="<?php echo esc_attr( $lc ); ?>" style="margin-top:8px;font-size:18px;color:#1d2327;">
                                    Aa Bb 123 — “Hello, world!” #Tag &amp; 中文示例
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <hr style="border:none;border-top:1px solid #eef1f4;margin:18px 0;" />

                <div class="ystp-field ystp-field-wide">
                    <label><?php esc_html_e( '進階：另同步覆寫的 CSS 變數', 'ys-translatepress-addons' ); ?></label>
                    <input type="text" data-setting="font_vars" value="<?php echo esc_attr( $font_vars ); ?>"
                        placeholder="--theme-font-family, --font-zh" class="regular-text" style="width:100%;max-width:520px;" />
                    <p class="ystp-field-desc">
                        <?php esc_html_e( '選填。若你的主題或頁面建構器以 CSS 變數控制字型（例如 GreenShift 區塊使用 --font-zh），在此列出變數名稱（逗號分隔），選定語言時會一併以該語言字型覆寫。', 'ys-translatepress-addons' ); ?>
                    </p>
                </div>

                <div class="ystp-form-foot">
                    <button type="button" class="ystp-btn ystp-btn-primary ystp-save-btn" data-form="fonts">
                        <span class="dashicons dashicons-saved"></span> 儲存設定
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
( function () {
    // 目錄（token → font-family stack、google 家族名）供預覽用
    var CATALOG = <?php echo wp_json_encode( array_map( static function ( $d ) {
        return [ 's' => $d['stack'], 'g' => $d['google'] ];
    }, $catalog ) ); ?>;
    var loaded = {};

    function loadGoogle( family ) {
        if ( ! family || loaded[ family ] ) { return; }
        loaded[ family ] = true;
        var l = document.createElement( 'link' );
        l.rel = 'stylesheet';
        l.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent( family ).replace( /%20/g, '+' ) + ':wght@400;500;700&display=swap';
        document.head.appendChild( l );
    }

    function applyPreview( lc ) {
        var sel = document.querySelector( '.ys-tp-font-select[data-lc="' + lc + '"]' );
        var prev = document.querySelector( '.ys-tp-font-preview[data-lc="' + lc + '"]' );
        if ( ! sel || ! prev ) { return; }
        var token = sel.value;
        var stack = '';
        if ( token === 'custom' ) {
            var ci = sel.parentNode.querySelector( '.ys-tp-font-custom' );
            stack = ci ? ci.value : '';
        } else if ( token && CATALOG[ token ] ) {
            stack = CATALOG[ token ].s;
            loadGoogle( CATALOG[ token ].g );
        }
        prev.style.fontFamily = stack || '';
    }

    document.addEventListener( 'change', function ( e ) {
        var sel = e.target.closest ? e.target.closest( '.ys-tp-font-select' ) : null;
        if ( sel ) {
            var custom = sel.parentNode.querySelector( '.ys-tp-font-custom' );
            if ( custom ) { custom.style.display = ( sel.value === 'custom' ) ? '' : 'none'; }
            applyPreview( sel.getAttribute( 'data-lc' ) );
        }
    } );
    document.addEventListener( 'input', function ( e ) {
        if ( e.target && e.target.classList && e.target.classList.contains( 'ys-tp-font-custom' ) ) {
            var s = e.target.closest( '.ystp-cr-type-body' ).querySelector( '.ys-tp-font-select' );
            if ( s ) { applyPreview( s.getAttribute( 'data-lc' ) ); }
        }
    } );

    document.querySelectorAll( '.ys-tp-font-select' ).forEach( function ( s ) {
        applyPreview( s.getAttribute( 'data-lc' ) );
    } );
} )();
</script>
