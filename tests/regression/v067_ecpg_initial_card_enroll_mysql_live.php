<?php
/**
 * v067 — 站內付 2.0 首購綁卡 × **真** Core（YSInitialSubscriptionCardBinding／YSSubscription）× 真引擎（v0.5.12）。
 *
 * 以 YS_CORE_ROOT 的 production DDL（YSTableMaker）在每個 process 自己的 arena 建 orders／order_items／
 * subscriptions／credit_cards／credit_card_defaults，種一筆「已付款、綠界 attempt、當期 processing 收據」的首購訂單，
 * 直接呼叫 production `EcpgInitialCardBinding::attach()`（EcpgSettlement 的 vault runner 用的同一支），
 * 再用**另一條**連線讀回 DB 位元組判定。兩種 Core 形狀的期望都手寫，由 YS_ECPG_EXPECT_ENROLL 釘住：
 *
 *   enrolled（Core 2.69.7 起，已知 provider＝ecpay 可寫首購卡片權威）
 *     E1 訂閱尚未建立：attach()＝true（不要求綠界重送）；收據上有精確權威 {customer,user,gateway,card}，
 *        Core 的首購綁卡效果 pending；沒有任何訂閱列被寫。
 *     E2 訂閱建立後，由 **Core** `YSInitialSubscriptionCardBinding::recover()` 完成綁卡（card_id＝權威卡），
 *        綠界不必再收到任何回呼。
 *     E3 別人的卡：enroll 被 Core 拒絕 → 退回舊版綁卡 → attach()＝false（要求重送），收據上沒有權威。
 *   legacy（Core 2.69.6 以前只認 PayUni）
 *     L1 attach()＝false（0.5.11 行為：訂閱未建立就要求重送），收據上沒有權威。
 *     L2 訂閱建立後綠界重送：舊版綁卡把同一張卡綁上。
 *     L3 別人的卡：同樣 false、沒有權威。
 *
 * Run（例）：YS_CORE_ROOT=… YS_ECPG_EXPECT_ENROLL=enrolled YS_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3310' \
 *            YS_TEST_MYSQL_USER=root YS_TEST_MYSQL_PASSWORD='' php tests/regression/v067_ecpg_initial_card_enroll_mysql_live.php
 */

declare(strict_types=1);

namespace YangSheep\Ecommerce\Utils {
	/** 替身：真的 YSLogger 也是 DB sink，這裡只記在記憶體。 */
	final class YSLogger {
		public static array $entries = [];
		public static function error( string $c, string $m, array $ctx = [] ): void { unset( $ctx ); self::$entries[] = [ 'error', $c, $m ]; }
		public static function warning( string $c, string $m, array $ctx = [] ): void { unset( $ctx ); self::$entries[] = [ 'warning', $c, $m ]; }
		public static function info( string $c, string $m, array $ctx = [] ): void { unset( $ctx ); self::$entries[] = [ 'info', $c, $m ]; }
		public static function debug( string $c, string $m, array $ctx = [] ): void { unset( $ctx ); self::$entries[] = [ 'debug', $c, $m ]; }
	}
}

namespace {
	if ( function_exists( 'mysqli_report' ) ) {
		mysqli_report( MYSQLI_REPORT_OFF );
	}
	date_default_timezone_set( 'UTC' );
	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
	if ( ! defined( 'YS_ECOMMERCE_TABLE_PREFIX' ) ) { define( 'YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_' ); }
	foreach ( [ 'OBJECT', 'OBJECT_K', 'ARRAY_A', 'ARRAY_N' ] as $constant ) {
		if ( ! defined( $constant ) ) { define( $constant, $constant ); }
	}
	function current_time( string $type ): string { unset( $type ); return '2026-09-23 12:00:00'; }
	function absint( $value ): int { return abs( (int) $value ); }
	function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
	function wp_json_encode( $data, int $flags = 0, int $depth = 512 ) { return json_encode( $data, $flags, $depth ); }
	function apply_filters( string $tag, $value, ...$rest ) { unset( $tag, $rest ); return $value; }
	function do_action( string $tag, ...$rest ): void { unset( $tag, $rest ); }
	function __( $text, $domain = '' ) { unset( $domain ); return $text; }
	function dbDelta( $queries, bool $execute = true ): array {
		global $wpdb;
		$out = [];
		foreach ( (array) $queries as $sql ) {
			if ( ! is_string( $sql ) || 1 !== preg_match( '/CREATE TABLE\s+`?([A-Za-z0-9_]+)`?/', $sql, $m ) ) { continue; }
			$out[ $m[1] ] = $execute && false === $wpdb->query( $sql ) ? 'create failed: ' . $wpdb->last_error : 'Created table ' . $m[1];
		}
		return $out;
	}

	$core   = rtrim( str_replace( '\\', '/', (string) getenv( 'YS_CORE_ROOT' ) ), '/' );
	$expect = (string) getenv( 'YS_ECPG_EXPECT_ENROLL' );
	$dsn    = (string) ( getenv( 'YS_TEST_MYSQL_DSN' ) ?: '' );
	$user   = (string) ( getenv( 'YS_TEST_MYSQL_USER' ) ?: '' );
	$password = (string) ( getenv( 'YS_TEST_MYSQL_PASSWORD' ) ?: '' );
	if ( '' === $core || ! is_file( $core . '/src/Services/Payment/YSInitialSubscriptionCardBinding.php' )
		|| ! is_file( $core . '/tests/regression/_engine_family.php' )
		|| ! in_array( $expect, [ 'enrolled', 'legacy' ], true ) ) {
		fwrite( STDERR, "YS_CORE_ROOT (a Core tree with YSInitialSubscriptionCardBinding) and YS_ECPG_EXPECT_ENROLL=enrolled|legacy are required.\n" );
		exit( 2 );
	}
	if ( ! extension_loaded( 'mysqli' ) ) { fwrite( STDERR, "mysqli extension is not loaded\n" ); exit( 2 ); }
	if ( 1 !== preg_match( '/\Amysql:host=([^;]+);port=([0-9]{1,5})\z/D', $dsn, $m ) || '' === $user ) {
		fwrite( STDERR, "missing or malformed YS_TEST_MYSQL_DSN / YS_TEST_MYSQL_USER\n" );
		exit( 2 );
	}
	[ , $host, $port ] = $m;
	require_once $core . '/tests/regression/_engine_family.php';

	/** wpdb-shaped adapter over mysqli（取自 Core v26840 的 live 轉接，只留 Core 這條路會用到的面）。 */
	final class V067LiveWpdb {
		public string $prefix;
		public string $last_error = '';
		public int $insert_id = 0;
		public int $rows_affected = 0;
		public int $num_rows = 0;
		public function __construct( public \mysqli $link, string $prefix ) { $this->prefix = $prefix; }
		public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci'; }
		public function prepare( string $query, ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
			$query = str_replace( [ "'%s'", '"%s"' ], '%s', $query );
			$query = preg_replace( '/(?<!%)%(?!%)(?![dsfiF])/', '%%', $query ) ?? $query;
			$out = '';
			$i = 0;
			$length = strlen( $query );
			for ( $p = 0; $p < $length; $p++ ) {
				$ch = $query[ $p ];
				if ( '%' !== $ch ) { $out .= $ch; continue; }
				$next = $query[ $p + 1 ] ?? '';
				if ( '%' === $next ) { $out .= '%'; $p++; continue; }
				$arg = $args[ $i++ ] ?? null;
				if ( 'd' === $next ) { $out .= (string) (int) $arg; }
				elseif ( 'f' === $next || 'F' === $next ) { $out .= (string) (float) $arg; }
				elseif ( 's' === $next ) { $out .= "'" . $this->link->real_escape_string( (string) $arg ) . "'"; }
				elseif ( 'i' === $next ) { $out .= '`' . str_replace( '`', '``', (string) $arg ) . '`'; }
				else { $out .= '%'; continue; }
				$p++;
			}
			return $out;
		}
		private function send( string $sql ) {
			$this->last_error = '';
			$result = @$this->link->query( $sql );
			if ( false === $result ) { $this->last_error = (string) $this->link->error; }
			return $result;
		}
		public function query( string $sql ) {
			$result = $this->send( $sql );
			if ( false === $result ) { return false; }
			if ( $result instanceof \mysqli_result ) {
				$this->num_rows = (int) $result->num_rows;
				$result->free();
				return $this->num_rows;
			}
			$this->rows_affected = (int) $this->link->affected_rows;
			$this->insert_id = (int) $this->link->insert_id;
			return $this->rows_affected;
		}
		public function get_results( string $sql, $output = OBJECT ) {
			unset( $output );
			$result = $this->send( $sql );
			if ( ! $result instanceof \mysqli_result ) { return []; }
			$rows = [];
			while ( $row = $result->fetch_object() ) { $rows[] = $row; }
			$result->free();
			return $rows;
		}
		public function get_row( string $sql, $output = OBJECT, int $y = 0 ) {
			$rows = $this->get_results( $sql, $output );
			return '' === $this->last_error && isset( $rows[ $y ] ) ? $rows[ $y ] : null;
		}
		public function get_var( string $sql, int $x = 0, int $y = 0 ) {
			$rows = $this->get_results( $sql );
			if ( '' !== $this->last_error || ! isset( $rows[ $y ] ) ) { return null; }
			$values = array_values( get_object_vars( $rows[ $y ] ) );
			return $values[ $x ] ?? null;
		}
		public function get_col( string $sql, int $x = 0 ): array {
			$out = [];
			foreach ( $this->get_results( $sql ) as $row ) {
				$values = array_values( get_object_vars( $row ) );
				$out[] = $values[ $x ] ?? null;
			}
			return '' === $this->last_error ? $out : [];
		}
	}

	$pass = 0;
	$fail = 0;
	$assert = static function ( bool $ok, string $label, mixed $detail = null ) use ( &$pass, &$fail ): void {
		if ( $ok ) { ++$pass; echo "  PASS  {$label}\n"; return; }
		++$fail; echo "  FAIL  {$label}" . ( null === $detail ? '' : ' -- ' . json_encode( $detail, JSON_UNESCAPED_UNICODE ) ) . "\n";
	};

	$db = @mysqli_connect( $host, $user, $password, '', (int) $port );
	if ( ! $db instanceof \mysqli ) { fwrite( STDERR, 'cannot connect errno=' . mysqli_connect_errno() . "\n" ); exit( 2 ); }
	$family = ys_test_engine_family( (string) $db->server_info );
	$pin    = (string) ( getenv( 'YS_TEST_EXPECTED_ENGINE' ) ?: '' );
	if ( 'unknown' === $family || ( '' !== $pin && $pin !== $family ) ) {
		fwrite( STDERR, "engine probe {$family} does not satisfy pin '{$pin}'\n" );
		exit( 2 );
	}

	$arena   = 'ys_v067_' . getmypid() . '_' . bin2hex( random_bytes( 4 ) );
	$dropped = false;
	$drop    = static function () use ( $db, $arena, &$dropped ): void {
		if ( $dropped ) { return; }
		$dropped = true;
		@$db->query( 'DROP DATABASE IF EXISTS `' . $arena . '`' );
	};
	register_shutdown_function( $drop );

	echo "## v067 ECPG initial card × real Core ({$expect}) × {$family}\n";
	try {
		if ( true !== $db->query( 'CREATE DATABASE `' . $arena . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' )
			|| true !== $db->select_db( $arena ) || ! $db->set_charset( 'utf8mb4' ) ) {
			fwrite( STDERR, 'cannot build arena errno=' . $db->errno . "\n" );
			exit( 2 );
		}
		$observer = @mysqli_connect( $host, $user, $password, $arena, (int) $port );
		if ( ! $observer instanceof \mysqli || ! $observer->set_charset( 'utf8mb4' ) ) {
			fwrite( STDERR, "cannot open the independent observer connection\n" );
			exit( 2 );
		}

		$wpdb = new V067LiveWpdb( $db, 'wp_' );
		$GLOBALS['wpdb'] = $wpdb;
		$root = str_replace( '\\', '/', dirname( __DIR__, 2 ) );
		spl_autoload_register( static function ( string $class ) use ( $core, $root ): void {
			foreach ( [ 'YangSheep\\Ecommerce\\' => $core . '/src/', 'YangSheep\\YSCartEcpay\\' => $root . '/src/' ] as $prefix => $dir ) {
				if ( str_starts_with( $class, $prefix ) ) {
					$file = $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
					if ( is_file( $file ) ) { require $file; }
					return;
				}
			}
		} );

		$maker = new \ReflectionClass( \YangSheep\Ecommerce\Database\YSTableMaker::class );
		foreach ( [ 'create_orders_table', 'create_order_items_table', 'create_subscriptions_table', 'create_credit_cards_table', 'create_credit_card_defaults_table' ] as $creator ) {
			$maker->getMethod( $creator )->invoke( null, 'wp_ys_ec_', $wpdb->get_charset_collate() );
		}
		$tables = $observer->query( "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('wp_ys_ec_orders','wp_ys_ec_order_items','wp_ys_ec_subscriptions','wp_ys_ec_credit_cards','wp_ys_ec_credit_card_defaults')" );
		$assert( $tables instanceof \mysqli_result && 5 === (int) ( $tables->fetch_assoc()['n'] ?? 0 ), 'S0 arena carries the five production tables from YS_CORE_ROOT\'s YSTableMaker' );

		$has_known_providers = defined( \YangSheep\Ecommerce\Services\Payment\YSPaymentAttempt::class . '::KNOWN_PROVIDERS' );
		$assert(
			( 'enrolled' === $expect ) === $has_known_providers,
			'S1 the pinned expectation matches the Core tree (Core 2.69.7 marker YSPaymentAttempt::KNOWN_PROVIDERS)',
			[ 'expect' => $expect, 'known_providers' => $has_known_providers ]
		);

		$GW    = 'ys_ec_ecpay_ecpg_credit';
		$NONCE = 'v067-ecpg-attempt-nonce';
		$exec  = static function ( string $sql ) use ( $observer ): bool { return true === @$observer->query( $sql ); };
		$esc   = static fn ( string $v ): string => "'" . $observer->real_escape_string( $v ) . "'";
		$receipt_of = static fn ( int $order_id ): string => substr( hash( 'sha256', $order_id . '|processing|1|' . $NONCE ), 0, 32 );
		$seed_order = static function ( int $order_id, int $customer, int $user ) use ( $exec, $esc, $receipt_of, $GW, $NONCE ): string {
			$receipt = $receipt_of( $order_id );
			$detail  = [
				'mer_trade_no'       => 'YSV067' . $order_id,
				'ys_payment_attempt' => [ 'generation' => 1, 'nonce' => $NONCE, 'provider' => 'ecpay', 'gateway_id' => $GW, 'id' => 'YSV067' . $order_id ],
				'_ys_payment_effects' => [ $receipt => [ 'target' => 'processing', 'generation' => 1, 'created_at' => '2026-09-23 12:00:00', 'effects' => [ 'ecpg_card_vault' => [ 'state' => 'running' ] ] ] ],
			];
			$exec( 'INSERT INTO wp_ys_ec_orders (id, order_number, customer_id, user_id, status, gateway_id, payment_method, total, payment_detail) VALUES ('
				. $order_id . ", 'YS{$order_id}', {$customer}, {$user}, 'processing', " . $esc( $GW ) . ', ' . $esc( $GW ) . ", 1290.00, " . $esc( (string) json_encode( $detail ) ) . ')' );
			$exec( 'INSERT INTO wp_ys_ec_order_items (order_id, product_id, product_title, quantity, unit_price, line_total, is_subscription) VALUES ('
				. $order_id . ", 5, 'Monthly Tea', 1, 1290.00, 1290.00, 1)" );
			return $receipt;
		};
		$seed_card = static function ( int $card_id, int $customer, int $user ) use ( $exec, $esc, $GW ): void {
			$exec( 'INSERT INTO wp_ys_ec_credit_cards (id, customer_id, user_id, gateway_id, token, token_hash, card_last4, card_brand, expire_date, is_default, status) VALUES ('
				. $card_id . ", {$customer}, {$user}, " . $esc( $GW ) . ", 'encrypted-fixture', " . $esc( hash( 'sha256', 'bind-' . $card_id ) ) . ", '2222', 'visa', '12/28', 1, 'active')" );
		};
		$seed_subscription = static function ( int $order_id, int $customer, int $user ) use ( $exec, $esc, $GW ): void {
			$exec( 'INSERT INTO wp_ys_ec_subscriptions (customer_id, user_id, product_id, variant_id, status, product_title, amount, billing_period, start_date, gateway_id, card_id, initial_order_id) VALUES ('
				. "{$customer}, {$user}, 5, NULL, 'active', 'Monthly Tea', 1290.00, 'month', '2026-09-23 12:00:00', " . $esc( $GW ) . ', NULL, ' . $order_id . ')' );
		};
		$receipt_row = static function ( int $order_id, string $receipt ) use ( $observer ): array {
			$result = $observer->query( 'SELECT payment_detail FROM wp_ys_ec_orders WHERE id = ' . $order_id );
			$raw = $result instanceof \mysqli_result ? (string) ( $result->fetch_assoc()['payment_detail'] ?? '' ) : '';
			$detail = json_decode( $raw, true );
			return is_array( $detail['_ys_payment_effects'][ $receipt ] ?? null ) ? $detail['_ys_payment_effects'][ $receipt ] : [];
		};
		$subscription_cards = static function ( int $order_id ) use ( $observer ): array {
			$result = $observer->query( 'SELECT card_id FROM wp_ys_ec_subscriptions WHERE initial_order_id = ' . $order_id . ' ORDER BY id' );
			$rows = $result instanceof \mysqli_result ? $result->fetch_all( MYSQLI_ASSOC ) : [];
			return array_map( static fn ( array $r ): ?int => null === $r['card_id'] ? null : (int) $r['card_id'], $rows );
		};
		$attach = static fn ( int $order_id, int $customer, int $user, int $card, string $receipt ): bool =>
			\YangSheep\YSCartEcpay\Ecpg\EcpgInitialCardBinding::attach( $order_id, $customer, $user, $card, $receipt );

		$r700 = $seed_order( 700, 91, 92 );
		$seed_card( 77, 91, 92 );
		$r701 = $seed_order( 701, 91, 92 );
		$seed_card( 78, 93, 94 );   // 不是這位顧客的卡

		// JSON 物件的鍵順序不是身分（MySQL 8 的 JSON 欄位會重排鍵）；每個值與型別才是——與 Core same_authority() 同一規則。
		$sorted = static function ( mixed $value ): mixed {
			if ( is_array( $value ) ) { ksort( $value ); }
			return $value;
		};
		$expected_authority = $sorted( [ 'customer_id' => 91, 'user_id' => 92, 'gateway_id' => $GW, 'card_id' => 77 ] );
		if ( 'enrolled' === $expect ) {
			$first   = $attach( 700, 91, 92, 77, $r700 );
			$receipt = $receipt_row( 700, $r700 );
			$assert( true === $first, 'E1 subscription not yet materialised → attach() reports done (no ECPay resend)' );
			$assert( $expected_authority === $sorted( $receipt['initial_subscription_card'] ?? null ), 'E1 the paid receipt now carries the exact initial-card authority (same values and types)', $receipt['initial_subscription_card'] ?? null );
			$assert( 'pending' === ( $receipt['effects']['initial_subscription_card_binding']['state'] ?? null ), 'E1 Core\'s initial-card binding effect is pending on the same receipt', $receipt['effects'] ?? null );
			$assert( [] === $subscription_cards( 700 ), 'E1 no subscription row was written' );

			$seed_subscription( 700, 91, 92 );
			$recovered = \YangSheep\Ecommerce\Services\Payment\YSInitialSubscriptionCardBinding::recover( 700, $r700 );
			$assert( true === $recovered && [ 77 ] === $subscription_cards( 700 ), 'E2 after materialisation Core alone (recover) binds the authoritative card — no further ECPay callback needed', [ 'recover' => $recovered, 'cards' => $subscription_cards( 700 ) ] );

			$foreign = $attach( 701, 91, 92, 78, $r701 );
			$assert( false === $foreign && ! array_key_exists( 'initial_subscription_card', $receipt_row( 701, $r701 ) ), 'E3 a card the customer does not own is refused by Core → legacy path → resend requested, no authority written' );
		} else {
			$first   = $attach( 700, 91, 92, 77, $r700 );
			$receipt = $receipt_row( 700, $r700 );
			$assert( false === $first, 'L1 Core refuses an ecpay attempt → 0.5.11 behaviour: resend requested while unmaterialised' );
			$assert( ! array_key_exists( 'initial_subscription_card', $receipt ), 'L1 no authority is written to the receipt by an older Core', $receipt );
			$seed_subscription( 700, 91, 92 );
			$resend = $attach( 700, 91, 92, 77, $r700 );
			$assert( true === $resend && [ 77 ] === $subscription_cards( 700 ), 'L2 the ECPay resend after materialisation binds the same card through the legacy path', [ 'resend' => $resend, 'cards' => $subscription_cards( 700 ) ] );
			$foreign = $attach( 701, 91, 92, 78, $r701 );
			$assert( false === $foreign && ! array_key_exists( 'initial_subscription_card', $receipt_row( 701, $r701 ) ), 'L3 a foreign card is not bound and leaves no authority' );
		}
	} finally {
		$drop();
	}

	echo "\nv067 ECPG initial card × real Core ({$expect}, {$family}): {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 || 0 === $pass ? 1 : 0 );
}
