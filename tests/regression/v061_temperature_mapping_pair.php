<?php
/**
 * Real ECPay descriptor/selection/requester paired with Core's typed mapping authority and codec.
 *
 * Run:
 *   set YS_CORE_ROOT=...\ys-cart-core-sol
 *   php tests/regression/v061_temperature_mapping_pair.php
 */

declare(strict_types=1);

namespace YangSheep\Ecommerce {
	final class YSEcommerce {
		private static ?self $instance = null;
		public static function get_instance(): self { return self::$instance ??= new self(); }
		public function get_setting( string $key, mixed $default = '' ): mixed { return $GLOBALS['ysc_options'][ $key ] ?? $default; }
		public function update_setting( string $key, mixed $value ): void { $GLOBALS['ysc_options'][ $key ] = $value; }
	}
}

namespace YangSheep\YSCartEcpay\Support {
	final class Settings {
		public const SENDER_KEYS = [
			'name' => 'sender_name', 'phone' => 'sender_phone', 'zipcode' => 'sender_zipcode', 'address' => 'sender_address',
		];
		public static function logistics_credentials_for_method( string $method_id ): array {
			unset( $method_id );
			return [ 'merchant_id' => '2000132', 'hash_key' => 'hash-key', 'hash_iv' => 'hash-iv', 'test_mode' => true ];
		}
		public static function shipping_method_option( string $method_id, string $key, mixed $default = '' ): mixed {
			unset( $method_id, $key );
			return $default;
		}
		public static function get( string $key, mixed $default = '' ): mixed { unset( $key ); return $default; }
		public static function shipping_base_fee( string $method_id ): float { unset( $method_id ); return 0.0; }
		public static function shipping_free_threshold( string $method_id ): float { unset( $method_id ); return 0.0; }
	}

	final class ShippingMethodOperability {
		public static function is_operable( string $method_id ): bool {
			return null !== \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog::get( $method_id );
		}
		public static function is_configured( string $method_id ): bool { return self::is_operable( $method_id ); }
		public static function has_operable_method(): bool { return true; }
	}

	final class ProviderMaintenanceLock {
		public static function reader_lease(): object { return (object) [ 'token' => 'lease-token' ]; }
		public static function reader_fence( string $token ): bool { return 'lease-token' === $token; }
	}

	final class CheckMacValue {
		public static function generate( array $fields, string $key, string $iv, string $algorithm = 'md5' ): string {
			return hash( 'sha256', json_encode( $fields ) . $key . $iv . $algorithm );
		}
	}
}

namespace YangSheep\YSCartEcpay\Shipping\Ecpay {
	/** Plugin's canonical HOME request still parses the cart scope before it branches by destination type. */
	final class EcpayStoreSelector {
		public static function subscription_id_from_scope( string $scope ): int {
			return 1 === preg_match( '/^sub_([1-9][0-9]*)$/D', $scope, $match ) ? (int) $match[1] : 0;
		}
	}
}

namespace {
	$core_root = (string) getenv( 'YS_CORE_ROOT' );
	if ( '' === $core_root || ! is_file( $core_root . '/src/Services/Checkout/YSTemperatureMappingAuthority.php' ) ) {
		fwrite( STDERR, "YS_CORE_ROOT must point to the paired Core worktree.\n" );
		exit( 2 );
	}

	$GLOBALS['ysc_user']            = 71;
	$GLOBALS['ysc_lifecycle_ready'] = true;
	require_once $core_root . '/tests/regression/_v26150_core_correction_bootstrap.php';
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
	}
	if ( ! function_exists( 'rest_url' ) ) {
		function rest_url( string $path = '' ): string { return 'https://shop.test/wp-json/' . ltrim( $path, '/' ); }
	}

	$root = dirname( __DIR__, 2 );
	defined( 'YS_CART_ECPAY_DIR' ) || define( 'YS_CART_ECPAY_DIR', $root . '/' );
	defined( 'YS_CART_ECPAY_VERSION' ) || define( 'YS_CART_ECPAY_VERSION', '0.5.4-test' );
	defined( 'YS_CART_ECPAY_BASENAME' ) || define( 'YS_CART_ECPAY_BASENAME', 'ys-cart-ecpay/ys-cart-ecpay.php' );
	$version_probe = is_string( $argv[1] ?? null ) && str_starts_with( $argv[1], '--manifest-version=' )
		? substr( $argv[1], strlen( '--manifest-version=' ) )
		: '';
	$fixture_core_version = in_array( $version_probe, [ '2.66.3', '2.67.0', 'undefined' ], true ) ? $version_probe : '2.67.0';
	if ( 'undefined' !== $fixture_core_version ) {
		defined( 'YS_ECOMMERCE_VERSION' ) || define( 'YS_ECOMMERCE_VERSION', $fixture_core_version );
	}

	foreach ( [
		'src/Utils/YSUtf8.php',
		'src/Shipping/YSShippingInterface.php',
		'src/Shipping/YSShippingMethodId.php',
		'src/Services/Shipping/YSShippingIdentifier.php',
		'src/Shipping/YSShippingRegistry.php',
		'src/Services/Shipping/YSFulfillmentSnapshotService.php',
		'src/Contracts/Cart/YSCartPartitionContext.php',
		'src/Contracts/Cart/YSCartPartitionPolicyInterface.php',
		'src/Contracts/Cart/YSCartPartitionShippingPolicyInterface.php',
		'src/Contracts/Cart/YSCartMutationConsumerInterface.php',
		'src/Services/Cart/YSCartAuthorityInteger.php',
		'src/Services/Cart/YSCartPartitionJsonCodec.php',
		'src/Services/Cart/YSCartPartitionRepository.php',
		'src/Services/Cart/YSCartMutationOutbox.php',
		'src/Services/Cart/YSCartPartitionEpochAuthority.php',
		'src/Core/Provider/YSTemperatureMappingCapability.php',
		'src/Core/Provider/YSManifestValidator.php',
		'src/Core/Provider/YSProviderLifecycleRegistry.php',
		'src/Core/Provider/YSProviderLifecycleState.php',
		'src/Services/Checkout/YSCheckoutFulfillmentService.php',
		'src/Services/Checkout/YSTemperatureMappingAuthority.php',
		'src/Services/Shipping/YSOrderFulfillmentSnapshotCodec.php',
	] as $file ) {
		require_once $core_root . '/' . $file;
	}

	require_once $root . '/src/Support/Utf8Text.php';
	require_once $root . '/src/Support/HttpFormClient.php';
	require_once $root . '/src/Payment/EcpayPaymentCatalog.php';
	require_once $root . '/src/Shipping/Ecpay/EcpayShippingCatalog.php';
	require_once $root . '/src/Shipping/Ecpay/EcpayShipping.php';
	foreach ( [
		'EcpayShippingTcat.php',
		'EcpayShippingTcatChilled.php',
		'EcpayShippingTcatFrozen.php',
		'EcpayShippingUnimartFreeze.php',
	] as $file ) {
		require_once $root . '/src/Shipping/Ecpay/' . $file;
	}
	require_once $root . '/src/Shipping/Ecpay/EcpayShippingRequester.php';
	require_once $root . '/src/Plugin.php';

	use YangSheep\Ecommerce\Core\Provider\YSManifestValidator;
	use YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleRegistry;
	use YangSheep\Ecommerce\Services\Checkout\YSCheckoutFulfillmentService;
	use YangSheep\Ecommerce\Services\Checkout\YSTemperatureMappingAuthority;
	use YangSheep\Ecommerce\Services\Shipping\YSOrderFulfillmentSnapshotCodec;
	use YangSheep\Ecommerce\Shipping\YSShippingRegistry;
	use YangSheep\YSCartEcpay\Plugin;
	use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog;
	use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingRequester;
	use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingTcatFrozen;
	use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingUnimartFreeze;

	$test = new YSCCheck( 'ECPay temperature mapping provider pair' );
	$manifest = Plugin::manifest();
	if ( '' !== $version_probe ) {
		$present = array_key_exists( 'temperature_layer_mapping_v1', $manifest['capabilities'] ?? [] );
		if ( '2.67.0' === $version_probe ) {
			$matches_catalog = $present
				&& method_exists( EcpayShippingCatalog::class, 'temperature_layer_mapping_capability' )
				&& EcpayShippingCatalog::temperature_layer_mapping_capability()
					=== $manifest['capabilities']['temperature_layer_mapping_v1'];
			echo $matches_catalog ? 'present_equal' : 'present_mismatch';
		} else {
			echo $present ? 'present' : 'absent';
		}
		exit( 0 );
	}

	$probe_manifest = static function ( string $core_version ) use ( $core_root ): array {
		$descriptors = [
			0 => [ 'pipe', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		];
		$process = proc_open( [ PHP_BINARY, __FILE__, '--manifest-version=' . $core_version ], $descriptors, $pipes, null, null );
		if ( ! is_resource( $process ) ) {
			return [ 'exit' => -1, 'stdout' => '', 'stderr' => 'proc_open failed', 'core_root' => $core_root ];
		}
		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return [ 'exit' => proc_close( $process ), 'stdout' => trim( (string) $stdout ), 'stderr' => trim( (string) $stderr ) ];
	};
	$old_manifest = $probe_manifest( '2.66.3' );
	$new_manifest = $probe_manifest( '2.67.0' );
	$missing_core_manifest = $probe_manifest( 'undefined' );
	$test->is(
		'rolling upgrade exposes the mapping capability only to Core 2.67.0 while Core 2.66.3 and an unloaded Core keep it absent',
		0 === $old_manifest['exit'] && 'absent' === $old_manifest['stdout']
			&& 0 === $new_manifest['exit'] && 'present_equal' === $new_manifest['stdout']
			&& 0 === $missing_core_manifest['exit'] && 'absent' === $missing_core_manifest['stdout'],
		[ 'old' => $old_manifest, 'new' => $new_manifest, 'missing' => $missing_core_manifest ]
	);
	$has_builder = method_exists( EcpayShippingCatalog::class, 'temperature_layer_mapping_capability' );
	$catalog_capability = $has_builder ? EcpayShippingCatalog::temperature_layer_mapping_capability() : null;
	$manifest_capability = $manifest['capabilities']['temperature_layer_mapping_v1'] ?? null;
	$validated = YSManifestValidator::validate( $manifest );
	$typed_capability = $validated['capabilities']['temperature_layer_mapping_v1'] ?? null;

	$profiles_ok = is_array( $catalog_capability );
	$distribution = [ 'room' => 0, 'chilled' => 0, 'frozen' => 0 ];
	foreach ( EcpayShippingCatalog::all() as $method_id => $descriptor ) {
		$expected_profile = match ( $descriptor['temperature'] ?? null ) {
			EcpayShippingCatalog::TEMP_ROOM    => 'room',
			EcpayShippingCatalog::TEMP_CHILLED => 'chilled',
			EcpayShippingCatalog::TEMP_FROZEN  => 'frozen',
			default                            => '',
		};
		$profiles = $catalog_capability['shipping_methods'][ $method_id ]['mapping_profiles'] ?? null;
		$profiles_ok = $profiles_ok
			&& '' !== $expected_profile
			&& is_array( $profiles )
			&& [ $expected_profile ] === array_keys( $profiles )
			&& $expected_profile === ( $profiles[ $expected_profile ]['wire_temperature_class'] ?? null )
			&& 1 === ( $profiles[ $expected_profile ]['mapping_version'] ?? null );
		if ( isset( $distribution[ $expected_profile ] ) ) {
			++$distribution[ $expected_profile ];
		}
	}
	$test->is(
		'catalog derives one exact mapping profile for every one of its 11 descriptors, including TCAT temperatures and UNIMARTFREEZE',
		$has_builder && $profiles_ok
			&& 1 === ( $catalog_capability['schema_version'] ?? null )
			&& 11 === count( $catalog_capability['shipping_methods'] ?? [] )
			&& [ 'room' => 8, 'chilled' => 1, 'frozen' => 2 ] === $distribution,
		[ 'builder' => $has_builder, 'distribution' => $distribution, 'capability' => $catalog_capability ]
	);
	$test->is(
		'manifest publishes that exact catalog capability and Core normalizes it as a valid typed capability',
		is_array( $catalog_capability )
			&& $catalog_capability === $manifest_capability
			&& 'valid' === ( $typed_capability['state'] ?? null )
			&& 11 === count( $typed_capability['shipping_methods'] ?? [] ),
		[ 'manifest' => $manifest_capability, 'typed' => $typed_capability ]
	);

	$method = new EcpayShippingTcatFrozen();
	$selection_result = Plugin::instance()->resolve_fulfillment_selection(
		[ 'handled' => false ],
		[
			'shipping_method' => $method->get_id(), 'payment_method' => 'ys_ec_ecpay_credit',
			'billing_name' => '測試收件人', 'billing_phone' => '0912345678', 'billing_country' => 'TW',
			'billing_postcode' => '100', 'billing_state' => '', 'billing_city' => 'Taipei',
			'billing_district' => 'Zhongzheng', 'billing_address' => 'No. 1', 'billing_address2' => '',
		],
		[
			'method_id' => $method->get_id(), 'payment_method' => 'ys_ec_ecpay_credit',
			'cart_scope' => 'default', 'zero_payment_order' => false,
		]
	);
	$selection = is_array( $selection_result['selection'] ?? null ) ? $selection_result['selection'] : [];
	$descriptor = EcpayShippingCatalog::get( $method->get_id() );
	$test->is(
		'real ECPay selection binds the catalog frozen descriptor to provider ecpay',
		true === ( $selection_result['ok'] ?? false )
			&& 'ecpay' === ( $selection['provider_id'] ?? null )
			&& $method->get_id() === ( $selection['method_id'] ?? null )
			&& 'frozen' === ( $selection['service']['temperature_class'] ?? null )
			&& EcpayShippingCatalog::TEMP_FROZEN === ( $descriptor['temperature'] ?? null ),
		$selection_result
	);

	$order_data = [
		'merchant_trade_no' => 'YSTEMPPAIR000000001', 'payment_method' => 'ys_ec_ecpay_credit',
		'product_amount' => 1200, 'product_name' => 'Frozen product',
		'sender_name' => 'Sender', 'sender_phone' => '0911000000', 'sender_zipcode' => '100', 'sender_address' => 'Sender road',
		'receiver_name' => 'Receiver', 'receiver_phone' => '0922000000', 'receiver_zipcode' => '100', 'receiver_address' => 'Receiver road',
	];
	$build_fields = new \ReflectionMethod( EcpayShippingRequester::class, 'build_create_fields' );
	$tcat_fields = $build_fields->invoke( new EcpayShippingRequester( $method ), $order_data, \YangSheep\YSCartEcpay\Support\Settings::logistics_credentials_for_method( $method->get_id() ) );
	$freeze_cvs = new EcpayShippingUnimartFreeze();
	$cvs_fields = $build_fields->invoke(
		new EcpayShippingRequester( $freeze_cvs ),
		array_merge( $order_data, [ 'merchant_trade_no' => 'YSTEMPPAIR000000002', 'receiver_store_id' => '991182' ] ),
		\YangSheep\YSCartEcpay\Support\Settings::logistics_credentials_for_method( $freeze_cvs->get_id() )
	);
	$test->is(
		'real requester emits TCAT frozen as Temperature 0003 and frozen CVS as the UNIMARTFREEZE subtype',
		'TCAT' === ( $tcat_fields['LogisticsSubType'] ?? null )
			&& '0003' === ( $tcat_fields['Temperature'] ?? null )
			&& 'UNIMARTFREEZE' === ( $cvs_fields['LogisticsSubType'] ?? null )
			&& ! array_key_exists( 'Temperature', $cvs_fields )
			&& '1200' === ( $cvs_fields['CollectionAmount'] ?? null ),
		[ 'tcat' => $tcat_fields, 'cvs' => $cvs_fields ]
	);

	$GLOBALS['ysc_filter_callbacks']['ys_ec_provider_manifests'] = static fn ( array $current ): array => [ $manifest ];
	$GLOBALS['ysc_options'] = [
		'ys_provider_ys_ecpay_enabled'            => '1',
		'ys_capability_ys_ecpay_shipping_enabled' => '1',
		'ys_methods_shipping_state'                => wp_json_encode( [ $method->get_id() => [ 'enabled' => 1, 'provider_id' => 'ys_ecpay' ] ] ),
	];
	YSProviderLifecycleRegistry::reset();
	YSShippingRegistry::register( $method );

	$tuple = [
		'partition_key' => 'frozen', 'frozen_title' => '冷凍', 'frozen_slug' => 'frozen', 'config_revision' => 4,
		'compatibility_digest' => str_repeat( 'a', 64 ), 'epoch_digest' => str_repeat( 'b', 64 ),
	];
	$policy_digest = str_repeat( 'c', 64 );
	$decision = YSTemperatureMappingAuthority::decide(
		'frozen',
		$method->get_id(),
		true,
		[ 'method_id' => $method->get_id(), 'mapping_mode' => 'mapped_v2', 'mapping_profile_key' => 'frozen' ],
		$tuple,
		$policy_digest
	);
	$legacy_default = YSTemperatureMappingAuthority::decide(
		'default',
		$method->get_id(),
		true,
		[ 'method_id' => $method->get_id(), 'mapping_mode' => 'legacy_default_v1', 'mapping_profile_key' => null ],
		[
			'partition_key' => 'default', 'frozen_title' => '一般商品', 'frozen_slug' => 'default', 'config_revision' => 4,
			'compatibility_digest' => str_repeat( 'e', 64 ), 'epoch_digest' => str_repeat( 'f', 64 ),
		],
		$policy_digest
	);
	$test->is(
		'an active-policy Default legacy allowance is explicitly refused after the valid ECPay capability appears',
		null === ( $legacy_default['mode'] ?? null )
			&& 'default_profile_not_configured' === ( $legacy_default['reason'] ?? null )
			&& 'valid' === ( $legacy_default['capability_state'] ?? null ),
		$legacy_default
	);
	$items = [
		[ 'line_key' => 'line-1', 'product_id' => 77, 'variant_id' => 0, 'quantity' => 1, 'unit_weight_kg' => '1.000', 'total_weight_kg' => '1.000' ],
	];
	$v1 = [] !== $selection ? YSCheckoutFulfillmentService::build_snapshot( $selection, $items ) : null;
	$wire = YSTemperatureMappingAuthority::wire_object( $decision );
	$v2 = is_array( $v1 ) && is_array( $wire )
		? YSOrderFulfillmentSnapshotCodec::build_v2(
			$v1,
			[
				'key' => 'frozen', 'title_snapshot' => '冷凍', 'slug_snapshot' => 'frozen', 'config_revision' => 4,
				'compatibility_digest' => str_repeat( 'a', 64 ), 'partition_epoch_digest' => str_repeat( 'b', 64 ),
				'partition_generation' => 1, 'partition_fingerprint' => str_repeat( 'd', 64 ), 'policy_digest' => $policy_digest,
			],
			$wire
		)
		: null;
	$test->is(
		'real Core authority and codec accept the ECPay selection only when wire identity is the registered method provider ecpay',
		'mapped_v2' === ( $decision['mode'] ?? null )
			&& 'ecpay' === ( $decision['provider_id'] ?? null )
			&& 'ecpay' === ( $wire['provider_id'] ?? null )
			&& is_array( $v2 )
			&& 'ecpay' === ( $v2['shipping']['provider_id'] ?? null )
			&& 'ecpay' === ( $v2['provider_wire_mapping']['provider_id'] ?? null )
			&& true === YSTemperatureMappingAuthority::verify_persisted_wire( $v2 ),
		[ 'decision' => $decision, 'wire' => $wire, 'v2' => $v2 ]
	);

	$test->finish();
}
