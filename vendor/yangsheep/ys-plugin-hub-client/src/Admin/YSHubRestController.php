<?php
/**
 * YSHubRestController - 客戶端 REST transport
 *
 * 保留既有 marketplace/plugin/settings owners 與 domain envelope，僅把傳輸改為
 * WordPress REST cookie authentication + wp_rest nonce。
 *
 * @package YangSheep\PluginHubClient\Admin
 */

namespace YangSheep\PluginHubClient\Admin;

use YangSheep\PluginHubClient\Database\YSHubClientLogRepo;
use YangSheep\PluginHubClient\Database\YSHubClientSettingsRepo;
use YangSheep\PluginHubClient\Http\YSCircuitBreaker;
use YangSheep\PluginHubClient\Http\YSHubApiClient;
use YangSheep\PluginHubClient\Marketplace\YSMarketplaceInstaller;
use YangSheep\PluginHubClient\Registry\YSEndpointRegistry;
use YangSheep\PluginHubClient\Updater\YSUpdateChecker;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 處理完整 Hub Client 的十一條 REST routes。
 */
final class YSHubRestController {

    private const NAMESPACE = 'ys-hub-client/v1';

    /**
     * Attach route registration to the WordPress REST lifecycle.
     */
    public static function init(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /**
     * 註冊 REST routes。這個方法只描述 routes，不執行 HTTP 或 business action。
     *
     * @return void
     */
    public static function register_routes(): void {
        self::register_route( '/marketplace', WP_REST_Server::READABLE, 'handle_get_marketplace', 'manage_options' );
        self::register_route( '/marketplace/refresh', WP_REST_Server::CREATABLE, 'handle_refresh_marketplace', 'manage_options' );
        self::register_route( '/plugins/install', WP_REST_Server::CREATABLE, 'handle_install_plugin', 'install_plugins' );
        self::register_route( '/plugins/update', WP_REST_Server::CREATABLE, 'handle_update_plugin', 'update_plugins' );
        self::register_route( '/plugins/activate', WP_REST_Server::CREATABLE, 'handle_activate_plugin', 'activate_plugins' );
        self::register_route( '/plugins/deactivate', WP_REST_Server::CREATABLE, 'handle_deactivate_plugin', 'activate_plugins' );
        self::register_route( '/plugins/delete', WP_REST_Server::CREATABLE, 'handle_delete_plugin', 'delete_plugins' );
        self::register_route( '/logs/clear', WP_REST_Server::CREATABLE, 'handle_clear_logs', 'manage_options' );
        self::register_route( '/settings', WP_REST_Server::CREATABLE, 'handle_save_settings', 'manage_options' );
        self::register_route( '/connection/test', WP_REST_Server::CREATABLE, 'handle_test_connection', 'manage_options' );
        self::register_route( '/site-key/generate', WP_REST_Server::CREATABLE, 'handle_generate_site_key', 'manage_options' );
    }

    /**
     * @param string $route      Route path.
     * @param string $methods    WP REST method constant.
     * @param string $callback   Controller callback.
     * @param string $capability Action-specific capability; manage_options is always required.
     */
    private static function register_route( string $route, string $methods, string $callback, string $capability ): void {
        register_rest_route(
            self::NAMESPACE,
            $route,
            array(
                'methods'             => $methods,
                'callback'            => array( __CLASS__, $callback ),
                'permission_callback' => static function () use ( $capability ) {
                    return self::authorize( $capability );
                },
            )
        );
    }

    /**
     * 取得市集外掛列表
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public static function handle_get_marketplace( WP_REST_Request $request ): WP_REST_Response {
        // 讀取本地快取
        $cached = get_site_transient( 'ys_hub_marketplace_data' );

        if ( false !== $cached && is_array( $cached ) ) {
            // 快取可能寫自舊版本或已被污染的回應，服務前一律重新正規化。
            $payload = self::normalize_marketplace_payload( $cached );
            return self::success( array_merge(
                $payload,
                array(
                    'plugins' => self::merge_local_status( $payload['plugins'] ),
                    'source'  => 'cache',
                )
            ) );
        }

        // 快取不存在 → 即時取得（管理員操作，允許同步）
        $api = YSHubApiClient::instance();

        // 取得外掛列表
        $response = $api->get_marketplace_plugins();

        if ( is_wp_error( $response ) ) {
            return self::failure( array(
                'message' => $response->get_error_message(),
                'state'   => YSCircuitBreaker::get_state(),
            ) );
        }

        // 取得分類與公告列表
        $cat_response = $api->get( YSEndpointRegistry::CATEGORIES );
        $ann_response = $api->get( YSEndpointRegistry::ANNOUNCEMENTS );

        // Hub 是外部輸入：必須在寫入快取「之前」正規化。只在輸出端正規化並不足夠——
        // 被污染的回應會在快取裡存活 6 小時，之後由防護最少的快取分支反覆送出。
        $payload = self::normalize_marketplace_payload( array(
            'plugins'       => ( isset( $response['plugins'] ) && is_array( $response['plugins'] ) ) ? $response['plugins'] : $response,
            'platforms'     => $response['platforms'] ?? null,
            'categories'    => is_array( $cat_response ) ? ( $cat_response['categories'] ?? null ) : null,
            'announcements' => is_array( $ann_response ) ? ( $ann_response['announcements'] ?? null ) : null,
        ) );

        // 快取 6 小時（包含分類和公告）
        set_site_transient( 'ys_hub_marketplace_data', $payload, 6 * HOUR_IN_SECONDS );

        return self::success( array_merge(
            $payload,
            array(
                'plugins' => self::merge_local_status( $payload['plugins'] ),
                'source'  => 'remote',
            )
        ) );
    }

    /**
     * 把 Hub 市集回應正規化成顯式 schema
     *
     * 任何非預期的 row/欄位形狀一律安全拒絕或降級為明確的安全預設值，
     * 不得產生診斷訊息、未捕捉例外，也不得以型別強轉掩蓋。
     *
     * @param array $raw 原始（或快取中的）市集資料。
     * @return array{plugins:array,platforms:array,categories:array,announcements:array}
     */
    private static function normalize_marketplace_payload( array $raw ): array {
        return array(
            'plugins'       => self::normalize_plugin_rows( $raw['plugins'] ?? null ),
            'platforms'     => self::normalize_taxonomy_rows( $raw['platforms'] ?? null ),
            'categories'    => self::normalize_taxonomy_rows( $raw['categories'] ?? null ),
            'announcements' => self::normalize_announcement_rows( $raw['announcements'] ?? null ),
        );
    }

    /**
     * 字串欄位：非字串一律退回預設值（拒絕該值，不做型別強轉）。
     *
     * @param mixed  $value   原始值。
     * @param string $default 預設值。
     * @return string
     */
    private static function normalize_string( $value, string $default = '' ): string {
        return is_string( $value ) ? $value : $default;
    }

    /**
     * class token：以允許字元集決定，HTML escaping 不是 token 的正確約束。
     *
     * @param mixed  $value   原始值。
     * @param string $default 預設值。
     * @return string
     */
    private static function normalize_token( $value, string $default = '' ): string {
        return ( is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $value ) ) ? $value : $default;
    }

    /**
     * 封閉域欄位：不在允許集合內一律退回預設值。
     *
     * @param mixed             $value   原始值。
     * @param array<int,string> $allowed 允許集合。
     * @param string            $default 預設值。
     * @return string
     */
    private static function normalize_enum( $value, array $allowed, string $default ): string {
        return ( is_string( $value ) && in_array( $value, $allowed, true ) ) ? $value : $default;
    }

    /**
     * 絕對 https 連結；其餘一律拒絕為空字串。
     *
     * @param mixed $value 原始值。
     * @return string
     */
    private static function normalize_https_url( $value ): string {
        if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 2048 ) {
            return '';
        }
        $parts  = parse_url( $value );
        $scheme = is_array( $parts ) && is_string( $parts['scheme'] ?? null ) ? strtolower( $parts['scheme'] ) : '';
        $host   = is_array( $parts ) && is_string( $parts['host'] ?? null ) ? $parts['host'] : '';

        return ( 'https' === $scheme && '' !== $host ) ? $value : '';
    }

    /**
     * 外掛 row 正規化。回傳一定是 list，且每列欄位型別固定。
     *
     * @param mixed $rows 原始 rows。
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_plugin_rows( $rows ): array {
        if ( ! is_array( $rows ) ) {
            return array();
        }

        $normalized = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue; // 非陣列 row 無法描述一個外掛。
            }
            // 身分用 '' !== trim() 判斷，不用 empty()：slug '0' 是合法識別字。
            $slug = self::normalize_string( $row['slug'] ?? null );
            if ( '' === trim( $slug ) ) {
                continue;
            }

            $normalized[] = array(
                'slug'             => $slug,
                'name'             => self::normalize_string( $row['name'] ?? null, $slug ),
                'version'          => self::normalize_string( $row['version'] ?? null ),
                'description'      => self::normalize_string( $row['description'] ?? null ),
                'icon'             => self::normalize_token( $row['icon'] ?? null, 'dashicons-admin-plugins' ),
                'category'         => self::normalize_string( $row['category'] ?? null ),
                'category_label'   => self::normalize_string( $row['category_label'] ?? null ),
                'platform'         => self::normalize_string( $row['platform'] ?? null ),
                'platform_label'   => self::normalize_string( $row['platform_label'] ?? null ),
                'price_type'       => self::normalize_enum( $row['price_type'] ?? null, array( 'free', 'paid' ), 'free' ),
                'price_amount'     => self::normalize_string( $row['price_amount'] ?? null ),
                'external_url'     => self::normalize_https_url( $row['external_url'] ?? null ),
                'info_url'         => self::normalize_https_url( $row['info_url'] ?? null ),
                'status'           => self::normalize_enum( $row['status'] ?? null, array( 'active', 'installed', 'not_installed' ), 'not_installed' ),
                'local_version'    => self::normalize_string( $row['local_version'] ?? null ),
                'update_available' => ! empty( $row['update_available'] ),
            );
        }

        return $normalized;
    }

    /**
     * 平台／分類 row 正規化。
     *
     * @param mixed $rows 原始 rows。
     * @return array<int,array<string,string>>
     */
    private static function normalize_taxonomy_rows( $rows ): array {
        if ( ! is_array( $rows ) ) {
            return array();
        }

        $normalized = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $slug = self::normalize_string( $row['slug'] ?? null );
            if ( '' === trim( $slug ) ) {
                continue;
            }
            $normalized[] = array(
                'slug' => $slug,
                'name' => self::normalize_string( $row['name'] ?? null, $slug ),
                'icon' => self::normalize_token( $row['icon'] ?? null ),
            );
        }

        return $normalized;
    }

    /**
     * 公告 row 正規化；type 是封閉域，直接決定前端的 class 與圖示。
     *
     * @param mixed $rows 原始 rows。
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_announcement_rows( $rows ): array {
        if ( ! is_array( $rows ) ) {
            return array();
        }

        $normalized = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $id = $row['id'] ?? null;
            if ( ! is_string( $id ) && ! is_int( $id ) ) {
                continue; // 沒有可辨識的 id 就無法被關閉，等同無效公告。
            }
            $normalized[] = array(
                'id'        => (string) $id,
                'type'      => self::normalize_enum( $row['type'] ?? null, array( 'info', 'warning', 'success', 'danger' ), 'info' ),
                'title'     => self::normalize_string( $row['title'] ?? null ),
                'content'   => self::normalize_string( $row['content'] ?? null ),
                'is_pinned' => ! empty( $row['is_pinned'] ),
            );
        }

        return $normalized;
    }

    /**
     * 安裝外掛
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_install_plugin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $slug = self::required_string_param( $request, 'slug' );
        if ( is_wp_error( $slug ) ) {
            return $slug;
        }
        $version = self::required_string_param( $request, 'version' );
        if ( is_wp_error( $version ) ) {
            return $version;
        }

        $result = YSMarketplaceInstaller::install( $slug, $version );

        if ( is_wp_error( $result ) ) {
            return self::failure( array(
                'message' => $result->get_error_message(),
            ) );
        }

        $plugin_data = self::get_updated_plugin_data( $slug, $version );

        return self::success( array(
            'message' => sprintf(
                /* translators: %s: 外掛 slug */
                __( '外掛 %s 安裝成功', 'ys-plugin-hub-client' ),
                $slug
            ),
            'plugin'  => $plugin_data,
        ) );
    }

    /**
     * 更新外掛
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_update_plugin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $slug = self::required_string_param( $request, 'slug' );
        if ( is_wp_error( $slug ) ) {
            return $slug;
        }
        $version = self::required_string_param( $request, 'version' );
        if ( is_wp_error( $version ) ) {
            return $version;
        }

        $result = YSMarketplaceInstaller::update( $slug, $version );

        if ( is_wp_error( $result ) ) {
            return self::failure( array(
                'message' => $result->get_error_message(),
            ) );
        }

        // 回傳更新後的外掛完整狀態（讓 JS 整張卡片重新渲染）
        $plugin_data = self::get_updated_plugin_data( $slug, $version );

        return self::success( array(
            'message' => sprintf(
                /* translators: %s: 外掛 slug */
                __( '外掛 %s 更新成功', 'ys-plugin-hub-client' ),
                $slug
            ),
            'plugin'  => $plugin_data,
        ) );
    }

    /**
     * 儲存連線設定
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_save_settings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $site_key = self::string_param( $request, 'site_key', true );
        if ( is_wp_error( $site_key ) ) {
            return $site_key;
        }
        $auto_check = self::required_string_param( $request, 'auto_check' );
        if ( is_wp_error( $auto_check ) ) {
            return $auto_check;
        }
        if ( ! in_array( $auto_check, array( 'yes', 'no' ), true ) ) {
            return new WP_Error(
                'ys_hub_invalid_auto_check',
                __( 'auto_check 必須是 yes 或 no', 'ys-plugin-hub-client' ),
                array( 'status' => 400 )
            );
        }

        $repo   = YSHubClientSettingsRepo::instance();
        $stored = $repo->set_many( array(
            'ys_hub_site_key'   => $site_key,
            'ys_hub_auto_check' => $auto_check,
        ) );

        // 重置 ApiClient 單例（site_key 可能已變更）
        YSHubApiClient::reset_instance();

        // 寫入未落地就回報成功，會讓管理者以為設定已生效。必須 fail closed。
        if ( true !== $stored ) {
            return self::failure( array(
                'message' => __( '設定未能寫入資料表，請確認 Hub 資料表已建立後再試一次', 'ys-plugin-hub-client' ),
            ) );
        }

        return self::success( array(
            'message' => __( '設定已儲存', 'ys-plugin-hub-client' ),
        ) );
    }

    /**
     * 測試連線
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public static function handle_test_connection( WP_REST_Request $request ): WP_REST_Response {
        $api      = YSHubApiClient::instance();
        $response = $api->test_connection();

        if ( is_wp_error( $response ) ) {
            return self::failure( array(
                'message' => $response->get_error_message(),
                'state'   => YSCircuitBreaker::get_state(),
                'label'   => YSCircuitBreaker::get_state_label(),
            ) );
        }

        return self::success( array(
            'message' => __( '連線成功', 'ys-plugin-hub-client' ),
            'state'   => YSCircuitBreaker::get_state(),
            'label'   => YSCircuitBreaker::get_state_label(),
            'data'    => $response,
        ) );
    }

    /**
     * 產生 Site Key
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public static function handle_generate_site_key( WP_REST_Request $request ): WP_REST_Response {
        $api      = YSHubApiClient::instance();
        $response = $api->post(
            YSEndpointRegistry::GENERATE_SITE_KEY,
            array(
                'site_url'  => get_site_url(),
                'site_name' => get_bloginfo( 'name' ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return self::failure( array(
                'message' => $response->get_error_message(),
            ) );
        }

        $site_key = $response['site_key'] ?? '';
        if ( ! is_string( $site_key ) || empty( $site_key ) ) {
            return self::failure( array(
                'message' => __( 'Hub 未回傳 Site Key', 'ys-plugin-hub-client' ),
            ) );
        }

        // 儲存到自訂資料表
        $repo   = YSHubClientSettingsRepo::instance();
        $stored = $repo->set( 'ys_hub_site_key', $site_key );

        // 重置 ApiClient 單例
        YSHubApiClient::reset_instance();

        // 未落地的 Site Key 不得回傳：否則畫面顯示的金鑰與實際生效的不一致。
        if ( true !== $stored ) {
            return self::failure( array(
                'message' => __( 'Site Key 未能寫入資料表，請確認 Hub 資料表已建立後再試一次', 'ys-plugin-hub-client' ),
            ) );
        }

        return self::success( array(
            'message'  => __( 'Site Key 已產生並儲存', 'ys-plugin-hub-client' ),
            'site_key' => $site_key,
        ) );
    }

    /**
     * 強制刷新市集
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public static function handle_refresh_marketplace( WP_REST_Request $request ): WP_REST_Response {
        // 清除市集快取（新舊 key 都清）
        delete_site_transient( 'ys_hub_marketplace_data' );
        delete_site_transient( 'ys_hub_marketplace_plugins' );

        // 同時刷新更新資料
        YSUpdateChecker::force_refresh();

        // 重新取得市集資料
        $api      = YSHubApiClient::instance();
        $response = $api->get_marketplace_plugins();

        if ( is_wp_error( $response ) ) {
            return self::failure( array(
                'message' => $response->get_error_message(),
                'state'   => YSCircuitBreaker::get_state(),
            ) );
        }

        // 取得分類與公告列表
        $cat_response = $api->get( YSEndpointRegistry::CATEGORIES );
        $ann_response = $api->get( YSEndpointRegistry::ANNOUNCEMENTS );

        // 與 handle_get_marketplace 走同一條 ingress，兩條路徑不得對「合法回應」有不同定義。
        $payload = self::normalize_marketplace_payload( array(
            'plugins'       => ( isset( $response['plugins'] ) && is_array( $response['plugins'] ) ) ? $response['plugins'] : $response,
            'platforms'     => $response['platforms'] ?? null,
            'categories'    => is_array( $cat_response ) ? ( $cat_response['categories'] ?? null ) : null,
            'announcements' => is_array( $ann_response ) ? ( $ann_response['announcements'] ?? null ) : null,
        ) );

        set_site_transient( 'ys_hub_marketplace_data', $payload, 6 * HOUR_IN_SECONDS );

        return self::success( array_merge(
            $payload,
            array(
                'plugins' => self::merge_local_status( $payload['plugins'] ),
                'message' => __( '市集資料已刷新', 'ys-plugin-hub-client' ),
            )
        ) );
    }

    /**
     * 啟用外掛
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_activate_plugin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $slug = self::required_string_param( $request, 'slug' );
        if ( is_wp_error( $slug ) ) {
            return $slug;
        }

        // 找到外掛主檔案
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_file = '';
        foreach ( get_plugins() as $file => $data ) {
            if ( dirname( $file ) === $slug ) {
                $plugin_file = $file;
                break;
            }
        }

        if ( empty( $plugin_file ) ) {
            return self::failure( array( 'message' => sprintf( __( '找不到外掛 %s', 'ys-plugin-hub-client' ), $slug ) ) );
        }

        $result = activate_plugin( $plugin_file );

        if ( is_wp_error( $result ) ) {
            YSHubClientLogRepo::error( 'activate', sprintf( '啟用 %s 失敗：%s', $slug, $result->get_error_message() ) );
            return self::failure( array( 'message' => $result->get_error_message() ) );
        }

        YSHubClientLogRepo::success( 'activate', sprintf( '外掛 %s 已啟用', $slug ) );

        $plugin_data = self::get_updated_plugin_data( $slug, '' );

        return self::success( array(
            'message' => sprintf( __( '外掛 %s 已啟用', 'ys-plugin-hub-client' ), $slug ),
            'plugin'  => $plugin_data,
        ) );
    }

    /**
     * 停用外掛
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_deactivate_plugin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $slug = self::required_string_param( $request, 'slug' );
        if ( is_wp_error( $slug ) ) {
            return $slug;
        }

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_file = '';
        foreach ( get_plugins() as $file => $data ) {
            if ( dirname( $file ) === $slug ) {
                $plugin_file = $file;
                break;
            }
        }

        if ( empty( $plugin_file ) ) {
            return self::failure( array( 'message' => sprintf( __( '找不到外掛 %s', 'ys-plugin-hub-client' ), $slug ) ) );
        }

        // 檢查是否為最後一個啟用的 YS 外掛（停用後市集會消失）
        $active_ys_count = 0;
        foreach ( get_plugins() as $file => $data ) {
            $s = dirname( $file );
            if ( ( 0 === strpos( $s, 'ys-' ) || 0 === strpos( $s, 'yangsheep-' ) ) && is_plugin_active( $file ) ) {
                $active_ys_count++;
            }
        }

        $is_last = ( 1 === $active_ys_count );

        deactivate_plugins( $plugin_file );

        YSHubClientLogRepo::success( 'deactivate', sprintf( '外掛 %s 已停用', $slug ) );

        $plugin_data = self::get_updated_plugin_data( $slug, '' );

        return self::success( array(
            'message'  => sprintf( __( '外掛 %s 已停用', 'ys-plugin-hub-client' ), $slug ),
            'plugin'   => $plugin_data,
            'is_last'  => $is_last,
            'redirect' => $is_last ? admin_url( 'plugins.php' ) : '',
        ) );
    }

    /**
     * 刪除外掛
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_delete_plugin( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $slug = self::required_string_param( $request, 'slug' );
        if ( is_wp_error( $slug ) ) {
            return $slug;
        }

        if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'delete_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $plugin_file = '';
        foreach ( get_plugins() as $file => $data ) {
            if ( dirname( $file ) === $slug ) {
                $plugin_file = $file;
                break;
            }
        }

        if ( empty( $plugin_file ) ) {
            return self::failure( array( 'message' => sprintf( __( '找不到外掛 %s', 'ys-plugin-hub-client' ), $slug ) ) );
        }

        // 先停用
        if ( is_plugin_active( $plugin_file ) ) {
            deactivate_plugins( $plugin_file );
        }

        $result = delete_plugins( array( $plugin_file ) );

        if ( is_wp_error( $result ) ) {
            YSHubClientLogRepo::error( 'delete', sprintf( '刪除 %s 失敗：%s', $slug, $result->get_error_message() ) );
            return self::failure( array( 'message' => $result->get_error_message() ) );
        }

        YSHubClientLogRepo::success( 'delete', sprintf( '外掛 %s 已刪除', $slug ) );

        // 檢查是否還有 YS 外掛存在
        $remaining_ys = 0;
        foreach ( get_plugins() as $file => $data ) {
            $s = dirname( $file );
            if ( ( 0 === strpos( $s, 'ys-' ) || 0 === strpos( $s, 'yangsheep-' ) ) && is_plugin_active( $file ) ) {
                $remaining_ys++;
            }
        }

        return self::success( array(
            'message'  => sprintf( __( '外掛 %s 已刪除', 'ys-plugin-hub-client' ), $slug ),
            'redirect' => ( 0 === $remaining_ys ) ? admin_url( 'plugins.php' ) : '',
        ) );
    }

    /**
     * 清除全部日誌
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public static function handle_clear_logs( WP_REST_Request $request ): WP_REST_Response {
        $log_repo = YSHubClientLogRepo::instance();
        $log_repo->clear_all();

        return self::success( array(
            'message' => __( '日誌已清除', 'ys-plugin-hub-client' ),
        ) );
    }

    /**
     * 合併本地安裝/啟用狀態到外掛列表
     *
     * @param array $plugins Hub 回傳的外掛列表。
     * @return array 合併後的外掛列表。
     */
    /**
     * 取得更新/安裝/啟用後的外掛完整狀態
     *
     * @param string $slug    外掛 slug
     * @param string $version 版本號（空字串時從本地讀取）
     * @return array 外掛資料（含 status, local_version, update_available）
     */
    private static function get_updated_plugin_data( string $slug, string $version ): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = get_option( 'active_plugins', array() );

        $local_version = '';
        $is_active     = false;
        $plugin_name   = $slug;

        foreach ( $all_plugins as $file => $data ) {
            if ( dirname( $file ) === $slug ) {
                $local_version = self::normalize_string( is_array( $data ) ? ( $data['Version'] ?? null ) : null, '0.0.0' );
                $is_active     = is_array( $active_plugins ) && in_array( $file, $active_plugins, true );
                $plugin_name   = self::normalize_string( is_array( $data ) ? ( $data['Name'] ?? null ) : null, $slug );
                break;
            }
        }

        // 從 Hub 快取取得遠端版本（如果有的話）。快取內容一律先正規化，因為這裡的
        // 回應會直接被前端當成一張卡片重繪。
        $remote_version = $version;
        $cached         = get_site_transient( 'ys_hub_marketplace_data' );
        $cached_rows    = is_array( $cached ) ? self::normalize_plugin_rows( $cached['plugins'] ?? null ) : array();

        foreach ( $cached_rows as $row ) {
            if ( $row['slug'] !== $slug ) {
                continue;
            }
            if ( '' === $remote_version ) {
                $remote_version = $row['version'];
            }

            // 只投射正規化後的已知欄位，不把整列未驗證的遠端資料合併進回應。
            return array_merge(
                $row,
                array(
                    'status'           => $is_active ? 'active' : 'installed',
                    'local_version'    => $local_version,
                    'update_available' => version_compare( $row['version'], $local_version, '>' ),
                )
            );
        }

        return array(
            'slug'             => $slug,
            'name'             => $plugin_name,
            'version'          => '' !== $remote_version ? $remote_version : $local_version,
            'status'           => $is_active ? 'active' : 'installed',
            'local_version'    => $local_version,
            'update_available' => false,
            'price_type'       => 'free',
        );
    }

    private static function merge_local_status( array $plugins ): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $local_plugins  = get_plugins();
        $active_plugins = get_option( 'active_plugins', array() );

        // 建立 slug → plugin_file 對照表
        $local_map = array();
        foreach ( $local_plugins as $file => $data ) {
            $slug = dirname( $file );
            if ( '.' === $slug ) {
                $slug = basename( $file, '.php' );
            }
            $local_map[ $slug ] = array(
                'file'    => $file,
                'version' => self::normalize_string( $data['Version'] ?? null, '0.0.0' ),
                'active'  => is_array( $active_plugins ) && in_array( $file, $active_plugins, true ),
            );
        }

        // 先正規化再合併：這個方法也可能拿到快取中的舊形狀，rows 未經證明時
        // 一個非字串 slug 會讓 isset() 丟 TypeError，非字串 version 會讓
        // version_compare() 丟 TypeError，而數值 version 會被靜默強轉。
        $plugins = self::normalize_plugin_rows( $plugins );

        foreach ( $plugins as &$plugin ) {
            $slug = $plugin['slug'];

            if ( isset( $local_map[ $slug ] ) ) {
                $local                      = $local_map[ $slug ];
                $plugin['status']           = $local['active'] ? 'active' : 'installed';
                $plugin['local_version']    = $local['version'];
                $plugin['update_available'] = version_compare( $plugin['version'], $local['version'], '>' );
            } else {
                $plugin['status']           = 'not_installed';
                $plugin['local_version']    = '';
                $plugin['update_available'] = false;
            }
        }
        unset( $plugin );

        return $plugins;
    }

    /**
     * REST permission boundary: every route requires manage_options and the
     * action-specific plugin capability where applicable.
     *
     * @param string $capability Action-specific capability.
     * @return true|WP_Error
     */
    private static function authorize( string $capability ): bool|WP_Error {
        foreach ( array_unique( array( 'manage_options', $capability ) ) as $required ) {
            if ( current_user_can( $required ) ) {
                continue;
            }

            return new WP_Error(
                'rest_forbidden',
                __( '權限不足', 'ys-plugin-hub-client' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        return true;
    }

    /**
     * Read one parsed JSON/query parameter without applying form-style unslash.
     *
     * @param WP_REST_Request $request     REST request.
     * @param string          $name        Parameter name.
     * @param bool            $allow_empty Whether an empty string is valid.
     * @return string|WP_Error
     */
    private static function string_param( WP_REST_Request $request, string $name, bool $allow_empty = false ): string|WP_Error {
        if ( ! $request->has_param( $name ) ) {
            return self::invalid_param( $name, __( '缺少必要參數', 'ys-plugin-hub-client' ) );
        }

        $value = $request->get_param( $name );
        if ( ! is_string( $value ) ) {
            return self::invalid_param( $name, __( '參數格式無效', 'ys-plugin-hub-client' ) );
        }

        $value = sanitize_text_field( $value );
        if ( ! $allow_empty && '' === trim( $value ) ) {
            return self::invalid_param( $name, __( '缺少必要參數', 'ys-plugin-hub-client' ) );
        }

        return $value;
    }

    /** @return string|WP_Error */
    private static function required_string_param( WP_REST_Request $request, string $name ): string|WP_Error {
        return self::string_param( $request, $name, false );
    }

    private static function invalid_param( string $name, string $message ): WP_Error {
        return new WP_Error(
            'rest_invalid_param',
            $message,
            array(
                'status' => 400,
                'param'  => $name,
            )
        );
    }

    private static function success( array $data, int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response(
            array(
                'success' => true,
                'data'    => $data,
            ),
            $status
        );
    }

    private static function failure( array $data, int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response(
            array(
                'success' => false,
                'data'    => $data,
            ),
            $status
        );
    }
}
