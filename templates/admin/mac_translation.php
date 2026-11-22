<?php
defined( 'ABSPATH' ) || exit;

use YangSheep\TPAddons\Modules\MacTranslation\YSTPAddonsMacSchedule;

$seen = (int) ( $report['seen'] ?? 0 );
$recent = $seen > 0 && time() - $seen <= 180;
$phases = [
    'idle' => __( '等待執行', 'ys-translatepress-addons' ),
    'scanning' => __( '處理中', 'ys-translatepress-addons' ),
    'complete' => __( '已完成', 'ys-translatepress-addons' ),
    'error' => __( '有項目處理失敗', 'ys-translatepress-addons' ),
    'paused' => __( '已暫停', 'ys-translatepress-addons' ),
];
?>
<div class="wrap ystp-wrap ystp-mac">
    <header class="ystp-mac-header">
        <h1><?php esc_html_e( 'Mac ChatGPT 翻譯', 'ys-translatepress-addons' ); ?></h1>
        <p><?php esc_html_e( '由 Mac 領取公開頁面並交給 ChatGPT 翻譯。啟用自動寫回後，訪客開啟語言頁時可將該頁優先排入補譯。', 'ys-translatepress-addons' ); ?></p>
    </header>
    <?php if ( $result === 'saved' || $result === 'blocked' ) : ?>
        <div class="notice <?php echo $result === 'saved' ? 'notice-success' : 'notice-error'; ?>" role="status"><p>
            <?php echo $result === 'saved' ? esc_html__( '排程設定已儲存。', 'ys-translatepress-addons' ) : esc_html__( '設定未儲存。請檢查連線設定與翻譯引擎。', 'ys-translatepress-addons' ); ?>
        </p></div>
    <?php endif; ?>
    <div class="ystp-card-row ystp-mac-grid">
        <section class="ystp-panel" aria-labelledby="ys-tp-mac-schedule-title">
            <div class="ystp-panel-head"><h2 id="ys-tp-mac-schedule-title"><?php esc_html_e( '自動翻譯排程', 'ys-translatepress-addons' ); ?></h2></div>
            <div class="ystp-panel-body">
                <?php if ( ! $ready ) : ?>
                    <p class="description"><?php echo $connected
                        ? esc_html__( '請先關閉本站其他自動翻譯引擎。', 'ys-translatepress-addons' )
                        : esc_html__( '請先由站台維運人員設定受限 SSH 及 Mac 排程。', 'ys-translatepress-addons' ); ?></p>
                <?php endif; ?>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
                    <input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
                    <?php wp_nonce_field( $action ); ?>
                    <div class="ystp-mac-field">
                        <label for="ys-tp-mac-auto-write">
                            <input type="checkbox" id="ys-tp-mac-auto-write" name="auto_write" value="1" <?php checked( $settings['auto_write'] ); ?>>
                            <?php esc_html_e( '自動寫回公開譯文', 'ys-translatepress-addons' ); ?>
                        </label>
                    </div>
                    <div class="ystp-mac-field">
                        <label for="ys-tp-mac-enabled">
                            <input type="checkbox" id="ys-tp-mac-enabled" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?> <?php disabled( ! $ready && ! $settings['enabled'] ); ?>>
                            <?php esc_html_e( '啟用公開頁面自動翻譯', 'ys-translatepress-addons' ); ?>
                        </label>
                    </div>
                    <div class="ystp-mac-field">
                        <label for="ys-tp-mac-minutes"><?php esc_html_e( '掃描間隔', 'ys-translatepress-addons' ); ?></label>
                        <select id="ys-tp-mac-minutes" name="minutes">
                            <?php foreach ( YSTPAddonsMacSchedule::INTERVALS as $interval ) : ?>
                                <option value="<?php echo esc_attr( (string) $interval ); ?>" <?php selected( $settings['minutes'], $interval ); ?>><?php
                                    /* translators: %d: 分鐘 */
                                    echo esc_html( sprintf( __( '每 %d 分鐘', 'ys-translatepress-addons' ), $interval ) );
                                ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="ystp-btn ystp-mac-save"><?php esc_html_e( '儲存設定', 'ys-translatepress-addons' ); ?></button>
                </form>
            </div>
        </section>
        <section class="ystp-panel" aria-labelledby="ys-tp-mac-status-title">
            <div class="ystp-panel-head"><h2 id="ys-tp-mac-status-title"><?php esc_html_e( 'Mac 執行狀態', 'ys-translatepress-addons' ); ?></h2></div>
            <div class="ystp-panel-body">
                <dl class="ystp-mac-status">
                    <dt><?php esc_html_e( '連線', 'ys-translatepress-addons' ); ?></dt>
                    <dd><?php echo $recent ? esc_html__( '最近 3 分鐘已連線', 'ys-translatepress-addons' ) : esc_html__( '等待 Mac 回報', 'ys-translatepress-addons' ); ?></dd>
                    <?php if ( $seen > 0 ) : ?>
                        <dt><?php esc_html_e( '上次連線', 'ys-translatepress-addons' ); ?></dt>
                        <dd><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $seen ) ); ?></dd>
                        <dt><?php esc_html_e( '執行結果', 'ys-translatepress-addons' ); ?></dt>
                        <dd><?php echo esc_html( $phases[ $report['phase'] ?? '' ] ?? '' ); ?></dd>
                        <?php if ( isset( $report['finished'] ) ) : ?>
                            <dt><?php esc_html_e( '上次完成', 'ys-translatepress-addons' ); ?></dt>
                            <dd><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $report['finished'] ) ); ?></dd>
                            <dt><?php esc_html_e( '審核批次', 'ys-translatepress-addons' ); ?></dt>
                            <dd><?php echo esc_html( number_format_i18n( (int) ( $report['previews'] ?? 0 ) ) ); ?></dd>
                            <dt><?php esc_html_e( '自動寫回筆數', 'ys-translatepress-addons' ); ?></dt>
                            <dd><?php echo esc_html( number_format_i18n( (int) ( $report['written'] ?? 0 ) ) ); ?></dd>
                        <?php endif; ?>
                    <?php endif; ?>
                </dl>
            </div>
        </section>
    </div>
</div>
