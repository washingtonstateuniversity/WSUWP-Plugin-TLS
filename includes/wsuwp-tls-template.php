<?php

namespace WSU\TLS\Template;

/**
 * Provide a default template for a single domain's Nginx configuration.
 *
 * @since 1.0.0
 *
 * @param string $domain
 * @return string Nginx configuration for the passed domain.
 */
function single_site_default( $domain ) {
	ob_start();
	?>
server {
	listen 80;
	server_name <?php echo esc_attr( $domain ); ?>;
	return 301 https://<?php echo esc_attr( $domain ); ?>$request_uri;
}

server {
	server_name <?php echo esc_attr( $domain ); ?>;

	include /etc/nginx/wsuwp-common-header.conf;

	ssl_certificate      /etc/letsencrypt/live/<?php echo esc_attr( $domain ); ?>/fullchain.pem;
	ssl_certificate_key /etc/letsencrypt/live/<?php echo esc_attr( $domain ); ?>/privkey.pem;

	include /etc/nginx/wsuwp-ssl-common.conf;
	include /etc/nginx/wsuwp-common.conf;
}
<?php
	$config = ob_get_contents();
	ob_end_clean();

	return $config;
}
