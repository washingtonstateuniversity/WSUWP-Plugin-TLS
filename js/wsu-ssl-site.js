(function($,ajaxurl, window){
	var domain;
	var row;

	function handle_submit_click() {
		domain = $('#add-domain' ).val();
		unconfirm_ssl_domain( domain );
	}

	function handle_confirm_click() {
		domain = $(this).attr('data-domain');
		row = $(this).attr('id');

		if ( true === confirm( "Removing " + domain + " from the SSL confirmation list." ) ) {
			confirm_ssl_domain( domain );
		}
	}

	/**
	 * Fire an ajax call to WordPress to find the generated CSR for display.
	 */
	function view_csr() {
		domain = $(this).attr('data-domain');

		var ajax_nonce = $('#ssl_ajax_nonce').val();

		var data = {
			'action' : 'view_csr',
			'domain' : domain,
			'ajax_nonce' : ajax_nonce
		};
		$.post(ajaxurl,data,view_csr_response);
	}

	/**
	 * Handle the response to the ajax request for viewing a CSR.
	 *
	 * @param response
	 */
	function view_csr_response( response ) {
		response = $.parseJSON( response );
		if ( response.success ) {
			$('#view-csr-container' ).html('<span id="csr-close">X</span><textarea>' + response.success + '</textarea>' ).show();
			$('#csr-close' ).on('click', remove_csr_response );
		}
	}

	/**
	 * Hide the container used to view the CSR.
	 */
	function remove_csr_response() {
		$('#view-csr-container' ).html('' ).hide();
	}

	/**
	 * Generate a CSR for a new domain, outside of the new site creation process.
	 *
	 * @param domain
	 */
	function unconfirm_ssl_domain( domain ) {
		var ajax_nonce = $('#ssl_ajax_nonce' ).val();
		var data = {
			'action' : 'unconfirm_ssl',
			'domain' : domain,
			'ajax_nonce' : ajax_nonce
		};
		$.post(ajaxurl,data,handle_unconfirm_response);
	}

	/**
	 * Handle the response from the ajax call used to request a new CSR.
	 *
	 * @param response
	 */
	function handle_unconfirm_response( response ) {
		response = $.parseJSON( response );
		if ( response.success ) {
			$('#add-domain' ).val('');
			window.location.reload();
		}
	}

	/**
	 * Close out an SSL request for a domain via ajax callback.
	 *
	 * @param domain
	 */
	function confirm_ssl_domain( domain ) {
		var ajax_nonce = $('#ssl_ajax_nonce' ).val();
		var data = {
			'action' : 'confirm_ssl',
			'domain' : domain,
			'ajax_nonce' : ajax_nonce
		};
		$.post(ajaxurl,data,handle_confirm_response);
	}

	/**
	 * Handle the response from the ajax call used to close an SSL request.
	 *
	 * @param response
	 */
	function handle_confirm_response( response ) {
		response = $.parseJSON( response );
		if ( response.success ) {
			$('#' + row ).parentsUntil('tbody' ).remove();
		}
	}

	$('#submit-add-domain' ).on('click',handle_submit_click );
	$('.confirm_ssl' ).on('click', handle_confirm_click );
	$('.view_csr' ).on('click', view_csr );
}(jQuery, ajaxurl, window));