<?php
/**
 * Behavioral regression: subscription-bound store selections live in a durable
 * raw-wpdb options row (InnoDB-proven, autoload=no) so their one-use claim can
 * ride the caller's InnoDB transaction as an exact issued->consumed byte CAS.
 * Ordinary checkout keeps the existing transient claim path untouched.
 *
 * Run: php -n tests/regression/v036_subscription_selection_durable_claim.php
 */

declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_' );
	define( 'ARRAY_A', 'ARRAY_A' );

	$GLOBALS['v036_user_id'] = 7;
	$GLOBALS['v036_password_counter'] = 0;
	$GLOBALS['v036_transients'] = [];
	/** @var list<array{0:string,1:string}> */
	$GLOBALS['v036_transient_calls'] = [];

	function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
	function sanitize_key( mixed $value ): string {
		return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) );
	}
	function absint( mixed $value ): int { return abs( (int) $value ); }
	function wp_unslash( mixed $value ): mixed { return $value; }
	function esc_url_raw( string $url ): string { return $url; }
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}
	function get_current_user_id(): int { return (int) $GLOBALS['v036_user_id']; }
	function is_user_logged_in(): bool { return get_current_user_id() > 0; }
	function current_time( string $type ): int|string {
		return 'timestamp' === $type ? 1788062400 : '2026-08-30 12:00:00';
	}
	function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
		++$GLOBALS['v036_password_counter'];
		$char = chr( 65 + ( $GLOBALS['v036_password_counter'] % 26 ) );
		return str_repeat( $char, $length );
	}
	function set_transient( string $key, mixed $value, int $ttl ): bool {
		$GLOBALS['v036_transient_calls'][] = [ 'set', $key ];
		$GLOBALS['v036_transients'][ $key ] = $value;
		return true;
	}
	function get_transient( string $key ): mixed {
		$GLOBALS['v036_transient_calls'][] = [ 'get', $key ];
		return $GLOBALS['v036_transients'][ $key ] ?? false;
	}
	function delete_transient( string $key ): bool {
		$GLOBALS['v036_transient_calls'][] = [ 'delete', $key ];
		if ( ! array_key_exists( $key, $GLOBALS['v036_transients'] ) ) {
			return false;
		}
		unset( $GLOBALS['v036_transients'][ $key ] );
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

	/**
	 * Raw-wpdb options-table emulator. prepare() returns a decodable sentinel so
	 * routing happens on the exact SQL template plus its exact argument bytes —
	 * never on a re-parsed quoted string.
	 */
	final class V036OptionsWpdb {
		public string $prefix = 'wp_';
		public string $options = 'wp_options';
		public string $last_error = '';
		public bool $ready = true;
		/** @var array<string,string> */
		public array $rows = [];
		/** @var array<string,string> */
		public array $autoload = [];
		public ?string $engine = 'InnoDB';
		/** @var list<string> */
		public array $statement_log = [];
		/** @var list<mixed> */
		public array $prepare_args = [];

		public function prepare( string $sql, mixed ...$args ): string {
			$this->prepare_args = $args;
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

		public function get_row( string $sql, string $output = 'OBJECT' ): mixed {
			[ $template, $args ] = $this->decode( $sql );
			$this->last_error = '';
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
			unset( $output );
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
			$this->last_error = '';
			if ( str_starts_with( $template, 'UPDATE ' ) && str_contains( $template, 'option_value = BINARY' ) ) {
				[ $new_value, $name, $old_value ] = [ (string) $args[0], (string) $args[1], (string) $args[2] ];
				if ( array_key_exists( $name, $this->rows ) && $this->rows[ $name ] === $old_value ) {
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
			return 0;
		}
	}
	$GLOBALS['wpdb'] = new V036OptionsWpdb();
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
	}

	final class ShippingMethodOperability {
		public static function is_operable( string $method ): bool {
			return in_array( $method, [ 'ys_ec_ecpay_ship_unimart', 'ys_ec_ecpay_ship_hilife' ], true );
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

	final class V036EcpayShipping extends EcpayShipping {}

	final class EcpayShippingCatalog {
		public const TEMP_ROOM = 'ROOM';
		public const TEMP_CHILLED = 'CHILLED';
		public const TEMP_FROZEN = 'FROZEN';

		/** @return array<string,mixed>|null */
		public static function get( string $id ): ?array {
			if ( ! in_array( $id, [ 'ys_ec_ecpay_ship_unimart', 'ys_ec_ecpay_ship_hilife' ], true ) ) {
				return null;
			}
			return [
				'class'               => V036EcpayShipping::class,
				'logistics_type'      => 'CVS',
				'logistics_subtype'   => 'ys_ec_ecpay_ship_unimart' === $id ? 'UNIMARTC2C' : 'HILIFEC2C',
				'requires_store'      => true,
				'cod_capable'         => true,
				'temperature'         => 'ROOM',
				'requires_goods_weight' => false,
			];
		}
		/** @return array<string,string> */
		public static function map_subtypes(): array {
			return [
				'ys_ec_ecpay_ship_unimart' => 'UNIMARTC2C',
				'ys_ec_ecpay_ship_hilife'  => 'HILIFEC2C',
			];
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
	use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreSelector;

	$root = dirname( __DIR__, 2 );
	require_once $root . '/src/Support/CartScope.php';
	$store_path = $root . '/src/Shipping/Ecpay/EcpaySubscriptionSelectionStore.php';
	$store_ready = is_file( $store_path );
	if ( $store_ready ) {
		require_once $store_path;
	}
	require_once $root . '/src/Shipping/Ecpay/EcpayStoreSelector.php';

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

	$store_class = 'YangSheep\\YSCartEcpay\\Shipping\\Ecpay\\EcpaySubscriptionSelectionStore';
	$reset_store_proof = static function () use ( $store_ready, $store_class ): void {
		if ( ! $store_ready ) {
			return;
		}
		$reflection = new \ReflectionClass( $store_class );
		$property = $reflection->getProperty( 'engine_proof' );
		$property->setAccessible( true );
		$property->setValue( null, null );
	};
	// The durable row is keyed by the token DIGEST; raw token bytes never enter
	// the options table (neither the name nor the value).
	$durable_name = static fn ( string $token ): string => 'ys_ec_ecpay_subsel_' . hash( 'sha256', $token );
	$durable_row = static function ( string $token ) use ( $durable_name ): ?array {
		$bytes = $GLOBALS['wpdb']->rows[ $durable_name( $token ) ] ?? null;
		if ( ! is_string( $bytes ) ) {
			return null;
		}
		$decoded = json_decode( $bytes, true );
		return is_array( $decoded ) ? $decoded : null;
	};
	$selection_transient_calls = static function (): array {
		return array_values( array_filter(
			$GLOBALS['v036_transient_calls'],
			static fn ( array $call ): bool => str_starts_with( $call[1], 'ys_ec_ecpay_sel_' )
		) );
	};

	$shipping = 'ys_ec_ecpay_ship_unimart';
	$payment = 'ys_ec_ecpay_credit';
	$scope = 'sub_41';
	$issue = new \ReflectionMethod( EcpayStoreSelector::class, 'issue_selection_token' );
	$issue->setAccessible( true );
	$subscription_selection = [
		'shipping_id'       => $shipping,
		'cvs_type'          => 'UNIMARTC2C',
		'store_id'          => '991122',
		'store_name'        => 'Canonical Store',
		'store_address'     => 'No. 1 Store Rd.',
		'store_verified'    => 1,
		'collection_mode'   => 'N',
		'payment_method'    => $payment,
		'cart_scope'        => $scope,
		'context'           => 'subscription',
		'subscription_id'   => 41,
		'authority_marker'  => 'subscription_fulfillment_v1',
	];

	// ── storage guard: engine must be proven InnoDB, else fail closed ──
	$GLOBALS['wpdb']->engine = 'MyISAM';
	$myisam_token = (string) $issue->invoke( null, $subscription_selection, 'u:7' );
	$reset_store_proof();
	$GLOBALS['wpdb']->engine = null;
	$missing_engine_token = (string) $issue->invoke( null, $subscription_selection, 'u:7' );
	$reset_store_proof();
	$GLOBALS['wpdb']->engine = 'InnoDB';
	$check(
		'subscription issuance fails closed unless the options table is proven InnoDB',
		'' === $myisam_token
			&& '' === $missing_engine_token
			&& [] === $GLOBALS['wpdb']->rows
	);

	// ── fresh subscription issuance is durable: raw row, digest key, autoload=no, zero cache use ──
	$GLOBALS['v036_transient_calls'] = [];
	$token = (string) $issue->invoke( null, $subscription_selection, 'u:7' );
	$row = $durable_row( $token );
	$row_bytes = (string) ( $GLOBALS['wpdb']->rows[ $durable_name( $token ) ] ?? '' );
	$check(
		'fresh subscription selection issues a durable issued row with autoload=no and an absolute expiry',
		'' !== $token
			&& is_array( $row )
			&& 'issued' === ( $row['state'] ?? '' )
			&& 'no' === ( $GLOBALS['wpdb']->autoload[ $durable_name( $token ) ] ?? '' )
			&& 41 === ( $row['record']['subscription_id'] ?? 0 )
			&& 'subscription_fulfillment_v1' === ( $row['record']['authority_marker'] ?? '' )
			&& ( $row['record']['expires_at'] ?? 0 ) > time()
			&& [] === $selection_transient_calls()
	);
	$check(
		'the durable row is keyed by the token digest and never stores raw token bytes',
		! array_key_exists( 'ys_ec_ecpay_subsel_' . $token, $GLOBALS['wpdb']->rows )
			&& '' !== $row_bytes
			&& ! str_contains( $row_bytes, $token )
	);

	// ── resolve stays direct-DB read-only ──
	$data = [
		'ecpay_store_token' => $token,
		'cvs_store_id'      => '991122',
		'cart_scope'        => $scope,
	];
	$statements_before = count( $GLOBALS['wpdb']->statement_log );
	$verify = EcpayStoreSelector::verify_selection( $data, $shipping, $payment );
	$inspect = EcpayStoreSelector::inspect_selection_authoritative( $data, $shipping, $payment );
	$mutating_statements = array_values( array_filter(
		array_slice( $GLOBALS['wpdb']->statement_log, $statements_before ),
		static fn ( string $template ): bool => str_starts_with( $template, 'UPDATE ' ) || str_starts_with( $template, 'DELETE ' )
	) );
	$check(
		'verify and inspect read the durable subscription row without mutating or caching it',
		null === $verify
			&& null === $inspect['error']
			&& '991122' === ( $inspect['store']['store_id'] ?? '' )
			&& [] === $mutating_statements
			&& 'issued' === ( $durable_row( $token )['state'] ?? '' )
			&& [] === $selection_transient_calls()
	);

	// ── fence: claims without the exact Core subscription fence are refused ──
	$no_fence = EcpayStoreSelector::claim_selection_authoritative( $data, $shipping, $payment );
	$wrong_subscription = EcpayStoreSelector::claim_selection_authoritative(
		$data,
		$shipping,
		$payment,
		[ 'subscription_id' => 42, 'profile_generation' => 4 ]
	);
	$missing_generation = EcpayStoreSelector::claim_selection_authoritative(
		$data,
		$shipping,
		$payment,
		[ 'subscription_id' => 41, 'profile_generation' => 0 ]
	);
	$check(
		'durable claim fails closed without the exact subscription id and target generation fence',
		null !== $no_fence['error']
			&& null !== $wrong_subscription['error']
			&& null !== $missing_generation['error']
			&& 'issued' === ( $durable_row( $token )['state'] ?? '' )
	);

	// ── happy claim: issued -> consumed CAS inside the caller's transaction, no cache, no txn statements ──
	$GLOBALS['v036_transient_calls'] = [];
	$statements_before = count( $GLOBALS['wpdb']->statement_log );
	$claimed = EcpayStoreSelector::claim_selection_authoritative(
		$data,
		$shipping,
		$payment,
		[ 'subscription_id' => 41, 'profile_generation' => 4 ]
	);
	$claim_statements = array_slice( $GLOBALS['wpdb']->statement_log, $statements_before );
	$transaction_statements = array_values( array_filter(
		$claim_statements,
		static fn ( string $template ): bool =>
			str_contains( $template, 'START TRANSACTION' )
			|| str_contains( $template, 'COMMIT' )
			|| str_contains( $template, 'ROLLBACK' )
	) );
	$consumed_row = $durable_row( $token );
	$check(
		'durable claim consumes via exact byte CAS with zero cache calls and zero provider transaction control',
		null === $claimed['error']
			&& '991122' === ( $claimed['store']['cvs_store_id'] ?? '' )
			&& is_array( $consumed_row )
			&& 'consumed' === ( $consumed_row['state'] ?? '' )
			&& 4 === ( $consumed_row['consumed']['generation'] ?? 0 )
			&& 41 === ( $consumed_row['consumed']['subscription_id'] ?? 0 )
			&& [] === $selection_transient_calls()
			&& [] === $transaction_statements
	);

	$replay = EcpayStoreSelector::claim_selection_authoritative(
		$data,
		$shipping,
		$payment,
		[ 'subscription_id' => 41, 'profile_generation' => 5 ]
	);
	$check(
		'consumed durable token rejects replay without resurrecting the row',
		null !== $replay['error']
			&& 'consumed' === ( $durable_row( $token )['state'] ?? '' )
	);

	// ── concurrent-loser CAS: a row consumed between read and CAS refuses the claim ──
	$raced_token = (string) $issue->invoke( null, $subscription_selection, 'u:7' );
	$raced_name = $durable_name( $raced_token );
	$raced_bytes = $GLOBALS['wpdb']->rows[ $raced_name ] ?? null;
	if ( is_string( $raced_bytes ) && is_array( json_decode( $raced_bytes, true ) ) ) {
		$raced_row = json_decode( $raced_bytes, true );
		$raced_row['state'] = 'consumed';
		$raced_row['consumed'] = [ 'subscription_id' => 41, 'generation' => 4, 'at' => '2026-08-30 11:59:00' ];
		$GLOBALS['wpdb']->rows[ $raced_name ] = (string) json_encode( $raced_row );
	}
	$raced_claim = EcpayStoreSelector::claim_selection_authoritative(
		array_merge( $data, [ 'ecpay_store_token' => $raced_token ] ),
		$shipping,
		$payment,
		[ 'subscription_id' => 41, 'profile_generation' => 4 ]
	);
	$check(
		'a concurrently consumed durable row loses the byte CAS and the claim is refused',
		null !== $raced_claim['error']
			&& 'consumed' === ( $durable_row( $raced_token )['state'] ?? '' )
	);

	// ── expiry is enforced on the durable path ──
	$expired_token = (string) $issue->invoke( null, $subscription_selection, 'u:7' );
	$expired_name = $durable_name( $expired_token );
	$expired_bytes = $GLOBALS['wpdb']->rows[ $expired_name ] ?? null;
	if ( is_string( $expired_bytes ) && is_array( json_decode( $expired_bytes, true ) ) ) {
		$expired_row = json_decode( $expired_bytes, true );
		$expired_row['record']['expires_at'] = time() - 10;
		$GLOBALS['wpdb']->rows[ $expired_name ] = (string) json_encode( $expired_row );
	}
	$expired_data = array_merge( $data, [ 'ecpay_store_token' => $expired_token ] );
	$expired_verify = EcpayStoreSelector::verify_selection( $expired_data, $shipping, $payment );
	$expired_claim = EcpayStoreSelector::claim_selection_authoritative(
		$expired_data,
		$shipping,
		$payment,
		[ 'subscription_id' => 41, 'profile_generation' => 4 ]
	);
	$check(
		'expired durable selections are rejected read-only and at claim time',
		null !== $expired_verify
			&& null !== $expired_claim['error']
			&& 'issued' === ( $durable_row( $expired_token )['state'] ?? '' )
	);

	// ── cleanup removes only expired durable rows, via raw statements ──
	if ( $store_ready ) {
		$store_class::cleanup_expired( 10 );
	}
	$check(
		'cleanup deletes expired durable rows and keeps live ones',
		! $store_ready
			? false
			: ( null === $durable_row( $expired_token )
				&& 'consumed' === ( $durable_row( $token )['state'] ?? '' ) )
	);

	// ── saved reauthorization also issues durable subscription authority ──
	$saved_token = EcpayStoreSelector::issue_canonical_saved_selection(
		$shipping,
		'UNIMARTC2C',
		'991122',
		[ 'name' => 'Canonical Store', 'address' => 'No. 1 Store Rd.', 'phone' => '0212345678' ],
		$payment,
		$scope,
		'u:7',
		'subscription',
		41
	);
	$check(
		'saved-store reauthorization issues the same durable subscription authority',
		'' !== $saved_token
			&& 'issued' === ( $durable_row( $saved_token )['state'] ?? '' )
			&& [] === $selection_transient_calls()
	);

	// ── ordinary checkout regression: transient one-use claim, durable store untouched ──
	$durable_rows_before = count( $GLOBALS['wpdb']->rows );
	$ordinary_scope = 'headless_1';
	$ordinary_token = (string) $issue->invoke( null, [
		'shipping_id'       => $shipping,
		'cvs_type'          => 'UNIMARTC2C',
		'store_id'          => '991122',
		'store_name'        => 'Canonical Store',
		'store_address'     => 'No. 1 Store Rd.',
		'store_verified'    => 1,
		'collection_mode'   => 'N',
		'payment_method'    => $payment,
		'cart_scope'        => $ordinary_scope,
		'context'           => 'checkout',
	], 'u:7' );
	$ordinary_data = [
		'ecpay_store_token' => $ordinary_token,
		'cvs_store_id'      => '991122',
		'cart_scope'        => $ordinary_scope,
	];
	$ordinary_claim = EcpayStoreSelector::claim_selection_authoritative( $ordinary_data, $shipping, $payment );
	$ordinary_replay = EcpayStoreSelector::claim_selection_authoritative( $ordinary_data, $shipping, $payment );
	$check(
		'ordinary checkout keeps the existing transient one-use claim and never touches the durable store',
		'' !== $ordinary_token
			&& null === $ordinary_claim['error']
			&& null !== $ordinary_replay['error']
			&& $durable_rows_before === count( $GLOBALS['wpdb']->rows )
	);

	echo "v036 subscription durable selection claim: {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 ? 1 : 0 );
}
