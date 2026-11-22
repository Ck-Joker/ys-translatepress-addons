<?php
/**
 * 專供本機 WordPress 副本，以 WP-CLI eval-file 執行。
 * 請先啟用翻譯者、Google 翻譯術語、SEO 與偵測模組，並以 zh_TW → en_US、Google v2 設定測試站。
 */

use YangSheep\TPAddons\Modules\Seo\YSTPAddonsSlugTranslator;
use YangSheep\TPAddons\Support\YSTPAddonsMigration;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || wp_get_environment_type() !== 'local' ) {
    fwrite( STDERR, "此測試只允許在本機 WordPress 由 WP-CLI 執行。\n" );
    exit( 1 );
}

global $wpdb;
$assert = static function ( bool $condition, string $label ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $label );
    }
    echo 'PASS ', $label, PHP_EOL;
};

$admin_id = get_current_user_id();
$assert( current_user_can( 'manage_options' ), '以管理員執行本機測試' );
do_action( 'admin_init' );
$role = get_role( 'translator' );
$assert( $role && $role->has_cap( 'translate_strings' ) && ! $role->has_cap( 'manage_options' ), '翻譯者角色最小權限' );
$assert( apply_filters( 'trp_translating_capability', 'manage_options' ) === 'manage_options', '管理員沿用核心權限' );

$translator_id = wp_insert_user( [
    'user_login' => wp_generate_uuid4(),
    'user_pass'  => wp_generate_password(),
    'role'       => 'translator',
] );
$assert( is_int( $translator_id ), '建立本機翻譯者帳號' );
require_once ABSPATH . 'wp-admin/includes/user.php';
try {
    wp_set_current_user( $translator_id );
    $assert( current_user_can( apply_filters( 'trp_translating_capability', 'manage_options' ) ), '翻譯者通過核心編輯器權限' );
    $assert( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_posts' ), '未取得站台設定或文章管理權限' );
    $assert( ! isset( apply_filters( 'trp_editor_nonces', [] )['editor'] ), '翻譯者不取得角色預覽授權' );
    wp_set_current_user( 0 );
    $assert( ! current_user_can( apply_filters( 'trp_translating_capability', 'manage_options' ) ), '匿名訪客不具翻譯權限' );
} finally {
    wp_set_current_user( $admin_id );
    wp_delete_user( $translator_id );
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$assert( isset( apply_filters( 'trp_editor_nonces', [] )['editor'] ), '管理員取得角色預覽授權' );
$preview = apply_filters( 'trp_temporary_change_current_user_role', wp_get_current_user(), 'editor' );
$assert( $preview->roles === [ 'editor' ] && ! $preview->has_cap( 'manage_options' ), '預覽取得指定角色，沒有管理員權限' );
$assert( wp_get_current_user()->roles === [ 'administrator' ], '目前管理員角色未被儲存或改寫' );

$slug_module = new YSTPAddonsSlugTranslator();
$detector = \YangSheep\TPAddons\YSTPAddonsPlugin::instance()->get_module( 'auto_detect' );
$assert( $detector instanceof \YangSheep\TPAddons\Modules\AutoDetect\YSTPAddonsAutoDetect, '載入偵測模組' );
$method = new ReflectionMethod( $detector, 'detection_data' );
add_filter( 'ys_tp_detect_country', static fn(): string => 'US' );
unset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] );
$assert( ( $method->invoke( $detector )['suggested']['code'] ?? '' ) === 'en_US', '沒有瀏覽器語言時採用可信任的國碼' );
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'zh-TW';
$assert( ( $method->invoke( $detector )['suggested'] ?? null ) === null, '瀏覽器語言命中時優先於國碼' );

$token = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
$slug = 'fleet-source-' . $token;
$manual = 'fleet-reviewed-' . $token;
$key = YSTPAddonsSlugTranslator::META_PREFIX . 'en_US';
$machine = TRP_Translate_Press::get_trp_instance()->get_component( 'machine_translator' );
$original_glossary = get_option( 'trp_glossary', null );
register_shutdown_function( static function () use ( $original_glossary ): void {
    if ( $original_glossary === null ) {
        delete_option( 'trp_glossary' );
    } else {
        update_option( 'trp_glossary', $original_glossary );
    }
} );
update_option( 'trp_glossary', [ '專有詞' => [ 'en_US' => 'Fixed Term' ] ] );
$mock_calls = 0;
add_filter( 'pre_http_request', static function ( $result, array $args, string $url ) use ( &$mock_calls, $token ) {
    if ( $url !== 'https://translation.googleapis.com/language/translate/v2' ) {
        return new WP_Error( 'fleet_fixture_network_disabled' );
    }

    $mock_calls++;
    preg_match_all( '/(?:^|&)q=([^&]+)/', $args['body'], $matches );
    $translated = array_map( static function ( string $value ) use ( $token ): array {
        $source = rawurldecode( $value );
        if ( str_contains( $source, 'fleet source' ) ) {
            return [ 'translatedText' => 'Fleet translated ' . $token ];
        }

        return [ 'translatedText' => str_replace( '測試', 'Fixture', $source ) ];
    }, $matches[1] );
    return [
        'response' => [ 'code' => 200, 'message' => 'OK' ],
        'body' => wp_json_encode( [ 'data' => [ 'translations' => $translated ] ] ),
    ];
}, 10, 3 );

$term_source = '測試專有詞';
$translations = $machine->translate( [ $term_source ], 'en_US', 'zh_TW' );
$machine->release_locks();
$assert( str_contains( $translations[ $term_source ] ?? '', 'Fixed Term' ), 'Google v2 模擬回應套用核心術語表' );
$assert( ! str_contains( $translations[ $term_source ] ?? '', 'trp-glossary-' ), '輸出移除術語保護標記' );

$post_id = wp_insert_post( [ 'post_title' => $slug, 'post_name' => $slug, 'post_status' => 'publish' ] );
$assert( (bool) wp_next_scheduled( YSTPAddonsSlugTranslator::AUTO_SLUG_HOOK, [ $post_id ] ), '首次發布排程產生翻譯 slug' );
try {
    do_action( YSTPAddonsSlugTranslator::AUTO_SLUG_HOOK, $post_id );
    $assert( get_post_meta( $post_id, $key, true ) === 'fleet-translated-' . $token, '排程寫入 Google v2 譯名' );

    update_post_meta( $post_id, $key, $manual );
    $calls_before = $mock_calls;
    $slug_module->translate_post_slugs( $post_id );
    $assert( get_post_meta( $post_id, $key, true ) === $manual && $calls_before === $mock_calls, '重跑保留人工譯名且不消耗 API 配額' );

    update_post_meta( $post_id, $key, sanitize_title( '測試' . $token ) );
    YSTPAddonsSlugTranslator::flush_map();
    $url = $slug_module->outbound_translate( home_url( '/en/' . $slug . '/' ), '', 'en_US' );
    $assert( str_contains( $url, '%E6%B8%AC' ) && ! str_contains( $url, '%25' ), '非 ASCII 翻譯網址不重複百分比編碼' );

    $translated_path = (string) wp_parse_url( $url, PHP_URL_PATH );
    $old_uri = $_SERVER['REQUEST_URI'] ?? '/';
    $old_path = $_SERVER['PATH_INFO'] ?? '';
    $_SERVER['REQUEST_URI'] = $translated_path . '?page=1';
    $_SERVER['PATH_INFO'] = $translated_path;
    $slug_module->inbound_rewrite();
    $assert( ! isset( $_SERVER['PATH_INFO'] ) && $_SERVER['REQUEST_URI'] === '/en/' . $slug . '/?page=1', '入站以改寫後的請求路徑解析並保留參數' );

    $source_slug = sanitize_title( '中文原始網址' . $token );
    $encoded_id = wp_insert_post( [ 'post_title' => '非 ASCII 來源', 'post_name' => $source_slug, 'post_status' => 'publish' ] );
    try {
        update_post_meta( $encoded_id, $key, 'encoded-source-fixture-' . $token );
        YSTPAddonsSlugTranslator::flush_map();
        $_SERVER['REQUEST_URI'] = $_SERVER['PATH_INFO'] = '/en/encoded-source-fixture-' . $token . '/';
        $slug_module->inbound_rewrite();
        $assert( ! isset( $_SERVER['PATH_INFO'] ) && $_SERVER['REQUEST_URI'] === '/en/' . $source_slug . '/', '原始中文網址僅保留單層編碼' );
    } finally {
        wp_clear_scheduled_hook( YSTPAddonsSlugTranslator::AUTO_SLUG_HOOK, [ $encoded_id ] );
        wp_delete_post( $encoded_id, true );
        YSTPAddonsSlugTranslator::flush_map();
    }
    $_SERVER['REQUEST_URI'] = $old_uri;
    $_SERVER['PATH_INFO'] = $old_path;

    // 既有 Pro 同原名的文章與分類，應各寫入自己的物件。
    $term = wp_insert_term( $slug, 'category', [ 'slug' => $slug ] );
    $assert( is_array( $term ), '建立測試分類' );
    $originals = $wpdb->prefix . 'trp_slug_originals';
    $translated = $wpdb->prefix . 'trp_slug_translations';
    $wpdb->query( "CREATE TABLE IF NOT EXISTS `$originals` (id BIGINT AUTO_INCREMENT PRIMARY KEY, original VARCHAR(200), type VARCHAR(40))" );
    $wpdb->query( "CREATE TABLE IF NOT EXISTS `$translated` (original_id BIGINT, language VARCHAR(15), translated VARCHAR(200))" );
    $wpdb->insert( $originals, [ 'original' => $slug, 'type' => 'term' ] );
    $fixture_row = (int) $wpdb->insert_id;
    $wpdb->insert( $translated, [ 'original_id' => $fixture_row, 'language' => 'en_US', 'translated' => 'category-' . $token ] );
    try {
        $migration = YSTPAddonsMigration::run();
        $assert( $migration['slug'] === 1 && get_term_meta( $term['term_id'], $key, true ) === 'category-' . $token, '分類 slug 遷移到 term meta' );
        $assert( get_post_meta( $post_id, $key, true ) === sanitize_title( '測試' . $token ), '同名文章的譯名未被分類搬移覆寫' );
        $assert( YSTPAddonsMigration::run()['slug'] === 0, '重跑搬移不覆蓋已存在的譯名' );
    } finally {
        $wpdb->delete( $translated, [ 'original_id' => $fixture_row ] );
        $wpdb->delete( $originals, [ 'id' => $fixture_row ] );
        wp_delete_term( $term['term_id'], 'category' );
    }
} finally {
    wp_clear_scheduled_hook( YSTPAddonsSlugTranslator::AUTO_SLUG_HOOK, [ $post_id ] );
    wp_delete_post( $post_id, true );
}
