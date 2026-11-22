<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap ystp-wrap">
    <h1><?php esc_html_e( '翻譯者與角色預覽', 'ys-translatepress-addons' ); ?></h1>
    <p><?php esc_html_e( '啟用模組後，可在 WordPress 使用者頁將「翻譯者」角色交給負責譯文的同事。已具翻譯權限的帳號可沿用。', 'ys-translatepress-addons' ); ?></p>
    <p><?php esc_html_e( '管理員可在翻譯編輯器選擇常見角色預覽前台。', 'ys-translatepress-addons' ); ?></p>
    <a class="button button-primary" href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>"><?php esc_html_e( '管理使用者', 'ys-translatepress-addons' ); ?></a>
</div>
