<?php
/*
Plugin Name: WSUWP SSL
Version: 0.0.0
Plugin URI: http://web.wsu.edu
Description: Manage SSL within the WSUWP Platform
Author: washingtonstateuniversity, jeremyfelt
Author URI: http://web.wsu.edu
*/

class WSUWP_SSL {

	/**
	 * Store the configuration used to generate CSRs with openSSL.
	 *
	 * @var array
	 */
	var $config = array(
		'digest_alg' => 'sha256',
		'private_key_bits' => '2048',
		'private_key_type' => OPENSSL_KEYTYPE_RSA,
	);

	/**
	 * Store the domain information added to each certificate request.
	 *
	 * @var array
	 */
	var $dn = array(
		'countryName' => 'US',
		'stateOrProvinceName' => 'Washington',
		'localityName' => 'Pullman',
		'organizationName' => 'Washington State University',
		'organizationalUnitName' => 'University Communications',
		'commonName' => '',
		'emailAddress' => 'web.support@wsu.edu',
	);

	/**
	 * Contains the resource containing the private key before export to disk.
	 *
	 * @var bool|object
	 */
	var $private_key = false;

	/**
	 * Contains the resource containing the certificate signing request before
	 * export to disk.
	 *
	 * @var bool|object
	 */
	var $csr = false;

	/**
	 * Setup the class.
	 */
	public function __construct() {}

	/**
	 * Given a server name, generate a private key and a matching CSR so that a
	 * certificate can be requested.
	 *
	 * @param string $server_name The domain name of the certificate request.
	 *
	 * @return bool True if successful. False if the server name is empty.
	 */
	public function generate_csr( $server_name ) {
		if ( empty( $server_name ) ) {
			return false;
		}

		if ( false === $this->validate_domain( $server_name ) ) {
			return false;
		}

		$server_name = strtolower( $server_name );

		$this->dn['commonName'] = $server_name;

		// Generate the private key.
		$this->private_key = openssl_pkey_new();

		// Generate a certificate signing request.
		$this->csr = openssl_csr_new( $this->dn, $this->private_key, $this->config );

		// Export the key and CSR to disk for later use.
		openssl_csr_export_to_file( $this->csr, '/home/www-data/' . $server_name . '.csr' );
		openssl_pkey_export_to_file( $this->private_key, '/home/www-data/' . $server_name . '.key' );

		return true;
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
$wsuwp_ssl = new WSUWP_SSL();