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
	'description'        => '綠界金流與物流整合。一般支付（導轉）支援信用卡、分期、銀聯、ATM、WebATM、超商代碼、超商條碼、Apple Pay、TWQR、微信、BNPL；站內付 2.0 綁卡信用卡（特約商店）支援訂閱自動續扣；物流支援超商取貨與宅配。',
	'version'            => YS_CART_ECPAY_VERSION,
	'contract_version'   => 1,
	'plugin_file'        => YS_CART_ECPAY_BASENAME,
	'icon'               => 'dashicons-money-alt',
	'documentation_url'  => 'https://www.ecpay.com.tw/',
	'legacy_setting_key' => 'ys_ec_ecpay_enabled',
	'domains'            => [ 'payment', 'shipping' ],
	'capabilities'       => [
		'payment'  => [
			// 🔴 金流方式清單由型錄導出，這裡**不再**抄一份——理由與下方物流相同。
			'methods'              => \YangSheep\YSCartEcpay\Payment\EcpayPaymentCatalog::manifest_methods(),
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
		// Core 2.67.0 才具備 runtime method-provider authority。Rolling upgrade 先升 ECPay 時，
		// 舊 Core 必須繼續把此能力視為 absent，保留原本 strict-v1／AIO／物流行為。
		...( defined( 'YS_ECOMMERCE_VERSION' ) && version_compare( (string) YS_ECOMMERCE_VERSION, '2.67.0', '>=' )
			? [ 'temperature_layer_mapping_v1' => \YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog::temperature_layer_mapping_capability() ]
			: [] ),
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
		'ecpg_return'      => [ 'namespace' => 'ys-ecommerce/v1', 'route' => '/ecpay/ecpg/return', 'methods' => [ 'POST' ], 'permission_callback' => [ \YangSheep\YSCartEcpay\Api\EcpgPaymentController::class, 'return_permission' ] ],
		'ecpg_result'      => [ 'namespace' => 'ys-ecommerce/v1', 'route' => '/ecpay/ecpg/result', 'methods' => [ 'POST' ], 'permission_callback' => [ \YangSheep\YSCartEcpay\Api\EcpgPaymentController::class, 'result_permission' ] ],
		'store_callback'   => [ 'namespace' => 'ys-ecommerce/v1', 'route' => '/ecpay/store-callback', 'methods' => [ 'POST' ], 'permission_callback' => [ \YangSheep\YSCartEcpay\Plugin::class, 'store_callback_permission' ], 'signature_scheme' => 'ecpay_check_mac_value' ],
	],
	'allowed_hosts'      => [
		'payment-stage.ecpay.com.tw',
		'payment.ecpay.com.tw',
		'logistics-stage.ecpay.com.tw',
		'logistics.ecpay.com.tw',
		// 站內付 2.0：Token／交易／綁卡走 ecpg，查詢／請退款走 ecpayment（兩者不可混用）。
		'ecpg-stage.ecpay.com.tw',
		'ecpg.ecpay.com.tw',
		'ecpayment-stage.ecpay.com.tw',
		'ecpayment.ecpay.com.tw',
	],
	'health_check'       => [
		'callback'  => null,
		'cache_ttl' => 3600,
	],
];
