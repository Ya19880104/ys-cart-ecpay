<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Services\Shipping\Adapters;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Enums\YSShippingPipelineState;
use YangSheep\Ecommerce\Services\Shipping\YSCarrierAdapter;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog;

final class EcpayShippingAdapter extends YSCarrierAdapter {
	public function get_id(): string {
		return 'ecpay';
	}

	public function map_to_pipeline_state( string $carrier_status ): ?string {
		$mapped = EcpayShippingCatalog::pipeline_state_for_logistics_status( $carrier_status );
		return null === $mapped ? null : match ( $mapped ) {
			'preparing'        => YSShippingPipelineState::PREPARING,
			'in_transit'       => YSShippingPipelineState::IN_TRANSIT,
			'arrived_at_store' => YSShippingPipelineState::ARRIVED_AT_STORE,
			'delivered'        => YSShippingPipelineState::DELIVERED,
			'returned'         => YSShippingPipelineState::RETURNED,
			default            => null,
		};
	}

	public function supports_webhook(): bool {
		return true;
	}

	public function supports_query_api(): bool {
		return true;
	}
}

