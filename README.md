# WSUWP TLS

A WordPress plugin to manage site specific TLS certificates on the WSUWP Platform.

WSUWP TLS maintains a list of domains awaiting TLS configuration on a multisite/multinetwork instance of WordPress through options stored in the primary site's `wp_options` table. A process is available for generating a domain's CSR and private key, uploading a matching certificate, creating an nginx server block, and in general—keeping track of the flow.

To take full advantage of the plugin by default, the following directories should be available and writeable by the user running WordPress:

* `/home/www-data/` - filtered through `wsuwp_tls_staging_dir`
* `/home/www-data/pending-cert/` - filtered through `wsuwp_tls_pending_cert_dir`
* `/home/www-data/to-deploy/` - filtered through `wsuwp_tls_to_deploy_dir`
* `/home/www-data/deployed/` - filtered through `wsuwp_tls_deployed_dir`
* `/home/www-data/complete/` - filtered through `wsuwp_tls_complete_dir`

An nginx configuration file will be generated in the staging directory (see above) with the server block(s) for the domain:

* `04_generated_config.conf` - filtered through `wsuwp_tls_nginx_config_file`

Matching deployment scripts should be created on the server, likely run via cron, to manage the deployment of certificates.

* On CSR and key generation, `.csr` and `.key` files will be in the pending certificate directory.
* Once a certificate for the domain is uploaded, the matching `.key` file and the uploaded `.cer` file will be moved by the plugin to the to deploy directory.
* At this point a cron script should manage the deployment of the `.key`, `.cer`, and nginx config files to their proper locations.
* Once deployed, the cron script should move the `.key` and `.cer` files to the deployed directory.
* Files will be moved from the deployed directory to the complete directory via the "Remove" action in the interface after confirmation of the working TLS config.
