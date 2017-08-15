<?php

namespace WSU\TLS;

add_filter( 'parent_file', 'WSU\TLS\admin_menu', 11, 1 );

/**
 * Filter the submenu global to add a 'Manage Site TLS' link for the primary network.
 *
 * @since 0.1.0
 *
 * @global string $self
 * @global array  $submenu
 * @global string $submenu_file
 *
 * @param string $parent_file Parent file of a menu subsection.
 *
 * @return string Parent file of a menu subsection.
 */
function admin_menu( $parent_file ) {
	global $self, $submenu, $submenu_file;

	if ( get_network()->id === get_main_network_id() ) {
		$submenu['sites.php'][15] = array(
			'Manage Site TLS',
			'manage_sites',
			'site-new.php?display=tls',
		);
	}

	if ( isset( $_GET['display'] ) && 'tls' === $_GET['display'] ) { // @codingStandardsIgnoreLine
		$self = 'site-new.php?display=tls';
		$parent_file = 'sites.php';
		$submenu_file = 'site-new.php?display=tls';
	}

	return $parent_file;
}
