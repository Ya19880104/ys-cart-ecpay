<?php
/**
 * Local-only vertical: real Core owner/profile/model/CAS/options/projection and
 * real ECPay map -> callback -> result -> durable selection -> transaction.
 * WordPress request/response, configuration, catalog and wpdb are boundaries.
 * No HTTP, real database, browser, gateway charge or renewal worker executes.
 * v038 JS covers the root-bound browser adapter; v038 PHP covers callback HTML.
 */
declare(strict_types=1);

namespace {
	define( 'V037_FIXTURE_ONLY', true );
	define( 'YS_CART_ECPAY_URL', 'https://assets.example.test/ecpay/' );
	define( 'YS_CART_ECPAY_VERSION', 'test-candidate' );
	final class V039ResponseBoundary extends \RuntimeException {}
	function nocache_headers(): void { throw new V039ResponseBoundary( 'response begins' ); }
	function status_header( int $status ): void {}
	function esc_url( string $url ): string { return $url; }
	function current_user_can( string $capability ): bool { return false; }
	function wp_die( string $message, string $title = '', array $args = [] ): never { throw new \RuntimeException( $message ); }
	function wp_register_script( string $handle, string $url, array $deps, string $version, bool $footer ): void {
		$GLOBALS['v039_registered'][ $handle ] = compact( 'url', 'deps', 'version', 'footer' );
	}
	function wp_enqueue_script( string $handle ): void { $GLOBALS['v039_enqueued'][] = $handle; }
	final class WP_REST_Request {
		public function __construct( private array $body = [], private array $query = [] ) {}
		public function get_json_params(): array { return $this->body; }
		public function get_body_params(): array { return $this->body; }
		public function get_query_params(): array { return $this->query; }
	}
	final class WP_REST_Response {
		public array $headers = [];
		public function __construct( public array $data, private int $status = 200 ) {}
		public function get_status(): int { return $this->status; }
		public function header( string $name, string $value ): void { $this->headers[ $name ] = $value; }
	}
}

namespace YangSheep\Ecommerce {
	/** Config boundary: this prepaid fixture has no configured COD methods. */
	final class YSEcommerce {
		public static function get_instance(): self { return new self(); }
		public function get_setting( string $key, string $default = '' ): string { return $default; }
	}
}
namespace YangSheep\Ecommerce\Gateways {
	/** Catalog boundary, not an ECPay recurring-payment implementation. */
	final class YSGatewayRegistry {
		public static function get( string $id ): ?object {
			return 'fixture_renewable_gateway' === $id ? new class {
				public function supports_token(): bool { return true; }
			} : null;
		}
	}
}

namespace {
	use YangSheep\Ecommerce\Models\YSSubscription;
	use YangSheep\Ecommerce\Models\V037SubscriptionState;
	use YangSheep\Ecommerce\Shipping\YSShippingRegistry;
	use YangSheep\Ecommerce\Services\Subscription\YSSubscriptionFulfillmentProfileCoordinator as Coordinator;
	use YangSheep\Ecommerce\Services\Subscription\YSSubscriptionFulfillmentProfileService as Profile;
	use YangSheep\Ecommerce\Services\Storefront\YSSubscriptionFulfillmentOptionsService as Options;
	use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayStoreSelector as Selector;

	require __DIR__ . '/v037_subscription_pair_commit_boundary.php';
	// The production renderer discards all output buffers before emitting headers.
	// Collect assertions until every callback has finished; do not mask diagnostics.
	$assertions = [];
	$check = static function ( string $label, bool $ok ) use ( &$pass, &$fail, &$assertions ): void {
		if ( $ok ) { ++$pass; } else { ++$fail; }
		$assertions[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $label;
	};
	require_once $core_root . '/src/Api/Storefront/YSRequestParser.php';
	require_once $core_root . '/src/Api/Storefront/YSRestResponder.php';
	require_once $core_root . '/src/Services/Checkout/YSCheckoutAvailabilityService.php';
	require_once $core_root . '/src/Services/Storefront/YSSubscriptionFulfillmentOptionsService.php';
	$plugin = $GLOBALS['v037_plugin'];
	$owner = [ 'customer_id' => 91, 'user_id' => 7 ];
	$reset = static function () use ( $reset_subscription, $reset_boundary ): void {
		$reset_boundary();
		$reset_subscription();
		$GLOBALS['wpdb'] = new V037PairWpdb();
		$GLOBALS['v037_user_id'] = 7;
		$GLOBALS['v037_transients'] = [];
		YSShippingRegistry::$enabled = true;
		YSShippingRegistry::$provider = 'ecpay';
		YSShippingRegistry::$availability_log = [];
		$row = V037SubscriptionState::$rows[41];
		$row->gateway_id = 'fixture_renewable_gateway';
		$profile = json_decode( $row->fulfillment_profile, true );
		$profile['fulfillment_snapshot']['service']['payment_method_id'] = $row->gateway_id;
		$authority = Profile::normalize_profile( $profile );
		if ( true !== ( $authority['ok'] ?? false ) ) { throw new \RuntimeException( 'vertical profile fixture invalid' ); }
		$row->fulfillment_profile = $authority['canonical'];
		$row->fulfillment_profile_hash = $authority['hash'];
	};
	$map_request = static fn ( array $extra = [] ): WP_REST_Request => new WP_REST_Request( $extra + [
		'context' => 'subscription', 'subscription_id' => 41,
		'shipping_id' => 'ys_ec_ecpay_ship_unimart', 'return_url' => 'https://shop.example.test/account/',
	] );
	$journey = static function () use ( $plugin, $map_request ): array {
		$map = $plugin->ecpay_map_url( $map_request() );
		if ( true !== ( $map->data['success'] ?? false ) ) { throw new \RuntimeException( 'map failed: ' . ( $map->data['code'] ?? '' ) ); }
		$fields = $map->data['data']['fields'];
		$callback = [
			'ExtraData' => $fields['ExtraData'], 'MerchantID' => $fields['MerchantID'],
			'MerchantTradeNo' => $fields['MerchantTradeNo'], 'LogisticsSubType' => $fields['LogisticsSubType'],
			'CVSStoreID' => '991122', 'CVSStoreName' => 'Untrusted hint', 'CVSAddress' => 'Untrusted address',
		];
		// Stop only at the WordPress response boundary AFTER real callback authority,
		// canonical directory lookup, durable token issuance and result-code issuance.
		try { Selector::handle_store_callback( new WP_REST_Request( $callback ) ); }
		catch ( V039ResponseBoundary $boundary ) {}
		$codes = array_values( array_filter( array_keys( $GLOBALS['v037_transients'] ),
			static fn ( string $key ): bool => str_starts_with( $key, 'ys_ec_ecpay_result_' ) ) );
		if ( 1 !== count( $codes ) ) { throw new \RuntimeException( 'callback must issue exactly one result' ); }
		$code = substr( $codes[0], strlen( 'ys_ec_ecpay_result_' ) );
		$request = new WP_REST_Request( [], [ 'code' => $code, 'cart_scope' => 'sub_41' ] );
		$result = $plugin->ecpay_store_result( $request );
		return [ $map, $result, $request, $callback ];
	};

	$reset();
	$options = Options::get_options( YSSubscription::find( 41 ) );
	$check( 'real Core options project one permitted ECPay store method',
		true === ( $options['success'] ?? false ) && 'ys_ec_ecpay_ship_unimart' === ( $options['data']['methods'][0]['id'] ?? '' ) );
	$check( 'real Core owner projection hides another customer',
		true === ( Coordinator::get( 41, 91, 7 )['success'] ?? false )
		&& 'subscription_not_found' === ( Coordinator::get( 41, 999, 7 )['code'] ?? '' ) );
	[ $map, $result, $result_request, $callback ] = $journey();
	$selection = $result->data['data'] ?? [];
	$token = (string) ( $selection['selection_token'] ?? '' );
	$check( 'map/callback/result use server-owned scope, prepaid gateway and canonical store',
		true === ( $result->data['success'] ?? false ) && 'no-store, private' === ( $result->headers['Cache-Control'] ?? '' )
		&& 'N' === ( $map->data['data']['collection_mode'] ?? '' )
		&& 'sub_41' === ( $selection['cart_scope'] ?? '' ) && 'subscription' === ( $selection['context'] ?? '' )
		&& 'Canonical Store' === ( $selection['cvs_store_name'] ?? '' ) && 'issued' === $durable_state( $token ) );
	$replay_result = $plugin->ecpay_store_result( $result_request );
	$check( 'result code replay is rejected without consuming the durable selection',
		false === ( $replay_result->data['success'] ?? true ) && 'issued' === $durable_state( $token ) );
	$positive = Coordinator::update( 41, $update_input( $token ), $owner );
	$projection = Profile::renewal_projection( YSSubscription::find( 41 ) );
	$check( 'same transaction advances real Core CAS N to N+1 and consumes ECPay authority',
		true === ( $positive['success'] ?? false ) && 4 === $generation() && 'consumed' === $durable_state( $token )
		&& 1 === V037SubscriptionState::$cas_calls && 1 === $GLOBALS['wpdb']->commits );
	$check( 'next renewal projection reads the new profile generation, store, total and preserved payment authority',
		true === ( $projection['ok'] ?? false ) && 4 === ( $projection['generation'] ?? 0 )
		&& '991122' === ( $projection['order_data']['cvs_store_id'] ?? '' )
		&& 65.0 === ( $projection['order_data']['shipping_total'] ?? null )
		&& 'fixture_renewable_gateway' === ( $projection['order_data']['fulfillment_snapshot']['service']['payment_method_id'] ?? '' ) );
	$stale = Coordinator::update( 41, $update_input( $token ), $owner );
	$check( 'stale generation replay never advances or consumes again',
		'stale_generation' === ( $stale['code'] ?? '' ) && 4 === $generation() && 1 === V037SubscriptionState::$cas_calls );

	foreach ( [ 'non-owner', 'method-disabled', 'provider-mismatch' ] as $case ) {
		$reset();
		if ( 'non-owner' === $case ) { $GLOBALS['v037_user_id'] = 8; }
		if ( 'method-disabled' === $case ) { YSShippingRegistry::$enabled = false; }
		if ( 'provider-mismatch' === $case ) { YSShippingRegistry::$provider = 'different_provider'; }
		$response = $plugin->ecpay_map_url( $map_request() );
		$check( $case . ' cannot issue a map session under real Core availability authority',
			false === ( $response->data['success'] ?? true ) && [] === $GLOBALS['v037_transients'] && 3 === $generation() );
	}
	$reset();
	[ , $result ] = $journey();
	$token = $result->data['data']['selection_token'];
	$foreign = Coordinator::update( 41, $update_input( $token ), [ 'customer_id' => 91, 'user_id' => 8 ] );
	$check( 'foreign Core owner cannot consume issued provider authority',
		'subscription_not_found' === ( $foreign['code'] ?? '' ) && 3 === $generation() && 'issued' === $durable_state( $token ) );
	$copy = clone V037SubscriptionState::$rows[41];
	$copy->id = 42;
	V037SubscriptionState::$rows[42] = $copy;
	$wrong_subscription = Coordinator::update( 42, $update_input( $token ), $owner );
	$check( 'token for subscription 41 cannot update subscription 42',
		false === ( $wrong_subscription['success'] ?? true ) && 3 === (int) V037SubscriptionState::$rows[42]->fulfillment_profile_generation
		&& 'issued' === $durable_state( $token ) );
	$invalid = Coordinator::update( 41, $update_input( 'invalid-token' ), $owner );
	$check( 'invalid token is rejected before Core CAS', false === ( $invalid['success'] ?? true ) && 0 === V037SubscriptionState::$cas_calls );

	$has_assets = method_exists( $plugin, 'register_account_fulfillment_assets' )
		&& method_exists( $plugin, 'enqueue_account_fulfillment_assets' ) && method_exists( $plugin, 'append_account_fulfillment_assets' );
	if ( $has_assets ) {
		$plugin->register_account_fulfillment_assets();
		$plugin->enqueue_account_fulfillment_assets();
		$assets = $plugin->append_account_fulfillment_assets( [ 'scripts' => [ 'account_handlers' => 'core-handler.js' ] ] );
	}
	$check( 'native account asset registration depends on actual Core handler seam', $has_assets
		&& [ 'ys-ec-account-handlers' ] === ( $GLOBALS['v039_registered']['ys-cart-ecpay-account-fulfillment']['deps'] ?? [] )
		&& [ 'ys-cart-ecpay-account-fulfillment' ] === ( $GLOBALS['v039_enqueued'] ?? [] ) );
	$check( 'SDK account asset envelope appends provider after Core handler', $has_assets
		&& [ 'account_handlers', 'ecpay_subscription_fulfillment' ] === array_keys( $assets['scripts'] ?? [] ) );

	ini_set( 'error_log', is_string( $pair_error_log_previous ) ? $pair_error_log_previous : '' );
	if ( is_file( $pair_error_log_file ) ) { unlink( $pair_error_log_file ); }
	echo implode( "\n", $assertions ) . "\n";
	echo "subscription account to renewal vertical: {$pass} PASS / {$fail} FAIL\n";
	exit( $fail > 0 ? 1 : 0 );
}
