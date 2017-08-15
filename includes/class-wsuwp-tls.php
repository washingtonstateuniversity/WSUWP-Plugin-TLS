<?php

class WSUWP_TLS {

	/**
	 * Directory where staging files for certificate and config deployment
	 * are located. The generated nginx config file lives here.
	 *
	 * @var string
	 */
	private $staging_dir = '/home/www-data/';

	/**
	 * Directory where CSR and private key files are stored while awaiting
	 * the upload of a certificate file.
	 *
	 * @var string
	 */
	private $pending_cert_dir = '/home/www-data/pending-cert/';

	/**
	 * Directory where private key and certificate files are stored while
	 * awaiting deployment via server script.
	 *
	 * @var string
	 */
	private $to_deploy_dir = '/home/www-data/to-deploy/';

	/**
	 * Directory where private key and certificate files are stored after
	 * deployment while awaiting confirmation in the admin.
	 *
	 * @var string
	 */
	private $deployed_dir = '/home/www-data/deployed/';

	/**
	 * Directory where private key and certificate files are stored after
	 * confirmation of a TLS domain. These can be cleaned up by a server
	 * admin or script at any time.
	 *
	 * @var string
	 */
	private $complete_dir = '/home/www-data/complete/';

	/**
	 * The file in which nginx server blocks will be stored for each domain that
	 * goes through the certificate process. By default, the configuration included
	 * with this plugin will be used.
	 *
	 * @var string
	 */
	private $nginx_config_file = '04_generated_config.conf';

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
	public function __construct() {
		add_action( 'admin_init', array( $this, 'setup_directories' ), 10 );
		add_action( 'wpmu_new_blog', array( $this, 'determine_new_site_tls' ), 10, 3 );
	}

	/**
	 * Provide filters for each of the directories used during the management of nginx config,
	 * CSR, private key, and certificate files as well as for the name of the nginx config
	 * file itself.
	 *
	 * See documentation for how to actually manage these files outside of the plugin.
	 */
	public function setup_directories() {
		$this->staging_dir = apply_filters( 'wsuwp_tls_staging_dir', $this->staging_dir );
		$this->pending_cert_dir = apply_filters( 'wsuwp_tls_pending_cert_dir', $this->pending_cert_dir );
		$this->to_deploy_dir = apply_filters( 'wsuwp_tls_to_deploy_dir', $this->to_deploy_dir );
		$this->deployed_dir = apply_filters( 'wsuwp_tls_deployed_dir', $this->deployed_dir );
		$this->complete_dir = apply_filters( 'wsuwp_tls_complete_dir', $this->complete_dir );

		$this->nginx_config_file = apply_filters( 'wsuwp_nginx_config_file', $this->nginx_config_file );
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
