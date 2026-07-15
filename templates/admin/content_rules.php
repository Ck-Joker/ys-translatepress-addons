<?php
/**
 * 模組設定頁：內容語言規則
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

$lang_col    = (int) YSTPAddonsSettingsRepo::get( 'content_lang_column', 1 );
$redirect_on = (int) YSTPAddonsSettingsRepo::get( 'content_redirect_enabled', 1 );
$status      = (string) YSTPAddonsSettingsRepo::get( 'content_redirect_status', '302' );
$parent_on   = (int) YSTPAddonsSettingsRepo::get( 'content_fb_parent', 1 );
$fb_global   = (string) YSTPAddonsSettingsRepo::get( 'content_fb_global', 'home' );

$languages = YSTPAddonsTP::languages();

$pub_types = get_post_types( [ 'public' => true ], 'objects' );
unset( $pub_types['attachment'] );

$pages = get_posts( [ 'post_type' => 'page', 'numberposts' => 100, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ] );

/**
 * 輸出 fallback 下拉選單
 *
 * @param bool $with_inherit 是否提供「未指定（用全域預設）」選項
 */
$render_fb = static function ( string $setting, string $current, bool $with_inherit = true ) use ( $pages ) {
    $options = [];
    if ( $with_inherit ) {
        $options['inherit'] = __( '未指定（用全域預設跳轉頁）', 'ys-translatepress-addons' );
    }
    $options['home']         = __( '該語言的首頁', 'ys-translatepress-addons' );
    $options['post_archive'] = __( '該語言的文章列表頁', 'ys-translatepress-addons' );
    echo '<select data-setting="' . esc_attr( $setting ) . '">';
    foreach ( $options as $val => $label ) {
        echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current, $val, false ) . '>' . esc_html( $label ) . '</option>';
    }
    if ( $pages ) {
        echo '<optgroup label="' . esc_attr__( '指定頁面（該語言版本）', 'ys-translatepress-addons' ) . '">';
        foreach ( $pages as $p ) {
            $val = 'page:' . $p->ID;
            echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current, $val, false ) . '>' . esc_html( $p->post_title ) . '</option>';
        }
        echo '</optgroup>';
    }
    echo '</select>';
};
?>
<div class="wrap ystp-wrap">
    <h1 class="ystp-screen-reader">內容語言規則</h1>

    <div class="ystp-hero ystp-hero-sm">
        <div class="ystp-hero-main">
            <div class="ystp-hero-title"><span class="dashicons <?php echo esc_attr( $meta['icon'] ); ?>"></span> 內容語言規則</div>
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
            <p>在每篇文章／頁面的編輯畫面右側「<strong>多語顯示規則</strong>」中，可選擇此內容要在哪些語言顯示。</p>
            <ul style="margin:0 0 4px 18px;line-height:1.9;">
                <li><strong>列表排除</strong>：切換到被隱藏的語言時，該內容自動從文章列表／封存／搜尋結果移除。</li>
                <li><strong>智慧重導（階梯式）</strong>：訪客直接造訪被隱藏的單頁時，依序嘗試 ① 跳到最近一個「該語言可見」的<strong>上層</strong> → ② 該<strong>內容類型</strong>的對應頁 → ③ <strong>全域預設跳轉頁</strong>；目標皆為<strong>當前語言</strong>版本。可選擇 301／302／404 處理方式。</li>
            </ul>
        </div>
    </div>

    <div class="ystp-panel">
        <div class="ystp-panel-head"><span class="dashicons dashicons-admin-settings"></span> 規則設定</div>
        <div class="ystp-panel-body ystp-form" data-form="content">
            <div class="ystp-field">
                <label><?php esc_html_e( '列表顯示語言欄', 'ys-translatepress-addons' ); ?></label>
                <label class="ystp-toggle" style="margin-top:4px;">
                    <input type="checkbox" data-setting="content_lang_column" <?php checked( $lang_col, 1 ); ?> />
                    <span class="ystp-slider"></span>
                </label>
                <p class="ystp-field-desc"><?php esc_html_e( '在文章／頁面／自訂內容的後台列表加入「語言」欄，以小國旗＋綠勾顯示各語言是否顯示（被排除語言呈灰階）。', 'ys-translatepress-addons' ); ?></p>
            </div>

            <div class="ystp-field">
                <label><?php esc_html_e( '啟用智慧重導', 'ys-translatepress-addons' ); ?></label>
                <label class="ystp-toggle" style="margin-top:4px;">
                    <input type="checkbox" data-setting="content_redirect_enabled" <?php checked( $redirect_on, 1 ); ?> />
                    <span class="ystp-slider"></span>
                </label>
                <p class="ystp-field-desc"><?php esc_html_e( '關閉後，被隱藏的單頁仍可直接造訪（僅從列表排除）。', 'ys-translatepress-addons' ); ?></p>
            </div>

            <div class="ystp-field" style="max-width:520px;">
                <label><?php esc_html_e( '重導處理方式', 'ys-translatepress-addons' ); ?></label>
                <select data-setting="content_redirect_status">
                    <option value="302" <?php selected( $status, '302' ); ?>><?php esc_html_e( '302 暫時重導', 'ys-translatepress-addons' ); ?></option>
                    <option value="301" <?php selected( $status, '301' ); ?>><?php esc_html_e( '301 永久重導（建議：該語言永久無此內容）', 'ys-translatepress-addons' ); ?></option>
                    <option value="404" <?php selected( $status, '404' ); ?>><?php esc_html_e( '404 找不到頁面（不重導，讓搜尋引擎移除索引）', 'ys-translatepress-addons' ); ?></option>
                </select>
            </div>

            <div class="ystp-field">
                <label><?php esc_html_e( '優先跳轉到上層', 'ys-translatepress-addons' ); ?></label>
                <label class="ystp-toggle" style="margin-top:4px;">
                    <input type="checkbox" data-setting="content_fb_parent" <?php checked( $parent_on, 1 ); ?> />
                    <span class="ystp-slider"></span>
                </label>
                <p class="ystp-field-desc"><?php esc_html_e( '開啟後，會先沿階層往上找最近一個「該語言可見」的上層（頁面／階層式內容）並跳過去；找不到才用下方各類型的 fallback。', 'ys-translatepress-addons' ); ?></p>
            </div>

            <div class="ystp-field ystp-field-wide">
                <label><?php esc_html_e( '各內容類型設定', 'ys-translatepress-addons' ); ?></label>
                <p class="ystp-field-desc" style="margin:0 0 12px;"><?php esc_html_e( '每個內容類型可各自：是否套用規則、整個類型在哪些語言隱藏（一鍵關閉整個類型的多語顯示）、以及被隱藏時重導到哪。', 'ys-translatepress-addons' ); ?></p>
                <div class="ystp-cr-types">
                    <?php foreach ( $pub_types as $pt ) :
                        $default_on = in_array( $pt->name, [ 'post', 'page' ], true ) ? 1 : 0;
                        $applied    = (int) YSTPAddonsSettingsRepo::get( 'content_pt_' . $pt->name, $default_on );
                        $fb_def     = ( 'post' === $pt->name ) ? 'post_archive' : ( ( 'page' === $pt->name ) ? 'home' : 'inherit' );
                        $fb_cur     = (string) YSTPAddonsSettingsRepo::get( 'content_fb_' . $pt->name, $fb_def );
                        ?>
                        <div class="ystp-cr-type">
                            <div class="ystp-cr-type-head">
                                <label class="ystp-toggle">
                                    <input type="checkbox" data-setting="content_pt_<?php echo esc_attr( $pt->name ); ?>" <?php checked( $applied, 1 ); ?> />
                                    <span class="ystp-slider"></span>
                                </label>
                                <span class="ystp-cr-type-name"><?php echo esc_html( $pt->labels->singular_name ); ?></span>
                                <code class="ystp-cr-type-slug"><?php echo esc_html( $pt->name ); ?></code>
                            </div>
                            <div class="ystp-cr-type-body">
                                <div class="ystp-cr-type-row">
                                    <span class="ystp-cr-type-lbl"><?php esc_html_e( '整個類型隱藏於', 'ys-translatepress-addons' ); ?></span>
                                    <span class="ystp-cr-hide-langs">
                                        <?php foreach ( $languages as $code => $name ) :
                                            $hk = 'content_pt_hide_' . $pt->name . '_' . strtolower( $code );
                                            $hv = (int) YSTPAddonsSettingsRepo::get( $hk, 0 );
                                            ?>
                                            <label class="ystp-cr-hide-lang">
                                                <input type="checkbox" data-setting="<?php echo esc_attr( $hk ); ?>" <?php checked( $hv, 1 ); ?> />
                                                <?php echo esc_html( $name ); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </span>
                                </div>
                                <div class="ystp-cr-type-row">
                                    <span class="ystp-cr-type-lbl"><?php esc_html_e( '被隱藏時重導至', 'ys-translatepress-addons' ); ?></span>
                                    <?php $render_fb( 'content_fb_' . $pt->name, $fb_cur ); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="ystp-field" style="max-width:520px;">
                <label><?php esc_html_e( '全域預設跳轉頁', 'ys-translatepress-addons' ); ?></label>
                <?php $render_fb( 'content_fb_global', $fb_global, false ); ?>
                <p class="ystp-field-desc"><?php esc_html_e( '上層找不到、且該類型設為「未指定」時，最後跳到這裡。', 'ys-translatepress-addons' ); ?></p>
            </div>

            <div class="ystp-form-foot">
                <button type="button" class="ystp-btn ystp-btn-primary ystp-save-btn" data-form="content">
                    <span class="dashicons dashicons-saved"></span> 儲存設定
                </button>
            </div>
        </div>
    </div>
</div>
