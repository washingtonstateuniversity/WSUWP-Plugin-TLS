<?php
/*
Plugin Name: WSUWP TLS
Version: 0.4.5
Plugin URI: https://web.wsu.edu/
Description: Manage TLS within the WSUWP Platform
Author: washingtonstateuniversity, jeremyfelt
Author URI: https://web.wsu.edu/
*/

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// This plugin uses namespaces and requires PHP 5.3 or greater.
if ( version_compare( PHP_VERSION, '5.3', '<' ) ) {
	add_action( 'admin_notices', create_function( '',
	"echo '<div class=\"error\"><p>" . __( 'WSUWP TLS requires PHP 5.3 to function properly. Please upgrade PHP or deactivate the plugin.', 'wsuwp-tls' ) . "</p></div>';" ) );
	return;
} else {
	include_once __DIR__ . '/includes/class-wsuwp-tls.php';
}
