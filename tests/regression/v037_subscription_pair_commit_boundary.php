<?php
/**
 * Exact pair oracle: the REAL Core fulfillment-profile coordinator drives the
 * REAL ECPay Plugin/StoreSelector claim across one shared DB boundary, so the
 * profile generation CAS and the provider's one-use selection claim must
 * commit or roll back together — including under COMMIT-acknowledgement loss.
 *
 * Requires the Core candidate: set YS_CORE_ROOT, or place ys-cart as sibling.
 * Run: php -n tests/regression/v037_subscription_pair_commit_boundary.php
 */

declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_' );
	define( 'ARRAY_A', 'ARRAY_A' );

	$GLOBALS['v037_user_id'] = 7;
	$GLOBALS['v037_password_counter'] = 0;
	$GLOBALS['v037_transients'] = [];
	/** @var list<array{0:string,1:string}> */
	$GLOBALS['v037_transient_calls'] = [];
	$GLOBALS['v037_claim_calls'] = 0;
	$GLOBALS['v037_swap_to'] = null;
	$GLOBALS['v037_drift_before_claim'] = false;
	$GLOBALS['v037_drift_after_claim'] = false;

	function absint( mixed $value ): int { return abs( (int) $value ); }
	function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
	function sanitize_email( mixed $value ): string { return trim( (string) $value ); }
	function sanitize_key( mixed $value ): string {
		return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) );
	}
	function wp_unslash( mixed $value ): mixed { return $value; }
	function esc_url_raw( string $url ): string { return $url; }
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}
	function get_current_user_id(): int { return (int) $GLOBALS['v037_user_id']; }
	function is_user_logged_in(): bool { return get_current_user_id() > 0; }
	function current_time( string $type ): int|string {
		return 'timestamp' === $type ? 1788062400 : '2026-08-30 12:00:00';
	}
	function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
		++$GLOBALS['v037_password_counter'];
		$char = chr( 65 + ( $GLOBALS['v037_password_counter'] % 26 ) );
		return str_repeat( $char, $length );
	}
	function set_transient( string $key, mixed $value, int $ttl ): bool {
		$GLOBALS['v037_transient_calls'][] = [ 'set', $key ];
		$GLOBALS['v037_transients'][ $key ] = $value;
		return true;
	}
	function get_transient( string $key ): mixed {
		$GLOBALS['v037_transient_calls'][] = [ 'get', $key ];
		return $GLOBALS['v037_transients'][ $key ] ?? false;
	}
	function delete_transient( string $key ): bool {
		$GLOBALS['v037_transient_calls'][] = [ 'delete', $key ];
		if ( ! array_key_exists( $key, $GLOBALS['v037_transients'] ) ) {
			return false;
		}
		unset( $GLOBALS['v037_transients'][ $key ] );
		return true;
	}
	function rest_url( string $path = '' ): string { return 'https://shop.example.test/wp-json/' . ltrim( $path, '/' ); }
	function wp_is_mobile(): bool { return false; }
	function home_url( string $path = '' ): string { return 'https://shop.example.test' . $path; }
	function wp_parse_url( string $url ): array|false { return parse_url( $url ); }
	function add_query_arg( array $args, string $url ): string {
		$separator = str_contains( $url, '?' ) ? '&' : '?';
		return $url . $separator . http_build_query( $args );
	}

	/** Routes the two typed fulfillment filters into the REAL ECPay plugin. */
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		$plugin = $GLOBALS['v037_plugin'] ?? null;
		if ( null === $plugin ) {
			return $value;
		}
		if ( 'ys_ec_resolve_fulfillment_selection_v1' === $hook ) {
			return $plugin->resolve_fulfillment_selection(
				$value,
				is_array( $args[0] ?? null ) ? $args[0] : [],
				is_array( $args[1] ?? null ) ? $args[1] : []
			);
		}
		if ( 'ys_ec_claim_fulfillment_selection_v1' === $hook ) {
			++$GLOBALS['v037_claim_calls'];
			// Drift fixture: swap the GLOBAL connection right before the provider
			// claim runs, exactly as a mid-request reconnect/swap would.
			if ( is_object( $GLOBALS['v037_swap_to'] ?? null ) ) {
				$GLOBALS['wpdb'] = $GLOBALS['v037_swap_to'];
			}
			// Physical-session drift on the SAME object: the old session (and its
			// open transaction, holding the Core CAS) dies before the claim runs.
			if ( true === ( $GLOBALS['v037_drift_before_claim'] ?? false ) ) {
				$GLOBALS['v037_drift_before_claim'] = false;
				$GLOBALS['wpdb']->reconnect();
			}
			$claim_result = $plugin->claim_fulfillment_selection(
				$value,
				is_array( $args[0] ?? null ) ? $args[0] : [],
				is_array( $args[1] ?? null ) ? $args[1] : [],
				is_array( $args[2] ?? null ) ? $args[2] : []
			);
			// Physical-session drift right AFTER a successful claim: the
			// coordinator's pre-COMMIT session verify must catch it.
			if ( true === ( $GLOBALS['v037_drift_after_claim'] ?? false ) ) {
				$GLOBALS['v037_drift_after_claim'] = false;
				$GLOBALS['wpdb']->reconnect();
			}
			return $claim_result;
		}
		return $value;
	}

	/**
	 * One shared DB boundary for the pair: the Core subscription row and the
	 * ECPay durable selection rows live inside the SAME emulated transaction.
	 * commit_mode fixtures make an acknowledgement-lost COMMIT deterministic:
	 * 'ack_lost_committed'  -> server committed, client saw false
	 * 'ack_lost_rolled_back'-> server kept the txn open; the follow-up ROLLBACK
	 *                          acknowledgement restores the pre-txn state
	 */
	final class V037PairWpdb {
		public string $prefix = 'wp_';
		public string $options = 'wp_options';
		public string $last_error = '';
		public bool $ready = true;
		public string $commit_mode = 'ok';
		public bool $rollback_fail = false;
		public int $starts = 0;
		public int $commits = 0;
		public int $rollbacks = 0;
		public int $closed = 0;
		public int $cas_updates = 0;
		public int $queries_after_close = 0;
		// Physical-session model: same PHP object, changeable MySQL session.
		public int $session_id = 9101;
		public string $dbname = 'wp_test_db';
		/** @var array<string,string> */
		public array $session_vars = [];
		public bool $drop_on_next_update = false;
		public bool $drop_on_commit = false;
		public int $consume_update_evaluations = 0;
		/** @var array<string,string> */
		public array $rows = [];
		/** @var array<string,string> */
		public array $autoload = [];
		public ?string $engine = 'InnoDB';
		/** @var list<string> */
		public array $statement_log = [];
		private ?array $snapshot = null;

		public function prepare( string $sql, mixed ...$args ): string {
			return "\x00PREP\x00" . json_encode( [ $sql, $args ] );
		}

		/** @return array{0:string,1:list<mixed>} */
		private function decode( string $sql ): array {
			if ( str_starts_with( $sql, "\x00PREP\x00" ) ) {
				$decoded = json_decode( substr( $sql, 6 ), true );
				return [ (string) ( $decoded[0] ?? '' ), array_values( (array) ( $decoded[1] ?? [] ) ) ];
			}
			return [ $sql, [] ];
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		private function capture(): void {
			$row = \YangSheep\Ecommerce\Models\V037SubscriptionState::$rows[41] ?? null;
			$this->snapshot = [ $row ? clone $row : null, $this->rows, $this->autoload ];
		}

		private function restore(): void {
			if ( ! is_array( $this->snapshot ) ) {
				return;
			}
			[ $row, $rows, $autoload ] = $this->snapshot;
			if ( $row ) {
				\YangSheep\Ecommerce\Models\V037SubscriptionState::$rows[41] = clone $row;
			}
			$this->rows = $rows;
			$this->autoload = $autoload;
			$this->snapshot = null;
		}

		public function close(): bool {
			++$this->closed;
			$this->restore();
			return true;
		}

		/** A dropped connection: the old session's txn dies (rolls back), the
		 *  same object reconnects as a NEW session with empty session vars. */
		public function reconnect(): void {
			$this->restore();
			++$this->session_id;
			$this->session_vars = [];
		}

		/** @return array{0:list<mixed>,1:bool} */
		private function split_fence( string $template, array $args ): array {
			if ( ! str_contains( $template, 'CONNECTION_ID()' ) ) {
				return [ $args, true ];
			}
			$fence = array_splice( $args, -3 );
			$ok = (string) ( $fence[0] ?? '' ) === (string) $this->session_id
				&& (string) ( $fence[1] ?? '' ) === $this->dbname
				&& (string) ( $fence[2] ?? '' ) === (string) ( $this->session_vars['@ys_profile_tx_owner'] ?? "\x00unset" );
			return [ $args, $ok ];
		}

		public function get_row( string $sql, string $output = 'OBJECT' ): mixed {
			[ $template, $args ] = $this->decode( $sql );
			$this->last_error = '';
			if ( str_starts_with( $template, 'SELECT * FROM wp_ys_ec_subscriptions WHERE id = %d' ) ) {
				[ $args, $fence_ok ] = $this->split_fence( $template, $args );
				$row = \YangSheep\Ecommerce\Models\V037SubscriptionState::$rows[ (int) ( $args[0] ?? 0 ) ] ?? null;
				return $fence_ok && $row ? clone $row : null;
			}
			if ( str_contains( $template, ') AS cid,' ) ) {
				unset( $args );
				$row = [
					'cid'    => (string) $this->session_id,
					'dbname' => $this->dbname,
					'owner'  => $this->session_vars['@ys_profile_tx_owner'] ?? null,
				];
				return ARRAY_A === $output ? $row : (object) $row;
			}
			if ( str_contains( $template, 'SHOW TABLE STATUS WHERE Name' ) ) {
				if ( null === $this->engine ) {
					return null;
				}
				$row = [ 'Name' => (string) ( $args[0] ?? '' ), 'Engine' => $this->engine ];
				return ARRAY_A === $output ? $row : (object) $row;
			}
			return null;
		}

		public function get_var( string $sql ): ?string {
			[ $template, $args ] = $this->decode( $sql );
			if ( $this->closed > 0 ) {
				++$this->queries_after_close;
			}
			$this->last_error = '';
			if ( str_contains( $template, 'SELECT option_value FROM' ) ) {
				return $this->rows[ (string) ( $args[0] ?? '' ) ] ?? null;
			}
			return null;
		}

		/** @return list<array<string,string>> */
		public function get_results( string $sql, string $output = 'OBJECT' ): array {
			[ $template, $args ] = $this->decode( $sql );
			$this->last_error = '';
			unset( $output );
			if ( ! str_contains( $template, 'SELECT option_name, option_value FROM' ) ) {
				return [];
			}
			$like = (string) ( $args[0] ?? '' );
			$prefix = str_replace( [ '\\_', '\\%', '\\\\' ], [ '_', '%', '\\' ], rtrim( $like, '%' ) );
			$limit = (int) ( $args[1] ?? 0 );
			$found = [];
			foreach ( $this->rows as $name => $value ) {
				if ( ! str_starts_with( $name, $prefix ) ) {
					continue;
				}
				$found[] = [ 'option_name' => $name, 'option_value' => $value ];
				if ( $limit > 0 && count( $found ) >= $limit ) {
					break;
				}
			}
			return $found;
		}

		public function insert( string $table, array $data, mixed $format = null ): int|false {
			unset( $format );
			$this->last_error = '';
			if ( $table !== $this->options ) {
				return false;
			}
			$name = (string) ( $data['option_name'] ?? '' );
			if ( '' === $name || array_key_exists( $name, $this->rows ) ) {
				$this->last_error = "Duplicate entry '{$name}' for key 'option_name'";
				return false;
			}
			$this->rows[ $name ] = (string) ( $data['option_value'] ?? '' );
			$this->autoload[ $name ] = (string) ( $data['autoload'] ?? '' );
			return 1;
		}

		public function query( string $sql ): int|false {
			[ $template, $args ] = $this->decode( $sql );
			$this->statement_log[] = $template;
			if ( $this->closed > 0 ) {
				++$this->queries_after_close;
			}
			$this->last_error = '';
			if ( str_starts_with( $template, 'SET @ys_profile_tx_owner' ) ) {
				$this->session_vars['@ys_profile_tx_owner'] = (string) ( $args[0] ?? '' );
				return 0;
			}
			if ( str_starts_with( $template, 'UPDATE wp_ys_ec_subscriptions' ) ) {
				++\YangSheep\Ecommerce\Models\V037SubscriptionState::$cas_calls;
				[ $args, $fence_ok ] = $this->split_fence( $template, $args );
				[ $canonical, $hash, $total, $updated, , $id, $generation ] = $args;
				$row = \YangSheep\Ecommerce\Models\V037SubscriptionState::$rows[ (int) $id ] ?? null;
				if ( ! $fence_ok || \YangSheep\Ecommerce\Models\V037SubscriptionState::$force_cas_loss
					|| ! $row || (int) $row->fulfillment_profile_generation !== (int) $generation
					|| ! in_array( $row->status, [ 'pending', 'active', 'on-hold', 'suspended' ], true ) ) { return 0; }
				$row->fulfillment_profile = $canonical;
				$row->fulfillment_profile_hash = $hash;
				$row->renewal_shipping_total = $total;
				$row->fulfillment_profile_updated_at = $updated;
				$row->fulfillment_profile_generation = (int) $generation + 1;
				return 1;
			}
			if ( 'START TRANSACTION' === $template ) {
				++$this->starts;
				$this->capture();
				return 1;
			}
			if ( 'ROLLBACK' === $template ) {
				++$this->rollbacks;
				if ( $this->rollback_fail ) {
					return false;
				}
				$this->restore();
				return 1;
			}
			if ( 'COMMIT' === $template ) {
				++$this->commits;
				if ( $this->drop_on_commit ) {
					// 2006 during COMMIT: wpdb reconnects and resends COMMIT on
					// the new session — a no-op ack while the old txn died.
					$this->reconnect();
					return 1;
				}
				if ( 'ok' === $this->commit_mode ) {
					$this->snapshot = null;
					return 1;
				}
				if ( 'ack_lost_committed' === $this->commit_mode ) {
					// Server committed; only the acknowledgement was lost.
					$this->snapshot = null;
					return false;
				}
				// ack_lost_rolled_back: the server never applied it — keep the
				// snapshot so the caller's protective ROLLBACK restores state.
				return false;
			}
			if ( str_starts_with( $template, 'UPDATE ' ) && str_contains( $template, 'option_value = BINARY' ) ) {
				++$this->cas_updates;
				++$this->consume_update_evaluations;
				if ( $this->drop_on_next_update ) {
					// 2006 on THIS statement: wpdb reconnects and resends it on
					// the new session; the caller only sees the resend's result.
					$this->drop_on_next_update = false;
					$this->reconnect();
				}
				[ $args, $fence_ok ] = $this->split_fence( $template, $args );
				[ $new_value, $name, $old_value ] = [ (string) $args[0], (string) $args[1], (string) $args[2] ];
				if ( $fence_ok && array_key_exists( $name, $this->rows ) && $this->rows[ $name ] === $old_value ) {
					$this->rows[ $name ] = $new_value;
					return 1;
				}
				return 0;
			}
			if ( str_starts_with( $template, 'DELETE FROM' ) ) {
				$name = (string) ( $args[0] ?? '' );
				if ( ! array_key_exists( $name, $this->rows ) ) {
					return 0;
				}
				if ( str_contains( $template, 'option_value = BINARY' )
					&& $this->rows[ $name ] !== (string) ( $args[1] ?? '' ) ) {
					return 0;
				}
				unset( $this->rows[ $name ], $this->autoload[ $name ] );
				return 1;
			}
			return 1;
		}
	}
	$GLOBALS['wpdb'] = new V037PairWpdb();
}

namespace YangSheep\Ecommerce\Models {
	final class YSProduct {
		public static function find( int $id ): ?object {
			return 501 === $id ? (object) [
				'id' => 501, 'type' => 'subscription', 'is_virtual' => 0,
				'has_digital_files' => 0, 'stock_qty' => -1, 'weight' => 1000,
			] : null;
		}
	}

	/** In-memory database rows only; production Core owns reads, locks and CAS. */
	final class V037SubscriptionState {
		public static array $rows = [];
		public static bool $force_cas_loss = false;
		public static int $cas_calls = 0;
	}

}

namespace YangSheep\Ecommerce\Shipping {
	final class YSShippingRegistry {
		public static bool $enabled = true;
		public static string $provider = 'ecpay';
		public static array $availability_log = [];
		public static function configured_enabled_method_ids(): array {
			return self::$enabled ? [ 'ys_ec_ecpay_ship_unimart' ] : [];
		}
		public static function get_available_for_subscription_profile( array $data, ?array $allowed ): array {
			self::$availability_log[] = $data;
			$id = 'ys_ec_ecpay_ship_unimart';
			$method = self::get_operable( $id );
			return self::$enabled && $method && ( null === $allowed || in_array( $id, $allowed, true ) )
				? [ $id => $method ] : [];
		}
		public static function get( string $id ): ?object { return self::get_operable( $id ); }
		public static function is_method_allowed_for_cart( string $id, array $items ): bool {
			unset( $id, $items );
			return true;
		}
		public static function get_operable( string $id ): ?object {
			if ( ! self::$enabled || 'ys_ec_ecpay_ship_unimart' !== $id ) {
				return null;
			}
			return new class() {
				public function get_id(): string { return 'ys_ec_ecpay_ship_unimart'; }
				public function get_provider(): string { return YSShippingRegistry::$provider; }
				public function get_type(): string { return 'cvs'; }
				public function get_title(): string { return 'ECPay Store'; }
				public function supports_cod(): bool { return false; }
				public function calculate_cost( array $items, array $address = [] ): float {
					unset( $items, $address );
					return 65.0;
				}
			};
		}
	}
}

namespace YangSheep\Ecommerce\Api\Storefront {
	final class YSRestAuth {
		public static function validate_guest_token( string $token ): bool { return false; }
		public static function get_guest_token(): ?string { return null; }
	}
}

namespace YangSheep\Ecommerce\Services\Setup {
	final class YSPageResolver {
		public static function checkout_url(): string { return 'https://shop.example.test/checkout/'; }
	}
}

namespace YangSheep\YSCartEcpay\Support {
	final class CheckMacValue {
		/** @param array<string,string> $fields */
		public static function generate( array $fields, string $key, string $iv, string $mode ): string { return 'CHECKMAC'; }
	}

	final class ProviderMaintenanceLock {
		public static function reader_lease(): object { return (object) [ 'token' => 'lease-token' ]; }
		public static function reader_fence( string $token ): bool { return 'lease-token' === $token; }
	}

	final class Settings {
		/** @return array{merchant_id:string,hash_key:string,hash_iv:string} */
		public static function logistics_credentials_for_method( string $method ): array {
			return [ 'merchant_id' => '2000132', 'hash_key' => 'key', 'hash_iv' => 'iv' ];
		}
		public static function logistics_endpoint( string $path, string $method ): string {
			return 'https://logistics-stage.ecpay.test' . $path;
		}
		public static function get( string $key, string $default = '' ): string { return $default; }
		public static function shipping_method_option( string $method, string $key, string $default = '' ): string {
			unset( $method, $key );
			return $default;
		}
	}

	final class ShippingMethodOperability {
		public static function is_operable( string $method ): bool {
			return 'ys_ec_ecpay_ship_unimart' === $method;
		}
		public static function has_operable_method(): bool { return true; }
		public static function is_configured( string $method ): bool { return self::is_operable( $method ); }
	}
}

namespace YangSheep\YSCartEcpay\Shipping\Ecpay {
	class EcpayShipping {
		public function supports_cod(): bool { return true; }
		public function get_logistics_subtype(): string { return 'UNIMARTC2C'; }
	}

	final class V037EcpayShipping extends EcpayShipping {}

	final class EcpayShippingCatalog {
		public const TEMP_ROOM = 'ROOM';
		public const TEMP_CHILLED = 'CHILLED';
		public const TEMP_FROZEN = 'FROZEN';

		/** @return array<string,mixed>|null */
		public static function get( string $id ): ?array {
			if ( 'ys_ec_ecpay_ship_unimart' !== $id ) {
				return null;
			}
			return [
				'class'               => V037EcpayShipping::class,
				'logistics_type'      => 'CVS',
				'logistics_subtype'   => 'UNIMARTC2C',
				'requires_store'      => true,
				'cod_capable'         => true,
				'temperature'         => 'ROOM',
				'requires_goods_weight' => false,
			];
		}
		/** @return array<string,string> */
		public static function map_subtypes(): array {
			return [ 'ys_ec_ecpay_ship_unimart' => 'UNIMARTC2C' ];
		}
	}

	final class EcpayStoreDirectory {
		/** @return array{name:string,address:string,phone:string}|array{} */
		public static function lookup( string $subtype, string $store_id ): array {
			if ( 'UNIMARTC2C' !== $subtype || '991122' !== $store_id ) {
				return [];
			}
			return [ 'name' => 'Canonical Store', 'address' => 'No. 1 Store Rd.', 'phone' => '0212345678' ];
		}
		public static function schedule_refresh_soon(): void {}
	}
}

namespace {
	use YangSheep\Ecommerce\Models\YSSubscription;
	use YangSheep\Ecommerce\Models\V037SubscriptionState;
	use YangSheep\Ecommerce\Services\Subscription\YSSubscriptionFulfillmentProfileCoordinator;
	use YangSheep\Ecommerce\Services\Subscription\YSSubscriptionFulfillmentProfileService;
	use YangSheep\YSCartEcpay\Plugin;
	use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreSelector;

	$core_root = (string) ( getenv( 'YS_CORE_ROOT' ) ?: dirname( __DIR__, 3 ) . '/ys-cart' );
	$coordinator_path = $core_root . '/src/Services/Subscription/YSSubscriptionFulfillmentProfileCoordinator.php';
	if ( ! is_file( $coordinator_path ) ) {
		echo "FAIL Core candidate not found (set YS_CORE_ROOT): {$coordinator_path}\n";
		echo "subscription pair commit boundary: 0 PASS / 1 FAIL\n";
		exit( 1 );
	}

	$ecpay_root = (string) ( getenv( 'YS_ECPAY_ROOT' ) ?: dirname( __DIR__, 2 ) );
	require_once $ecpay_root . '/src/Support/CartScope.php';
	$store_path = $ecpay_root . '/src/Shipping/Ecpay/EcpaySubscriptionSelectionStore.php';
	if ( is_file( $store_path ) ) {
		require_once $store_path;
	}
	require_once $ecpay_root . '/src/Shipping/Ecpay/EcpayStoreSelector.php';
	require_once $ecpay_root . '/src/Plugin.php';

	require_once $core_root . '/src/Models/YSSubscription.php';
	require_once $core_root . '/src/Services/Shipping/YSShippingIdentifier.php';
	require_once $core_root . '/src/Services/Subscription/YSSubscriptionRecurringAmount.php';
	require_once $core_root . '/src/Utils/YSUtf8.php';
	require_once $core_root . '/src/Services/Shipping/YSFulfillmentSnapshotService.php';
	require_once $core_root . '/src/Services/Checkout/YSCheckoutFulfillmentService.php';
	require_once $core_root . '/src/Services/Subscription/YSSubscriptionFulfillmentProfileService.php';
	$boundary_path = $core_root . '/src/Services/Subscription/YSSubscriptionSharedDbBoundary.php';
	if ( is_file( $boundary_path ) ) {
		require_once $boundary_path;
	}
	require_once $coordinator_path;

	$pass = 0;
	$fail = 0;
	$check = static function ( string $label, bool $ok ) use ( &$pass, &$fail ): void {
		if ( $ok ) {
			++$pass;
			echo "PASS {$label}\n";
			return;
		}
		++$fail;
		echo "FAIL {$label}\n";
	};

	// error_log() reaches STDERR under CLI; the strict pair gate requires zero
	// unexpected STDERR, so typed evidence is captured in a scratch file.
	$pair_error_log_previous = ini_get( 'error_log' );
	$pair_error_log_file = sys_get_temp_dir() . '/yscart-v037-pair-' . getmypid() . '.log';
	@unlink( $pair_error_log_file );
	ini_set( 'error_log', $pair_error_log_file );

	$GLOBALS['v037_plugin'] = new Plugin();

	$boundary_class = 'YangSheep\\Ecommerce\\Services\\Subscription\\YSSubscriptionSharedDbBoundary';
	$reset_boundary = static function () use ( $boundary_class ): void {
		if ( ! class_exists( $boundary_class ) ) {
			return;
		}
		$reflection = new \ReflectionClass( $boundary_class );
		foreach ( [ 'poisoned' => false, 'reason' => '' ] as $property_name => $value ) {
			$property = $reflection->getProperty( $property_name );
			$property->setValue( null, $value );
		}
	};

	// Durable rows are keyed by the token DIGEST; raw token bytes never reach DB.
	$durable_state = static function ( string $token ): string {
		$bytes = $GLOBALS['wpdb']->rows[ 'ys_ec_ecpay_subsel_' . hash( 'sha256', $token ) ] ?? null;
		if ( ! is_string( $bytes ) ) {
			return '';
		}
		$decoded = json_decode( $bytes, true );
		return is_array( $decoded ) ? (string) ( $decoded['state'] ?? '' ) : '';
	};
	$selection_transient_calls = static function (): array {
		return array_values( array_filter(
			$GLOBALS['v037_transient_calls'],
			static fn ( array $call ): bool => str_starts_with( $call[1], 'ys_ec_ecpay_sel_' )
		) );
	};

	$base_profile = [
		'contract_version' => 1,
		'contract_kind' => 'physical',
		'allowed_shipping_methods' => [ 'home_old', 'ys_ec_ecpay_ship_unimart' ],
		'billing' => [
			'name' => 'Billing Owner', 'phone' => '0900000000', 'email' => 'owner@example.test',
			'country' => 'TW', 'postcode' => '100', 'state' => '', 'city' => 'Taipei',
			'district' => 'Zhongzheng', 'address' => 'Old Billing Rd.', 'address2' => '',
		],
		'invoice' => [
			'type' => 'electronic', 'buyer_type' => 'personal', 'carrier_type' => '',
			'carrier_id' => '', 'tax_id' => '', 'buyer_name' => '', 'donate_code' => '',
		],
		'shipping_method_id' => 'home_old',
		'shipping_provider' => 'provider-old',
		'shipping_total' => '75.00',
		'fulfillment_snapshot' => [
			'contract_version' => 1, 'provider_id' => 'provider-old', 'method_id' => 'home_old',
			'destination' => [
				'type' => 'home', 'recipient_name' => 'Old Recipient', 'recipient_phone' => '0911000000',
				'country' => 'TW', 'postcode' => '100', 'state' => '', 'city' => 'Taipei',
				'district' => 'Zhongzheng', 'address' => 'Old Shipping Rd.', 'address2' => '',
			],
			'service' => [
				'shipping_type' => 'home', 'temperature_class' => 'room',
				'payment_method_id' => 'ys_ec_ecpay_credit', 'collection_mode' => 'prepaid',
			],
			'parcel' => [ 'weight_kg' => '2.000', 'weight_source' => 'order_item_snapshot' ],
			'items' => [ [
				'line_key' => 'line-1', 'product_id' => 501, 'variant_id' => 0, 'quantity' => 2,
				'unit_weight_kg' => '1.000', 'total_weight_kg' => '2.000',
			] ],
		],
	];
	$normalized = YSSubscriptionFulfillmentProfileService::normalize_profile( $base_profile );
	if ( true !== ( $normalized['ok'] ?? false ) ) {
		echo "FAIL pair fixture profile is invalid\n";
		echo "subscription pair commit boundary: {$pass} PASS / " . ( $fail + 1 ) . " FAIL\n";
		exit( 1 );
	}

	$reset_subscription = static function () use ( $normalized ): void {
		V037SubscriptionState::$rows = [ 41 => (object) [
			'id' => 41, 'customer_id' => 91, 'user_id' => 7,
			'status' => 'active',
			'product_id' => 501, 'variant_id' => 0, 'quantity' => 2,
			'amount' => '500.00', 'next_amount' => null, 'gateway_id' => 'ys_ec_ecpay_credit',
			'fulfillment_contract_kind' => 'physical',
			'fulfillment_profile' => $normalized['canonical'],
			'fulfillment_profile_generation' => 3,
			'fulfillment_profile_hash' => $normalized['hash'],
			'renewal_shipping_total' => '75.00',
			'fulfillment_profile_updated_at' => '2026-08-30 11:00:00',
		] ];
		V037SubscriptionState::$force_cas_loss = false;
		V037SubscriptionState::$cas_calls = 0;
	};

	$issue = new \ReflectionMethod( EcpayStoreSelector::class, 'issue_selection_token' );
	$mint_token = static function () use ( $issue ): string {
		return (string) $issue->invoke( null, [
			'shipping_id'       => 'ys_ec_ecpay_ship_unimart',
			'cvs_type'          => 'UNIMARTC2C',
			'store_id'          => '991122',
			'store_name'        => 'Canonical Store',
			'store_address'     => 'No. 1 Store Rd.',
			'store_verified'    => 1,
			'collection_mode'   => 'N',
			'payment_method'    => 'ys_ec_ecpay_credit',
			'cart_scope'        => 'sub_41',
			'context'           => 'subscription',
			'subscription_id'   => 41,
			'authority_marker'  => 'subscription_fulfillment_v1',
		], 'u:7' );
	};
	$update_input = static function ( string $token ): array {
		return [
			'expected_generation' => 3,
			'shipping_method_id'  => 'ys_ec_ecpay_ship_unimart',
			'selection_token'     => $token,
			'billing_name'        => 'Pair Recipient',
			'billing_phone'       => '0912345678',
			'billing_country'     => 'TW',
			'cvs_store_id'        => '991122',
		];
	};
	$generation = static fn (): int => (int) ( V037SubscriptionState::$rows[41]->fulfillment_profile_generation ?? -1 );

	// Shared local-only fixture for the account-to-renewal vertical oracle.
	if ( defined( 'V037_FIXTURE_ONLY' ) && V037_FIXTURE_ONLY ) { return; }

	// ── p1: positive pair — CAS N->N+1 and issued->consumed commit together ──
	$reset_subscription();
	$token = $mint_token();
	$GLOBALS['v037_transient_calls'] = [];
	$positive = YSSubscriptionFulfillmentProfileCoordinator::update( 41, $update_input( $token ) );
	$check(
		'positive pair: generation N+1 and the consumed durable claim commit together',
		true === ( $positive['success'] ?? false )
			&& 4 === ( $positive['data']['generation'] ?? 0 )
			&& 4 === $generation()
			&& 'consumed' === $durable_state( $token )
			&& '991122' === ( $positive['data']['destination']['store_id'] ?? '' )
			&& 1 === $GLOBALS['wpdb']->starts
			&& 1 === $GLOBALS['wpdb']->commits
			&& [] === $selection_transient_calls()
	);

	// ── p2: frozen blocker — ambiguous COMMIT that actually rolled back must
	//        keep generation AND token jointly at their pre-transaction state.
	//        The ambiguous response itself stays manual/no-retry; the request
	//        never re-invokes the provider claim on its own. ──
	$reset_subscription();
	$GLOBALS['wpdb'] = new V037PairWpdb();
	$token = $mint_token();
	$claims_before_ambiguous = $GLOBALS['v037_claim_calls'];
	$GLOBALS['wpdb']->commit_mode = 'ack_lost_rolled_back';
	$ambiguous = YSSubscriptionFulfillmentProfileCoordinator::update( 41, $update_input( $token ) );
	$GLOBALS['wpdb']->commit_mode = 'ok';
	$check(
		'ack-lost COMMIT that rolled back keeps generation=N with the token still issued, claim invoked exactly once',
		false === ( $ambiguous['success'] ?? true )
			&& 'profile_update_commit_indeterminate' === ( $ambiguous['code'] ?? '' )
			&& false === ( $ambiguous['retryable'] ?? true )
			&& true === ( $ambiguous['requires_manual_reconciliation'] ?? false )
			&& 3 === $generation()
			&& 'issued' === $durable_state( $token )
			&& 1 === $GLOBALS['v037_claim_calls'] - $claims_before_ambiguous
	);
	// A NEW request, issued only after a human/reconciler confirmed the server
	// really rolled back, may reuse the still-issued token. This models manual
	// reconciliation — never an automatic retry of the ambiguous request.
	$manual_confirmed_new_request = YSSubscriptionFulfillmentProfileCoordinator::update( 41, $update_input( $token ) );
	$check(
		'after外部人工確認 rollback, a NEW request may consume the still-issued token to success',
		true === ( $manual_confirmed_new_request['success'] ?? false )
			&& 4 === $generation()
			&& 'consumed' === $durable_state( $token )
	);

	// ── p3: ambiguous COMMIT that actually committed leaves both sides at N+1/consumed ──
	$reset_subscription();
	$GLOBALS['wpdb'] = new V037PairWpdb();
	$token = $mint_token();
	$GLOBALS['wpdb']->commit_mode = 'ack_lost_committed';
	$committed_ambiguous = YSSubscriptionFulfillmentProfileCoordinator::update( 41, $update_input( $token ) );
	$GLOBALS['wpdb']->commit_mode = 'ok';
	$stale_retry = YSSubscriptionFulfillmentProfileCoordinator::update( 41, $update_input( $token ) );
	$check(
		'ack-lost COMMIT that committed leaves generation=N+1 with the token consumed, and the retry is stale',
		false === ( $committed_ambiguous['success'] ?? true )
			&& 'profile_update_commit_indeterminate' === ( $committed_ambiguous['code'] ?? '' )
			&& 4 === $generation()
			&& 'consumed' === $durable_state( $token )
			&& false === ( $stale_retry['success'] ?? true )
			&& 'stale_generation' === ( $stale_retry['code'] ?? '' )
	);

	// ── p4: a lost CAS never reaches the provider claim and never consumes ──
	$reset_subscription();
	$GLOBALS['wpdb'] = new V037PairWpdb();
	$token = $mint_token();
	V037SubscriptionState::$force_cas_loss = true;
	$claims_before = $GLOBALS['v037_claim_calls'];
	$cas_lost = YSSubscriptionFulfillmentProfileCoordinator::update( 41, $update_input( $token ) );
	V037SubscriptionState::$force_cas_loss = false;
	$check(
		'CAS loss rolls back before any claim; the durable token stays issued',
		false === ( $cas_lost['success'] ?? true )
			&& 'stale_generation' === ( $cas_lost['code'] ?? '' )
			&& $claims_before === $GLOBALS['v037_claim_calls']
			&& 'issued' === $durable_state( $token )
			&& 1 === $GLOBALS['wpdb']->rollbacks
	);

	// ── p6: same-handle fence — a global-connection swap right before the claim
	//        must never consume on the second handle; the original transaction
	//        rolls back with generation and token unchanged ──
	$reset_subscription();
	$first_pair_wpdb = new V037PairWpdb();
	$GLOBALS['wpdb'] = $first_pair_wpdb;
	$token = $mint_token();
	$second_pair_wpdb = new V037PairWpdb();
	$GLOBALS['v037_swap_to'] = $second_pair_wpdb;
	$drift_response = YSSubscriptionFulfillmentProfileCoordinator::update( 41, $update_input( $token ) );
	$GLOBALS['v037_swap_to'] = null;
	$GLOBALS['wpdb'] = $first_pair_wpdb;
	$second_handle_updates = array_values( array_filter(
		$second_pair_wpdb->statement_log,
		static fn ( string $template ): bool => str_starts_with( $template, 'UPDATE ' )
	) );
	$check(
		'a swapped global handle at claim time consumes nothing: token issued, N unchanged, zero UPDATE on the second handle',
		false === ( $drift_response['success'] ?? true )
			&& 3 === $generation()
			&& 'issued' === $durable_state( $token )
			&& 0 === $second_pair_wpdb->cas_updates
			&& [] === $second_handle_updates
			&& 1 === $first_pair_wpdb->starts
			&& 1 === $first_pair_wpdb->rollbacks
	);

	// ── p6b: drift plus an unacknowledged rollback must poison and close the
	//         ORIGINAL frozen connection — never the swapped-in second handle ──
	$reset_subscription();
	$first_pair_wpdb = new V037PairWpdb();
	$GLOBALS['wpdb'] = $first_pair_wpdb;
	$token = $mint_token();
	$second_pair_wpdb = new V037PairWpdb();
	$first_pair_wpdb->rollback_fail = true;
	$GLOBALS['v037_swap_to'] = $second_pair_wpdb;
	$drift_rollback_lost = YSSubscriptionFulfillmentProfileCoordinator::update( 41, $update_input( $token ) );
	$GLOBALS['v037_swap_to'] = null;
	$first_pair_wpdb->rollback_fail = false;
	$GLOBALS['wpdb'] = $first_pair_wpdb;
	$second_updates_after_drift = array_values( array_filter(
		$second_pair_wpdb->statement_log,
		static fn ( string $template ): bool => str_starts_with( $template, 'UPDATE ' )
	) );
	$check(
		'drift plus unacknowledged rollback closes only the original frozen handle and stops all work',
		false === ( $drift_rollback_lost['success'] ?? true )
			&& 'profile_update_rollback_indeterminate' === ( $drift_rollback_lost['code'] ?? '' )
			&& 1 === $first_pair_wpdb->closed
			&& false === $first_pair_wpdb->ready
			&& 0 === $first_pair_wpdb->queries_after_close
			&& 0 === $second_pair_wpdb->closed
			&& true === $second_pair_wpdb->ready
			&& 0 === $second_pair_wpdb->cas_updates
			&& [] === $second_updates_after_drift
			&& 'issued' === $durable_state( $token )
			&& 3 === $generation()
	);
	$reset_boundary();

	// ── p7: same PHP object, NEW physical session after the Core CAS — the
	//        durable consume must be fenced to zero rows on the new session ──
	$reset_subscription();
	$GLOBALS['wpdb'] = new V037PairWpdb();
	$token = $mint_token();
	$GLOBALS['v037_drift_before_claim'] = true;
	$claims_before_p7 = $GLOBALS['v037_claim_calls'];
	$p7 = YSSubscriptionFulfillmentProfileCoordinator::update( 41, $update_input( $token ) );
	$check(
		'post-CAS physical drift on the SAME object never consumes on the new session: joint rollback, no split',
		false === ( $p7['success'] ?? true )
			&& 3 === $generation()
			&& 'issued' === $durable_state( $token )
			&& 1 === $GLOBALS['v037_claim_calls'] - $claims_before_p7
			&& 0 === $GLOBALS['wpdb']->commits
	);

	// ── p8: 2006 lands ON the consume UPDATE itself; wpdb resends it on the
	//        reconnected session — the resend must match zero rows ──
	$reset_subscription();
	$GLOBALS['wpdb'] = new V037PairWpdb();
	$token = $mint_token();
	$GLOBALS['wpdb']->drop_on_next_update = true;
	$claims_before_p8 = $GLOBALS['v037_claim_calls'];
	$p8 = YSSubscriptionFulfillmentProfileCoordinator::update( 41, $update_input( $token ) );
	$check(
		'a consume UPDATE resent after 2006 is fenced to zero rows: generation and token never split',
		false === ( $p8['success'] ?? true )
			&& 3 === $generation()
			&& 'issued' === $durable_state( $token )
			&& 1 === $GLOBALS['v037_claim_calls'] - $claims_before_p8
			&& 1 === $GLOBALS['wpdb']->consume_update_evaluations
			&& 0 === $GLOBALS['wpdb']->commits
	);

	// ── p9: drift right AFTER the successful claim — the pre-COMMIT session
	//        verify must refuse to COMMIT; both sides roll back together ──
	$reset_subscription();
	$GLOBALS['wpdb'] = new V037PairWpdb();
	$token = $mint_token();
	$GLOBALS['v037_drift_after_claim'] = true;
	$p9 = YSSubscriptionFulfillmentProfileCoordinator::update( 41, $update_input( $token ) );
	$check(
		'identity drift after the claim is caught BEFORE COMMIT: typed manual failure, zero COMMIT, joint rollback',
		false === ( $p9['success'] ?? true )
			&& 'profile_update_session_drift' === ( $p9['code'] ?? '' )
			&& false === ( $p9['retryable'] ?? true )
			&& true === ( $p9['requires_manual_reconciliation'] ?? false )
			&& 0 === $GLOBALS['wpdb']->commits
			&& 3 === $generation()
			&& 'issued' === $durable_state( $token )
	);

	// ── p10: 2006 during COMMIT — the resent COMMIT acks a no-op on the new
	//         session; the post-COMMIT verify must report manual indeterminate ──
	$reset_subscription();
	$GLOBALS['wpdb'] = new V037PairWpdb();
	$token = $mint_token();
	$GLOBALS['wpdb']->drop_on_commit = true;
	$p10 = YSSubscriptionFulfillmentProfileCoordinator::update( 41, $update_input( $token ) );
	$check(
		'a COMMIT resent on a new session becomes manual indeterminate with generation and token jointly rolled back',
		false === ( $p10['success'] ?? true )
			&& 'profile_update_commit_indeterminate' === ( $p10['code'] ?? '' )
			&& true === ( $p10['requires_manual_reconciliation'] ?? false )
			&& 1 === $GLOBALS['wpdb']->commits
			&& 3 === $generation()
			&& 'issued' === $durable_state( $token )
	);

	// The fence must be ONE cross-repo spelling: ECPay's consume predicate uses
	// byte-for-byte the fragment the loaded production Core model freezes.
	$fence_fragment = 'CAST(CAST(CONNECTION_ID() AS CHAR) AS BINARY) = CAST(%s AS BINARY)'
		. ' AND CAST(DATABASE() AS BINARY) = CAST(%s AS BINARY)'
		. ' AND CAST(CAST(@ys_profile_tx_owner AS CHAR) AS BINARY) = CAST(%s AS BINARY)';
	$store_fence_class = 'YangSheep\\YSCartEcpay\\Shipping\\Ecpay\\EcpaySubscriptionSelectionStore';
	$check(
		'Core model and ECPay store freeze the identical physical-session fence spelling',
		defined( $store_fence_class . '::PROFILE_SESSION_FENCE_SQL_V1' )
			&& constant( $store_fence_class . '::PROFILE_SESSION_FENCE_SQL_V1' ) === $fence_fragment
			&& YSSubscription::PROFILE_SESSION_FENCE_SQL_V1 === $fence_fragment
	);

	// ── p5: a legacy ordinary transient token cannot enter the subscription scope ──
	$reset_subscription();
	$GLOBALS['wpdb'] = new V037PairWpdb();
	$legacy_token = str_repeat( 'Z', 32 );
	set_transient( 'ys_ec_ecpay_sel_' . $legacy_token, [
		'shipping_id'       => 'ys_ec_ecpay_ship_unimart',
		'logistics_subtype' => 'UNIMARTC2C',
		'store_id'          => '991122',
		'store_name'        => 'Canonical Store',
		'store_address'     => 'No. 1 Store Rd.',
		'store_verified'    => 1,
		'collection_mode'   => 'N',
		'payment_method'    => 'ys_ec_ecpay_credit',
		'cart_scope'        => 'sub_41',
		'context'           => 'subscription',
		'authority_marker'  => '',
		'subscription_id'   => 0,
		'principal'         => 'u:7',
		'issued_at'         => 1788062400,
	], 1800 );
	$legacy = YSSubscriptionFulfillmentProfileCoordinator::update( 41, $update_input( $legacy_token ) );
	$check(
		'legacy ordinary token is rejected in subscription scope before any claim or CAS advance',
		false === ( $legacy['success'] ?? true )
			&& 3 === $generation()
			&& 0 === $GLOBALS['wpdb']->cas_updates
	);

	$pair_evidence = is_file( $pair_error_log_file ) ? (string) file_get_contents( $pair_error_log_file ) : '';
	ini_set( 'error_log', is_string( $pair_error_log_previous ) ? $pair_error_log_previous : '' );
	@unlink( $pair_error_log_file );
	$check(
		'both ambiguous COMMIT outcomes left typed non-DB reconciliation evidence',
		2 === substr_count( $pair_evidence, 'profile_update_commit_indeterminate subscription_id=41' )
	);

	echo "subscription pair commit boundary: {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 ? 1 : 0 );
}
