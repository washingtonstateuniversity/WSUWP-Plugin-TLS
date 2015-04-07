<?php
/*
Plugin Name: WSUWP TLS
Version: 0.0.0
Plugin URI: https://web.wsu.edu/
Description: Manage TLS within the WSUWP Platform
Author: washingtonstateuniversity, jeremyfelt
Author URI: https://web.wsu.edu/
*/

class WSUWP_TLS {

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
	public function __construct() {
		add_action( 'wpmu_new_blog', array( $this, 'determine_new_site_tls' ), 10, 3 );
		add_filter( 'parent_file', array( $this, 'tls_admin_menu' ), 11, 1 );
		add_action( 'load-site-new.php', array( $this, 'tls_sites_display' ), 1 );
		add_action( 'wp_ajax_confirm_tls', array( $this, 'confirm_tls_ajax' ), 10 );
		add_action( 'wp_ajax_unconfirm_tls', array( $this, 'unconfirm_tls_ajax' ), 10 );
		add_action( 'wp_ajax_view_csr', array( $this, 'view_csr_ajax' ), 10 );
	}

	/**
	 * Determine if a new site should be flagged for TLS configuration.
	 *
	 * If this domain has already been added for another site, we'll assume the TLS status
	 * of that configuration and allow it to play out. If this is the first time for this
	 * domain, then we should flag it as TLS disabled.
	 *
	 * @param $blog_id
	 * @param $user_id
	 * @param $domain
	 */
	public function determine_new_site_tls( $blog_id, $user_id, $domain ) {
		/* @type WPDB $wpdb */
		global $wpdb;

		$domain_exists = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->blogs WHERE domain = %s AND blog_id != %d LIMIT 1", $domain, $blog_id ) );

		if ( ! $domain_exists ) {
			switch_to_blog( 1 );
			update_option( $domain . '_ssl_disabled', 1 );
			$this->generate_csr( $domain );
			restore_current_blog();
		}
	}

	/**
	 * Filter the submenu global to add a 'Manage Site TLS' link for the primary network.
	 *
	 * @param string $parent_file Parent file of a menu subsection.
	 *
	 * @return string Parent file of a menu subsection.
	 */
	public function tls_admin_menu( $parent_file ) {
		global $self, $submenu, $submenu_file;

		if ( wsuwp_get_current_network()->id == wsuwp_get_primary_network_id() ) {
			$submenu['sites.php'][15] = array(
				'Manage Site TLS',
				'manage_sites',
				'site-new.php?display=tls',
			);
		}

		if ( isset( $_GET['display'] ) && 'tls' === $_GET['display'] ) {
			$self = 'site-new.php?display=tls';
			$parent_file = 'sites.php';
			$submenu_file = 'site-new.php?display=tls';
		}

		return $parent_file;
	}

	/**
	 * Retrieve a list of domains that have not yet been confirmed as TLS ready.
	 *
	 * @return array List of domains waiting for TLS confirmation.
	 */
	public function get_tls_disabled_domains() {
		/* @type WPDB $wpdb */
		global $wpdb;

		$domains = $wpdb->get_results( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE '%_ssl_disabled'" );
		$domains = wp_list_pluck( $domains, 'option_name' );
		$domains = array_map( array( $this, 'strip_domain' ), $domains );

		return $domains;
	}

	/**
	 * Strip the _ssl_disabled suffix from the option name.
	 *
	 * @param string $option_name Original option name to be stripped.
	 *
	 * @return string Modified domain name.
	 */
	public function strip_domain( $option_name ) {
		$domain = str_replace( '_ssl_disabled', '',  $option_name );

		return $domain;
	}

	/**
	 * Clean a value originally passed as part of a certificate's subjectAltName.
	 *
	 * @param string $alt_name Should be formatted as `DNS:my.server.tld`
	 *
	 * @return string my.server.tld
	 */
	private function clean_alt_name( $alt_name ) {
		$alt_name = trim( $alt_name );
		$alt_name = str_replace( 'DNS:', '', $alt_name );

		return $alt_name;
	}

	/**
	 * Provide a page to display domains that have not yet been confirmed as TLS ready.
	 */
	public function tls_sites_display() {
		global $title;

		if ( ! isset( $_GET['display'] ) || 'tls' !== $_GET['display'] ) {
			return;
		}

		// Manage the attempted upload of an x.509 certificate for a site.
		if ( isset( $_FILES['cer_filename'] ) ) {

			if ( false === wp_verify_nonce( $_POST['_certnonce'], 'wsuwp-tls-cert' ) ) {
				wp_die( 'Invalid attempt to upload certificate.' );
			}

			$new_cert_file = $_FILES['cer_filename'];

			// We take a guess that the file size is normal for a certificate.
			if ( 'application/x-x509-ca-cert' === $new_cert_file['type'] && 2000 < $new_cert_file['size'] && 2300 > $new_cert_file['size'] ) {
				$cert_contents = file_get_contents( $new_cert_file['tmp_name'] );
				$cert_data = openssl_x509_parse( $cert_contents );

				// Check that a valid domain is attached to the CN before creating the full certificate file.
				if ( isset( $cert_data['subject'] ) && isset( $cert_data['subject']['CN'] ) && $this->validate_domain( $cert_data['subject']['CN'] ) ) {
					$new_cert_domain = $cert_data['subject']['CN'];

					// Retrieve the subjectAltNames from the cert to check for a www domain.
					$new_cert_alt_names = explode( ',', $cert_data['extensions']['subjectAltName'] );
					$new_cert_alt_names = array_map( array( $this, 'clean_alt_name' ), $new_cert_alt_names );

					// Grab a template with which to write the server's nginx configuration.
					if ( 1 === count( $new_cert_alt_names ) ) {
						$server_block_config = file_get_contents( dirname( __FILE__ ) . '/config/single-site-nginx-block.conf' );
						$server_block_config = str_replace( '<% cert_domain %>', $new_cert_domain, $server_block_config );
					} elseif ( 2 === count( $new_cert_alt_names ) ) {
						$server_block_config = file_get_contents( dirname( __FILE__ ) . '/config/multi-site-nginx-block.conf' );
						$server_block_config = str_replace( '<% cert_domain %>', $new_cert_domain, $server_block_config );
					} else {
						wp_die( 'The number of subjectAltName values in this certificate is invalid.' );
						exit;
					}

					// Grab the existing generated configuration and append new servers to it before saving again.
					$server_config_contents = '';
					if ( file_exists( '/home/www-data/04_generated_config.conf' ) ) {
						$server_config_contents = file_get_contents( '/home/www-data/04_generated_config.conf' ) . "\n";
					}

					$server_config_contents .= $server_block_config . "\n";
					file_put_contents( '/home/www-data/04_generated_config.conf', $server_config_contents );

					$new_local_file = '/home/www-data/' . $new_cert_domain . '.cer';

					// Append the intermediate certificates to the site certificate.
					$sha2_intermediate = file_get_contents( dirname( __FILE__ ) . '/sha2-intermediate.crt' );
					$cert_contents = $cert_contents . "\n" . $sha2_intermediate . "\n";

					file_put_contents( $new_local_file, $cert_contents );
					unlink( $new_cert_file['tmp_name'] );

					// Set correct file permissions.
					$stat = stat( dirname( $new_local_file ));
					$perms = $stat['mode'] & 0000666;
					@chmod( $new_local_file, $perms );

					wp_safe_redirect( network_admin_url( 'site-new.php?display=tls' ) );
				} else {
					wp_die( 'The certificate appeared correct, but a valid CN was not found. Please verify the cert.' );
				}
			} else {
				wp_die( 'This is not a valid x.509 certificate file.' );
			}
		}

		$title = __('Manage Site TLS');

		wp_enqueue_style( 'wsu-tls-style', plugins_url( '/css/style.css', __FILE__ ) );
		wp_enqueue_script( 'wsu-tls', plugins_url( '/js/wsu-tls-site.min.js', __FILE__ ), array( 'jquery' ), wsuwp_global_version(), true );

		require( ABSPATH . 'wp-admin/admin-header.php' );

		?>
		<div class="wrap">
			<h2 id="add-new-site"><?php _e('Manage Site TLS') ?></h2>
			<p class="description">These sites have been configured on the WSUWP Platform, but do not yet have confirmed TLS configurations.</p>
			<input id="tls_ajax_nonce" type="hidden" value="<?php echo esc_attr( wp_create_nonce( 'confirm-tls' ) ); ?>" />
			<table class="form-table">
				<?php
				foreach( $this->get_tls_disabled_domains() as $domain ) {
					if ( file_exists( '/home/www-data/' . $domain . '.csr' ) ) {
						$action_text = 'View CSR';
						$action_class = 'view_csr';
					} else {
						$action_text = 'Unavailable';
						$action_class = 'confirm_tls';
					}
					?><tr><td><span id="<?php echo md5( $domain ); ?>" data-domain="<?php echo esc_attr( $domain ); ?>" class="<?php echo $action_class; ?>"><?php echo $action_text; ?></span></td><td><?php echo esc_html( $domain ); ?></td></tr><?php
				}
				?>
				<tr><td><label for="add_domain">Generate a CSR:</label></td><td>
						<input name="add_domain" id="add-domain" class="regular-text" value="" />
						<input type="button" id="submit-add-domain" class="button button-primary" value="Get CSR" />
						<p class="description">Enter a domain name here to generate a <a href="http://en.wikipedia.org/wiki/Certificate_signing_request">CSR</a> to be used for obtaining a new <a href="http://en.wikipedia.org/wiki/Public_key_certificate">public key certificate</a> through InCommon's <a href="https://cert-manager.com/customer/InCommon/ssl?action=enroll">cert manager</a>.</p>
					</td></tr>
			</table>
			<h3><?php _e( 'Upload Certificate' ); ?></h3>
			<p class="description">Upload the standard x.509 certificate from InCommon. Do not use a certificate that includes any intermediate or root certificate information.</p>
			<form method="POST" action="" enctype="multipart/form-data">
				<?php wp_nonce_field( 'wsuwp-tls-cert', '_certnonce' ); ?>
				<input type="file" name="cer_filename">
				<input type="submit" value="Upload">
			</form>
			<div class="view-csr-container-wrapper">
				<div id="view-csr-container" style="display: none;"></div>
			</div>
		</div>

		<?php
		require( ABSPATH . 'wp-admin/admin-footer.php' );
		die();
	}

	/**
	 * Handle an AJAX request to mark a domain as confirmed for TLS.
	 */
	public function confirm_tls_ajax() {
		/* @type WPDB $wpdb */
		global $wpdb;

		check_ajax_referer( 'confirm-tls', 'ajax_nonce' );

		if ( true === wsuwp_validate_domain( $_POST['domain'] ) ) {
			$domain_option = $_POST['domain'] . '_ssl_disabled';
			$result = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name = %s", $domain_option ) );
			wp_cache_delete( 'alloptions', 'options' );
			if ( $result ) {
				$domain_sites = $wpdb->get_results( $wpdb->prepare( "SELECT domain, path FROM $wpdb->blogs WHERE domain = %s", $_POST['domain'] ) );

				// Clear site cache on each confirmed domain.
				foreach( $domain_sites as $cached_site ) {
					wp_cache_delete( $cached_site->domain . $cached_site->path, 'wsuwp:site' );
				}

				$response = json_encode( array( 'success' => $_POST['domain'] ) );
			} else {
				$response = json_encode( array( 'error' => 'The domain passed was valid, but confirmation was not successful.' ) );
			}
		} else {
			$response = json_encode( array( 'error' => 'The domain passed for confirmation is not valid.' ) );
		}

		echo $response;
		die();
	}

	/**
	 * Handle an AJAX request to mark a domain as unconfirmed for TLS.
	 */
	public function unconfirm_tls_ajax() {
		/* @type WPDB $wpdb */
		global $wpdb;

		check_ajax_referer( 'confirm-tls', 'ajax_nonce' );

		if ( true === wsuwp_validate_domain( trim( $_POST['domain'] ) ) ) {
			$option_name = trim( $_POST['domain'] ) . '_ssl_disabled';
			switch_to_blog( 1 );
			update_option( $option_name, '1' );
			restore_current_blog();

			$domain_sites = $wpdb->get_results( $wpdb->prepare( "SELECT domain, path FROM $wpdb->blogs WHERE domain = %s", $_POST['domain'] ) );

			// If we're unconfirming, it's because we want to create a new certificate.
			$this->generate_csr( $_POST['domain'] );

			// Clear site cache on each unconfirmed domain.
			foreach( $domain_sites as $cached_site ) {
				wp_cache_delete( $cached_site->domain . $cached_site->path, 'wsuwp:site' );
			}

			$response = json_encode( array( 'success' => trim( $_POST['domain'] ) ) );
		} else {
			$response = json_encode( array( 'error' => 'Invalid domain.' ) );
		}

		echo $response;
		die();
	}

	/**
	 * Handle an AJAX request to retrieve and view the CSR for a domain.
	 */
	public function view_csr_ajax() {
		check_ajax_referer( 'confirm-tls', 'ajax_nonce' );

		if ( true === $this->validate_domain( $_POST['domain'] ) && file_exists( '/home/www-data/' . $_POST['domain'] . '.csr' ) ) {
			$csr_data = file_get_contents( '/home/www-data/' . $_POST['domain'] . '.csr' );
			$response = json_encode( array( 'success' => $csr_data ) );
		} else {
			$response = json_encode( array( 'error' => 'No CSR is available for this domain.' ) );
		}

		echo $response;
		die();
	}

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
$wsuwp_tls = new WSUWP_TLS();