<?php
/** Real product loading and capture-only DDL; no WordPress site bootstrap or SQL execution. */
declare(strict_types=1);

namespace {
	if ( ! function_exists( 'dbDelta' ) ) {
		function dbDelta( string|array $queries, bool $execute = true ): array {
			unset( $execute );
			if ( ! isset( $GLOBALS['ecpay_product_ddl_capture'] ) || ! is_array( $GLOBALS['ecpay_product_ddl_capture'] ) ) {
				throw new \YSCartEcpay\Tests\Live\SubscriptionSqlFailure( 'ddl_capture_not_active' );
			}
			foreach ( (array) $queries as $sql ) {
				if ( ! is_string( $sql ) ) { throw new \YSCartEcpay\Tests\Live\SubscriptionSqlFailure( 'ddl_capture_invalid' ); }
				$GLOBALS['ecpay_product_ddl_capture'][] = $sql;
			}
			return [];
		}
	}
}

namespace YSCartEcpay\Tests\Live {
	final class SubscriptionProductSqlFixture {
		public const CORE = '7c8acd9843fe4507cc39eb18e8bb54bfd6fc3ec1';
		public const ECPAY = '445adc76c4dc6653abdd228b529bad636eef5d42';
		public const AFFILIATE = '18c609a1b94cca28e57c2ce4f225f25662555ccd';
		private const ALLOWED_DESCENDANT_PATHS = [
			'tests/live/PRODUCT_SQL_OFFLINE.md',
			'tests/live/live_subscription_product_pair.php',
			'tests/live/helpers/SubscriptionSqlSession.php',
			'tests/live/helpers/SubscriptionSqlAllocation.php',
			'tests/live/helpers/SubscriptionSqlBarrier.php',
			'tests/live/helpers/SubscriptionProductSqlFixture.php',
			'tests/live/helpers/SubscriptionSqlRequestBoundary.php',
			'tests/live/helpers/SubscriptionSqlScenario.php',
			'tests/regression/v041_subscription_product_sql_offline_guards.php',
			'tests/regression/v042_subscription_product_sql_capture_behavior.php',
			'tests/regression/v043_subscription_product_sql_custody.php',
			'tests/regression/v044_subscription_product_sql_wiring.php',
			'tests/live/helpers/SubscriptionSqlController.php',
			'tests/live/helpers/SubscriptionSqlWorker.php',
			'tests/live/helpers/SubscriptionSqlSchema.php',
			'tests/live/helpers/SubscriptionSqlFaults.php',
			'tests/live/helpers/SubscriptionSqlEvidence.php',
			'tests/regression/v045_subscription_sql_controller_protocol.php',
			'tests/regression/v046_subscription_sql_seed_fault_boundaries.php',
			'tests/regression/v047_subscription_sql_evidence_provenance.php',
			'tests/regression/v048_subscription_mysql_slice_admission.php',
		];
		private static function git( string $root, array $args ): string {
			$process = proc_open( [ 'git', '-C', $root, ...$args ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
			if ( ! is_resource( $process ) ) { throw new SubscriptionSqlFailure( 'pair_git_unavailable' ); }
			fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
			if ( 0 !== proc_close( $process ) || '' !== $err ) { throw new SubscriptionSqlFailure( 'pair_git_unavailable' ); }
			return trim( $out );
		}
		public static function inspectSources( array $roots ): array {
			$receipts = [];
			foreach ( [ 'core' => self::CORE, 'ecpay' => self::ECPAY, 'affiliate' => self::AFFILIATE ] as $role => $revision ) {
				$root = $roots[$role] ?? '';
				if ( ! is_string( $root ) || '' === $root || ! is_dir( $root ) ) { throw new SubscriptionSqlFailure( 'pair_root_required' ); }
				$root = realpath( $root );
				$head = self::git( $root, [ 'rev-parse', 'HEAD' ] );
				if ( '' !== self::git( $root, [ 'status', '--porcelain=v1', '--untracked-files=all' ] ) ) { throw new SubscriptionSqlFailure( 'pair_anchor_drift' ); }
				if ( 'ecpay' !== $role && $head !== $revision ) { throw new SubscriptionSqlFailure( 'pair_anchor_drift' ); }
				if ( 'ecpay' === $role ) {
					self::git( $root, [ 'merge-base', '--is-ancestor', $revision, 'HEAD' ] );
					$changed = self::git( $root, [ 'diff', '--no-renames', '--name-only', $revision, 'HEAD' ] );
					foreach ( '' === $changed ? [] : explode( "\n", $changed ) as $path ) {
						if ( ! in_array( $path, self::ALLOWED_DESCENDANT_PATHS, true ) ) { throw new SubscriptionSqlFailure( 'pair_path_not_allowed' ); }
					}
				}
				$receipts[$role] = [ 'root' => $root, 'head' => $head, 'product_revision' => $revision, 'tree' => self::git( $root, [ 'rev-parse', 'HEAD^{tree}' ] ) ];
			}
			return $receipts;
		}
		public static function loadProduct( array $sources ): array {
			if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
			if ( ! defined( 'YS_ECOMMERCE_TABLE_PREFIX' ) ) { define( 'YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_' ); }
			if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
			$classes = [
				'core' => [
					'YangSheep\\Ecommerce\\Database\\YSTableMaker' => 'src/Database/YSTableMaker.php',
					'YangSheep\\Ecommerce\\Models\\YSSubscription' => 'src/Models/YSSubscription.php',
					'YangSheep\\Ecommerce\\Models\\YSProduct' => 'src/Models/YSProduct.php',
					'YangSheep\\Ecommerce\\Shipping\\YSShippingMethodId' => 'src/Shipping/YSShippingMethodId.php',
					'YangSheep\\Ecommerce\\Services\\Subscription\\YSSubscriptionFulfillmentProfileCoordinator' => 'src/Services/Subscription/YSSubscriptionFulfillmentProfileCoordinator.php',
					'YangSheep\\Ecommerce\\Services\\Subscription\\YSSubscriptionFulfillmentProfileService' => 'src/Services/Subscription/YSSubscriptionFulfillmentProfileService.php',
					'YangSheep\\Ecommerce\\Services\\Subscription\\YSSubscriptionSharedDbBoundary' => 'src/Services/Subscription/YSSubscriptionSharedDbBoundary.php',
					'YangSheep\\Ecommerce\\Services\\Shipping\\YSShippingIdentifier' => 'src/Services/Shipping/YSShippingIdentifier.php',
					'YangSheep\\Ecommerce\\Services\\Subscription\\YSSubscriptionRecurringAmount' => 'src/Services/Subscription/YSSubscriptionRecurringAmount.php',
					'YangSheep\\Ecommerce\\Utils\\YSUtf8' => 'src/Utils/YSUtf8.php',
					'YangSheep\\Ecommerce\\Services\\Shipping\\YSFulfillmentSnapshotService' => 'src/Services/Shipping/YSFulfillmentSnapshotService.php',
					'YangSheep\\Ecommerce\\Services\\Checkout\\YSCheckoutFulfillmentService' => 'src/Services/Checkout/YSCheckoutFulfillmentService.php',
				],
				'ecpay' => [
					'YangSheep\\YSCartEcpay\\Plugin' => 'src/Plugin.php',
					'YangSheep\\YSCartEcpay\\Shipping\\Ecpay\\EcpayStoreSelector' => 'src/Shipping/Ecpay/EcpayStoreSelector.php',
					'YangSheep\\YSCartEcpay\\Shipping\\Ecpay\\EcpaySubscriptionSelectionStore' => 'src/Shipping/Ecpay/EcpaySubscriptionSelectionStore.php',
					'YangSheep\\YSCartEcpay\\Support\\CartScope' => 'src/Support/CartScope.php',
				],
			];
			$receipts = [];
			foreach ( $classes as $role => $map ) {
				foreach ( $map as $class => $relative ) {
					$path = $sources[$role]['root'] . '/' . $relative;
					$blob = self::git( $sources[$role]['root'], [ 'rev-parse', $sources[$role]['product_revision'] . ':' . $relative ] );
					if ( self::git( $sources[$role]['root'], [ 'hash-object', '--no-filters', $path ] ) !== $blob ) { throw new SubscriptionSqlFailure( 'product_blob_drift' ); }
					require_once $path;
					if ( realpath( (string) ( new \ReflectionClass( $class ) )->getFileName() ) !== realpath( $path ) ) { throw new SubscriptionSqlFailure( 'product_class_counterfeit' ); }
					$receipts[] = [ 'class' => $class, 'path' => realpath( $path ), 'blob' => $blob, 'sha256' => hash_file( 'sha256', $path ) ];
				}
			}
			return $receipts;
		}
		public static function helperReceipt( string $directory ): array {
			$directory = realpath( $directory );
			if ( false === $directory || $directory !== realpath( __DIR__ ) ) { throw new SubscriptionSqlFailure( 'helper_directory_invalid' ); }
			$override = (string) getenv( 'YS_ECPAY_SQL_HARNESS_HELPER_ROOT' );
			$mutation = (string) getenv( 'YS_ECPAY_SQL_MUTATION_PHASE' );
			if ( '' !== $override && ( realpath( $override ) !== $directory || 1 !== preg_match( '/\Amutation-[a-z0-9-]+\z/', $mutation ) ) ) { throw new SubscriptionSqlFailure( 'helper_override_not_named' ); }
			$root = dirname( $directory, 3 ); $head = null; $clean = false;
			if ( '' === $override ) {
				$head = self::git( $root, [ 'rev-parse', 'HEAD' ] );
				$clean = '' === self::git( $root, [ 'status', '--porcelain=v1', '--untracked-files=all' ] );
			}
			$rows = [];
			foreach ( [ 'SubscriptionSqlSession.php','SubscriptionSqlAllocation.php','SubscriptionSqlBarrier.php','SubscriptionProductSqlFixture.php','SubscriptionSqlRequestBoundary.php','SubscriptionSqlScenario.php',
				'SubscriptionSqlController.php','SubscriptionSqlWorker.php','SubscriptionSqlSchema.php','SubscriptionSqlFaults.php','SubscriptionSqlEvidence.php' ] as $name ) {
				$path = $directory . '/' . $name;
				if ( ! is_file( $path ) ) { throw new SubscriptionSqlFailure( 'helper_file_missing' ); }
				$blob = null;
				if ( null !== $head ) {
					$tree = self::git( $root, [ 'ls-tree', 'HEAD', '--', 'tests/live/helpers/' . $name ] );
					if ( 1 === preg_match( '/\A100644 blob ([a-f0-9]{40})\t/', $tree, $match ) ) { $blob = $match[1]; }
					if ( null === $blob || $blob !== self::git( $root, [ 'hash-object', '--no-filters', $path ] ) ) { $clean = false; }
				}
				$rows[] = [ 'path' => realpath( $path ), 'head_blob' => $blob, 'sha256' => hash_file( 'sha256', $path ) ];
			}
			return [ 'head' => $head, 'state' => '' !== $override ? 'NAMED MUTATION' : ( $clean ? 'CANONICAL' : 'UNFROZEN AUTHORING' ), 'mutation_phase' => '' !== $override ? $mutation : null, 'files' => $rows ];
		}
		/** Calls actual YSTableMaker methods; this function has no session or executor argument. */
		public static function captureDdl( string $prefix ): array {
			SubscriptionSqlSession::assertPrefix( $prefix );
			$reflection = new \ReflectionFunction( 'dbDelta' );
			if ( realpath( (string) $reflection->getFileName() ) !== realpath( __FILE__ ) ) { throw new SubscriptionSqlFailure( 'ddl_capture_boundary_invalid' ); }
			if ( array_key_exists( 'ecpay_product_ddl_capture', $GLOBALS ) ) { throw new SubscriptionSqlFailure( 'ddl_capture_already_active' ); }
			$GLOBALS['ecpay_product_ddl_capture'] = [];
			try {
				foreach ( [ 'products', 'subscriptions', 'orders', 'order_items', 'order_created_outbox' ] as $role ) {
					$method = new \ReflectionMethod( \YangSheep\Ecommerce\Database\YSTableMaker::class, 'create_' . $role . '_table' );
					$method->invoke( null, $prefix . 'ys_ec_', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
				}
				$statements = $GLOBALS['ecpay_product_ddl_capture'];
			} finally { unset( $GLOBALS['ecpay_product_ddl_capture'] ); }
			if ( 5 !== count( $statements ) ) { throw new SubscriptionSqlFailure( 'ddl_capture_count_invalid' ); }
			$statements[] = "CREATE TABLE {$prefix}options (option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name VARCHAR(191) NOT NULL, option_value LONGTEXT NOT NULL, autoload VARCHAR(20) NOT NULL DEFAULT 'no', UNIQUE KEY option_name (option_name)) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
			return [ 'status' => 'CAPTURE ONLY', 'executed_statements' => 0, 'product_ddl_count' => 5, 'fixture_options_ddl_count' => 1, 'statements' => $statements ];
		}
		/** Actual entrypoint reserved for later workers; no fake coordinator/CAS implementation. */
		public static function updateProduct( int $subscriptionId, array $input, array $owner ): array {
			return \YangSheep\Ecommerce\Services\Subscription\YSSubscriptionFulfillmentProfileCoordinator::update( $subscriptionId, $input, $owner );
		}
		/** Pure seed plan. No executor receives these rows; token is represented only by its digest. */
		public static function seedPlan( string $prefix, int $now, string $token ): array {
			SubscriptionSqlSession::assertPrefix( $prefix );
			if ( $now < 1 || $now > PHP_INT_MAX - 1800 || 32 !== strlen( $token ) ) { throw new SubscriptionSqlFailure( 'seed_contract_invalid' ); }
			$profile = [ 'contract_version' => 1, 'contract_kind' => 'physical', 'allowed_shipping_methods' => [ 'home_old', 'ys_ec_ecpay_ship_unimart' ],
				'billing' => [ 'name' => 'Billing Owner', 'phone' => '0900000000', 'email' => 'owner@example.test', 'country' => 'TW', 'postcode' => '100', 'state' => '', 'city' => 'Taipei', 'district' => 'Zhongzheng', 'address' => 'Old Billing Rd.', 'address2' => '' ],
				'invoice' => [ 'type' => 'electronic', 'buyer_type' => 'personal', 'carrier_type' => '', 'carrier_id' => '', 'tax_id' => '', 'buyer_name' => '', 'donate_code' => '' ],
				'shipping_method_id' => 'home_old', 'shipping_provider' => 'provider-old', 'shipping_total' => '75.00',
				'fulfillment_snapshot' => [ 'contract_version' => 1, 'provider_id' => 'provider-old', 'method_id' => 'home_old',
					'destination' => [ 'type' => 'home', 'recipient_name' => 'Old Recipient', 'recipient_phone' => '0911000000', 'country' => 'TW', 'postcode' => '100', 'state' => '', 'city' => 'Taipei', 'district' => 'Zhongzheng', 'address' => 'Old Shipping Rd.', 'address2' => '' ],
					'service' => [ 'shipping_type' => 'home', 'temperature_class' => 'room', 'payment_method_id' => 'fixture_renewable_gateway', 'collection_mode' => 'prepaid' ],
					'parcel' => [ 'weight_kg' => '2.000', 'weight_source' => 'order_item_snapshot' ],
					'items' => [ [ 'line_key' => 'line-1', 'product_id' => 501, 'variant_id' => 0, 'quantity' => 2, 'unit_weight_kg' => '1.000', 'total_weight_kg' => '2.000' ] ],
				],
			];
			$normal = \YangSheep\Ecommerce\Services\Subscription\YSSubscriptionFulfillmentProfileService::normalize_profile( $profile );
			if ( true !== ( $normal['ok'] ?? false ) ) { throw new SubscriptionSqlFailure( 'seed_profile_invalid' ); }
			$record = [ 'shipping_id' => 'ys_ec_ecpay_ship_unimart', 'logistics_subtype' => 'UNIMARTC2C', 'store_id' => '991122', 'store_name' => 'Canonical Store', 'store_address' => 'No. 1 Store Rd.', 'store_verified' => 1,
				'collection_mode' => 'N', 'payment_method' => 'fixture_renewable_gateway', 'cart_scope' => 'sub_41', 'context' => 'subscription', 'authority_marker' => 'subscription_fulfillment_v1', 'subscription_id' => 41, 'principal' => 'u:7', 'issued_at' => $now, 'expires_at' => $now + 1800 ];
			return [ 'status' => 'SEED PLAN ONLY', 'executed_sql' => 0, 'prefix' => $prefix,
				'product' => [ 'id' => 501, 'title' => 'SQL pair fixture', 'slug' => $prefix . 'product', 'type' => 'subscription', 'status' => 'published', 'stock_qty' => -1, 'is_virtual' => 0, 'weight' => 1000 ],
				'subscription' => [ 'id' => 41, 'customer_id' => 91, 'user_id' => 7, 'status' => 'active', 'product_id' => 501, 'variant_id' => 0, 'quantity' => 2, 'amount' => '500.00', 'next_amount' => null, 'currency' => 'TWD', 'billing_period' => 'monthly', 'billing_interval' => 1, 'product_title' => 'SQL pair fixture', 'start_date' => '2026-08-30 11:00:00', 'gateway_id' => 'fixture_renewable_gateway', 'current_period_key' => null,
					'fulfillment_contract_kind' => 'physical', 'fulfillment_profile' => $normal['canonical'], 'fulfillment_profile_generation' => 3, 'fulfillment_profile_hash' => $normal['hash'], 'renewal_shipping_total' => '75.00', 'fulfillment_profile_updated_at' => '2026-08-30 11:00:00', 'updated_at' => '2026-08-30 11:00:00' ],
				'selection' => [ 'option_name' => 'ys_ec_ecpay_subsel_' . hash( 'sha256', $token ), 'option_value' => json_encode( [ 'state' => 'issued', 'record' => $record ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), 'autoload' => 'no' ],
			];
		}
		public static function request( string $token ): array {
			if ( 32 !== strlen( $token ) ) { throw new SubscriptionSqlFailure( 'request_contract_invalid' ); }
			return [ 'expected_generation' => 3, 'shipping_method_id' => 'ys_ec_ecpay_ship_unimart', 'selection_token' => $token, 'billing_name' => 'Pair Recipient', 'billing_phone' => '0912345678', 'billing_country' => 'TW', 'cvs_store_id' => '991122' ];
		}
		/** Read-only observer interface on the supplied boundary; no transition or global handle substitution. */
		public static function readPair( SubscriptionSqlSession $db, int $id, string $token ): array {
			if ( $id < 1 || 32 !== strlen( $token ) ) { throw new SubscriptionSqlFailure( 'readback_contract_invalid' ); }
			$row = $db->get_row( $db->prepare( 'SELECT * FROM ' . $db->prefix . 'ys_ec_subscriptions WHERE id = %d', $id ), 'ARRAY_A' );
			if ( '' !== $db->last_error || ! is_array( $row ) ) { return [ 'ok' => false, 'code' => 'readback_subscription_missing' ]; }
			$authority = \YangSheep\Ecommerce\Services\Subscription\YSSubscriptionFulfillmentProfileService::current_profile_authority( (object) $row );
			if ( true !== ( $authority['ok'] ?? false ) ) { return [ 'ok' => false, 'code' => 'readback_profile_invalid' ]; }
			$bytes = $db->get_var( $db->prepare( 'SELECT option_value FROM ' . $db->options . ' WHERE option_name = %s', 'ys_ec_ecpay_subsel_' . hash( 'sha256', $token ) ) );
			$value = is_string( $bytes ) ? json_decode( $bytes, true ) : null;
			if ( '' !== $db->last_error || ! is_array( $value ) || ! in_array( $value['state'] ?? '', [ 'issued', 'consumed' ], true ) || ! is_array( $value['record'] ?? null )
				|| $id !== ( $value['record']['subscription_id'] ?? null ) ) { return [ 'ok' => false, 'code' => 'readback_selection_invalid' ]; }
			if ( 'consumed' === $value['state'] && ( ! is_array( $value['consumed'] ?? null ) || 3 !== count( $value['consumed'] )
				|| $id !== ( $value['consumed']['subscription_id'] ?? null ) || ! is_int( $value['consumed']['generation'] ?? null )
				|| $value['consumed']['generation'] < 1 || ! is_string( $value['consumed']['at'] ?? null ) || '' === $value['consumed']['at'] ) ) { return [ 'ok' => false, 'code' => 'readback_selection_invalid' ]; }
			if ( 'issued' === $value['state'] && array_key_exists( 'consumed', $value ) ) { return [ 'ok' => false, 'code' => 'readback_selection_invalid' ]; }
			return [ 'ok' => true, 'subscription_id' => $id, 'contract_kind' => $row['fulfillment_contract_kind'], 'generation' => (int) $authority['generation'], 'profile_bytes' => $row['fulfillment_profile'], 'profile_hash' => $authority['hash'], 'shipping_total' => $row['renewal_shipping_total'], 'profile_updated_at' => $row['fulfillment_profile_updated_at'], 'updated_at' => $row['updated_at'] ?? null,
				'selection_bytes' => $bytes, 'selection_sha256' => hash( 'sha256', $bytes ), 'selection_state' => $value['state'], 'selection_generation' => $value['consumed']['generation'] ?? null, 'token_digest' => hash( 'sha256', $token ) ];
		}
	}
}
