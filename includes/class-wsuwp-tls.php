<?php

class WSUWP_TLS {
	/**
	 * Setup the class.
	 */
	public function __construct() {
		add_action( 'wpmu_new_blog', array( $this, 'determine_new_site_tls' ), 10, 3 );
	}

	/**
	 * Determine if a new site should be flagged for TLS configuration.
	 *
	 * If this domain has already been added for another site, we'll assume the TLS status
	 * of that configuration and allow it to play out. If this is the first time for this
	 * domain, then we should flag it as TLS disabled.
	 *
	 * @global wpdb $wpdb
	 *
	 * @param $blog_id
	 * @param $user_id
	 * @param $domain
	 */
	public function determine_new_site_tls( $blog_id, $user_id, $domain ) {
		global $wpdb;

		$domain_exists = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->blogs WHERE domain = %s AND blog_id != %d LIMIT 1", $domain, $blog_id ) );

		if ( ! $domain_exists ) {
			switch_to_blog( 1 );
			update_option( $domain . '_ssl_disabled', 1 );
			restore_current_blog();
		}
	}

	/**
	 * Validate a domain against a set of allowed characters.
	 *
	 * Allowed characters are a-z, A-Z, 0-9, -, and .
	 *
	 * @param string $domain Domain to validate
	 *
	 * @return bool True if valid, false if not.
	 */
	function validate_domain( $domain ) {
		if ( preg_match( '|^([a-zA-Z0-9-.])+$|', $domain ) ) {
			return true;
		}

		return false;
	}
}
$wsuwp_tls = new WSUWP_TLS();
