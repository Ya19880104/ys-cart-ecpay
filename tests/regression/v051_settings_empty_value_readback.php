<?php
/**
 * Settings existence/readback through actual WordPress wpdb result readers.
 * Set YS_ECPAY_WPDB_SOURCE to a retained stock wp-includes/class-wpdb.php.
 * The constructor/query transport is controlled: no WP boot or SQL connection.
 */
declare(strict_types=1);

$wpdb_source = getenv( 'YS_ECPAY_WPDB_SOURCE' );
if ( ! is_string( $wpdb_source ) || ! is_file( $wpdb_source ) ) {
	fwrite( STDERR, "Set YS_ECPAY_WPDB_SOURCE to a local stock class-wpdb.php.\n" );
	exit( 2 );
}
defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( $wpdb_source, 2 ) . '/' );
defined( 'YS_ECOMMERCE_TABLE_PREFIX' ) || define( 'YS_ECOMMERCE_TABLE_PREFIX', 'ys_ec_' );
require_once $wpdb_source;
require_once dirname( __DIR__, 2 ) . '/src/Support/Settings.php';

use YangSheep\YSCartEcpay\Support\Settings;

final class V051ResultTransport extends wpdb {
	public array $rows = [];
	public array $read_queries = [];
	public string $fault = '';

	public function __construct() {
		$this->prefix = 'wp_';
		$this->check_current_query = false;
	}

	public function prepare( $query, ...$args ) {
		if ( 1 !== count( $args ) || ! is_string( $args[0] ) ) {
			throw new RuntimeException( 'Unexpected fixture prepare shape.' );
		}
		return str_replace( '%s', "'" . str_replace( "'", "''", $args[0] ) . "'", $query );
	}

	public function query( $query ) {
		$this->read_queries[] = $query;
		$this->last_result = [];
		$this->last_error = '';
		if ( 'throw' === $this->fault ) {
			throw new RuntimeException( 'Controlled read transport failure.' );
		}
		if ( 'error' === $this->fault ) {
			$this->last_error = 'Controlled read error.';
			return false;
		}
		if ( ! preg_match( "/^SELECT setting_value FROM wp_ys_ec_settings WHERE setting_key = '([a-z_]+)'$/D", trim( $query ), $match ) ) {
			throw new RuntimeException( 'Unexpected query; no database transport is available.' );
		}
		if ( array_key_exists( $match[1], $this->rows ) ) {
			$this->last_result = [ (object) [ 'setting_value' => $this->rows[ $match[1] ] ] ];
		}
		return count( $this->last_result );
	}
}

$pass = 0;
$fail = 0;
function v051_check( string $label, bool $ok ): void {
	global $pass, $fail;
	$ok ? ++$pass : ++$fail;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}

$db = new V051ResultTransport();
$GLOBALS['wpdb'] = $db;
$db->rows = [ 'empty' => '', 'zero' => '0', 'ordinary' => 'setting-text', 'nullcell' => null ];
$query = "SELECT setting_value FROM wp_ys_ec_settings WHERE setting_key = 'empty'";
v051_check( 'actual wpdb get_var collapses an existing empty string to null', null === $db->get_var( $query ) );
$row = $db->get_row( $query );
v051_check( 'actual wpdb get_row preserves the existing empty string', is_object( $row ) && '' === $row->setting_value );

foreach ( [ 'empty' => '', 'zero' => '0', 'ordinary' => 'setting-text' ] as $key => $value ) {
	$count = count( $db->read_queries );
	v051_check( "db_probe preserves existing $key row", [ 'ok' => true, 'existed' => true, 'value' => $value ] === Settings::db_probe( $key ) );
	v051_check( "db_probe $key uses one result read", 1 === count( $db->read_queries ) - $count );
}
v051_check( 'db_probe distinguishes missing row from existing empty value', [ 'ok' => true, 'existed' => false, 'value' => '' ] === Settings::db_probe( 'missing' ) );
$count = count( $db->read_queries );
v051_check( 'db_probe reads an existing NULL cell as an absent value, not as a read failure', [ 'ok' => true, 'existed' => false, 'value' => '' ] === Settings::db_probe( 'nullcell' ) );
v051_check( 'db_probe NULL cell uses one result read', 1 === count( $db->read_queries ) - $count );
v051_check( 'actual wpdb get_var collapses the NULL cell too', null === $db->get_var( "SELECT setting_value FROM wp_ys_ec_settings WHERE setting_key = 'nullcell'" ) );
foreach ( [ 'error', 'throw' ] as $fault ) {
	$db->fault = $fault;
	v051_check( "db_probe $fault fails without claiming absence was verified", [ 'ok' => false, 'existed' => false, 'value' => '' ] === Settings::db_probe( 'empty' ) );
}
$db->fault = '';
v051_check( 'recovered result read does not retain stale error', [ 'ok' => true, 'existed' => true, 'value' => '0' ] === Settings::db_probe( 'zero' ) );

echo "Transport: actual wpdb get_var/get_row; controlled result rows; no SQL or WP boot\n";
echo "Result: $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
