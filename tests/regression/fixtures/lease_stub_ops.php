<?php
declare(strict_types=1);

/**
 * R14 共用：ProviderMaintenanceLock（reader/writer lease）所需的 wp_options
 * 原語——以 **delegating proxy** 包住各測試既有的 wpdb stub：
 *
 *   require_once __DIR__ . '/fixtures/lease_stub_ops.php';   // 於 requires 區
 *   v0216_wrap_wpdb_with_lease();                            // 於 $GLOBALS['wpdb'] 建好之後
 *
 * lease 相關的 SELECT/INSERT/UPDATE/DELETE 由 proxy 以
 * $GLOBALS['v0216_lease_options']（空＝無任何鎖）處理；其餘一律委派 inner stub，
 * 各測試的既有行為完全不變。測試可預植 writer row 模擬「設定 commit 進行中」。
 */
if ( ! class_exists( 'V0216LeaseWpdbProxy' ) ) {
	final class V0216LeaseWpdbProxy {
		public object $inner;
		public string $options = 'wp_options';

		public function __construct( object $inner ) {
			$this->inner = $inner;
		}

		public function __get( $name ) {
			return $this->inner->$name ?? null;
		}

		public function __set( $name, $value ): void {
			$this->inner->$name = $value;
		}

		public function __isset( $name ): bool {
			return isset( $this->inner->$name );
		}

		public function __call( $name, $args ) {
			return $this->inner->$name( ...$args );
		}

		// 🔴 guard 相容：production 以 method_exists($wpdb,'get_row'/'delete') 判能力
		// ——__call 對 method_exists 不可見，必須提供真實方法（委派 inner）。
		public function get_row( $sql, ...$args ) {
			return method_exists( $this->inner, 'get_row' ) ? $this->inner->get_row( $sql, ...$args ) : null;
		}

		public function delete( $table, array $where ) {
			return method_exists( $this->inner, 'delete' ) ? $this->inner->delete( $table, $where ) : 0;
		}

		public function prepare( $sql, ...$args ) {
			if ( method_exists( $this->inner, 'prepare' ) ) {
				return $this->inner->prepare( $sql, ...$args );
			}
			foreach ( $args as $a ) {
				$sql = preg_replace( '/%[sd]/', "'" . str_replace( "'", "''", (string) $a ) . "'", (string) $sql, 1 );
			}
			return $sql;
		}

		public function esc_like( $text ): string {
			return addcslashes( (string) $text, '_%\\' );
		}

		public function get_var( $sql ) {
			if ( preg_match( "/SELECT option_value FROM wp_options WHERE option_name = '([^']+)'/", (string) $sql, $m ) ) {
				return $GLOBALS['v0216_lease_options'][ $m[1] ] ?? null;
			}
			return method_exists( $this->inner, 'get_var' ) ? $this->inner->get_var( $sql ) : null;
		}

		public function get_results( $sql ) {
			if ( preg_match( "/WHERE option_name LIKE '([^']+)'/", (string) $sql, $m ) ) {
				$like  = $m[1];
				$regex = '';
				for ( $i = 0, $n = strlen( $like ); $i < $n; $i++ ) {
					$char = $like[ $i ];
					if ( '\\' === $char && $i + 1 < $n ) {
						$regex .= preg_quote( $like[ ++$i ], '/' );
					} elseif ( '%' === $char ) {
						$regex .= '.*';
					} elseif ( '_' === $char ) {
						$regex .= '.';
					} else {
						$regex .= preg_quote( $char, '/' );
					}
				}
				$out    = [];
				foreach ( $GLOBALS['v0216_lease_options'] as $k => $v ) {
					if ( preg_match( '/^' . $regex . '$/D', (string) $k ) ) {
						$out[] = (object) [ 'option_name' => $k, 'option_value' => $v ];
					}
				}
				return $out;
			}
			return method_exists( $this->inner, 'get_results' ) ? $this->inner->get_results( $sql ) : [];
		}

		public function insert( $table, array $data ) {
			if ( 'wp_options' === $table && isset( $data['option_name'] ) ) {
				$k = (string) $data['option_name'];
				if ( array_key_exists( $k, $GLOBALS['v0216_lease_options'] ) ) {
					return false; // NX 撞鍵
				}
				$GLOBALS['v0216_lease_options'][ $k ] = (string) ( $data['option_value'] ?? '' );
				return 1;
			}
			return method_exists( $this->inner, 'insert' ) ? $this->inner->insert( $table, $data ) : false;
		}

		public function query( $sql ) {
			$sql = (string) $sql;
			if ( preg_match( "/UPDATE wp_options SET option_value = '([^']+)' WHERE option_name = '([^']+)' AND option_value = '([^']+)'/", $sql, $m ) ) {
				if ( ( $GLOBALS['v0216_lease_options'][ $m[2] ] ?? null ) === $m[3] ) {
					$GLOBALS['v0216_lease_options'][ $m[2] ] = $m[1];
					return 1;
				}
				return 0;
			}
			if ( preg_match( "/UPDATE wp_options SET option_value = '([^']+)' WHERE option_name = '([^']+)' AND option_value LIKE '([^']+)'/", $sql, $m ) ) {
				$cur = $GLOBALS['v0216_lease_options'][ $m[2] ] ?? null;
				if ( null !== $cur && 0 === strpos( (string) $cur, rtrim( $m[3], '%' ) ) ) {
					$GLOBALS['v0216_lease_options'][ $m[2] ] = $m[1];
					return 1;
				}
				return 0;
			}
			if ( preg_match( "/DELETE FROM wp_options WHERE option_name = '([^']+)' AND option_value LIKE '([^']+)'/", $sql, $m ) ) {
				$cur = $GLOBALS['v0216_lease_options'][ $m[1] ] ?? null;
				if ( null !== $cur && 0 === strpos( (string) $cur, rtrim( $m[2], '%' ) ) ) {
					unset( $GLOBALS['v0216_lease_options'][ $m[1] ] );
					return 1;
				}
				return 0;
			}
			if ( preg_match( "/DELETE FROM wp_options WHERE option_name = '([^']+)' AND option_value = '([^']+)'/", $sql, $m ) ) {
				if ( ( $GLOBALS['v0216_lease_options'][ $m[1] ] ?? null ) === $m[2] ) {
					unset( $GLOBALS['v0216_lease_options'][ $m[1] ] );
					return 1;
				}
				return 0;
			}
			if ( preg_match( "/DELETE FROM wp_options WHERE option_name = '([^']+)'$/", $sql, $m ) ) {
				$existed = array_key_exists( $m[1], $GLOBALS['v0216_lease_options'] );
				unset( $GLOBALS['v0216_lease_options'][ $m[1] ] );
				return $existed ? 1 : 0;
			}
			return method_exists( $this->inner, 'query' ) ? $this->inner->query( $sql ) : 0;
		}
	}

	function v0216_wrap_wpdb_with_lease(): void {
		$GLOBALS['v0216_lease_options'] = [];
		$GLOBALS['wpdb'] = new V0216LeaseWpdbProxy( $GLOBALS['wpdb'] );
	}
}
