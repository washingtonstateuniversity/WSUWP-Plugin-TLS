<?php

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

		add_action( 'load-site-new.php', array( $this, 'tls_sites_display' ), 1 );
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

		if ( ! isset( $_GET['display'] ) || 'tls' !== $_GET['display'] ) { // @codingStandardsIgnoreLine
			return;
		}

		$title = __( 'Manage Site TLS' );

		wp_enqueue_style( 'wsu-tls-style', plugins_url( '/css/style.css', dirname( __FILE__ ) ) );
		wp_enqueue_script( 'wsu-tls', plugins_url( '/js/wsu-tls-site.min.js', dirname( __FILE__ ) ), array( 'backbone' ), wsuwp_global_version(), true );

		require( ABSPATH . 'wp-admin/admin-header.php' );

		?>
		<div class="wrap wsu-manage-tls">
			<h2 id="add-new-site"><?php esc_html_e( 'Manage Site TLS' ) ?></h2>
			<p class="description">These sites have been configured on the WSUWP Platform, but do not yet have confirmed TLS configurations.</p>
			<input id="tls_ajax_nonce" type="hidden" value="<?php echo esc_attr( wp_create_nonce( 'confirm-tls' ) ); ?>" />
			<table class="form-table" style="width: 600px;">
				<?php

				foreach ( $this->get_tls_disabled_domains() as $domain ) {
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
					<tr id="<?php echo esc_attr( md5( $domain ) ); ?>">
						<td><?php echo esc_html( $domain ); ?></td>
						<td class="tls-table-action">
							<span data-domain="<?php echo esc_attr( $domain ); ?>" class="<?php echo esc_attr( $action_class ); ?>"><?php echo esc_html( $action_text ); ?></span>
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
