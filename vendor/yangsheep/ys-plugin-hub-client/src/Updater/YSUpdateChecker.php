<?php
/**
 * YSUpdateChecker - 異步更新檢查器
 *
 * 核心原則：pre_set_site_transient_update_plugins 中永遠不做同步 HTTP。
 * 只讀快取，過期時排程背景 Cron 更新。
 *
 * @package YangSheep\PluginHubClient\Updater
 */

namespace YangSheep\PluginHubClient\Updater;

use YangSheep\PluginHubClient\Http\YSCircuitBreaker;
use YangSheep\PluginHubClient\Http\YSHubApiClient;
use YangSheep\PluginHubClient\YSPluginHubClient;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 完全異步的更新檢查器
 *
 * 流程：
 * 1. pre_set_site_transient_update_plugins 觸發
 * 2. 讀取快取 (site_transient: ys_hub_update_data, TTL 12h)
 * 3. 有快取 → 直接注入 transient → 回傳（0ms）
 * 4. 無快取且沒有排程 → wp_schedule_single_event(time(), 'ys_hub_bg_check')
 * 5. 直接回傳原 transient（不等 HTTP）
 * 6. WP Cron 背景觸發 → 呼叫 Hub → 更新快取
 */
class YSUpdateChecker {

    /**
     * 更新資料快取 key
     *
     * @var string
     */
    private const CACHE_KEY = 'ys_hub_update_data';

    /**
     * 快取 TTL（秒）— 12 小時
     *
     * @var int
     */
    private const CACHE_TTL = 43200;

    /**
     * 背景排程 flag transient
     *
     * @var string
     */
    private const BG_LOCK_KEY = 'ys_hub_bg_check_scheduled';

    /**
     * 初始化 hooks
     *
     * @return void
     */
    public static function init(): void {
        // 注入更新資訊（只讀快取，零 HTTP）
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update_data' ) );

        // 攔截 plugins_api 提供外掛詳細資訊
        add_filter( 'plugins_api', array( __CLASS__, 'plugins_api_filter' ), 10, 3 );
    }

    /**
     * 注入更新資料到 update_plugins transient
     *
     * **關鍵**：此方法中永遠不做同步 HTTP 請求。
     *
     * @param object $transient WordPress update_plugins transient
     * @return object
     */
    public static function inject_update_data( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        // 讀取快取
        $cached = get_site_transient( self::CACHE_KEY );

        if ( false === $cached || ! is_array( $cached ) ) {
            // 快取不存在或過期 → 只排程背景工作，filter 路徑永遠不做 HTTP。
            self::schedule_background_check();
            return $transient;
        }

        // 有快取 → 注入更新資訊
        $ys_plugins = YSPluginHubClient::detect_ys_plugins();

        foreach ( $cached as $slug => $update_data ) {
            // map key 決定安裝目標（plugin file / 本地版本），非字串 key 不可能對應已安裝外掛。
            if ( ! is_string( $slug ) || ! isset( $ys_plugins[ $slug ] ) ) {
                continue;
            }

            // 防禦性再驗：快取可能來自舊版本或已被污染。row 自己的 slug 必須等於 map key，
            // 否則同一筆更新會有兩個互相矛盾的身分權威——安裝目標取自 key，顯示身分取自 row。
            $update_data = YSPluginInfo::canonicalize_row( $update_data, $slug );
            if ( null === $update_data ) {
                continue;
            }

            $local_version  = $ys_plugins[ $slug ]['version'];
            $remote_version = $update_data['version'];
            $plugin_file    = $ys_plugins[ $slug ]['file'];

            if ( version_compare( $remote_version, $local_version, '>' ) ) {
                // 有新版本 → 加入 response（更新列表）
                $transient->response[ $plugin_file ] = YSPluginInfo::to_update_object( $update_data, $plugin_file );
            } else {
                // 已是最新 → 加入 no_update
                $transient->no_update[ $plugin_file ] = YSPluginInfo::to_update_object( $update_data, $plugin_file );
            }
        }

        return $transient;
    }

    /**
     * 攔截 plugins_api 提供 YS 外掛的詳細資訊
     *
     * @param false|object|array $result 預設結果
     * @param string             $action API 動作
     * @param object             $args   查詢參數
     * @return false|object
     */
    public static function plugins_api_filter( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        $slug = $args->slug ?? '';
        // 此 filter 對每個 plugins_api 呼叫者都會執行；非字串 slug 不得進入字串函式。
        if ( ! is_string( $slug ) || '' === $slug ) {
            return $result;
        }

        // 只處理 YS 系列外掛
        if ( 0 !== strpos( $slug, 'ys-' ) && 0 !== strpos( $slug, 'yangsheep-' ) ) {
            return $result;
        }

        // 從快取讀取外掛資訊
        $cached = get_site_transient( self::CACHE_KEY );
        if ( ! is_array( $cached ) || ! isset( $cached[ $slug ] ) ) {
            return $result;
        }

        // 防禦性再驗：非正規 row 不得進入型別化對應，且回傳的資訊必須確實描述
        // 呼叫端指名的那個外掛（row slug 必須等於 requested slug）。
        $plugin_data = YSPluginInfo::canonicalize_row( $cached[ $slug ], $slug );
        if ( null === $plugin_data ) {
            return $result;
        }

        return YSPluginInfo::from_hub_response( $plugin_data );
    }

    /**
     * 將 Hub 回應正規化成可信任的 row map
     *
     * Hub 是外部輸入：任何 row 都必須先證明形狀，才可以進入快取。這裡同時把
     * map key 綁回 row 自己的 slug，避免 list 形狀在 array_merge 重編號後
     * 失去 slug 對應。
     *
     * @param mixed $response Hub 回應
     * @return array<string,array> slug => 正規化 row
     */
    private static function canonicalize_response( $response ): array {
        if ( ! is_array( $response ) ) {
            return array();
        }

        $sources = array();
        foreach ( array( 'updates', 'no_updates' ) as $section ) {
            if ( isset( $response[ $section ] ) && is_array( $response[ $section ] ) ) {
                $sources[] = $response[ $section ];
            }
        }

        // Fallback: 如果 Hub 回傳的格式不同
        if ( array() === $sources && isset( $response['plugins'] ) && is_array( $response['plugins'] ) ) {
            $sources[] = $response['plugins'];
        }

        $canonical = array();
        foreach ( $sources as $rows ) {
            foreach ( $rows as $key => $row ) {
                // JSON 物件的 key "0" 會解碼成 PHP 的 int 0，與陣列元素無法區分，因此
                // list / associative 必須逐項依 key 型別判斷，不能對整個 section 做
                // array_is_list()。字串 key 是 Hub 對該列的宣告身分，必須與 row 自己的
                // slug 完全一致；整數 key 只是位置，身分由 row slug 決定。
                $expected_slug = is_string( $key ) ? $key : null;

                $canonical_row = YSPluginInfo::canonicalize_row( $row, $expected_slug );
                if ( null === $canonical_row ) {
                    continue; // 逐筆略過不合規 row，不讓單筆污染整批。
                }

                if ( isset( $canonical[ $canonical_row['slug'] ] ) ) {
                    // 先到者為準：updates 早於 no_updates，因此真實更新不會被
                    // 同 slug 的 no_updates 列靜默覆寫，重複列也不會改寫已驗證資料。
                    continue;
                }

                $canonical[ $canonical_row['slug'] ] = $canonical_row;
            }
        }

        return $canonical;
    }

    /**
     * 排程一次背景更新檢查
     *
     * @return void
     */
    private static function schedule_background_check(): void {
        if ( false !== get_site_transient( self::BG_LOCK_KEY ) ) {
            return;
        }
        if ( false !== wp_next_scheduled( 'ys_hub_bg_check' ) ) {
            return;
        }

        $scheduled = wp_schedule_single_event( time(), 'ys_hub_bg_check', array() );
        if ( true === $scheduled ) {
            set_site_transient( self::BG_LOCK_KEY, 1, 300 );
        }
    }

    /**
     * 背景 Cron 執行：呼叫 Hub 取得更新資料
     *
     * 由 WP Cron 觸發，不在前台執行。
     *
     * @return void
     */
    public static function background_check(): void {
        // HTTP 執行期間保留短期鎖；所有 return/exception 路徑都會釋放。
        set_site_transient( self::BG_LOCK_KEY, 1, 300 );
        try {
            // 熔斷器檢查
            if ( ! YSCircuitBreaker::is_available() ) {
                return; // 熔斷中 → 跳過，繼續使用過期快取
            }

            // 收集已安裝的 YS 外掛資訊
            $ys_plugins = YSPluginHubClient::detect_ys_plugins();
            if ( empty( $ys_plugins ) ) {
                return;
            }

            // 組裝傳送資料
            $plugins_data = array();
            foreach ( $ys_plugins as $slug => $info ) {
                $plugins_data[ $slug ] = $info['version'];
            }

            // 呼叫 Hub
            $api      = YSHubApiClient::instance();
            $response = $api->check_updates( $plugins_data );

            if ( is_wp_error( $response ) ) {
                // 失敗（CircuitBreaker 已在 ApiClient 中處理）
                return;
            }

            // 成功 → 更新快取
            // Hub /update-check 回傳 {success, count, updates: {slug: {...}}, no_updates: {slug: {...}}}
            // 只有通過形狀驗證的 row 才可以進入快取。
            $all_plugins = self::canonicalize_response( $response );

            if ( ! empty( $all_plugins ) ) {
                set_site_transient( self::CACHE_KEY, $all_plugins, self::CACHE_TTL );
            }
        } finally {
            delete_site_transient( self::BG_LOCK_KEY );
        }
    }

    /**
     * 強制刷新更新快取
     *
     * @return bool 是否成功
     */
    public static function force_refresh(): bool {
        // 清除現有快取
        delete_site_transient( self::CACHE_KEY );
        delete_site_transient( self::BG_LOCK_KEY );

        // 直接執行背景檢查（管理員手動觸發，允許同步）
        $ys_plugins = YSPluginHubClient::detect_ys_plugins();
        if ( empty( $ys_plugins ) ) {
            return false;
        }

        $plugins_data = array();
        foreach ( $ys_plugins as $slug => $info ) {
            $plugins_data[ $slug ] = $info['version'];
        }

        $api      = YSHubApiClient::instance();
        $response = $api->check_updates( $plugins_data );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        // 手動刷新走同一條 ingress 驗證，避免兩條路徑對快取有不同信任度。
        $all_plugins = self::canonicalize_response( $response );

        if ( ! empty( $all_plugins ) ) {
            set_site_transient( self::CACHE_KEY, $all_plugins, self::CACHE_TTL );
            return true;
        }

        return false;
    }
}
