<?php
/*
Plugin Name: WSUWP TLS
Version: 0.4.1
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
		add_filter( 'parent_file', array( $this, 'tls_admin_menu' ), 11, 1 );
		add_action( 'load-site-new.php', array( $this, 'tls_sites_display' ), 1 );
		add_action( 'wp_ajax_confirm_tls', array( $this, 'confirm_tls_ajax' ), 10 );
		add_action( 'wp_ajax_unconfirm_tls', array( $this, 'unconfirm_tls_ajax' ), 10 );
		add_action( 'wp_ajax_view_csr', array( $this, 'view_csr_ajax' ), 10 );
		add_action( 'wp_ajax_check_tls', array( $this, 'check_tls_ajax' ), 10 );
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
			$this->generate_csr( $domain );
			restore_current_blog();
		}
	}

	/**
	 * Filter the submenu global to add a 'Manage Site TLS' link for the primary network.
	 *
	 * @global string $self
	 * @global array  $submenu
	 * @global string $submenu_file
	 *
	 * @param string $parent_file Parent file of a menu subsection.
	 *
	 * @return string Parent file of a menu subsection.
	 */
	public function tls_admin_menu( $parent_file ) {
		global $self, $submenu, $submenu_file;

		if ( wsuwp_get_current_network()->id == get_main_network_id() ) {
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
	 * @global wpdb $wpdb
	 *
	 * @return array List of domains waiting for TLS confirmation.
	 */
	public function get_tls_disabled_domains() {
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
	 * Provide a page to display domains that have not yet been confirmed as TLS ready. On
	 * this page:
	 *
	 *  - A CSR can be generated for the domain so that a certificate can be requested
	 *    through a 3rd party.
	 *  - A matching private key for that CSR is generated and not exposed through the UI.
	 *  - The certificate obtained through a 3rd party can be uploaded to complete the request.
	 *  - Status of a deployed TLS configuration can be checked.
	 *  - The domain can be removed as an "unconfirmed" TLS domain.
	 *
	 * @global string $title
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

					$config_user = wp_get_current_user();
					// Grab a template with which to write the server's nginx configuration.
					if ( 1 === count( $new_cert_alt_names ) ) {
						$server_block_config = file_get_contents( dirname( __FILE__ ) . '/config/single-site-nginx-block.conf' );
						$server_block_config = str_replace( '<% cert_domain %>', $new_cert_domain, $server_block_config );
						$server_block_config = str_replace( '<% config_generated %>', date('Y-m-d H:i:s' ), $server_block_config );
						$server_block_config = str_replace( '<% config_creator %>', $config_user->user_login, $server_block_config );
					} elseif ( 2 <= count( $new_cert_alt_names ) ) {
						$server_block_config = file_get_contents( dirname( __FILE__ ) . '/config/multi-site-nginx-block.conf' );
						// Remove the primary CN domain from the list of alternate names.
						foreach( $new_cert_alt_names as $k => $v ) {
							if ( $v === $new_cert_domain ) {
								unset( $new_cert_alt_names[ $k ] );
							}
						}
						$new_cert_alt_names = implode( ' ', $new_cert_alt_names );
						$server_block_config = str_replace( '<% alt_domains %>', $new_cert_alt_names, $server_block_config );
						$server_block_config = str_replace( '<% cert_domain %>', $new_cert_domain, $server_block_config );
						$server_block_config = str_replace( '<% config_generated %>', date('Y-m-d H:i:s' ), $server_block_config );
						$server_block_config = str_replace( '<% config_creator %>', $config_user->user_login, $server_block_config );
					} else {
						// Should only throw this error when 0 domains are passed for subjectAltName.
						wp_die( 'The number of subjectAltName values in this certificate is invalid.' );
						exit;
					}

					// This may be overkill, but it's a way for us to make sure all pieces are in
					// order for deployment. Both a CSR and private key must be present.
					if ( ! file_exists( $this->pending_cert_dir . $new_cert_domain . '.csr' ) ) {
						wp_die( 'There is no existing CSR for this domain.' );
					}

					if ( ! file_exists( $this->pending_cert_dir . $new_cert_domain . '.key' ) ) {
						wp_die( 'There is no existing private key for this domain.' );
					}

					// Grab the existing generated configuration and append new servers to it before saving again.
					$server_config_contents = '';
					$matches = array();
					if ( file_exists( $this->staging_dir . $this->nginx_config_file ) ) {
						$server_config_contents = file_get_contents( $this->staging_dir . $this->nginx_config_file ) . "\n";
						$regex = '/# BEGIN generated server block for ' . $new_cert_domain . '(.*)END generated server block for ' . $new_cert_domain . '/s';
						preg_match( $regex, $server_config_contents, $matches );
					}

					// Strip a previous single or multi domain configuration for this domain before appending.
					if ( ! empty( array( $matches ) ) ) {
						$server_config_contents = str_replace( $matches[0], '', $server_config_contents );
					}

					$server_config_contents .= $server_block_config . "\n";
					file_put_contents( $this->staging_dir . $this->nginx_config_file, $server_config_contents );

					// The new certificate should go in a directory to await deployment.
					$new_local_file = $this->to_deploy_dir . $new_cert_domain . '.cer';

					// Append the intermediate certificates to the site certificate.
					$sha2_intermediate = file_get_contents( dirname( __FILE__ ) . '/config/sha2-intermediate.crt' );
					$cert_contents = $cert_contents . "\n" . $sha2_intermediate . "\n";

					file_put_contents( $new_local_file, $cert_contents );
					unlink( $new_cert_file['tmp_name'] );

					// Move the new key file to await deployment.
					rename( $this->pending_cert_dir . $new_cert_domain . '.key', $this->to_deploy_dir . $new_cert_domain . '.key' );

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
		wp_enqueue_script( 'wsu-tls', plugins_url( '/js/wsu-tls-site.min.js', __FILE__ ), array( 'backbone' ), wsuwp_global_version(), true );

		require( ABSPATH . 'wp-admin/admin-header.php' );

		?>
		<div class="wrap wsu-manage-tls">
			<h2 id="add-new-site"><?php _e('Manage Site TLS') ?></h2>
			<p class="description">These sites have been configured on the WSUWP Platform, but do not yet have confirmed TLS configurations.</p>
			<input id="tls_ajax_nonce" type="hidden" value="<?php echo esc_attr( wp_create_nonce( 'confirm-tls' ) ); ?>" />
			<table class="form-table" style="width: 600px;">
				<?php

				foreach( $this->get_tls_disabled_domains() as $domain ) {
					// The default action status is to allow a CSR to be generated.
					$action_text = 'Generate CSR';
					$action_class = 'no_action';

					// If a CSR has been generated, we'll want to view it to request a certificate.
					if ( file_exists( $this->pending_cert_dir . $domain . '.csr' ) ) {
						$action_text = 'View CSR';
						$action_class = 'view_csr';
					}

					// If a certificate has been uploaded, it will await deployment.
					if ( file_exists( $this->to_deploy_dir . $domain . '.cer' ) ) {
						$action_text = 'Awaiting Deployment';
						$action_class = 'no_action';
					}

					// If a certificate has been deployed, it should be TLS ready shortly.
					if ( file_exists( $this->deployed_dir . $domain . '.cer' ) ) {
						$action_text = 'Check Status';
						$action_class = 'check_tls';
					}

					?>
					<tr id="<?php echo md5( $domain ); ?>">
						<td><?php echo esc_html( $domain ); ?></td>
						<td class="tls-table-action">
							<span data-domain="<?php echo esc_attr( $domain ); ?>" class="<?php echo $action_class; ?>"><?php echo $action_text; ?></span>
						</td>
						<td class="tls-table-remove">
							<span data-domain="<?php echo esc_attr( $domain ); ?>" class="confirm_tls">Remove</span>
						</td>
					</tr>
					<?php
				}

				?>
			</table>

			<div class="generate-csr">
				<h3><label for="add-domain">Generate a CSR:</label></h3>
				<input name="add_domain" id="add-domain" class="regular-text" value="" />
				<input type="button" id="submit-add-domain" class="button button-primary" value="Get CSR" />
				<span id="tls-error-message"></span>
				<p class="description">Enter a domain name here to generate a <a href="http://en.wikipedia.org/wiki/Certificate_signing_request">CSR</a> to be used for obtaining a new <a href="http://en.wikipedia.org/wiki/Public_key_certificate">public key certificate</a> through InCommon's <a href="https://cert-manager.com/customer/InCommon/ssl?action=enroll">cert manager</a>.</p>
			</div>

			<div class="upload-cert">
				<h3>Upload Certificate</h3>
				<p class="description">Upload the standard x.509 certificate from InCommon. Do not use a certificate that includes any intermediate or root certificate information.</p>

				<form method="POST" action="" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wsuwp-tls-cert', '_certnonce' ); ?>
					<input type="file" name="cer_filename">
					<input type="submit" value="Upload">
				</form>
			</div>

			<div class="view-csr-container-wrapper tls-container-wrapper">
				<div class="view-csr-container tls-container">
					<div class="tls-container-header">
						<span class="view-csr-close dashicons dashicons-no-alt">X</span>
					</div>
					<div class="view-csr-container-body">

					</div>
				</div>
			</div>
			<div class="tls-status-container-wrapper tls-container-wrapper">
				<div class="tls-status-container tls-container">
					<div class="tls-container-header">
						<span class="tls-status-close dashicons dashicons-no-alt">X</span>
					</div>
					<div class="tls-status-container-body">

					</div>
				</div>
			</div>
		</div>

		<?php
		require( ABSPATH . 'wp-admin/admin-footer.php' );
		die();
	}

	/**
	 * Handle an AJAX request to mark a domain as confirmed for TLS.
	 *
	 * @global wpdb $wpdb
	 */
	public function confirm_tls_ajax() {
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

				// If the files still exist in the pending deploy location, delete them.
				if ( file_exists( $this->to_deploy_dir . $_POST['domain'] . '.key' ) ) {
					@unlink( $this->to_deploy_dir . $_POST['domain'] . '.key' );
					@unlink( $this->to_deploy_dir . $_POST['domain'] . '.cer' );
				}

				// Move the private key and certificate to a completed directory.
				if ( file_exists( $this->deployed_dir . $_POST['domain'] . '.key' ) ) {
					@rename( $this->deployed_dir . $_POST['domain'] . '.key', $this->complete_dir . $_POST['domain'] . '.key' );
					@rename( $this->deployed_dir . $_POST['domain'] . '.cer', $this->complete_dir . $_POST['domain'] . '.cer' );
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
	 *
	 * @global wpdb $wpdb
	 */
	public function unconfirm_tls_ajax() {
		global $wpdb;

		check_ajax_referer( 'confirm-tls', 'ajax_nonce' );

		if ( true === wsuwp_validate_domain( trim( $_POST['domain'] ) ) ) {
			$domain_sites = $wpdb->get_results( $wpdb->prepare( "SELECT domain, path FROM $wpdb->blogs WHERE domain = %s", $_POST['domain'] ) );

			// If we're unconfirming, it's because we want to create a new certificate.
			$csr_result = $this->generate_csr( $_POST['domain'] );

			if ( true !== $csr_result ) {
				$response = json_encode( array( 'error' => $csr_result ) );
			} else {
				$option_name = trim( $_POST['domain'] ) . '_ssl_disabled';
				switch_to_blog( 1 );
				update_option( $option_name, '1' );
				restore_current_blog();

				// Clear site cache on each unconfirmed domain.
				foreach( $domain_sites as $cached_site ) {
					wp_cache_delete( $cached_site->domain . $cached_site->path, 'wsuwp:site' );
				}

				$response = json_encode( array( 'success' => trim( $_POST['domain'] ) ) );
			}
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

		if ( true === $this->validate_domain( $_POST['domain'] ) && file_exists( $this->pending_cert_dir . $_POST['domain'] . '.csr' ) ) {
			$csr_data = file_get_contents( $this->pending_cert_dir . $_POST['domain'] . '.csr' );
			$response = json_encode( array( 'success' => $csr_data ) );
		} else {
			$response = json_encode( array( 'error' => 'No CSR is available for this domain.' ) );
		}

		echo $response;
		die();
	}

	/**
	 * Check a domain passed via ajax for a valid HTTPS connection. This is
	 * very rudimentary, but should serve its purpose.
	 */
	public function check_tls_ajax() {
		check_ajax_referer( 'confirm-tls', 'ajax_nonce' );

		if ( true === $this->validate_domain( $_POST['domain'] ) ) {
			$tls_response = wp_remote_get( 'https://' . $_POST['domain'] );
			if ( is_wp_error( $tls_response ) ) {
				$response = json_encode( array( 'success' => '<p>https://' . $_POST['domain'] . ' did not respond to a connection attempt. You may want to check manually.</p>' ) );
			} else {
				$tls_response = wp_remote_retrieve_headers( $tls_response );
				if ( empty( $tls_response ) ) {
					$response = json_encode( array( 'success' => '<p>https://' . $_POST['domain'] . ' responded, but no headers were returned. Check the HTTPS connection manually.</p>' ) );
				} else {
					$response = json_encode( array( 'success' => '<p>https://' . $_POST['domain'] . ' responded to an HTTPS connection and can be removed from the list.</p>' ) );
				}
			}
		} else {
			$response = json_encode( array( 'success' => '<p>Invalid domain passed, unable to check HTTPS connecton.</p>' ) );
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
		$csr_export_result = openssl_csr_export_to_file( $this->csr, $this->pending_cert_dir . $server_name . '.csr' );

		if ( $csr_export_result ) {
			$key_export_result = openssl_pkey_export_to_file( $this->private_key, $this->pending_cert_dir . $server_name . '.key' );
		} else {
			return 'Unable to export a CSR file';
		}

		if ( $key_export_result ) {
			return true;
		}

		return 'Unable to export a private key file';
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
