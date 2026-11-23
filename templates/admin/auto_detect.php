<?php
/**
 * 模組設定頁：語言自動偵測
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
use YangSheep\TPAddons\Modules\AutoDetect\YSTPAddonsAutoDetect;

$method    = YSTPAddonsAutoDetect::detection_method();
$behavior  = (string) YSTPAddonsSettingsRepo::get( 'detect_behavior', 'prompt' );
$style     = (string) YSTPAddonsSettingsRepo::get( 'detect_style', 'card' );
$position  = (string) YSTPAddonsSettingsRepo::get( 'detect_position', 'bottom-right' );
$custom_on = (int) YSTPAddonsSettingsRepo::get( 'detect_msg_custom', 0 );

// 已發佈語言：預設（主要）語言排最前
$pub_codes = YSTPAddonsTP::published_language_codes();
$def_code  = YSTPAddonsTP::default_language();
$ordered   = array_merge( [ $def_code ], array_values( array_diff( $pub_codes, [ $def_code ] ) ) );

// 逐語言組裝：內建母語文案（placeholder／預覽）與後台已存自訂值
$lang_rows = [];
foreach ( $ordered as $code ) {
    $code = (string) $code;
    if ( '' === $code ) {
        continue;
    }
    $nat     = YSTPAddonsTP::language_native_name( $code );
    $strings = YSTPAddonsAutoDetect::prompt_strings_for( $code );
    $slug    = strtolower( $code );
    $lang_rows[] = [
        'code'       => $code,
        'slug'       => $slug,
        'name'       => $nat,
        'flag'       => YSTPAddonsTP::flag_url( $code ),
        'is_source'  => ( $code === $def_code ),
        'ph_title'   => sprintf( $strings['title'], $nat ),
        'ph_accept'  => sprintf( $strings['accept'], $nat ),
        'val_title'  => (string) YSTPAddonsSettingsRepo::get( 'detect_msg_title_' . $slug, '' ),
        'val_accept' => (string) YSTPAddonsSettingsRepo::get( 'detect_msg_accept_' . $slug, '' ),
    ];
}

// 預覽：取一個「非主要」語言示範；反映目前模式（手動且有填 → 用填的，否則內建母語）
$demo = null;
foreach ( $lang_rows as $r ) {
    if ( ! $r['is_source'] ) {
        $demo = $r;
        break;
    }
}
if ( ! $demo && ! empty( $lang_rows ) ) {
    $demo = $lang_rows[0];
}
$demo_flag = $demo_title = $demo_accept = '';
if ( $demo ) {
    $demo_flag = $demo['flag'];
    $dt = ( $custom_on && '' !== trim( $demo['val_title'] ) ) ? $demo['val_title'] : $demo['ph_title'];
    $da = ( $custom_on && '' !== trim( $demo['val_accept'] ) ) ? $demo['val_accept'] : $demo['ph_accept'];
    $demo_title  = ( false !== strpos( $dt, '%s' ) ) ? sprintf( $dt, $demo['name'] ) : $dt;
    $demo_accept = ( false !== strpos( $da, '%s' ) ) ? sprintf( $da, $demo['name'] ) : $da;
}
?>
<div class="wrap ystp-wrap">
    <h1 class="ystp-screen-reader">語言自動偵測</h1>

    <div class="ystp-hero ystp-hero-sm">
        <div class="ystp-hero-main">
            <div class="ystp-hero-title"><span class="dashicons <?php echo esc_attr( $meta['icon'] ); ?>"></span> 語言自動偵測</div>
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

    <div class="ystp-card-row">
        <div class="ystp-panel">
            <div class="ystp-panel-head"><span class="dashicons dashicons-admin-settings"></span> 設定</div>
            <div class="ystp-panel-body ystp-form" data-form="detect">
                <div class="ystp-field">
                    <label for="ystp-detect-method"><?php esc_html_e( '偵測來源', 'ys-translatepress-addons' ); ?></label>
                    <select id="ystp-detect-method" data-setting="detect_method">
                        <option value="browser" <?php selected( $method, 'browser' ); ?>><?php esc_html_e( '瀏覽器語言', 'ys-translatepress-addons' ); ?></option>
                        <option value="browser-ip" <?php selected( $method, 'browser-ip' ); ?>><?php esc_html_e( '瀏覽器語言優先，IP 備援', 'ys-translatepress-addons' ); ?></option>
                        <option value="ip-browser" <?php selected( $method, 'ip-browser' ); ?>><?php esc_html_e( 'IP 優先，瀏覽器語言備援', 'ys-translatepress-addons' ); ?></option>
                        <option value="ip" <?php selected( $method, 'ip' ); ?>><?php esc_html_e( 'IP 所在國家或地區', 'ys-translatepress-addons' ); ?></option>
                    </select>
                    <p class="ystp-field-desc"><?php esc_html_e( 'IP 偵測需設定可信任的 Cloudflare 入口，或在站上安裝 WooCommerce 的本機地理資料庫。', 'ys-translatepress-addons' ); ?></p>
                </div>
                <div class="ystp-field">
                    <label><?php esc_html_e( '提示行為', 'ys-translatepress-addons' ); ?></label>
                    <select data-setting="detect_behavior">
                        <option value="prompt" <?php selected( $behavior, 'prompt' ); ?>><?php esc_html_e( '詢問訪客（顯示提示，由訪客決定）', 'ys-translatepress-addons' ); ?></option>
                        <option value="auto" <?php selected( $behavior, 'auto' ); ?>><?php esc_html_e( '自動切換（首次到訪直接切換）', 'ys-translatepress-addons' ); ?></option>
                    </select>
                    <p class="ystp-field-desc"><?php esc_html_e( '建議使用「詢問訪客」，較友善且不影響 SEO。', 'ys-translatepress-addons' ); ?></p>
                </div>
                <div class="ystp-field">
                    <label><?php esc_html_e( '提示樣式', 'ys-translatepress-addons' ); ?></label>
                    <select data-setting="detect_style">
                        <option value="card" <?php selected( $style, 'card' ); ?>><?php esc_html_e( '角落提示卡', 'ys-translatepress-addons' ); ?></option>
                        <option value="bar" <?php selected( $style, 'bar' ); ?>><?php esc_html_e( '頂部橫幅', 'ys-translatepress-addons' ); ?></option>
                    </select>
                </div>
                <div class="ystp-field">
                    <label><?php esc_html_e( '提示卡位置', 'ys-translatepress-addons' ); ?></label>
                    <select data-setting="detect_position">
                        <option value="bottom-right" <?php selected( $position, 'bottom-right' ); ?>><?php esc_html_e( '右下', 'ys-translatepress-addons' ); ?></option>
                        <option value="bottom-left" <?php selected( $position, 'bottom-left' ); ?>><?php esc_html_e( '左下', 'ys-translatepress-addons' ); ?></option>
                    </select>
                </div>

                <div class="ystp-field ystp-field-wide">
                    <label><?php esc_html_e( '自訂提示文字', 'ys-translatepress-addons' ); ?></label>
                    <label class="ystp-toggle" style="margin-top:4px;">
                        <input type="checkbox" class="ystp-detect-custom-toggle" data-setting="detect_msg_custom" <?php checked( $custom_on, 1 ); ?> />
                        <span class="ystp-slider"></span>
                    </label>
                    <p class="ystp-field-desc"><?php esc_html_e( '關閉（自動）：依訪客的瀏覽器語言，自動以「該語言」的內建母語文案顯示提示。開啟後，可自訂每個語言的提示文字。', 'ys-translatepress-addons' ); ?></p>
                </div>

                <div class="ystp-detect-manual" style="<?php echo $custom_on ? '' : 'display:none;'; ?>">
                    <?php foreach ( $lang_rows as $r ) : ?>
                        <div class="ystp-detect-lang<?php echo $r['is_source'] ? ' is-source' : ''; ?>" data-lang="<?php echo esc_attr( $r['slug'] ); ?>" data-code="<?php echo esc_attr( $r['code'] ); ?>">
                            <div class="ystp-detect-lang-head">
                                <?php if ( $r['flag'] ) : ?>
                                    <img class="ystp-detect-lang-flag" src="<?php echo esc_url( $r['flag'] ); ?>" alt="" width="22" height="15" />
                                <?php endif; ?>
                                <span class="ystp-detect-lang-name"><?php echo esc_html( $r['name'] ); ?></span>
                                <?php if ( $r['is_source'] ) : ?>
                                    <span class="ystp-detect-lang-badge"><?php esc_html_e( '主要來源語言', 'ys-translatepress-addons' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="ystp-detect-lang-fields">
                                <input type="text" data-setting="detect_msg_title_<?php echo esc_attr( $r['slug'] ); ?>" value="<?php echo esc_attr( $r['val_title'] ); ?>" placeholder="<?php echo esc_attr( $r['ph_title'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: 語言名稱 */ __( '%s 提示標題', 'ys-translatepress-addons' ), $r['name'] ) ); ?>" />
                                <input type="text" data-setting="detect_msg_accept_<?php echo esc_attr( $r['slug'] ); ?>" value="<?php echo esc_attr( $r['val_accept'] ); ?>" placeholder="<?php echo esc_attr( $r['ph_accept'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: 語言名稱 */ __( '%s 切換按鈕文字', 'ys-translatepress-addons' ), $r['name'] ) ); ?>" />
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <p class="ystp-field-desc"><?php esc_html_e( '留空的欄位會自動使用該語言的內建母語文案；文字中的 %s 會替換為語言名稱。', 'ys-translatepress-addons' ); ?></p>
                </div>

                <div class="ystp-form-foot">
                    <button type="button" class="ystp-btn ystp-btn-primary ystp-save-btn" data-form="detect">
                        <span class="dashicons dashicons-saved"></span> 儲存設定
                    </button>
                </div>
            </div>
        </div>

        <div class="ystp-panel">
            <div class="ystp-panel-head"><span class="dashicons dashicons-visibility"></span> 提示卡預覽</div>
            <div class="ystp-panel-body">
                <p class="ystp-muted" style="margin-bottom:16px;"><?php esc_html_e( '訪客首次到訪、且瀏覽器語言與當前頁面不同時，會看到：', 'ys-translatepress-addons' ); ?></p>
                <?php if ( $demo ) : ?>
                <div style="background:#eef2f5;border-radius:12px;padding:30px;display:flex;justify-content:center;">
                    <div class="ystp-ald ystp-ald--card is-in" style="position:static;transform:none;">
                        <div class="ystp-ald-card-head">
                            <img class="ystp-ald-flag" src="<?php echo esc_url( $demo_flag ); ?>" alt="" width="26" height="18" />
                            <div class="ystp-ald-card-title"><?php echo esc_html( $demo_title ); ?></div>
                        </div>
                        <div class="ystp-ald-card-actions">
                            <button type="button" class="ystp-ald-btn ystp-ald-accept" style="flex:1;"><?php echo esc_html( $demo_accept ); ?></button>
                            <button type="button" class="ystp-ald-btn ystp-ald-dismiss"><?php esc_html_e( '維持目前語言', 'ys-translatepress-addons' ); ?></button>
                        </div>
                    </div>
                </div>
                <p class="ystp-field-desc" style="margin-top:12px;"><?php esc_html_e( '預覽以其他語言示範；實際提示會依每位訪客的瀏覽器語言，以「該語言」文案顯示。修改後請儲存並重新整理以更新預覽。', 'ys-translatepress-addons' ); ?></p>
                <?php else : ?>
                    <p class="ystp-muted"><?php esc_html_e( '需要至少兩個語言才能預覽。', 'ys-translatepress-addons' ); ?></p>
                <?php endif; ?>
                <p class="ystp-field-desc" style="margin-top:18px;"><span class="dashicons dashicons-shield" style="color:#5b9a8b;"></span> <?php esc_html_e( '完全由 JavaScript 驅動，搜尋引擎爬蟲不受影響，不會造成重複內容問題。', 'ys-translatepress-addons' ); ?></p>
            </div>
        </div>
    </div>
</div>
