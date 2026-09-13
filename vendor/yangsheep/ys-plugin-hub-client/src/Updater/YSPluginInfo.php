<?php
/**
 * YSPluginInfo - 外掛資訊對應
 *
 * 將 Hub 回傳的外掛資料對應到 WordPress 更新 API 格式。
 *
 * Hub 是外部輸入。本類別同時是「正規化權威」與「型別化對應」：任何資料在進入
 * 快取或 WordPress 更新物件之前，都必須先通過 canonicalize_row() 的顯式 schema。
 * 不接受的 shape/型別一律安全拒絕，不得產生診斷訊息，也不得以型別強轉掩蓋。
 *
 * @package YangSheep\PluginHubClient\Updater
 */

namespace YangSheep\PluginHubClient\Updater;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 將 Hub 回傳的外掛資訊轉為 WP 更新格式
 */
class YSPluginInfo {

    /**
     * 外掛 slug 允許的字元集。
     *
     * @var string
     */
    private const SLUG_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D';

    /**
     * 版本字串允許的字元集。
     *
     * @var string
     */
    private const VERSION_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/D';

    /**
     * 圖示 map 允許的 key。
     *
     * @var array<int,string>
     */
    private const ICON_KEYS = array( 'svg', '1x', '2x', 'default' );

    /**
     * 橫幅 map 允許的 key。
     *
     * @var array<int,string>
     */
    private const BANNER_KEYS = array( 'low', 'high', '1x', '2x', 'default' );

    /**
     * URL 長度上限。
     *
     * @var int
     */
    private const MAX_URL_LENGTH = 2048;

    /**
     * 把任意 Hub row 正規化成顯式 schema
     *
     * 身分（slug、version）不合規即拒絕整列；其餘欄位不合規則退回其文件化預設值。
     * 兩者都是「拒絕該值」，不是型別強轉。未知 key 一律丟棄。
     *
     * @param mixed       $row           Hub 提供的單一 row。
     * @param string|null $expected_slug 呼叫端已知的 slug；提供時必須與 row 自身的 slug 完全一致。
     * @return array<string,mixed>|null 正規化 row；不合規時為 null。
     */
    public static function canonicalize_row( $row, ?string $expected_slug = null ): ?array {
        if ( ! is_array( $row ) ) {
            return null;
        }

        $slug = self::exact_token( $row['slug'] ?? null, self::SLUG_PATTERN );
        if ( null === $slug ) {
            return null;
        }
        if ( null !== $expected_slug && $expected_slug !== $slug ) {
            return null; // 身分權威只有一個：row 自己的 slug 必須等於呼叫端所指的 slug。
        }

        $version = self::exact_token( $row['version'] ?? null, self::VERSION_PATTERN );
        if ( null === $version ) {
            return null;
        }

        return array(
            'slug'           => $slug,
            'version'        => $version,
            'name'           => self::plain_string( $row['name'] ?? null, '' ),
            'homepage'       => self::web_url( $row['homepage'] ?? null, '', false ),
            'requires'       => self::version_token( $row['requires'] ?? null, '5.8' ),
            'requires_php'   => self::version_token( $row['requires_php'] ?? null, '7.4' ),
            'tested'         => self::version_token( $row['tested'] ?? null, '' ),
            'package'        => self::package_url( $row ),
            'icons'          => self::url_map( $row['icons'] ?? null, self::ICON_KEYS ),
            'banners'        => self::url_map( $row['banners'] ?? null, self::BANNER_KEYS ),
            'description'    => self::plain_string( $row['description'] ?? null, '' ),
            'changelog'      => self::plain_string( $row['changelog'] ?? null, '' ),
            'author'         => self::non_empty_string( $row['author'] ?? null, 'YANGSHEEP DESIGN' ),
            'author_profile' => self::web_url( $row['author_profile'] ?? null, 'https://yangsheep.com.tw', false ),
            'last_updated'   => self::plain_string( $row['last_updated'] ?? null, '' ),
        );
    }

    /**
     * 完全惰性的 row：用於無法辨識身分時，讓對應方法保持 total（不警告、不致命）。
     *
     * @return array<string,mixed>
     */
    private static function empty_row(): array {
        return array(
            'slug'           => '',
            'version'        => '',
            'name'           => '',
            'homepage'       => '',
            'requires'       => '5.8',
            'requires_php'   => '7.4',
            'tested'         => '',
            'package'        => '',
            'icons'          => array(),
            'banners'        => array(),
            'description'    => '',
            'changelog'      => '',
            'author'         => 'YANGSHEEP DESIGN',
            'author_profile' => 'https://yangsheep.com.tw',
            'last_updated'   => '',
        );
    }

    /**
     * 從 Hub 回應建立 WP 更新物件
     *
     * 對應 WordPress 的 plugins_api() 回傳格式。
     *
     * @param array $plugin_data Hub 回傳的單一外掛資料
     * @return object
     */
    public static function from_hub_response( array $plugin_data ): object {
        $row = self::canonicalize_row( $plugin_data );
        if ( null === $row ) {
            $row = self::empty_row();
        }

        $info         = new \stdClass();
        $download_url = self::safe_download_url( $row );

        $info->name           = $row['name'];
        $info->slug           = $row['slug'];
        $info->version        = $row['version'];
        $info->author         = $row['author'];
        $info->author_profile = $row['author_profile'];
        $info->homepage       = $row['homepage'];
        $info->requires       = $row['requires'];
        $info->tested         = $row['tested'];
        $info->requires_php   = $row['requires_php'];
        $info->download_link  = $download_url;
        $info->trunk          = $download_url;
        $info->last_updated   = $row['last_updated'];

        // 描述區塊
        $info->sections = array(
            'description' => $row['description'],
            'changelog'   => $row['changelog'],
        );

        // 圖示：空 map 代表「沒有」，維持既有語意不輸出該屬性。
        if ( array() !== $row['icons'] ) {
            $info->icons = $row['icons'];
        }

        // 橫幅
        if ( array() !== $row['banners'] ) {
            $info->banners = $row['banners'];
        }

        return $info;
    }

    /**
     * 從 Hub 回應建立 WP 更新 transient 格式的物件
     *
     * 用於注入 update_plugins transient。
     *
     * @param array  $plugin_data Hub 回傳的單一外掛資料
     * @param string $plugin_file 外掛檔案路徑（如 ys-paynow-shipping/ys-paynow-shipping.php）
     * @return object
     */
    public static function to_update_object( array $plugin_data, string $plugin_file ): object {
        $row = self::canonicalize_row( $plugin_data );
        if ( null === $row ) {
            $row = self::empty_row();
        }

        $update       = new \stdClass();
        $download_url = self::safe_download_url( $row );

        $update->id           = $row['slug'];
        $update->slug         = $row['slug'];
        $update->plugin       = $plugin_file;
        $update->new_version  = $row['version'];
        $update->url          = $row['homepage'];
        $update->package      = $download_url;
        $update->requires     = $row['requires'];
        $update->tested       = $row['tested'];
        $update->requires_php = $row['requires_php'];

        if ( array() !== $row['icons'] ) {
            $update->icons = $row['icons'];
        }

        return $update;
    }

    /**
     * 套用套件下載網址 allowlist。
     *
     * 只接受已正規化的字串；此處不再有任何型別轉換。
     *
     * @param array<string,mixed> $row 已正規化的 row。
     * @return string
     */
    private static function safe_download_url( array $row ): string {
        $url = $row['package'] ?? '';
        if ( ! is_string( $url ) || '' === $url ) {
            return '';
        }

        return function_exists( 'ys_hub_client_is_allowed_package_url' )
            && \ys_hub_client_is_allowed_package_url( $url )
                ? $url
                : '';
    }

    /**
     * 套件網址：字串優先序，絕不使用 `??`（空字串不得遮蔽有效的備援值）。
     *
     * @param array<string,mixed> $row 原始 row。
     * @return string
     */
    private static function package_url( array $row ): string {
        foreach ( array( 'package', 'download_url' ) as $key ) {
            $candidate = $row[ $key ] ?? null;
            if ( is_string( $candidate ) && '' !== $candidate ) {
                return self::web_url( $candidate, '', true );
            }
        }

        return '';
    }

    /**
     * 精確 token：必須是字串、去除首尾空白後仍非空、且完全符合樣式。
     *
     * @param mixed  $value   原始值。
     * @param string $pattern 允許的樣式。
     * @return string|null
     */
    private static function exact_token( $value, string $pattern ): ?string {
        if ( ! is_string( $value ) ) {
            return null;
        }
        $trimmed = trim( $value );
        if ( '' === $trimmed || 1 !== preg_match( $pattern, $trimmed ) ) {
            return null;
        }

        return $trimmed;
    }

    /**
     * 版本樣式欄位；不合規時退回預設值。
     *
     * @param mixed  $value   原始值。
     * @param string $default 預設值。
     * @return string
     */
    private static function version_token( $value, string $default ): string {
        return self::exact_token( $value, self::VERSION_PATTERN ) ?? $default;
    }

    /**
     * 純字串欄位；非字串時退回預設值。
     *
     * @param mixed  $value   原始值。
     * @param string $default 預設值。
     * @return string
     */
    private static function plain_string( $value, string $default ): string {
        return is_string( $value ) ? $value : $default;
    }

    /**
     * 非空字串欄位；非字串或空白時退回預設值。
     *
     * @param mixed  $value   原始值。
     * @param string $default 預設值。
     * @return string
     */
    private static function non_empty_string( $value, string $default ): string {
        if ( ! is_string( $value ) ) {
            return $default;
        }
        $trimmed = trim( $value );

        return '' === $trimmed ? $default : $trimmed;
    }

    /**
     * 網址欄位：必須是絕對網址、host 非空、長度受限。
     *
     * @param mixed  $value     原始值。
     * @param string $default   預設值。
     * @param bool   $https_only 是否只接受 https。
     * @return string
     */
    private static function web_url( $value, string $default, bool $https_only ): string {
        if ( ! is_string( $value ) || '' === $value || strlen( $value ) > self::MAX_URL_LENGTH ) {
            return $default;
        }

        $parts = parse_url( $value );
        if ( ! is_array( $parts ) ) {
            return $default;
        }

        $scheme = isset( $parts['scheme'] ) && is_string( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
        $host   = isset( $parts['host'] ) && is_string( $parts['host'] ) ? $parts['host'] : '';
        if ( '' === $host ) {
            return $default;
        }

        $allowed = $https_only ? array( 'https' ) : array( 'http', 'https' );

        return in_array( $scheme, $allowed, true ) ? $value : $default;
    }

    /**
     * 圖示／橫幅 map：只保留允許 key、且值為絕對 https 字串網址的項目。
     *
     * @param mixed             $value        原始值。
     * @param array<int,string> $allowed_keys 允許的 key。
     * @return array<string,string>
     */
    private static function url_map( $value, array $allowed_keys ): array {
        if ( ! is_array( $value ) ) {
            return array();
        }

        $map = array();
        foreach ( $allowed_keys as $key ) {
            $candidate = $value[ $key ] ?? null;
            if ( ! is_string( $candidate ) ) {
                continue;
            }
            $url = self::web_url( $candidate, '', true );
            if ( '' !== $url ) {
                $map[ $key ] = $url;
            }
        }

        return $map;
    }
}
