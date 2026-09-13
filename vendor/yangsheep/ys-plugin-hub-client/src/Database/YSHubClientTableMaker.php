<?php
/**
 * YSHubClientTableMaker - 客戶端設定資料表建立類別
 *
 * @package YangSheep\PluginHubClient\Database
 */

namespace YangSheep\PluginHubClient\Database;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 負責建立和管理 ys_hub_client_settings 資料表
 */
class YSHubClientTableMaker {

    /**
     * Schema 版本號
     *
     * @var int
     */
    private const SCHEMA_VERSION = 2;

    /**
     * Schema 版本號
     *
     * @var int
     */
    protected $schema_version = self::SCHEMA_VERSION;

    /**
     * 資料表名稱（不含前綴）
     *
     * @var string
     */
    protected $table_name = 'ys_hub_client_settings';

    /**
     * 日誌資料表名稱（不含前綴）
     *
     * @var string
     */
    protected $log_table_name = 'ys_hub_client_logs';

    /**
     * Schema 版本 option key
     *
     * @var string
     */
    const SCHEMA_VERSION_OPTION = 'ys_hub_client_settings_schema_version';

    /**
     * Schema 驗證 ACK option key（儲存由 expected schema contract 導出的簽章）
     *
     * @var string
     */
    const SCHEMA_ACK_OPTION = 'ys_hub_client_settings_schema_ack';

    /**
     * Schema 修復退避 option key（有界 retry gate；失敗永不標記完成）
     *
     * @var string
     */
    const SCHEMA_RETRY_OPTION = 'ys_hub_client_settings_schema_retry';

    /**
     * 單例實例
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * 取得單例實例
     *
     * @return self
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 私有建構子
     */
    private function __construct() {}

    /**
     * 取得完整資料表名稱（含前綴）
     *
     * @return string
     */
    public function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . $this->table_name;
    }

    /**
     * 取得完整日誌資料表名稱（含前綴）
     *
     * @return string
     */
    public function get_log_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . $this->log_table_name;
    }

    /**
     * 取得本 schema 版本要求的兩張資料表名稱
     *
     * @param object $wpdb 資料庫存取物件。
     * @return array<string,string> 邏輯名稱 => 完整資料表名稱。
     */
    public static function required_tables( object $wpdb ): array {
        $prefix = is_string( $wpdb->prefix ?? null ) ? $wpdb->prefix : '';

        return array(
            'settings' => $prefix . 'ys_hub_client_settings',
            'logs'     => $prefix . 'ys_hub_client_logs',
        );
    }

    /**
     * 本 schema 版本的建表語句
     *
     * @param object $wpdb 資料庫存取物件。
     * @return array<string,string> 邏輯名稱 => CREATE TABLE 語句。
     */
    public static function ddl( object $wpdb ): array {
        $tables          = self::required_tables( $wpdb );
        $charset_collate = (string) $wpdb->get_charset_collate();

        return array(
            'settings' => "CREATE TABLE {$tables['settings']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            setting_key varchar(191) NOT NULL,
            setting_value longtext,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_setting_key (setting_key)
        ) {$charset_collate};",
            'logs'     => "CREATE TABLE {$tables['logs']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL DEFAULT 'info',
            action varchar(100) NOT NULL,
            message text NOT NULL,
            context longtext,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_level (level),
            KEY idx_action (action),
            KEY idx_created_at (created_at)
        ) {$charset_collate};",
        );
    }

    /**
     * 檢查資料表是否存在
     *
     * @return bool
     */
    public function table_exists(): bool {
        global $wpdb;
        $table = $this->get_table_name();

        // 表名含底線，`LIKE` 會把底線當單一字元萬用字元；必須先 esc_like，
        // 否則可能比對到另一張名稱相近的資料表。
        $pattern = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $table ) : $table;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) ) === $table;
    }

    /**
     * 檢查是否需要更新 Schema
     *
     * 只比對版本號並不足夠：dbDelta 會吞掉失敗，「版本已標記完成、但結構有缺陷」的
     * 狀態會被永久凍結。但每次 request 都做精確讀回同樣錯誤——健康站台不該支付永久
     * 的 information_schema 成本。因此完成狀態由兩個一起寫入的標記構成：版本號，加上
     * 由 exact expected schema contract 導出的驗證 ACK。
     *
     * 三種狀態：
     * 1. steady state —— 版本為當前版本且 ACK 與 contract 簽章一致：回傳 false，
     *    全程 0 次資料庫查詢、0 次 dbDelta。
     * 2. 需要工作 —— 版本落後，或 ACK 缺席／過期（包含只存版本號的舊安裝，以及
     *    contract 變更後的新 generation）：回傳 true，呼叫端執行一次精確 readback/repair。
     * 3. 退避中 —— 前次修復失敗且仍在有界退避視窗內：回傳 false（本次略過），視窗
     *    過期後自動重試；失敗永遠不會被標記為完成。
     *
     * @return bool
     */
    public function schema_update_required(): bool {
        global $wpdb;

        $signature = self::verification_signature( $wpdb );

        $current_version = (int) get_option( self::SCHEMA_VERSION_OPTION, 0 );
        $ack             = get_option( self::SCHEMA_ACK_OPTION, '' );
        if ( $current_version >= $this->schema_version
            && is_string( $ack )
            && '' !== $ack
            && hash_equals( $signature, $ack )
        ) {
            return false;
        }

        // 需要修復；先徵詢有界退避，讓持續失敗不會形成每-request 的 dbDelta storm。
        // gate 綁定簽章：contract 一旦變更（新 generation），舊 gate 立即失效。
        $gate = get_option( self::SCHEMA_RETRY_OPTION, array() );
        if ( is_array( $gate )
            && is_string( $gate['signature'] ?? null )
            && hash_equals( $signature, $gate['signature'] )
            && is_numeric( $gate['next'] ?? null )
            && time() < (int) $gate['next']
        ) {
            return false;
        }

        return true;
    }

    /**
     * 由 exact expected schema contract 導出的驗證簽章
     *
     * 純粹由 contract 資料（schema 版本、實際前綴後的資料表名、期望欄位、期望索引）
     * 導出，不是另一個手寫魔術布林。contract 的任何變更（例如把 INDEX_TYPE 納入期望）
     * 都會改變簽章，使既有 ACK 全數過期，每個站台恰好多做一次精確 readback。
     *
     * @param object $wpdb 資料庫存取物件。
     * @return string
     */
    public static function verification_signature( object $wpdb ): string {
        $preimage = json_encode( array(
            'schema_version' => self::SCHEMA_VERSION,
            'tables'         => self::required_tables( $wpdb ),
            'columns'        => self::expected_columns(),
            'indexes'        => self::expected_indexes(),
        ) );

        return hash( 'sha256', is_string( $preimage ) ? $preimage : '' );
    }

    /**
     * 建立或更新資料表
     *
     * @return array dbDelta 執行結果
     */
    public function create_table(): array {
        global $wpdb;

        $statements = self::ddl( $wpdb );

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $result = dbDelta( $statements['settings'] );
        dbDelta( $statements['logs'] );

        // dbDelta 失敗時不會拋例外，也不會回報。只有在精確讀回證明兩張表的欄位、
        // 索引與儲存特性都與本版本一致之後，才可以同時寫入版本號與驗證 ACK；
        // 失敗則記錄有界退避，永不標記完成。
        if ( self::schema_ready( $wpdb ) ) {
            $this->mark_schema_update_complete();
        } else {
            $this->record_schema_repair_failure( $wpdb );
        }

        return $result;
    }

    /**
     * 記錄一次修復失敗並設定有界退避
     *
     * 延遲以 60 秒為基底倍增、上限一天；attempts 僅供診斷。gate 綁定當前簽章，
     * contract 變更（新 generation）會使既有 gate 失效並立即允許一次新的嘗試。
     * 失敗路徑永遠不寫版本號、不寫 ACK。
     *
     * @param object $wpdb 資料庫存取物件。
     * @return void
     */
    private function record_schema_repair_failure( object $wpdb ): void {
        $signature = self::verification_signature( $wpdb );
        $gate      = get_option( self::SCHEMA_RETRY_OPTION, array() );
        $attempts  = 1;
        if ( is_array( $gate )
            && is_string( $gate['signature'] ?? null )
            && hash_equals( $signature, $gate['signature'] )
            && is_int( $gate['attempts'] ?? null )
            && $gate['attempts'] >= 1
        ) {
            $attempts = $gate['attempts'] + 1;
        }

        $delay = (int) min( 86400, 60 * ( 2 ** min( $attempts - 1, 10 ) ) );
        update_option( self::SCHEMA_RETRY_OPTION, array(
            'signature' => $signature,
            'attempts'  => $attempts,
            'next'      => time() + $delay,
        ) );
    }

    /**
     * 精確讀回：兩張資料表的欄位、索引與儲存特性都必須與本 schema 版本一致
     *
     * 刻意採用相依注入（與 src/Database/Schema.php 相同形狀），因此可用 double 驅動，
     * 也可在真實引擎上驗證。任何查詢錯誤、缺漏或多餘結構都 fail closed。
     *
     * 可攜性邊界（皆為刻意決定，於 docs 與交接檔說明）：
     * - 不比對 ENGINE 名稱：本 DDL 未宣告 ENGINE，預設引擎由主機決定，硬性要求
     *   InnoDB 會讓健康安裝永久 fail closed 且無修復路徑。只要求它是非空字串。
     * - 索引可見性以「特徵探測」決定投影欄位，而非解析版本號：MariaDB 10.5 沒有
     *   IGNORED、MySQL 5.7 沒有 IS_VISIBLE，硬選欄位會直接是 SQL 錯誤。
     * - 只要求索引成員不是遞減（COLLATION !== 'D'），不主張各引擎對「遞增」的拼法。
     * - get_charset_collate() 未提供 COLLATE token 時跳過定序比對：本 DDL 的定序
     *   委由該函式決定，此時沒有可比對的期望值。
     *
     * @param object $wpdb 資料庫存取物件。
     * @return bool
     */
    public static function schema_ready( object $wpdb ): bool {
        try {
            $server_version = $wpdb->get_var( 'SELECT VERSION()' );
            if ( ! is_string( $server_version ) || '' === $server_version || self::has_database_error( $wpdb ) ) {
                return false;
            }
            $is_mariadb = false !== stripos( $server_version, 'mariadb' );

            $visibility = self::index_visibility_projection( $wpdb );
            if ( null === $visibility ) {
                return false;
            }

            $tables             = self::required_tables( $wpdb );
            $expected_collation = self::expected_table_collation( $wpdb );

            return self::tables_ready( $wpdb, $tables, $expected_collation )
                && self::columns_ready( $wpdb, $tables, $expected_collation, $is_mariadb )
                && self::indexes_ready( $wpdb, $tables, $visibility );
        } catch ( \Throwable ) {
            return false;
        }
    }

    /**
     * 決定索引可見性的投影欄位與期望值（特徵探測，不解析版本號）。
     *
     * @param object $wpdb 資料庫存取物件。
     * @return array{projection:string,expected:mixed}|null
     */
    private static function index_visibility_projection( object $wpdb ): ?array {
        foreach ( array( 'IS_VISIBLE' => 'YES', 'IGNORED' => 'NO' ) as $column => $expected ) {
            $sql = $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                'information_schema',
                'STATISTICS',
                $column
            );
            if ( ! is_string( $sql ) ) {
                return null;
            }
            $present = $wpdb->get_var( $sql );
            if ( self::has_database_error( $wpdb ) ) {
                return null;
            }
            if ( null !== $present && (int) $present > 0 ) {
                return array(
                    'projection' => $column,
                    'expected'   => $expected,
                );
            }
        }

        // 兩個欄位都不存在（例如 MySQL 5.7）：投影常數 NULL 並期望 null。
        return array(
            'projection' => 'NULL',
            'expected'   => null,
        );
    }

    /**
     * @param array<string,string> $tables
     */
    private static function tables_ready( object $wpdb, array $tables, ?string $expected_collation ): bool {
        $sql = $wpdb->prepare(
            'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)',
            $tables['settings'],
            $tables['logs']
        );
        if ( ! is_string( $sql ) ) {
            return false;
        }

        $rows = $wpdb->get_results( $sql );
        if ( ! is_array( $rows ) || self::has_database_error( $wpdb ) || count( $rows ) !== count( $tables ) ) {
            return false;
        }

        $seen = array();
        foreach ( $rows as $row ) {
            $name = self::value( $row, 'TABLE_NAME' );
            if ( ! is_string( $name ) || isset( $seen[ $name ] ) || ! in_array( $name, $tables, true ) ) {
                return false;
            }
            if ( 'BASE TABLE' !== self::value( $row, 'TABLE_TYPE' ) ) {
                return false; // 同名的 VIEW 會通過名稱檢查，但不是本 schema 的資料表。
            }
            $engine = self::value( $row, 'ENGINE' );
            if ( ! is_string( $engine ) || '' === $engine ) {
                return false;
            }
            if ( null !== $expected_collation
                && self::canonical_collation( self::value( $row, 'TABLE_COLLATION' ) ) !== $expected_collation
            ) {
                return false;
            }
            $seen[ $name ] = true;
        }

        return count( $seen ) === count( $tables );
    }

    /**
     * @param array<string,string> $tables
     */
    private static function columns_ready( object $wpdb, array $tables, ?string $expected_collation, bool $is_mariadb ): bool {
        $sql = $wpdb->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME, EXTRA'
                . ' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)'
                . ' ORDER BY TABLE_NAME, ORDINAL_POSITION',
            $tables['settings'],
            $tables['logs']
        );
        if ( ! is_string( $sql ) ) {
            return false;
        }

        $rows = $wpdb->get_results( $sql );
        if ( ! is_array( $rows ) || self::has_database_error( $wpdb ) ) {
            return false;
        }

        $actual = array();
        foreach ( $rows as $row ) {
            $table  = self::value( $row, 'TABLE_NAME' );
            $column = self::value( $row, 'COLUMN_NAME' );
            if ( ! is_string( $table ) || ! is_string( $column ) || isset( $actual[ $table ][ $column ] ) ) {
                return false;
            }

            $collation = self::canonical_collation( self::value( $row, 'COLLATION_NAME' ) );
            if ( null !== $collation && null !== $expected_collation && $collation !== $expected_collation ) {
                return false; // 文字欄位必須沿用資料表定序。
            }

            $actual[ $table ][ $column ] = array(
                self::canonical_column_type( self::value( $row, 'COLUMN_TYPE' ) ),
                self::value( $row, 'IS_NULLABLE' ),
                self::canonical_default( self::value( $row, 'COLUMN_DEFAULT' ), $is_mariadb ),
                self::canonical_extra( self::value( $row, 'EXTRA' ) ),
                null === $collation ? 'binary-or-none' : 'table-collation',
            );
        }

        foreach ( self::expected_columns() as $logical => $expected ) {
            $table = $tables[ $logical ];
            if ( ! isset( $actual[ $table ] ) ) {
                return false;
            }
            ksort( $expected, SORT_STRING );
            $found = $actual[ $table ];
            ksort( $found, SORT_STRING );
            if ( $found !== $expected ) {
                return false;
            }
        }

        return count( $actual ) === count( $tables );
    }

    /**
     * @param array<string,string>                 $tables
     * @param array{projection:string,expected:mixed} $visibility
     */
    private static function indexes_ready( object $wpdb, array $tables, array $visibility ): bool {
        $sql = $wpdb->prepare(
            'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, SUB_PART, INDEX_TYPE, '
                . $visibility['projection'] . ' AS INDEX_VISIBILITY'
                . ' FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)'
                . ' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            $tables['settings'],
            $tables['logs']
        );
        if ( ! is_string( $sql ) ) {
            return false;
        }

        $rows = $wpdb->get_results( $sql );
        if ( ! is_array( $rows ) || self::has_database_error( $wpdb ) ) {
            return false;
        }

        $actual = array();
        foreach ( $rows as $row ) {
            $table  = self::value( $row, 'TABLE_NAME' );
            $index  = self::value( $row, 'INDEX_NAME' );
            $column = self::value( $row, 'COLUMN_NAME' );
            if ( ! is_string( $table ) || ! is_string( $index ) || ! is_string( $column ) ) {
                return false;
            }
            if ( 'D' === self::value( $row, 'COLLATION' ) ) {
                return false; // 遞減索引不是本 schema 宣告的結構。
            }
            if ( null !== self::value( $row, 'SUB_PART' ) ) {
                return false; // 前綴索引不是本 schema 宣告的結構。
            }
            if ( self::value( $row, 'INDEX_VISIBILITY' ) !== $visibility['expected'] ) {
                return false;
            }

            $sequence   = self::canonical_int( self::value( $row, 'SEQ_IN_INDEX' ) );
            $non_unique = self::canonical_int( self::value( $row, 'NON_UNIQUE' ) );
            if ( null === $sequence || $sequence <= 0 || ! in_array( $non_unique, array( 0, 1 ), true ) ) {
                return false;
            }

            // 索引 TYPE 是精確性的一部分：同名、同欄位、同唯一性但型別不同的索引
            // （例如 FULLTEXT／HASH 取代一般 BTREE KEY）不是本 schema 宣告的結構。
            // 資料表 ENGINE 維持可攜（不比對名稱），但索引語意由 contract 決定。
            $index_type = self::canonical_index_type( self::value( $row, 'INDEX_TYPE' ) );
            if ( null === $index_type ) {
                return false;
            }
            if ( isset( $actual[ $table ][ $index ]['columns'][ $sequence ] )
                || ( isset( $actual[ $table ][ $index ]['non_unique'] ) && $actual[ $table ][ $index ]['non_unique'] !== $non_unique )
                || ( isset( $actual[ $table ][ $index ]['type'] ) && $actual[ $table ][ $index ]['type'] !== $index_type )
            ) {
                return false;
            }

            $actual[ $table ][ $index ]['non_unique']           = $non_unique;
            $actual[ $table ][ $index ]['type']                 = $index_type;
            $actual[ $table ][ $index ]['columns'][ $sequence ] = $column;
        }

        foreach ( self::expected_indexes() as $logical => $expected ) {
            $table = $tables[ $logical ];
            if ( ! isset( $actual[ $table ] ) ) {
                return false;
            }
            $found = $actual[ $table ];
            foreach ( $found as $index => $definition ) {
                ksort( $definition['columns'], SORT_NUMERIC );
                $found[ $index ] = array(
                    'non_unique' => $definition['non_unique'],
                    'type'       => $definition['type'],
                    'columns'    => array_values( $definition['columns'] ),
                );
            }
            ksort( $found, SORT_STRING );
            ksort( $expected, SORT_STRING );
            if ( $found !== $expected ) {
                return false;
            }
        }

        return count( $actual ) === count( $tables );
    }

    /**
     * 本 schema 版本要求的欄位。
     *
     * 每筆為 [正規化型別, 可空性, 正規化預設值, 正規化 EXTRA, 定序類別]。
     *
     * @return array<string,array<string,array<int,mixed>>>
     */
    public static function expected_columns(): array {
        return array(
            'settings' => array(
                'id'            => array( 'bigint_unsigned', 'NO', null, 'auto_increment', 'binary-or-none' ),
                'setting_key'   => array( 'varchar(191)', 'NO', null, '', 'table-collation' ),
                'setting_value' => array( 'longtext', 'YES', null, '', 'table-collation' ),
                'created_at'    => array( 'datetime', 'NO', 'current_timestamp', '', 'binary-or-none' ),
                'updated_at'    => array( 'datetime', 'NO', 'current_timestamp', 'on update current_timestamp', 'binary-or-none' ),
            ),
            'logs'     => array(
                'id'         => array( 'bigint_unsigned', 'NO', null, 'auto_increment', 'binary-or-none' ),
                'level'      => array( 'varchar(20)', 'NO', 'info', '', 'table-collation' ),
                'action'     => array( 'varchar(100)', 'NO', null, '', 'table-collation' ),
                'message'    => array( 'text', 'NO', null, '', 'table-collation' ),
                'context'    => array( 'longtext', 'YES', null, '', 'table-collation' ),
                'created_at' => array( 'datetime', 'NO', 'current_timestamp', '', 'binary-or-none' ),
            ),
        );
    }

    /**
     * 本 schema 版本要求的索引。
     *
     * @return array<string,array<string,array{non_unique:int,columns:array<int,string>}>>
     */
    public static function expected_indexes(): array {
        return array(
            'settings' => array(
                'PRIMARY'         => array( 'non_unique' => 0, 'type' => 'BTREE', 'columns' => array( 'id' ) ),
                'idx_setting_key' => array( 'non_unique' => 0, 'type' => 'BTREE', 'columns' => array( 'setting_key' ) ),
            ),
            'logs'     => array(
                'PRIMARY'         => array( 'non_unique' => 0, 'type' => 'BTREE', 'columns' => array( 'id' ) ),
                'idx_level'       => array( 'non_unique' => 1, 'type' => 'BTREE', 'columns' => array( 'level' ) ),
                'idx_action'      => array( 'non_unique' => 1, 'type' => 'BTREE', 'columns' => array( 'action' ) ),
                'idx_created_at'  => array( 'non_unique' => 1, 'type' => 'BTREE', 'columns' => array( 'created_at' ) ),
            ),
        );
    }

    /**
     * 正規化 information_schema 的 INDEX_TYPE 值；非字串或空值 fail closed。
     */
    private static function canonical_index_type( mixed $value ): ?string {
        return is_string( $value ) && '' !== $value ? strtoupper( $value ) : null;
    }

    /**
     * 期望的資料表定序；get_charset_collate() 未給 COLLATE token 時回傳 null（跳過比對）。
     */
    private static function expected_table_collation( object $wpdb ): ?string {
        $charset_collate = $wpdb->get_charset_collate();
        if ( ! is_string( $charset_collate )
            || 1 !== preg_match( '/(?:^|\s)COLLATE\s+([a-z0-9_]+)(?:\s|$)/i', trim( $charset_collate ), $match )
        ) {
            return null;
        }

        return self::canonical_collation( $match[1] );
    }

    /**
     * MySQL 8.0.29+ 把 utf8_* 報成 utf8mb3_*；兩邊都正規化才不會誤判健康安裝。
     */
    private static function canonical_collation( mixed $value ): ?string {
        if ( ! is_string( $value ) || '' === $value ) {
            return null;
        }
        $collation = strtolower( $value );

        return str_starts_with( $collation, 'utf8_' ) ? 'utf8mb3_' . substr( $collation, 5 ) : $collation;
    }

    private static function canonical_column_type( mixed $value ): ?string {
        if ( ! is_string( $value ) ) {
            return null;
        }
        $type = strtolower( $value );
        if ( 1 === preg_match( '/^bigint(?:\([0-9]+\))? unsigned$/D', $type ) ) {
            return 'bigint_unsigned';
        }
        if ( 1 === preg_match( '/^datetime(?:\(0\))?$/D', $type ) ) {
            return 'datetime';
        }

        return $type;
    }

    /**
     * 正規化 COLUMN_DEFAULT。
     *
     * MariaDB 10.2+ 以 SQL 運算式回報預設值，因此採用「封閉」對照表：只認本 schema
     * 實際使用的兩個值，其餘一律原樣通過並在比對時被拒絕。刻意不寫通用去引號器——
     * 那會把帶引號的字面值 'NULL' 誤判成 SQL NULL。
     */
    private static function canonical_default( mixed $value, bool $is_mariadb ): mixed {
        if ( ! is_string( $value ) ) {
            return $value;
        }
        if ( $is_mariadb ) {
            $value = match ( $value ) {
                'NULL'   => null,
                "'info'" => 'info',
                default  => $value,
            };
            if ( ! is_string( $value ) ) {
                return $value;
            }
        }

        return 1 === preg_match( '/^current_timestamp(?:\(\))?$/i', $value ) ? 'current_timestamp' : $value;
    }

    /**
     * 正規化 EXTRA：MySQL 8 會多出 DEFAULT_GENERATED，MariaDB 會帶 ()。
     */
    private static function canonical_extra( mixed $value ): string {
        if ( ! is_string( $value ) ) {
            return '';
        }
        $extra = strtolower( $value );
        $extra = str_replace( 'default_generated', '', $extra );
        $extra = str_replace( 'current_timestamp()', 'current_timestamp', $extra );
        $extra = preg_replace( '/\s+/', ' ', $extra );

        return trim( is_string( $extra ) ? $extra : '' );
    }

    private static function canonical_int( mixed $value ): ?int {
        if ( is_int( $value ) ) {
            return $value;
        }
        if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
            return null;
        }

        return (int) $value;
    }

    private static function has_database_error( object $wpdb ): bool {
        if ( ! property_exists( $wpdb, 'last_error' ) ) {
            return false;
        }

        return ! is_string( $wpdb->last_error ) || '' !== $wpdb->last_error;
    }

    private static function value( mixed $row, string $key ): mixed {
        if ( is_array( $row ) ) {
            return $row[ $key ] ?? null;
        }

        return is_object( $row ) ? ( $row->{$key} ?? null ) : null;
    }

    /**
     * 標記 Schema 更新完成
     *
     * 版本號與驗證 ACK 一起寫入；此方法只可在精確讀回成功後呼叫。
     * 同時清除失敗退避 gate。
     *
     * @return void
     */
    private function mark_schema_update_complete(): void {
        global $wpdb;
        update_option( self::SCHEMA_VERSION_OPTION, $this->schema_version );
        update_option( self::SCHEMA_ACK_OPTION, self::verification_signature( $wpdb ) );
        delete_option( self::SCHEMA_RETRY_OPTION );
    }

    /**
     * 刪除資料表（解除安裝時使用）
     *
     * @return bool
     */
    public function drop_table(): bool {
        global $wpdb;
        $table_name = $this->get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
        delete_option( self::SCHEMA_VERSION_OPTION );
        delete_option( self::SCHEMA_ACK_OPTION );
        delete_option( self::SCHEMA_RETRY_OPTION );
        return $result !== false;
    }

    /**
     * 取得當前 Schema 版本
     *
     * @return int
     */
    public function get_schema_version(): int {
        return $this->schema_version;
    }

    /**
     * 取得已安裝的 Schema 版本
     *
     * @return int
     */
    public function get_installed_schema_version(): int {
        return (int) get_option( self::SCHEMA_VERSION_OPTION, 0 );
    }
}
