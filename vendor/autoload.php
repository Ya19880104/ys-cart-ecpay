<?php
// Runtime shim for the bundled YS Plugin Hub Client.
$hub_client_dir = __DIR__ . '/yangsheep/ys-plugin-hub-client/';

if ( is_readable( $hub_client_dir . 'ys-plugin-hub-client.php' ) ) {
	require_once $hub_client_dir . 'ys-plugin-hub-client.php';
}
