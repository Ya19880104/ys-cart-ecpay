<?php
/**
 * YSHubAjaxHandler - 客戶端 AJAX 處理
 *
 * 所有後台操作走 wp_ajax_*，禁止 form POST。
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

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 處理所有客戶端 AJAX 請求
 */
final class YSHubAjaxHandler {

    /**
     * 初始化 AJAX hooks
     *
     * @return void
     */
    public static function init(): void {
        $actions = array(
            'ys_hub_client_get_marketplace',
            'ys_hub_client_install_plugin',
            'ys_hub_client_update_plugin',
            'ys_hub_client_save_settings',
            'ys_hub_client_test_connection',
            'ys_hub_client_generate_site_key',
            'ys_hub_client_refresh_marketplace',
            'ys_hub_client_activate_plugin',
            'ys_hub_client_deactivate_plugin',
            'ys_hub_client_delete_plugin',
            'ys_hub_client_clear_logs',
        );

        foreach ( $actions as $action ) {
            $method = str_replace( 'ys_hub_client_', 'handle_', $action );
            add_action( "wp_ajax_{$action}", array( __CLASS__, $method ) );
        }
    }

    /**
     * 取得市集外掛列表
     *
     * @return void
     */
    public static function handle_get_marketplace(): void {
        self::verify_request( 'ys_hub_marketplace_nonce' );

        // 讀取本地快取
        $cached = get_site_transient( 'ys_hub_marketplace_data' );

        if ( false !== $cached && is_array( $cached ) ) {
            // 快取可能寫自舊版本或已被污染的回應，服務前一律重新正規化。
            $payload = self::normalize_marketplace_payload( $cached );
            wp_send_json_success( array_merge(
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
            wp_send_json_error( array(
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

        wp_send_json_success( array_merge(
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
     * @return void
     */
    public static function handle_install_plugin(): void {
        self::verify_request( 'ys_hub_marketplace_nonce' );

        if ( ! current_user_can( 'install_plugins' ) ) {
            wp_send_json_error( array(
                'message' => __( '權限不足', 'ys-plugin-hub-client' ),
            ) );
        }

        $slug    = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
        $version = sanitize_text_field( wp_unslash( $_POST['version'] ?? '' ) );

        if ( empty( $slug ) || empty( $version ) ) {
            wp_send_json_error( array(
                'message' => __( '缺少必要參數', 'ys-plugin-hub-client' ),
            ) );
        }

        $result = YSMarketplaceInstaller::install( $slug, $version );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
            ) );
        }

        $plugin_data = self::get_updated_plugin_data( $slug, $version );

        wp_send_json_success( array(
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
     * @return void
     */
    public static function handle_update_plugin(): void {
        self::verify_request( 'ys_hub_marketplace_nonce' );

        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_send_json_error( array(
                'message' => __( '權限不足', 'ys-plugin-hub-client' ),
            ) );
        }

        $slug    = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
        $version = sanitize_text_field( wp_unslash( $_POST['version'] ?? '' ) );

        if ( empty( $slug ) || empty( $version ) ) {
            wp_send_json_error( array(
                'message' => __( '缺少必要參數', 'ys-plugin-hub-client' ),
            ) );
        }

        $result = YSMarketplaceInstaller::update( $slug, $version );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array(
                'message' => $result->get_error_message(),
            ) );
        }

        // 回傳更新後的外掛完整狀態（讓 JS 整張卡片重新渲染）
        $plugin_data = self::get_updated_plugin_data( $slug, $version );

        wp_send_json_success( array(
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
     * @return void
     */
    public static function handle_save_settings(): void {
        self::verify_request( 'ys_hub_marketplace_nonce' );

        $site_key   = sanitize_text_field( wp_unslash( $_POST['site_key'] ?? '' ) );
        $auto_check = sanitize_text_field( wp_unslash( $_POST['auto_check'] ?? 'no' ) );

        // 驗證 auto_check 值
        $auto_check = in_array( $auto_check, array( 'yes', 'no' ), true ) ? $auto_check : 'no';

        $repo   = YSHubClientSettingsRepo::instance();
        $stored = $repo->set_many( array(
            'ys_hub_site_key'   => $site_key,
            'ys_hub_auto_check' => $auto_check,
        ) );

        // 重置 ApiClient 單例（site_key 可能已變更）
        YSHubApiClient::reset_instance();

        // 寫入未落地就回報成功，會讓管理者以為設定已生效。必須 fail closed。
        if ( true !== $stored ) {
            wp_send_json_error( array(
                'message' => __( '設定未能寫入資料表，請確認 Hub 資料表已建立後再試一次', 'ys-plugin-hub-client' ),
            ) );
        }

        wp_send_json_success( array(
            'message' => __( '設定已儲存', 'ys-plugin-hub-client' ),
        ) );
    }

    /**
     * 測試連線
     *
     * @return void
     */
    public static function handle_test_connection(): void {
        self::verify_request( 'ys_hub_marketplace_nonce' );

        $api      = YSHubApiClient::instance();
        $response = $api->test_connection();

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array(
                'message' => $response->get_error_message(),
                'state'   => YSCircuitBreaker::get_state(),
                'label'   => YSCircuitBreaker::get_state_label(),
            ) );
        }

        wp_send_json_success( array(
            'message' => __( '連線成功', 'ys-plugin-hub-client' ),
            'state'   => YSCircuitBreaker::get_state(),
            'label'   => YSCircuitBreaker::get_state_label(),
            'data'    => $response,
        ) );
    }

    /**
     * 產生 Site Key
     *
     * @return void
     */
    public static function handle_generate_site_key(): void {
        self::verify_request( 'ys_hub_marketplace_nonce' );

        $api      = YSHubApiClient::instance();
        $response = $api->post(
            YSEndpointRegistry::GENERATE_SITE_KEY,
            array(
                'site_url'  => get_site_url(),
                'site_name' => get_bloginfo( 'name' ),
            )
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array(
                'message' => $response->get_error_message(),
            ) );
        }

        $site_key = $response['site_key'] ?? '';
        if ( ! is_string( $site_key ) || empty( $site_key ) ) {
            wp_send_json_error( array(
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
            wp_send_json_error( array(
                'message' => __( 'Site Key 未能寫入資料表，請確認 Hub 資料表已建立後再試一次', 'ys-plugin-hub-client' ),
            ) );
        }

        wp_send_json_success( array(
            'message'  => __( 'Site Key 已產生並儲存', 'ys-plugin-hub-client' ),
            'site_key' => $site_key,
        ) );
    }

    /**
     * 強制刷新市集
     *
     * @return void
     */
    public static function handle_refresh_marketplace(): void {
        self::verify_request( 'ys_hub_marketplace_nonce' );

        // 清除市集快取（新舊 key 都清）
        delete_site_transient( 'ys_hub_marketplace_data' );
        delete_site_transient( 'ys_hub_marketplace_plugins' );

        // 同時刷新更新資料
        YSUpdateChecker::force_refresh();

        // 重新取得市集資料
        $api      = YSHubApiClient::instance();
        $response = $api->get_marketplace_plugins();

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array(
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

        wp_send_json_success( array_merge(
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
     * @return void
     */
    public static function handle_activate_plugin(): void {
        self::verify_request( 'ys_hub_marketplace_nonce' );

        if ( ! current_user_can( 'activate_plugins' ) ) {
            wp_send_json_error( array( 'message' => __( '權限不足', 'ys-plugin-hub-client' ) ) );
        }

        $slug = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
        if ( empty( $slug ) ) {
            wp_send_json_error( array( 'message' => __( '缺少外掛 slug', 'ys-plugin-hub-client' ) ) );
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
            wp_send_json_error( array( 'message' => sprintf( __( '找不到外掛 %s', 'ys-plugin-hub-client' ), $slug ) ) );
        }

        $result = activate_plugin( $plugin_file );

        if ( is_wp_error( $result ) ) {
            YSHubClientLogRepo::error( 'activate', sprintf( '啟用 %s 失敗：%s', $slug, $result->get_error_message() ) );
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        YSHubClientLogRepo::success( 'activate', sprintf( '外掛 %s 已啟用', $slug ) );

        $plugin_data = self::get_updated_plugin_data( $slug, '' );

        wp_send_json_success( array(
            'message' => sprintf( __( '外掛 %s 已啟用', 'ys-plugin-hub-client' ), $slug ),
            'plugin'  => $plugin_data,
        ) );
    }

    /**
     * 停用外掛
     *
     * @return void
     */
    public static function handle_deactivate_plugin(): void {
        self::verify_request( 'ys_hub_marketplace_nonce' );

        if ( ! current_user_can( 'activate_plugins' ) ) {
            wp_send_json_error( array( 'message' => __( '權限不足', 'ys-plugin-hub-client' ) ) );
        }

        $slug = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( empty( $slug ) ) {
            wp_send_json_error( array( 'message' => __( '缺少外掛 slug', 'ys-plugin-hub-client' ) ) );
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
            wp_send_json_error( array( 'message' => sprintf( __( '找不到外掛 %s', 'ys-plugin-hub-client' ), $slug ) ) );
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

        wp_send_json_success( array(
            'message'  => sprintf( __( '外掛 %s 已停用', 'ys-plugin-hub-client' ), $slug ),
            'plugin'   => $plugin_data,
            'is_last'  => $is_last,
            'redirect' => $is_last ? admin_url( 'plugins.php' ) : '',
        ) );
    }

    /**
     * 刪除外掛
     *
     * @return void
     */
    public static function handle_delete_plugin(): void {
        self::verify_request( 'ys_hub_marketplace_nonce' );

        if ( ! current_user_can( 'delete_plugins' ) ) {
            wp_send_json_error( array( 'message' => __( '權限不足', 'ys-plugin-hub-client' ) ) );
        }

        $slug = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( empty( $slug ) ) {
            wp_send_json_error( array( 'message' => __( '缺少外掛 slug', 'ys-plugin-hub-client' ) ) );
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
            wp_send_json_error( array( 'message' => sprintf( __( '找不到外掛 %s', 'ys-plugin-hub-client' ), $slug ) ) );
        }

        // 先停用
        if ( is_plugin_active( $plugin_file ) ) {
            deactivate_plugins( $plugin_file );
        }

        $result = delete_plugins( array( $plugin_file ) );

        if ( is_wp_error( $result ) ) {
            YSHubClientLogRepo::error( 'delete', sprintf( '刪除 %s 失敗：%s', $slug, $result->get_error_message() ) );
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
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

        wp_send_json_success( array(
            'message'  => sprintf( __( '外掛 %s 已刪除', 'ys-plugin-hub-client' ), $slug ),
            'redirect' => ( 0 === $remaining_ys ) ? admin_url( 'plugins.php' ) : '',
        ) );
    }

    /**
     * 清除全部日誌
     *
     * @return void
     */
    public static function handle_clear_logs(): void {
        self::verify_request( 'ys_hub_marketplace_nonce' );

        $log_repo = YSHubClientLogRepo::instance();
        $log_repo->clear_all();

        wp_send_json_success( array(
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
     * 驗證 AJAX 請求（nonce + 權限）
     *
     * @param string $nonce_action Nonce action 名稱
     * @return void
     */
    private static function verify_request( string $nonce_action ): void {
        // 檢查 nonce
        if ( ! check_ajax_referer( $nonce_action, 'nonce', false ) ) {
            wp_send_json_error( array(
                'message' => __( '安全驗證失敗，請重新整理頁面', 'ys-plugin-hub-client' ),
            ), 403 );
        }

        // 檢查權限
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array(
                'message' => __( '權限不足', 'ys-plugin-hub-client' ),
            ), 403 );
        }
    }
}
