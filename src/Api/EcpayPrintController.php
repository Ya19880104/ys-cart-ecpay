<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Api;

defined( 'ABSPATH' ) || exit;

final class EcpayPrintController {
	public static function register(): void {
		add_action( 'admin_post_ys_cart_ecpay_print', [ __CLASS__, 'handle' ] );
	}

	public static function unregister(): void {
		remove_action( 'admin_post_ys_cart_ecpay_print', [ __CLASS__, 'handle' ] );
	}

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ys-cart-ecpay' ), 403 );
		}

		$key = sanitize_text_field( wp_unslash( (string) ( $_GET['key'] ?? '' ) ) );
		if ( '' === $key ) {
			wp_die( esc_html__( 'Missing print payload.', 'ys-cart-ecpay' ), 400 );
		}

		$payload = get_transient( 'ys_ec_ecpay_print_' . $key );
		delete_transient( 'ys_ec_ecpay_print_' . $key );

		if ( ! is_array( $payload ) || empty( $payload['api_url'] ) || empty( $payload['fields'] ) || ! is_array( $payload['fields'] ) ) {
			wp_die( esc_html__( 'Print payload expired.', 'ys-cart-ecpay' ), 410 );
		}

		$method_id = sanitize_key( (string) ( $payload['method_id'] ?? '' ) );
		if ( '' !== $method_id
			&& class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' )
			&& ! \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_method_enabled( 'shipping', $method_id, \YangSheep\YSCartEcpay\Plugin::manifest() )
		) {
			wp_die( esc_html__( 'ECPay print method is disabled.', 'ys-cart-ecpay' ), 403 );
		}

		$api_url = (string) $payload['api_url'];
		$host    = strtolower( (string) wp_parse_url( $api_url, PHP_URL_HOST ) );
		if ( ! in_array( $host, [ 'logistics.ecpay.com.tw', 'logistics-stage.ecpay.com.tw' ], true ) ) {
			wp_die( esc_html__( 'Unsupported print host.', 'ys-cart-ecpay' ), 400 );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( 200 );
		header_remove( 'Content-Type' );
		header( 'Content-Type: text/html; charset=UTF-8' );
		nocache_headers();

		?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
	<meta charset="utf-8">
	<title>ECPay Print</title>
	<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>
	<form id="ys-cart-ecpay-print" method="post" action="<?php echo esc_url( $api_url ); ?>">
		<?php foreach ( $payload['fields'] as $name => $value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
		<?php endforeach; ?>
		<noscript><button type="submit">Print</button></noscript>
	</form>
	<script>document.getElementById('ys-cart-ecpay-print').submit();</script>
</body>
</html>
		<?php
		exit;
	}
}
