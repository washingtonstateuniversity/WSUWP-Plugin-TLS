<?php

namespace WSU\TLS;

add_filter( 'parent_file', 'WSU\TLS\admin_menu', 11, 1 );
add_action( 'load-site-new.php', 'WSU\TLS\manage_tls_sites_display', 1 );

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

/**
 * Strip the _ssl_disabled suffix from the option name.
 *
 * @since 0.1.0
 *
 * @param string $option_name Original option name to be stripped.
 *
 * @return string Modified domain name.
 */
function strip_domain( $option_name ) {
	$domain = str_replace( '_ssl_disabled', '',  $option_name );

	return $domain;
}

/**
 * Retrieve a list of domains that have not yet been confirmed as TLS ready.
 *
 * @since 0.1.0
 *
 * @global \wpdb $wpdb
 *
 * @return array List of domains waiting for TLS confirmation.
 */
function get_tls_disabled_domains() {
	global $wpdb;

	$domains = $wpdb->get_results( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE '%_ssl_disabled'" );
	$domains = wp_list_pluck( $domains, 'option_name' );
	$domains = array_map( 'WSU\TLS\strip_domain', $domains );

	return $domains;
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
 * @since 0.1.0
 *
 * @global string $title
 */
function manage_tls_sites_display() {
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

			foreach ( get_tls_disabled_domains() as $domain ) {
				// The default action status is to allow a CSR to be generated.
				$action_text = 'Generate CSR';
				$action_class = 'no_action';

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
