<?php

class WSUWP_TLS {
	/**
	 * Setup the class.
	 */
	public function __construct() {

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
