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
		public const CORE = '47b07b523445492c163b26ba7047c19d64911c0b';
		public const ECPAY = '445adc76c4dc6653abdd228b529bad636eef5d42';
		public const AFFILIATE = '18c609a1b94cca28e57c2ce4f225f25662555ccd';
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
				if ( 'ecpay' !== $role && $head !== $revision ) { throw new SubscriptionSqlFailure( 'pair_anchor_drift' ); }
				if ( 'ecpay' === $role ) {
					self::git( $root, [ 'merge-base', '--is-ancestor', $revision, 'HEAD' ] );
					// Descendant test-only checkpoints may carry new harness files, but product bytes stay pinned.
					$changed = self::git( $root, [ 'diff', '--name-only', $revision, '--', 'src', 'assets', 'ys-cart-ecpay.php' ] );
					if ( '' !== $changed ) { throw new SubscriptionSqlFailure( 'pair_product_drift' ); }
				} elseif ( '' !== self::git( $root, [ 'status', '--porcelain=v1', '--untracked-files=all' ] ) ) { throw new SubscriptionSqlFailure( 'pair_anchor_drift' ); }
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
					'YangSheep\\Ecommerce\\Services\\Subscription\\YSSubscriptionFulfillmentProfileCoordinator' => 'src/Services/Subscription/YSSubscriptionFulfillmentProfileCoordinator.php',
					'YangSheep\\Ecommerce\\Services\\Subscription\\YSSubscriptionFulfillmentProfileService' => 'src/Services/Subscription/YSSubscriptionFulfillmentProfileService.php',
					'YangSheep\\Ecommerce\\Services\\Subscription\\YSSubscriptionSharedDbBoundary' => 'src/Services/Subscription/YSSubscriptionSharedDbBoundary.php',
				],
				'ecpay' => [
					'YangSheep\\YSCartEcpay\\Plugin' => 'src/Plugin.php',
					'YangSheep\\YSCartEcpay\\Shipping\\Ecpay\\EcpayStoreSelector' => 'src/Shipping/Ecpay/EcpayStoreSelector.php',
					'YangSheep\\YSCartEcpay\\Shipping\\Ecpay\\EcpaySubscriptionSelectionStore' => 'src/Shipping/Ecpay/EcpaySubscriptionSelectionStore.php',
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
	}
}
