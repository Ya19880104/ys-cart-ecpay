<?php
/**
 * Plugin Name: YS Plugin Hub Client
 * Plugin URI:  https://yangsheep.com.tw
 * Description: YANGSHEEP DESIGN 外掛市集客戶端 — 連接 Hub 取得更新和市集資訊。
 * Version:     2.0.2
 * Author:      YANGSHEEP DESIGN
 * Author URI:  https://yangsheep.com.tw
 * License:     GPL-2.0-or-later
 * Text Domain: ys-plugin-hub-client
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 5.8
 *
 * @package YangSheep\PluginHubClient
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ──────────────────────────────────────────────
 * 防止重複載入（必須在常數定義之前！）
 * 當多個 YS 外掛的 vendor/ 都包含此檔案時，只載入第一個。
 * ────────────────────────────────────────────── */
if ( defined( 'YS_HUB_CLIENT_VERSION' ) || did_action( 'ys_hub_client_loaded' ) ) {
    return;
}

/* ──────────────────────────────────────────────
 * 常數定義（放在防重複之後，確保只定義一次）
 * ────────────────────────────────────────────── */
define( 'YS_HUB_CLIENT_VERSION', '2.0.2' );
define( 'YS_HUB_CLIENT_FILE', __FILE__ );
define( 'YS_HUB_CLIENT_DIR', plugin_dir_path( __FILE__ ) );
define( 'YS_HUB_CLIENT_URL', plugin_dir_url( __FILE__ ) );
define( 'YS_HUB_CLIENT_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Hub 伺服器 URL（寫死，不可變更）
 */
if ( ! function_exists( 'ys_hub_client_allowed_hosts' ) ) {
    function ys_hub_client_allowed_hosts(): array {
        $hosts = array( 'yangsheep.com.tw', '*.yangsheep.com.tw' );
        if ( function_exists( 'apply_filters' ) ) {
            $hosts = (array) apply_filters( 'ys_hub_client_allowed_hosts', $hosts );
        }

        return array_values( array_filter( array_map(
            static function ( $host ): string {
                return strtolower( trim( (string) $host ) );
            },
            $hosts
        ) ) );
    }
}

if ( ! function_exists( 'ys_hub_client_url_part' ) ) {
    function ys_hub_client_url_part( string $url, int $component ): string {
        $value = function_exists( 'wp_parse_url' )
            ? wp_parse_url( $url, $component )
            : parse_url( $url, $component );

        return strtolower( trim( (string) $value ) );
    }
}

if ( ! function_exists( 'ys_hub_client_is_allowed_host' ) ) {
    function ys_hub_client_is_allowed_host( string $host ): bool {
        $host = strtolower( trim( $host ) );
        if ( '' === $host ) {
            return false;
        }

        foreach ( ys_hub_client_allowed_hosts() as $allowed ) {
            if ( $host === $allowed ) {
                return true;
            }

            if ( 0 === strpos( $allowed, '*.' ) ) {
                $suffix = substr( $allowed, 1 );
                if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
                    return true;
                }
            }
        }

        return false;
    }
}

if ( ! function_exists( 'ys_hub_client_is_allowed_package_url' ) ) {
    function ys_hub_client_is_allowed_package_url( string $url ): bool {
        return 'https' === ys_hub_client_url_part( $url, PHP_URL_SCHEME )
            && ys_hub_client_is_allowed_host( ys_hub_client_url_part( $url, PHP_URL_HOST ) );
    }
}

if ( ! function_exists( 'ys_hub_client_sanitize_hub_url' ) ) {
    function ys_hub_client_sanitize_hub_url( string $url, string $fallback ): string {
        $url      = rtrim( trim( $url ), '/' );
        $fallback = rtrim( trim( $fallback ), '/' );

        if ( ! ys_hub_client_is_allowed_package_url( $url ) ) {
            return $fallback;
        }

        return function_exists( 'esc_url_raw' ) ? rtrim( esc_url_raw( $url ), '/' ) : $url;
    }
}

$ys_hub_client_default_hub_url = 'https://yangsheep.com.tw';
$ys_hub_client_requested_url   = defined( 'YS_CART_HUB_URL' )
    ? (string) YS_CART_HUB_URL
    : (string) get_option( 'ys_cart_hub_url', $ys_hub_client_default_hub_url );

define(
    'YS_HUB_CLIENT_HUB_URL',
    ys_hub_client_sanitize_hub_url( $ys_hub_client_requested_url, $ys_hub_client_default_hub_url )
);

/* ──────────────────────────────────────────────
 * Fallback PSR-4 Autoloader
 * ────────────────────────────────────────────── */
spl_autoload_register( function ( $class ) {
    $prefix    = 'YangSheep\\PluginHubClient\\';
    $base_dir  = __DIR__ . '/src/';
    $len       = strlen( $prefix );

    if ( 0 !== strncmp( $prefix, $class, $len ) ) {
        return;
    }

    $relative = substr( $class, $len );
    $file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

/* ──────────────────────────────────────────────
 * HPOS 相容宣告
 * ────────────────────────────────────────────── */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

/* ──────────────────────────────────────────────
 * Activation Hook — 建立資料表
 * ────────────────────────────────────────────── */
register_activation_hook( __FILE__, function () {
    $table_maker = \YangSheep\PluginHubClient\Database\YSHubClientTableMaker::instance();
    $table_maker->create_table();
} );

/* ──────────────────────────────────────────────
 * 主要啟動
 * ────────────────────────────────────────────── */
add_action( 'plugins_loaded', function () {
    // 檢查 schema 版本，必要時升級資料表
    $table_maker = \YangSheep\PluginHubClient\Database\YSHubClientTableMaker::instance();
    if ( $table_maker->schema_update_required() ) {
        $table_maker->create_table();
    }

    // 初始化主 Facade
    \YangSheep\PluginHubClient\YSPluginHubClient::instance();

    /**
     * 標記已載入，供其他外掛偵測
     */
    do_action( 'ys_hub_client_loaded' );
}, 10 );
