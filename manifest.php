<?php
/**
 * ECPay provider manifest for YS CART.
 *
 * @package YangSheep\YSCartEcpay
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

return [
	'id'                 => 'ys_ecpay',
	'name'               => '綠界 ECPay',
	'description'        => '綠界金流與物流整合，支援信用卡、ATM、超商代碼、條碼付款，以及超商取貨與宅配物流。',
	'version'            => YS_CART_ECPAY_VERSION,
	'contract_version'   => 1,
	'plugin_file'        => YS_CART_ECPAY_BASENAME,
	'icon'               => 'dashicons-money-alt',
	'documentation_url'  => 'https://www.ecpay.com.tw/',
	'legacy_setting_key' => 'ys_ec_ecpay_enabled',
	'domains'            => [ 'payment', 'shipping' ],
	'capabilities'       => [
		'payment'  => [
			'methods'              => [
				[ 'id' => 'ys_ec_ecpay_credit', 'label' => '信用卡', 'class' => \YangSheep\YSCartEcpay\Payment\EcpayCreditGateway::class ],
				[ 'id' => 'ys_ec_ecpay_atm', 'label' => 'ATM 虛擬帳號', 'class' => \YangSheep\YSCartEcpay\Payment\EcpayAtmGateway::class ],
				[ 'id' => 'ys_ec_ecpay_cvs', 'label' => '超商代碼', 'class' => \YangSheep\YSCartEcpay\Payment\EcpayCvsGateway::class ],
				[ 'id' => 'ys_ec_ecpay_barcode', 'label' => '超商條碼', 'class' => \YangSheep\YSCartEcpay\Payment\EcpayBarcodeGateway::class ],
			],
			'supported_currencies' => [ 'TWD' ],
			'supported_countries'  => [ 'TW' ],
			'test_mode_available'  => true,
		],
		'shipping' => [
			// 🔴 物流方式清單由型錄導出，這裡**不再**抄一份。
			// 抄第二份的後果不是「少一個方式」，而是它半開著：後台勾得到、
			// manifest 認不得，於是 provider lifecycle 一律判定停用。
			'methods'             => \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog::manifest_methods(),
			'supported_countries' => [ 'TW' ],
			'shipping_requester'  => \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingRequester::class,
			'carrier_adapter'     => \YangSheep\YSCartEcpay\Services\Shipping\Adapters\EcpayShippingAdapter::class,
		],
	],
	'admin_page'         => [
		'slug'                => 'ys-provider-ecpay',
		'title'               => '綠界 ECPay 設定',
		'render_callback'     => [ \YangSheep\YSCartEcpay\Admin\EcpaySettings::class, 'render_page' ],
		'capability_required' => 'manage_options',
		'icon'                => 'dashicons-money-alt',
	],
	'callback_routes'    => [
		'payment_notify'   => [ 'namespace' => 'ys-ecommerce/v1', 'route' => '/ecpay/notify', 'methods' => [ 'POST' ], 'permission_callback' => [ \YangSheep\YSCartEcpay\Api\EcpayPaymentController::class, 'notify_permission' ], 'signature_scheme' => 'ecpay_check_mac_value' ],
		'payment_info'     => [ 'namespace' => 'ys-ecommerce/v1', 'route' => '/ecpay/payment-info', 'methods' => [ 'POST' ], 'permission_callback' => [ \YangSheep\YSCartEcpay\Api\EcpayPaymentController::class, 'payment_info_permission' ], 'signature_scheme' => 'ecpay_check_mac_value' ],
		'payment_return'   => [ 'namespace' => 'ys-ecommerce/v1', 'route' => '/ecpay/return', 'methods' => [ 'GET', 'POST' ], 'permission_callback' => [ \YangSheep\YSCartEcpay\Api\EcpayPaymentController::class, 'return_permission' ], 'signature_scheme' => 'ecpay_check_mac_value' ],
		'logistics_notify' => [ 'namespace' => 'ys-ecommerce/v1', 'route' => '/ecpay/logistics-notify', 'methods' => [ 'POST' ], 'permission_callback' => [ \YangSheep\YSCartEcpay\Api\EcpayLogisticsController::class, 'notify_permission' ], 'signature_scheme' => 'ecpay_check_mac_value' ],
		'store_callback'   => [ 'namespace' => 'ys-ecommerce/v1', 'route' => '/ecpay/store-callback', 'methods' => [ 'POST' ], 'permission_callback' => [ \YangSheep\YSCartEcpay\Plugin::class, 'store_callback_permission' ], 'signature_scheme' => 'ecpay_check_mac_value' ],
	],
	'allowed_hosts'      => [
		'payment-stage.ecpay.com.tw',
		'payment.ecpay.com.tw',
		'logistics-stage.ecpay.com.tw',
		'logistics.ecpay.com.tw',
	],
	'health_check'       => [
		'callback'  => null,
		'cache_ttl' => 3600,
	],
];
