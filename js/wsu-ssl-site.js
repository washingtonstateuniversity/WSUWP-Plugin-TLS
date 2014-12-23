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

	function view_csr() {
		var domain = $(this).attr('data-domain');

		var ajax_nonce = $('#ssl_ajax_nonce').val();

		var data = {
			'action' : 'view_csr',
			'domain' : domain,
			'ajax_nonce' : ajax_nonce
		};
		$.post(ajaxurl,data,view_csr_response);
	}

	function view_csr_response( response ) {
		response = $.parseJSON( response );
		if ( response.success ) {
			$('#view-csr-container' ).html('<span id="csr-close">X</span><textarea>' + response.success + '</textarea>' ).show();
			$('#csr-close' ).on('click', remove_csr_response );
		}
	}

	function remove_csr_response() {
		$('#view-csr-container' ).html('' ).hide();
	}

	function unconfirm_ssl_domain( domain ) {
		var ajax_nonce = $('#ssl_ajax_nonce' ).val();
		var data = {
			'action' : 'unconfirm_ssl',
			'domain' : domain,
			'ajax_nonce' : ajax_nonce
		};
		$.post(ajaxurl,data,handle_unconfirm_response);
	}

	function handle_unconfirm_response( response ) {
		response = $.parseJSON( response );
		if ( response.success ) {
			$('#add-domain' ).val('');
			window.location.reload();
		}
	}

	function confirm_ssl_domain( domain ) {
		var ajax_nonce = $('#ssl_ajax_nonce' ).val();
		var data = {
			'action' : 'confirm_ssl',
			'domain' : domain,
			'ajax_nonce' : ajax_nonce
		};
		$.post(ajaxurl,data,handle_confirm_response);
	}

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