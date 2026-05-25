<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Services\Shipping\Adapters;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Enums\YSShippingPipelineState;
use YangSheep\Ecommerce\Services\Shipping\YSCarrierAdapter;

final class EcpayShippingAdapter extends YSCarrierAdapter {
	public function get_id(): string {
		return 'ecpay';
	}

	public function map_to_pipeline_state( string $carrier_status ): ?string {
		return match ( $carrier_status ) {
			'1' => YSShippingPipelineState::PREPARING,
			'300', '2063', '2067' => YSShippingPipelineState::DELIVERED,
			'2073', '2074', '2077' => YSShippingPipelineState::RETURNED,
			'999' => YSShippingPipelineState::FAILED,
			default => YSShippingPipelineState::IN_TRANSIT,
		};
	}

	public function supports_webhook(): bool {
		return true;
	}

	public function supports_query_api(): bool {
		return false;
	}
}

