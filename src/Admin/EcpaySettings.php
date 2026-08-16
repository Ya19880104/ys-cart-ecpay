<?php
declare(strict_types=1);

namespace YangSheep\YSCartEcpay\Admin;

defined( 'ABSPATH' ) || exit;

use YangSheep\Ecommerce\Admin\YSAdminApp;
use YangSheep\YSCartEcpay\Plugin;
use YangSheep\YSCartEcpay\Shipping\Ecpay\EcpayShippingCatalog;
use YangSheep\YSCartEcpay\Support\ProviderMaintenanceLock;
use YangSheep\YSCartEcpay\Support\Settings;

final class EcpaySettings {
	private const NONCE_ACTION = 'ys_cart_ecpay_save_settings';
	private const DEFAULT_TAB = 'api';
	private const TABS = [
		'api'         => 'API 設定',
		'payment'     => '金流方式',
		'shipping'    => '物流方式',
		'diagnostics' => '串接資訊',
	];
	private const PAYMENT_GATEWAY_IDS = [
		'credit'  => 'ys_ec_ecpay_credit',
		'atm'     => 'ys_ec_ecpay_atm',
		'cvs'     => 'ys_ec_ecpay_cvs',
		'barcode' => 'ys_ec_ecpay_barcode',
	];
	/**
	 * alias → method_id。由型錄導出，不維護第二份清單。
	 *
	 * @return array<string,string>
	 */
	private static function shipping_method_ids(): array {
		return EcpayShippingCatalog::alias_to_id();
	}

	public static function register(): void {
		add_action( 'admin_post_ys_cart_ecpay_save_settings', [ __CLASS__, 'handle_save' ] );
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '權限不足。', 'ys-cart-ecpay' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$tab = self::normalize_tab( sanitize_key( wp_unslash( (string) ( $_POST['ys_ec_ecpay_tab'] ?? self::DEFAULT_TAB ) ) ) );
		$provider_enabled = isset( $_POST['ys_ec_ecpay_enabled'] );
		$settings_error   = '';

		if ( 'api' === $tab ) {
			// 🔴 v0.2.16 R13：api tab（provider 開關／HOME family／reuse 開關／三組
			// 憑證）走**單一原子管線**——先計算 desired state（零寫入）、以 provider
			// 維護鎖（與出網 pre-send 共用）圍住 gate 檢查與 commit、任何失敗
			// {existed,value} 全量回滾。不再有「先寫後驗」的出網窗口，也不再有
			// 「多個獨立 handler 各寫各的、一半成功一半失敗」的混合狀態。
			$settings_error = self::apply_api_tab_atomically( $provider_enabled );
		} elseif ( in_array( $tab, [ 'payment', 'shipping' ], true ) ) {
			$settings_error = self::apply_methods_tab_atomically( $tab, $provider_enabled );
		} else {
			$settings_error = self::apply_provider_enabled_atomically( $provider_enabled );
		}

		$redirect_args = [
			'page' => 'ys-provider-ecpay',
			'tab'  => $tab,
		];
		if ( '' === $settings_error ) {
			$redirect_args['updated'] = '1';
		} else {
			$redirect_args['settings_error'] = $settings_error;
		}
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * 某 channel 的 effective signer 指紋（比較用；不含明文金鑰）。
	 *
	 * @param array<string,string|null>|null $pending 寫入前評估用 overlay（見 Settings）
	 * @return array{mid:string, key_fp:string, iv_fp:string, test:bool}|null null=無簽章能力
	 */
	private static function channel_signer_fingerprint( string $channel, ?array $pending = null ): ?array {
		$c = Settings::logistics_credentials_for_channel( $channel, $pending );
		if ( '' === $c['merchant_id'] || '' === $c['hash_key'] || '' === $c['hash_iv'] ) {
			return null;
		}
		return [
			'mid'    => $c['merchant_id'],
			'key_fp' => substr( hash( 'sha256', $c['hash_key'] ), 0, 12 ),
			'iv_fp'  => substr( hash( 'sha256', $c['hash_iv'] ), 0, 12 ),
			'test'   => (bool) $c['test_mode'],
		];
	}

	/**
	 * 🔴 R14：payment signer 指紋。付款表單（EcpayPaymentClient）以當下 payment
	 * 憑證簽 CMV、回呼（EcpayPaymentController）以當下憑證驗章——payment key
	 * 旋轉對「已簽出未送回的表單」與「未終局的付款 attempt」的破壞力與物流
	 * signer 完全同級，必須在同一個 authority 快照裡。
	 *
	 * @param array<string,string|null>|null $pending
	 * @return array{mid:string, key_fp:string, iv_fp:string, test:bool}|null
	 */
	private static function payment_signer_fingerprint( ?array $pending = null ): ?array {
		$c = Settings::payment_credentials( $pending );
		if ( '' === $c['merchant_id'] || '' === $c['hash_key'] || '' === $c['hash_iv'] ) {
			return null;
		}
		return [
			'mid'    => $c['merchant_id'],
			'key_fp' => substr( hash( 'sha256', $c['hash_key'] ), 0, 12 ),
			'iv_fp'  => substr( hash( 'sha256', $c['hash_iv'] ), 0, 12 ),
			'test'   => (bool) $c['test_mode'],
		];
	}

	/**
	 * 四 channel signer 快照（🔴 R14：含 payment）。$pending 非 null＝「假設這些
	 * 鍵已寫入」的 effective 評估（value null＝row 不存在）——一個 byte 都不落盤。
	 *
	 * @param array<string,string|null>|null $pending
	 * @return array<string, array|null>
	 */
	private static function signer_snapshot( ?array $pending = null ): array {
		return [
			'payment' => self::payment_signer_fingerprint( $pending ),
			'b2c'     => self::channel_signer_fingerprint( 'b2c', $pending ),
			'home'    => self::channel_signer_fingerprint( 'home', $pending ),
			'c2c'     => self::channel_signer_fingerprint( 'c2c', $pending ),
		];
	}

	/**
	 * Read every credential-selector input directly from the settings table.
	 * Core's get_setting() cache is not an authority snapshot for a writer that
	 * may have waited behind another request.
	 *
	 * @return array{ok:bool,overlay:array<string,string|null>,snapshot:array<string,array|null>}
	 */
	private static function signer_snapshot_from_db(): array {
		$keys = array_values( array_unique( array_merge(
			array_values( Settings::PAYMENT_KEYS ),
			array_values( Settings::LOGISTICS_B2C_HOME_KEYS ),
			array_values( Settings::LOGISTICS_C2C_KEYS ),
			array_values( Settings::LOGISTICS_KEYS ),
			[ 'ys_ec_ecpay_logistics_reuse_payment', Settings::HOME_CREDENTIAL_FAMILY ]
		) ) );
		$overlay = [];
		foreach ( $keys as $key ) {
			$probe = Settings::db_probe( $key );
			if ( ! $probe['ok'] ) {
				return [ 'ok' => false, 'overlay' => [], 'snapshot' => [] ];
			}
			$overlay[ $key ] = $probe['existed'] ? $probe['value'] : null;
		}

		return [ 'ok' => true, 'overlay' => $overlay, 'snapshot' => self::signer_snapshot( $overlay ) ];
	}

	/** A crash repair must explicitly replace or clear every credential tuple. */
	private static function full_api_repair_requested(): bool {
		if ( ! array_key_exists( 'ys_ec_ecpay_home_credential_family', $_POST ) ) {
			return false;
		}
		foreach ( [ 'payment', 'logistics_b2c_home', 'logistics_c2c' ] as $prefix ) {
			if ( isset( $_POST[ 'ys_ec_ecpay_' . $prefix . '_clear' ] ) ) {
				continue;
			}
			$merchant = trim( (string) wp_unslash( $_POST[ 'ys_ec_ecpay_' . $prefix . '_merchant_id' ] ?? '' ) );
			$key      = trim( (string) wp_unslash( $_POST[ 'ys_ec_ecpay_' . $prefix . '_hash_key' ] ?? '' ) );
			$iv       = trim( (string) wp_unslash( $_POST[ 'ys_ec_ecpay_' . $prefix . '_hash_iv' ] ?? '' ) );
			if ( '' === $merchant || '' === $key || '' === $iv ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 🔴 v0.2.16 R13 核心管線：api tab 全部設定（provider 開關／HOME family／
	 * reuse／三組憑證）的原子套用。
	 *
	 * R12 版是「先寫入→驗證→回滾」：驗證前的窗口裡，並行建單會讀到暫時的新
	 * 簽章並**送出網**，隨後設定又回滾——provider 端從此有一個本地驗不了的
	 * label。R13 改為：
	 *
	 *   Phase A  解析 desired state（純計算，零寫入；secret 只加密一次，
	 *            compute 與 commit 用同一 ciphertext）
	 *   Phase B  以 pending overlay 評估 before/after effective signer（零寫入）
	 *   Phase C  取得 provider 維護鎖——出網 pre-send 讀同一把鎖，鎖持有中
	 *            一律拒送（bracket 檢查），寫入窗口內沒有任何簽章請求出網
	 *   Phase D  signer 變更時鎖內驗 gate：全部物流方式停用＋零 active/legacy
	 *            label＋查詢成功
	 *   Phase E  commit：每鍵 {existed,value} 備份（DB 直讀）→ fence → 寫入
	 *            ＋DB readback → effective signer 對 after 驗證
	 *   Phase F  任何失敗：**全量**回滾（不早退；原本不存在的 row 用 delete
	 *            恢復 absent 態，不是寫 ''）→ 對 before 驗證 effective signer
	 *
	 * @return string ''＝成功；否則 settings_error code
	 */
	private static function apply_api_tab_atomically( bool $provider_enabled ): string {
		// ── Phase A：desired state（零寫入）──
		$desired = [ Settings::ENABLED => $provider_enabled ? '1' : '0' ];

		// HOME family：舊表單沒有此欄位＝不變更，不是切回預設。
		if ( array_key_exists( 'ys_ec_ecpay_home_credential_family', $_POST ) ) {
			$family = Settings::normalize_home_credential_family(
				sanitize_key( wp_unslash( (string) $_POST['ys_ec_ecpay_home_credential_family'] ) )
			);
			if ( '' === $family ) {
				return 'invalid_home_credential_family';
			}
			$desired[ Settings::HOME_CREDENTIAL_FAMILY ] = $family;
		}

		$desired['ys_ec_ecpay_logistics_reuse_payment'] = isset( $_POST['ys_ec_ecpay_logistics_reuse_payment'] ) ? '1' : '0';

		try {
			foreach ( [
				'payment'            => Settings::PAYMENT_KEYS,
				'logistics_b2c_home' => Settings::LOGISTICS_B2C_HOME_KEYS,
				'logistics_c2c'      => Settings::LOGISTICS_C2C_KEYS,
			] as $prefix => $keys ) {
				$desired += self::desired_credentials_group( $prefix, $keys );
			}
		} catch ( \RuntimeException $e ) {
			return 'secret_encrypt_failed'; // 加密能力缺失＝一個 byte 都還沒寫，安全中止。
		}

		$full_repair = self::full_api_repair_requested();

		// ── Phase B：writer lease（readers＝所有簽章/驗章操作；activeReader 存在
		// 時 acquire 讓步——真互斥，不是唯讀檢查）──
		$owner = ProviderMaintenanceLock::acquire();
		if ( null === $owner ) {
			return 'settings_maintenance_lock_unavailable';
		}
		$release_owner = true;
		try {
			$repair_required = ProviderMaintenanceLock::crashed_flag_present();
			if ( $repair_required && ! $full_repair ) {
				return 'settings_crash_repair_requires_full_credentials';
			}
			$lifecycle = self::lifecycle_provider_settings_desired( $provider_enabled );
			if ( ! $lifecycle['ok'] ) {
				return 'settings_state_read_failed';
			}
			$desired = array_replace( $desired, $lifecycle['desired'] );

			// Re-read and filter only after ownership. Another settings request may
			// have committed while this request was parsing/encrypting POST values.
			$before_state = self::signer_snapshot_from_db();
			if ( ! $before_state['ok'] ) {
				return 'settings_state_read_failed';
			}
			$before = $before_state['snapshot'];
			$backup = [];
			foreach ( $desired as $key => $value ) {
				$probe = Settings::db_probe( $key );
				if ( ! $probe['ok'] ) {
					return 'settings_state_read_failed';
				}
				if ( ! $repair_required && $probe['existed'] && $probe['value'] === $value ) {
					unset( $desired[ $key ] );
					continue;
				}
				$backup[ $key ] = [ 'existed' => $probe['existed'], 'value' => $probe['value'] ];
			}
			if ( [] === $desired ) {
				return '';
			}
			$after_overlay = $before_state['overlay'];
			foreach ( $desired as $key => $value ) {
				$after_overlay[ $key ] = $value;
			}
			$after             = self::signer_snapshot( $after_overlay );
			$logistics_changed = [ $before['b2c'], $before['home'], $before['c2c'] ]
				!== [ $after['b2c'], $after['home'], $after['c2c'] ];
			$payment_changed   = $before['payment'] !== $after['payment'];

			// ── Phase C：signer 變更 gate（鎖內驗；此刻起新 reader 全被拒，
			// 既有 reader 已在 acquire 的 readers-clear 檢查中排除）──
			if ( $logistics_changed ) {
				foreach ( EcpayShippingCatalog::all() as $method_id => $descriptor ) {
					if ( self::home_method_is_enabled( (string) $method_id, $descriptor ) ) {
						return 'signer_change_requires_methods_disabled';
					}
				}
				$authority = self::all_methods_authority_state();
				if ( 'error' === $authority ) {
					return 'signer_change_label_lookup_failed';
				}
				if ( 'active' === $authority ) {
					return 'signer_change_active_labels';
				}
			}
			// 🔴 R14：payment signer 變更＝維護操作——(a) 全部付款方式停用
			// （已簽出的結帳表單以舊 key 送回會失驗）(b) 零 unresolved payment
			// attempt（pending 的 ECPay 訂單其 ReturnURL/notify/query 都按當下
			// 憑證驗章，rotation 會讓結果無法收斂）(c) 狀態查詢成功。
			if ( $payment_changed ) {
				foreach ( self::PAYMENT_GATEWAY_IDS as $alias => $gateway_id ) {
					if ( self::payment_method_is_enabled( $alias, $gateway_id ) ) {
						return 'payment_signer_change_requires_methods_disabled';
					}
				}
				$attempts = self::unresolved_payment_attempts_state();
				if ( 'error' === $attempts ) {
					return 'payment_signer_change_attempt_lookup_failed';
				}
				if ( 'active' === $attempts ) {
					return 'payment_signer_change_active_attempts';
				}
			}

			// ── Phase E：commit（{existed,value} 備份→fence→寫入＋DB readback）──
			// Core L1/L2/L3 lifecycle rows are in $desired as ordinary verified rows,
			// so partial initialization cannot hide behind Core's void setters.
			$commit_failed = false;
			foreach ( $desired as $key => $value ) {
				$probe = null;
				if ( ! ProviderMaintenanceLock::fence( $owner )
					|| ! Settings::update( $key, $value )
					|| ! ( $probe = Settings::db_probe( $key ) )['ok']
					|| ! $probe['existed']
					|| $probe['value'] !== $value ) {
					$commit_failed = true;
					break;
				}
			}
			// commit 後的 effective 驗證：DB 已逐鍵 readback，這裡驗 resolver 對
			// committed 狀態的讀取＝pending 計算（防 resolver/overlay 語意漂移）。
			if ( ! $commit_failed ) {
				$committed = self::signer_snapshot_from_db();
				if ( ! $committed['ok'] || $committed['snapshot'] !== $after ) {
					$commit_failed = true;
				}
			}

			if ( ! $commit_failed ) {
				if ( ProviderMaintenanceLock::fence( $owner )
					&& ( ! $repair_required || ProviderMaintenanceLock::clear_crashed_flag( $owner ) )
					&& ProviderMaintenanceLock::fence( $owner ) ) {
					return '';
				}
				$commit_failed = true;
			}

			// ── Phase F：全量回滾（掃完全部鍵，不因單鍵失敗早退）──
			$rollback_failed = false;
			foreach ( $backup as $key => $orig ) {
				if ( ! ProviderMaintenanceLock::fence( $owner ) ) {
					$rollback_failed = true;
					continue; // ownership 已丟：不得回寫覆蓋新 writer。
				} elseif ( $orig['existed'] ) {
					$restored = Settings::update( $key, $orig['value'] );
					$probe    = Settings::db_probe( $key );
					$restored = $restored && $probe['ok'] && $probe['existed'] && $probe['value'] === $orig['value'];
				} else {
					$restored = Settings::delete( $key );
					$probe    = Settings::db_probe( $key );
					$restored = $restored && $probe['ok'] && ! $probe['existed'];
				}
				if ( ! $restored ) {
					$rollback_failed = true; // 記下，繼續掃剩餘鍵——不留 NEWKEY|OLDIV 混合 tuple。
				}
			}
			// 回滾後 effective 驗證：core 2.56.12 無 cache 失效 API，get() 在本
			// request 內可能仍回 commit 時的快取值——一律以「backup overlay」評估
			// （overlay 只蓋被本次寫過的鍵，其餘鍵 cache 與 DB 一致）。
			$restored_state = self::signer_snapshot_from_db();
			if ( $rollback_failed || ! $restored_state['ok'] || $restored_state['snapshot'] !== $before ) {
				if ( ! ProviderMaintenanceLock::mark_crashed( $owner ) ) {
					$release_owner = false; // keep the writer row as the last fail-closed sentinel.
				}
				return 'signer_gate_rollback_failed';
			}
			if ( $repair_required && ! ProviderMaintenanceLock::mark_crashed( $owner ) ) {
				$release_owner = false;
			}

			return 'settings_commit_failed_rolled_back';
		} finally {
			if ( $release_owner ) {
				ProviderMaintenanceLock::release( $owner );
			}
		}
	}

	/**
	 * 一組憑證的 desired 值（純計算，零寫入）。
	 *
	 * 「清除此組憑證」勾選＝整組四鍵清空、忽略同組其他輸入（secret 空白＝保留的
	 * 慣例讓已填過的站永遠清不空）。secret 有輸入才加密（一次），空白＝保留現值
	 * （key 不出現在 desired）。
	 *
	 * @param array<string,string> $keys
	 * @return array<string,string>
	 * @throws \RuntimeException 加密能力缺失
	 */
	private static function desired_credentials_group( string $prefix, array $keys ): array {
		if ( isset( $_POST[ 'ys_ec_ecpay_' . $prefix . '_clear' ] ) ) {
			return [
				$keys['test_mode']   => '',
				$keys['merchant_id'] => '',
				$keys['hash_key']    => '',
				$keys['hash_iv']     => '',
			];
		}

		$out = [
			$keys['test_mode']   => isset( $_POST[ 'ys_ec_ecpay_' . $prefix . '_test_mode' ] ) ? '1' : '0',
			$keys['merchant_id'] => sanitize_text_field( wp_unslash( (string) ( $_POST[ 'ys_ec_ecpay_' . $prefix . '_merchant_id' ] ?? '' ) ) ),
		];
		foreach ( [ 'hash_key', 'hash_iv' ] as $secret_key ) {
			$raw = trim( (string) wp_unslash( $_POST[ 'ys_ec_ecpay_' . $prefix . '_' . $secret_key ] ?? '' ) );
			if ( '' !== $raw ) {
				$out[ $keys[ $secret_key ] ] = Settings::encrypt_secret( $raw );
			}
		}

		return $out;
	}

	/**
	 * 🔴 R14：付款方式啟用狀態（payment signer gate 用）。lifecycle 為權威、
	 * 半套 API／throw 視為啟用（fail-closed 擋 signer 變更）；無 lifecycle 時
	 * 讀方式開關。
	 */
	private static function payment_method_is_enabled( string $alias, string $gateway_id ): bool {
		return self::method_is_enabled_from_db(
			'payment',
			$gateway_id,
			(string) ( Settings::PAYMENT_METHOD_KEYS[ $alias ] ?? '' )
		);
	}

	/**
	 * 🔴 R14：unresolved payment attempt 的 durable 判定——ECPay 付款方式且
	 * status 為 pending/offline_payment 的訂單：其已簽出的表單／
	 * ReturnURL／notify／query 都按
	 * 「當下憑證」驗章，payment signer 旋轉會讓這些 attempt 無法收斂。
	 * timeout/cancelled/failed 是 Core 本機終態，mark_paid 不再允許推進；在沒有
	 * durable provider receipt／人工 release 欄位前，不能用無界歷史終態永久鎖站。
	 *
	 * @return string 'none'|'active'|'error'
	 */
	private static function unresolved_payment_attempts_state(): string {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return 'error';
		}
		$orders           = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'orders';
		$wpdb->last_error = '';
		$count            = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$orders}
			 WHERE status IN ('pending','offline_payment')
			   AND ( payment_method LIKE 'ys\\_ec\\_ecpay\\_%' OR gateway_id LIKE 'ys\\_ec\\_ecpay\\_%' )"
		);
		if ( null === $count || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			return 'error';
		}

		return (int) $count > 0 ? 'active' : 'none';
	}

	/**
	 * 🔴 R14：lifecycle 鏡像同步＋readback 驗證（set_provider_enabled 是 void、
	 * 底層寫入不回報——不驗 readback 的「原子」宣稱不成立）。
	 */
	private static function sync_provider_lifecycle_verified( bool $enabled ): bool {
		$class = '\\YangSheep\\Ecommerce\\Core\\Provider\\YSProviderLifecycleState';
		if ( ! class_exists( $class ) ) {
			return true; // 無 lifecycle 系統＝ENABLED 設定列即權威，無鏡像可同步。
		}
		if ( ! method_exists( $class, 'set_provider_enabled' ) || ! method_exists( $class, 'is_provider_enabled' ) ) {
			return false; // 半套 API：無法驗證＝失敗（fail-closed）。
		}
		try {
			$class::set_provider_enabled( 'ys_ecpay', $enabled, Plugin::manifest() );

			return $enabled === (bool) $class::is_provider_enabled( 'ys_ecpay', Plugin::manifest() );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** @return bool|null null＝lifecycle 系統缺席（無鏡像） */
	private static function lifecycle_provider_enabled_state(): ?bool {
		$class = '\\YangSheep\\Ecommerce\\Core\\Provider\\YSProviderLifecycleState';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'is_provider_enabled' ) ) {
			return null;
		}
		try {
			return (bool) $class::is_provider_enabled( 'ys_ecpay', Plugin::manifest() );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** @param array<string,mixed> $descriptor */
	private static function home_method_is_enabled( string $method_id, array $descriptor ): bool {
		$enabled_option = (string) ( $descriptor['enabled_option'] ?? '' );
		return self::method_is_enabled_from_db( 'shipping', $method_id, $enabled_option );
	}

	/**
	 * Non-mutating Core 2.56.12 lifecycle probe for signer-authority gates.
	 * Core's public reads can lazily migrate L1/L2/L3; a refused credential save
	 * must remain truly zero-write. Any unreadable/malformed authority fails closed.
	 */
	private static function method_is_enabled_from_db( string $domain, string $method_id, string $legacy_key ): bool {
		if ( ! class_exists( '\\YangSheep\\Ecommerce\\Core\\Provider\\YSProviderLifecycleState' ) ) {
			if ( '' === $legacy_key ) {
				return true;
			}
			$legacy = Settings::db_probe( $legacy_key );
			return ! $legacy['ok'] || ( $legacy['existed'] && self::setting_truthy( $legacy['value'] ) );
		}

		$provider = Settings::db_probe( 'ys_provider_ys_ecpay_enabled' );
		$legacy   = Settings::db_probe( Settings::ENABLED );
		$cap      = Settings::db_probe( 'ys_capability_ys_ecpay_' . sanitize_key( $domain ) . '_enabled' );
		$methods  = Settings::db_probe( 'ys_methods_' . sanitize_key( $domain ) . '_state' );
		if ( ! $provider['ok'] || ! $legacy['ok'] || ! $cap['ok'] || ! $methods['ok'] ) {
			return true;
		}

		// If L1 is absent and legacy is enabled, Core's read would migrate and
		// initialize any missing L2/L3 rows to enabled. Model that state without
		// performing the migration.
		$migrates         = ! $provider['existed'] && $legacy['existed'] && self::setting_truthy( $legacy['value'] );
		$provider_enabled = $provider['existed'] ? self::setting_truthy( $provider['value'] ) : $migrates;
		if ( ! $provider_enabled ) {
			return false;
		}
		$cap_enabled = $cap['existed'] ? self::setting_truthy( $cap['value'] ) : $migrates;
		if ( ! $cap_enabled ) {
			return false;
		}

		$state = $methods['existed'] ? json_decode( $methods['value'], true ) : [];
		if ( ! is_array( $state ) ) {
			return true;
		}
		if ( isset( $state[ $method_id ] ) && is_array( $state[ $method_id ] )
			&& array_key_exists( 'enabled', $state[ $method_id ] ) ) {
			return self::setting_truthy( $state[ $method_id ]['enabled'] );
		}

		return $migrates;
	}

	private static function setting_truthy( $value ): bool {
		return in_array( (string) $value, [ '1', 'yes', 'true', 'on' ], true );
	}

	/**
	 * 全部 ECPay 物流方式的 label authority 狀態（v0.2.16：任何 effective signer
	 * 變更的 gate 用——R13 起 HOME family／reuse／憑證旋轉共用同一 gate，不再有
	 * home-only 的窄版檢查）。
	 *
	 * @return string 'none'|'active'|'error'
	 */
	private static function all_methods_authority_state(): string {
		return self::label_authority_state( array_map( 'strval', array_keys( EcpayShippingCatalog::all() ) ) );
	}

	/**
	 * @param string[] $method_ids
	 * @return string 'none'|'active'|'error'
	 */
	private static function label_authority_state( array $method_ids ): string {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return 'error';
		}

		$home_methods = array_values( array_filter( $method_ids, static fn( $m ) => '' !== (string) $m ) );
		if ( [] === $home_methods ) {
			return 'error';
		}

		$attempts     = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'shipping_label_attempts';
		$labels       = $wpdb->prefix . YS_ECOMMERCE_TABLE_PREFIX . 'shipping_labels';
		$placeholders = implode( ', ', array_fill( 0, count( $home_methods ), '%s' ) );
		$args         = array_merge( [ 'ecpay' ], $home_methods );

		$active = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$attempts}
			 WHERE provider = %s
			   AND shipping_method IN ({$placeholders})
			   AND active_order_key IS NOT NULL",
			...$args
		) );
		if ( null === $active || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			return 'error';
		}
		if ( (int) $active > 0 ) {
			return 'active';
		}

		// Pre-authority labels have no attempt row.  Core intentionally treats
		// them as active even if an old local-only cancel flag exists, because no
		// provider cancellation was proven.
		$legacy = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$labels} l
			 LEFT JOIN {$attempts} a ON a.label_id = l.id
			 WHERE l.provider = %s
			   AND l.shipping_method IN ({$placeholders})
			   AND a.id IS NULL",
			...$args
		) );
		if ( null === $legacy || '' !== (string) ( $wpdb->last_error ?? '' ) ) {
			return 'error';
		}

		return (int) $legacy > 0 ? 'active' : 'none';
	}

	/**
	 * @param array<int,string>      $aliases
	 * @param array<int,string>|null $allowed 實際獲准啟用的 alias；null 表示照勾選存。
	 */
	private static function save_method_switches( array $aliases, ?array $allowed = null ): void {
		foreach ( $aliases as $alias ) {
			$setting_key = Settings::method_key( $alias );
			if ( '' === $setting_key ) {
				continue;
			}

			$checked = isset( $_POST[ 'ys_ec_ecpay_' . $alias . '_enabled' ] );
			$enabled = null === $allowed ? $checked : in_array( $alias, $allowed, true );

			Settings::update( $setting_key, $enabled ? '1' : '0' );
		}
	}

	/**
	 * 存每個物流方式的專屬設定。
	 *
	 * 🔴 C2C 的退貨門市**每個方式一把 key**。舊版全部共用一個隱藏設定，而且後台
	 * 根本沒有輸入欄位——業主無從填起，送單必然失敗；就算手動塞進資料庫，全家的
	 * 退貨門市也會被 7-ELEVEN 拿去用。
	 */
	private static function save_shipping_method_options(): void {
		foreach ( EcpayShippingCatalog::all() as $method_id => $descriptor ) {
			$alias = (string) $descriptor['alias'];

			if ( true === $descriptor['supports_return_store'] ) {
				$option = (string) $descriptor['return_store_option'];
				if ( '' !== $option ) {
					Settings::update(
						$option,
						sanitize_text_field( wp_unslash( (string) ( $_POST[ 'ys_ec_ecpay_' . $alias . '_return_store_id' ] ?? '' ) ) )
					);
				}
			}

			if ( true === $descriptor['requires_goods_weight'] ) {
				$raw    = (string) wp_unslash( $_POST[ 'ys_ec_ecpay_' . $alias . '_goods_weight' ] ?? '' );
				$weight = (float) $raw;

				// 🔴 超過上限**不 clamp**。悄悄把 25 公斤存成 20 公斤，業主看到的是
				// 一個他沒填的數字，而送出去的是一張運費算錯、到門市才被退的單。
				// 超出範圍就當沒填（該方式因此無法啟用，錯誤是看得見的）。
				$valid = $weight > 0.0 && $weight <= 20.0;

				Settings::update(
					'shipping_' . $method_id . '_goods_weight',
					$valid ? number_format( $weight, 3, '.', '' ) : ''
				);
			}
		}
	}

	/**
	 * 勾選了、而且**設定完整**因此真的可以啟用的 alias。
	 *
	 * 沒填重量的郵局擋下來：讓它「開著但送不出」是最糟的狀態——後台看起來是好的，
	 * 顧客選得到，錯誤要到出貨那天才出現。
	 *
	 * 🔴 退貨門市**不在這裡**。官方規格是選填（未設定時退回原寄件門市），把它當
	 * 必填會讓一個完全合法的設定被判定成「未設定完成」而無法啟用。
	 *
	 * @param array<int,string> $aliases
	 * @return array<int,string>
	 */
	private static function selectable_aliases( array $aliases ): array {
		$out = [];
		foreach ( $aliases as $alias ) {
			if ( ! isset( $_POST[ 'ys_ec_ecpay_' . $alias . '_enabled' ] ) ) {
				continue;
			}

			$descriptor = EcpayShippingCatalog::get_by_alias( $alias );
			if ( null === $descriptor ) {
				continue;
			}

			if ( true === $descriptor['requires_goods_weight']
				&& (float) Settings::get( 'shipping_' . (string) $descriptor['method_id'] . '_goods_weight', '0' ) <= 0.0 ) {
				continue;
			}

			$out[] = $alias;
		}

		return $out;
	}

	private static function save_sender_fields(): void {
		foreach ( Settings::SENDER_KEYS as $alias => $setting_key ) {
			$value = sanitize_text_field( wp_unslash( (string) ( $_POST[ 'ys_ec_ecpay_sender_' . $alias ] ?? '' ) ) );
			Settings::update( $setting_key, $value );
		}
	}

	/**
	 * @param array<int,string>    $aliases
	 * @param array<string,string> $ids
	 * @return array<int,string>
	 */
	private static function selected_ids_from_post( array $aliases, array $ids ): array {
		$selected = [];
		foreach ( $aliases as $alias ) {
			$id = $ids[ $alias ] ?? '';
			if ( '' !== $id && isset( $_POST[ 'ys_ec_ecpay_' . $alias . '_enabled' ] ) ) {
				$selected[] = $id;
			}
		}

		return $selected;
	}

	/**
	 * Keep YS CART's legacy gateway visibility list in sync when it exists.
	 *
	 * @param array<int,string> $selected_ids
	 */
	private static function sync_gateway_enabled_list( array $selected_ids ): void {
		self::sync_enabled_list( 'gateway_enabled_list', array_values( self::PAYMENT_GATEWAY_IDS ), $selected_ids );
	}

	/**
	 * Keep YS CART's legacy shipping visibility list in sync when it exists.
	 *
	 * @param array<int,string> $selected_ids
	 */
	private static function sync_shipping_enabled_list( array $selected_ids ): void {
		self::sync_enabled_list( 'ys_ec_shipping_enabled_list', array_values( self::shipping_method_ids() ), $selected_ids );
	}

	/**
	 * @param array<int,string> $owned_ids
	 * @param array<int,string> $selected_ids
	 */
	private static function sync_enabled_list( string $setting_key, array $owned_ids, array $selected_ids ): void {
		$raw = (string) Settings::get( $setting_key, '' );
		if ( '' === $raw ) {
			return;
		}

		$current = json_decode( $raw, true );
		if ( ! is_array( $current ) ) {
			return;
		}

		$owned_ids    = array_values( array_unique( array_map( 'sanitize_key', $owned_ids ) ) );
		$selected_ids = array_values( array_unique( array_map( 'sanitize_key', $selected_ids ) ) );
		$next         = [];

		foreach ( $current as $id ) {
			$id = sanitize_key( (string) $id );
			if ( '' !== $id && ! in_array( $id, $owned_ids, true ) ) {
				$next[] = $id;
			}
		}

		foreach ( $selected_ids as $id ) {
			if ( '' !== $id && ! in_array( $id, $next, true ) ) {
				$next[] = $id;
			}
		}

		Settings::update( $setting_key, wp_json_encode( $next ) );
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '權限不足。', 'ys-cart-ecpay' ), 403 );
		}

		$settings     = self::settings_for_render();
		$nonce_action = self::NONCE_ACTION;

		if ( class_exists( YSAdminApp::class ) ) {
			YSAdminApp::open( '綠界 ECPay 設定', '金物流 / 綠界' );
		}

		$template = YS_CART_ECPAY_DIR . 'templates/admin/ecpay-settings.php';
		if ( is_readable( $template ) ) {
			include $template;
		} else {
			echo '<div class="notice notice-error"><p>' . esc_html__( '找不到綠界設定樣板。', 'ys-cart-ecpay' ) . '</p></div>';
		}

		if ( class_exists( YSAdminApp::class ) ) {
			YSAdminApp::close();
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function settings_for_render(): array {
		$tab = self::normalize_tab( sanitize_key( wp_unslash( (string) ( $_GET['tab'] ?? self::DEFAULT_TAB ) ) ) );
		$out = [
			'enabled'               => self::is_provider_enabled(),
			'tab'                   => $tab,
			'tabs'                  => self::TABS,
			'page_url'              => admin_url( 'admin.php?page=ys-provider-ecpay' ),
			'shipping_settings_url' => admin_url( 'admin.php?page=ys-ec-shipping' ),
			'callback_urls'         => [
				'payment_notify'   => rest_url( 'ys-ecommerce/v1/ecpay/notify' ),
				'payment_info'     => rest_url( 'ys-ecommerce/v1/ecpay/payment-info' ),
				'payment_return'   => rest_url( 'ys-ecommerce/v1/ecpay/return' ),
				'store_callback'   => rest_url( 'ys-ecommerce/v1/ecpay/store-callback' ),
				'logistics_notify' => rest_url( 'ys-ecommerce/v1/ecpay/logistics-notify' ),
				'store_map'        => rest_url( 'ys-ecommerce-headless/v1/stores/ecpay/map-url' ),
			],
			'payment_methods'       => [
				'credit'  => '信用卡',
				'atm'     => 'ATM 虛擬帳號',
				'cvs'     => '超商代碼',
				'barcode' => '超商條碼',
			],
			// 物流方式清單、通路、溫層、是否需要退貨門市——全部由型錄導出。
			'shipping_methods'      => EcpayShippingCatalog::admin_rows(),
		];

		// 每個方式的專屬設定目前值（後台必須讀得回來，否則存了等於沒存）。
		foreach ( EcpayShippingCatalog::all() as $method_id => $descriptor ) {
			$alias = (string) $descriptor['alias'];

			if ( true === $descriptor['supports_return_store'] ) {
				$out['shipping_methods'][ $alias ]['return_store_id'] =
					(string) Settings::get( (string) $descriptor['return_store_option'], '' );
			}

			if ( true === $descriptor['requires_goods_weight'] ) {
				$out['shipping_methods'][ $alias ]['goods_weight'] =
					(string) Settings::get( 'shipping_' . $method_id . '_goods_weight', '' );
			}
		}

		foreach ( [
			'payment'            => Settings::PAYMENT_KEYS,
			'logistics_b2c_home' => Settings::LOGISTICS_B2C_HOME_KEYS,
			'logistics_c2c'      => Settings::LOGISTICS_C2C_KEYS,
		] as $prefix => $keys ) {
			$out[ $prefix . '_test_mode' ]       = '1' === (string) Settings::get( $keys['test_mode'], '1' );
			$out[ $prefix . '_merchant_id' ]     = (string) Settings::get( $keys['merchant_id'], '' );
			$out[ $prefix . '_hash_key_is_set' ] = '' !== (string) Settings::get( $keys['hash_key'], '' );
			$out[ $prefix . '_hash_iv_is_set' ]  = '' !== (string) Settings::get( $keys['hash_iv'], '' );
		}
		$out['logistics_reuse_payment'] = Settings::payment_reuse_enabled();
		$out['legacy_logistics_credentials_present'] = '' !== (string) Settings::get( Settings::LOGISTICS_KEYS['merchant_id'], '' )
			|| '' !== (string) Settings::get( Settings::LOGISTICS_KEYS['hash_key'], '' )
			|| '' !== (string) Settings::get( Settings::LOGISTICS_KEYS['hash_iv'], '' );
		$out['home_credential_family'] = Settings::home_credential_family();

		$gateway_enabled_list  = self::read_enabled_list( 'gateway_enabled_list' );
		$shipping_enabled_list = self::read_enabled_list( 'ys_ec_shipping_enabled_list' );
		$shipping_ids          = self::shipping_method_ids();
		foreach ( Settings::method_keys() as $alias => $setting_key ) {
			$enabled = '1' === (string) Settings::get( $setting_key, '0' );
			if ( isset( self::PAYMENT_GATEWAY_IDS[ $alias ] ) && null !== $gateway_enabled_list ) {
				$enabled = $enabled && in_array( self::PAYMENT_GATEWAY_IDS[ $alias ], $gateway_enabled_list, true );
			}
			if ( isset( $shipping_ids[ $alias ] ) && null !== $shipping_enabled_list ) {
				$enabled = $enabled && in_array( $shipping_ids[ $alias ], $shipping_enabled_list, true );
			}
			$out[ $alias . '_enabled' ] = $enabled;
		}

		foreach ( Settings::SENDER_KEYS as $alias => $setting_key ) {
			$out[ 'sender_' . $alias ] = (string) Settings::get( $setting_key, '' );
		}

		return $out;
	}

	private static function normalize_tab( string $tab ): string {
		return array_key_exists( $tab, self::TABS ) ? $tab : self::DEFAULT_TAB;
	}

	private static function is_provider_enabled(): bool {
		if ( class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::is_provider_enabled( 'ys_ecpay', Plugin::manifest() );
		}

		return '1' === (string) Settings::get( Settings::ENABLED, '0' );
	}

	/** @return array{ok:bool,desired:array<string,string>} */
	private static function lifecycle_provider_settings_desired( bool $enabled ): array {
		if ( ! class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return [ 'ok' => true, 'desired' => [] ];
		}
		try {
			$manifest = Plugin::manifest();
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'desired' => [] ];
		}
		if ( ! is_array( $manifest ) ) {
			return [ 'ok' => false, 'desired' => [] ];
		}

		$desired = [ 'ys_provider_ys_ecpay_enabled' => $enabled ? '1' : '0' ];
		if ( ! $enabled ) {
			return [ 'ok' => true, 'desired' => $desired ];
		}

		$domains = $manifest['domains'] ?? [];
		if ( ! is_array( $domains ) ) {
			return [ 'ok' => false, 'desired' => [] ];
		}
		foreach ( $domains as $domain ) {
			$domain = sanitize_key( (string) $domain );
			if ( '' === $domain ) {
				continue;
			}
			$capability_key = 'ys_capability_ys_ecpay_' . $domain . '_enabled';
			$capability = Settings::db_probe( $capability_key );
			if ( ! $capability['ok'] ) {
				return [ 'ok' => false, 'desired' => [] ];
			}
			if ( ! $capability['existed'] ) {
				$desired[ $capability_key ] = '1';
			}

			$items = $manifest['capabilities'][ $domain ]['methods'] ?? [];
			if ( ! is_array( $items ) || [] === $items ) {
				continue;
			}
			$method_ids = [];
			foreach ( $items as $item ) {
				$id = is_array( $item ) ? sanitize_key( (string) ( $item['id'] ?? '' ) ) : '';
				if ( '' !== $id ) {
					$method_ids[] = $id;
				}
			}
			if ( [] === $method_ids ) {
				continue;
			}

			$methods_key = 'ys_methods_' . $domain . '_state';
			$probe       = Settings::db_probe( $methods_key );
			if ( ! $probe['ok'] ) {
				return [ 'ok' => false, 'desired' => [] ];
			}
			$state = $probe['existed'] ? json_decode( $probe['value'], true ) : [];
			$state = is_array( $state ) ? $state : [];
			$next  = $state;
			$order = 0;
			foreach ( $next as $row ) {
				if ( is_array( $row ) && isset( $row['order'] ) ) {
					$order = max( $order, (int) $row['order'] + 1 );
				}
			}
			foreach ( array_values( array_unique( $method_ids ) ) as $method_id ) {
				if ( ! isset( $next[ $method_id ] ) || ! is_array( $next[ $method_id ] ) ) {
					$next[ $method_id ] = [ 'enabled' => true, 'order' => $order++, 'provider_id' => 'ys_ecpay' ];
				} elseif ( ! isset( $next[ $method_id ]['provider_id'] ) ) {
					$next[ $method_id ]['provider_id'] = 'ys_ecpay';
				}
			}
			if ( $next !== $state ) {
				$encoded = wp_json_encode( $next );
				if ( ! is_string( $encoded ) ) {
					return [ 'ok' => false, 'desired' => [] ];
				}
				$desired[ $methods_key ] = $encoded;
			}
		}

		return [ 'ok' => true, 'desired' => $desired ];
	}

	/** @return array{ok:bool,desired:array<string,string>} */
	private static function lifecycle_methods_setting_desired(
		string $domain,
		array $owned_ids_by_alias,
		array $selected_ids,
		array $pending
	): array {
		if ( ! class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return [ 'ok' => true, 'desired' => [] ];
		}
		$domain      = sanitize_key( $domain );
		$methods_key = 'ys_methods_' . $domain . '_state';
		if ( array_key_exists( $methods_key, $pending ) ) {
			$raw = (string) $pending[ $methods_key ];
		} else {
			$probe = Settings::db_probe( $methods_key );
			if ( ! $probe['ok'] ) {
				return [ 'ok' => false, 'desired' => [] ];
			}
			$raw = $probe['existed'] ? $probe['value'] : '';
		}
		$state        = json_decode( $raw, true );
		$state        = is_array( $state ) ? $state : [];
		$owned_ids    = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_values( $owned_ids_by_alias ) ) ) ) );
		$selected_ids = array_values( array_unique( array_filter( array_map( 'sanitize_key', $selected_ids ) ) ) );
		$order        = 0;
		foreach ( $state as $row ) {
			if ( is_array( $row ) && isset( $row['order'] ) ) {
				$order = max( $order, (int) $row['order'] + 1 );
			}
		}
		foreach ( $owned_ids as $method_id ) {
			if ( ! isset( $state[ $method_id ] ) || ! is_array( $state[ $method_id ] ) ) {
				$state[ $method_id ] = [ 'order' => $order++ ];
			}
			$state[ $method_id ]['enabled']     = in_array( $method_id, $selected_ids, true );
			$state[ $method_id ]['provider_id'] = 'ys_ecpay';
		}
		$encoded = wp_json_encode( $state );
		return is_string( $encoded )
			? [ 'ok' => true, 'desired' => [ $methods_key => $encoded ] ]
			: [ 'ok' => false, 'desired' => [] ];
	}

	/** @return array{ok:bool,desired:array<string,string>} */
	private static function enabled_list_setting_desired( string $key, array $owned_ids, array $selected_ids ): array {
		$probe = Settings::db_probe( $key );
		if ( ! $probe['ok'] ) {
			return [ 'ok' => false, 'desired' => [] ];
		}
		if ( ! $probe['existed'] || '' === $probe['value'] ) {
			return [ 'ok' => true, 'desired' => [] ];
		}
		$current = json_decode( $probe['value'], true );
		if ( ! is_array( $current ) ) {
			return [ 'ok' => true, 'desired' => [] ];
		}
		$owned_ids    = array_values( array_unique( array_filter( array_map( 'sanitize_key', $owned_ids ) ) ) );
		$selected_ids = array_values( array_unique( array_filter( array_map( 'sanitize_key', $selected_ids ) ) ) );
		$next = [];
		foreach ( $current as $id ) {
			$id = sanitize_key( (string) $id );
			if ( '' !== $id && ! in_array( $id, $owned_ids, true ) ) {
				$next[] = $id;
			}
		}
		foreach ( $selected_ids as $id ) {
			if ( ! in_array( $id, $next, true ) ) {
				$next[] = $id;
			}
		}
		$encoded = wp_json_encode( $next );
		return is_string( $encoded )
			? [ 'ok' => true, 'desired' => [ $key => $encoded ] ]
			: [ 'ok' => false, 'desired' => [] ];
	}

	/** @return array{error:string,release:bool} */
	private static function commit_settings_map_under_owner( array $desired, string $owner, string $error_prefix ): array {
		$backup = [];
		foreach ( $desired as $key => $value ) {
			$probe = Settings::db_probe( (string) $key );
			if ( ! $probe['ok'] ) {
				return [ 'error' => 'settings_state_read_failed', 'release' => true ];
			}
			if ( $probe['existed'] && $probe['value'] === (string) $value ) {
				unset( $desired[ $key ] );
				continue;
			}
			$backup[ $key ] = [ 'existed' => $probe['existed'], 'value' => $probe['value'] ];
		}
		$commit_ok = true;
		foreach ( $desired as $key => $value ) {
			$commit_ok = ProviderMaintenanceLock::fence( $owner )
				&& Settings::update( (string) $key, (string) $value );
			$readback = Settings::db_probe( (string) $key );
			$commit_ok = $commit_ok && $readback['ok'] && $readback['existed'] && (string) $value === $readback['value'];
			if ( ! $commit_ok ) {
				break;
			}
		}
		if ( $commit_ok && ProviderMaintenanceLock::fence( $owner ) ) {
			return [ 'error' => '', 'release' => true ];
		}

		$rollback_failed = false;
		foreach ( $backup as $key => $orig ) {
			if ( ! ProviderMaintenanceLock::fence( $owner ) ) {
				$rollback_failed = true;
				continue;
			}
			if ( $orig['existed'] ) {
				$restored = Settings::update( (string) $key, $orig['value'] );
				$readback = Settings::db_probe( (string) $key );
				$restored = $restored && $readback['ok'] && $readback['existed'] && $orig['value'] === $readback['value'];
			} else {
				$restored = Settings::delete( (string) $key );
				$readback = Settings::db_probe( (string) $key );
				$restored = $restored && $readback['ok'] && ! $readback['existed'];
			}
			$rollback_failed = $rollback_failed || ! $restored;
		}
		if ( $rollback_failed ) {
			$marked = ProviderMaintenanceLock::mark_crashed( $owner );
			return [ 'error' => $error_prefix . '_rollback_failed', 'release' => $marked ];
		}

		return [ 'error' => $error_prefix . '_commit_failed_rolled_back', 'release' => true ];
	}

	/** Payment/shipping tab: legacy rows, enabled list, and Core L1/L2/L3 are one transaction. */
	private static function apply_methods_tab_atomically( string $tab, bool $provider_enabled ): string {
		if ( ! in_array( $tab, [ 'payment', 'shipping' ], true ) ) {
			return 'settings_state_read_failed';
		}
		$owner = ProviderMaintenanceLock::acquire();
		if ( null === $owner ) {
			return 'settings_maintenance_lock_unavailable';
		}
		$release_owner = true;
		try {
			if ( ProviderMaintenanceLock::crashed_flag_present() ) {
				return 'settings_crash_repair_requires_api_tab';
			}
			$desired = [ Settings::ENABLED => $provider_enabled ? '1' : '0' ];
			$lifecycle = self::lifecycle_provider_settings_desired( $provider_enabled );
			if ( ! $lifecycle['ok'] ) {
				return 'settings_state_read_failed';
			}
			$desired = array_replace( $desired, $lifecycle['desired'] );

			if ( 'payment' === $tab ) {
				$aliases      = [ 'credit', 'atm', 'cvs', 'barcode' ];
				$selected_ids = self::selected_ids_from_post( $aliases, self::PAYMENT_GATEWAY_IDS );
				foreach ( $aliases as $alias ) {
					$desired[ Settings::PAYMENT_METHOD_KEYS[ $alias ] ] = isset( $_POST[ 'ys_ec_ecpay_' . $alias . '_enabled' ] ) ? '1' : '0';
				}
				$list = self::enabled_list_setting_desired(
					'gateway_enabled_list',
					array_values( self::PAYMENT_GATEWAY_IDS ),
					$selected_ids
				);
				$methods = self::lifecycle_methods_setting_desired( 'payment', self::PAYMENT_GATEWAY_IDS, $selected_ids, $desired );
				if ( ! $list['ok'] || ! $methods['ok'] ) {
					return 'settings_state_read_failed';
				}
				$desired = array_replace( $desired, $list['desired'], $methods['desired'] );
			} else {
				$ids     = self::shipping_method_ids();
				$aliases = array_keys( $ids );
				foreach ( EcpayShippingCatalog::all() as $method_id => $descriptor ) {
					$alias = (string) ( $descriptor['alias'] ?? '' );
					if ( true === ( $descriptor['supports_return_store'] ?? false ) ) {
						$key = (string) ( $descriptor['return_store_option'] ?? '' );
						if ( '' !== $key ) {
							$desired[ $key ] = sanitize_text_field( wp_unslash( (string) ( $_POST[ 'ys_ec_ecpay_' . $alias . '_return_store_id' ] ?? '' ) ) );
						}
					}
					if ( true === ( $descriptor['requires_goods_weight'] ?? false ) ) {
						$raw    = (string) wp_unslash( $_POST[ 'ys_ec_ecpay_' . $alias . '_goods_weight' ] ?? '' );
						$weight = (float) $raw;
						$key    = 'shipping_' . (string) $method_id . '_goods_weight';
						$desired[ $key ] = $weight > 0.0 && $weight <= 20.0 ? number_format( $weight, 3, '.', '' ) : '';
					}
				}

				$selected_aliases = [];
				foreach ( $aliases as $alias ) {
					if ( ! isset( $_POST[ 'ys_ec_ecpay_' . $alias . '_enabled' ] ) ) {
						continue;
					}
					$descriptor = EcpayShippingCatalog::get_by_alias( $alias );
					if ( null === $descriptor ) {
						continue;
					}
					if ( true === ( $descriptor['requires_goods_weight'] ?? false ) ) {
						$key = 'shipping_' . (string) $descriptor['method_id'] . '_goods_weight';
						if ( (float) ( $desired[ $key ] ?? 0 ) <= 0.0 ) {
							continue;
						}
					}
					$selected_aliases[] = $alias;
				}
				$selected_ids = [];
				foreach ( $aliases as $alias ) {
					$key = Settings::method_key( $alias );
					if ( '' !== $key ) {
						$desired[ $key ] = in_array( $alias, $selected_aliases, true ) ? '1' : '0';
					}
				}
				foreach ( $selected_aliases as $alias ) {
					$selected_ids[] = $ids[ $alias ];
				}
				$list = self::enabled_list_setting_desired( 'ys_ec_shipping_enabled_list', array_values( $ids ), $selected_ids );
				$methods = self::lifecycle_methods_setting_desired( 'shipping', $ids, $selected_ids, $desired );
				if ( ! $list['ok'] || ! $methods['ok'] ) {
					return 'settings_state_read_failed';
				}
				$desired = array_replace( $desired, $list['desired'], $methods['desired'] );
				foreach ( Settings::SENDER_KEYS as $alias => $setting_key ) {
					$desired[ $setting_key ] = sanitize_text_field( wp_unslash( (string) ( $_POST[ 'ys_ec_ecpay_sender_' . $alias ] ?? '' ) ) );
				}
			}

			$result        = self::commit_settings_map_under_owner( $desired, $owner, 'method_lifecycle' );
			$release_owner = $result['release'];
			return $result['error'];
		} finally {
			if ( $release_owner ) {
				ProviderMaintenanceLock::release( $owner );
			}
		}
	}

	/**
	 * Payment/shipping tabs also carry the provider checkbox. Keep the legacy
	 * setting row and Core lifecycle mirror as one verified writer operation.
	 */
	private static function apply_provider_enabled_atomically( bool $enabled ): string {
		$owner = ProviderMaintenanceLock::acquire();
		if ( null === $owner ) {
			return 'settings_maintenance_lock_unavailable';
		}
		$release_owner = true;
		try {
			// A narrow payment/shipping-tab save cannot prove or repair a credential
			// transaction that crashed. Only a full API tuple re-entry may clear it.
			if ( ProviderMaintenanceLock::crashed_flag_present() ) {
				return 'settings_crash_repair_requires_api_tab';
			}

			$lifecycle = self::lifecycle_provider_settings_desired( $enabled );
			if ( ! $lifecycle['ok'] ) {
				return 'settings_state_read_failed';
			}
			$desired       = array_replace( [ Settings::ENABLED => $enabled ? '1' : '0' ], $lifecycle['desired'] );
			$result        = self::commit_settings_map_under_owner( $desired, $owner, 'provider_lifecycle' );
			$release_owner = $result['release'];
			return $result['error'];
		} finally {
			if ( $release_owner ) {
				ProviderMaintenanceLock::release( $owner );
			}
		}
	}

	/**
	 * @param array<string,string> $owned_ids_by_alias
	 * @param array<int,string>   $selected_ids
	 */
	private static function sync_lifecycle_methods( string $domain, array $owned_ids_by_alias, array $selected_ids ): void {
		if ( ! class_exists( '\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState' ) ) {
			return;
		}

		$state        = \YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::get_methods_state( $domain );
		$owned_ids    = array_values( array_unique( array_map( 'sanitize_key', array_values( $owned_ids_by_alias ) ) ) );
		$selected_ids = array_values( array_unique( array_map( 'sanitize_key', $selected_ids ) ) );
		$order        = 0;

		foreach ( $state as $row ) {
			if ( is_array( $row ) && isset( $row['order'] ) ) {
				$order = max( $order, (int) $row['order'] + 1 );
			}
		}

		foreach ( $owned_ids as $method_id ) {
			if ( '' === $method_id ) {
				continue;
			}

			if ( ! isset( $state[ $method_id ] ) || ! is_array( $state[ $method_id ] ) ) {
				$state[ $method_id ] = [ 'order' => $order++ ];
			}

			$state[ $method_id ]['enabled']     = in_array( $method_id, $selected_ids, true );
			$state[ $method_id ]['provider_id'] = 'ys_ecpay';
		}

		\YangSheep\Ecommerce\Core\Provider\YSProviderLifecycleState::update_methods_state( $domain, $state );
	}

	/**
	 * @return array<int,string>|null
	 */
	private static function read_enabled_list( string $setting_key ): ?array {
		$raw = (string) Settings::get( $setting_key, '' );
		if ( '' === $raw ) {
			return null;
		}

		$list = json_decode( $raw, true );
		if ( ! is_array( $list ) ) {
			return null;
		}

		$normalized = array_values( array_unique( array_filter( array_map( static fn( $id ): string => sanitize_key( (string) $id ), $list ) ) ) );
		return [] === $normalized ? null : $normalized;
	}
}
