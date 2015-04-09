/* global Backbone, jQuery, _ */
var wsuTLS = wsuTLS || {};

(function (window, Backbone, $, _, wsuTLS) {
	'use strict';

	wsuTLS.appView = Backbone.View.extend({

		el: '.wsu-manage-tls',

		domain: '',

		row: '',

		// Setup the events used in the overall application view.
		events: {
			'click #submit-add-domain': 'handle_submit_click',
			'click .confirm_tls': 'handle_confirm_click',
			'click .check_tls': 'handle_tls_check',
			'click .view_csr': 'view_csr',
			'click .view-csr-close': 'remove_csr_response',
			'click .tls-status-close': 'remove_tls_response'
		},

		handle_submit_click: function() {
			this.domain = $('#add-domain').val();
			this.unconfirm_tls_domain( this.domain );
		},

		handle_confirm_click: function(evt) {
			this.domain = $(evt.target).attr('data-domain');
			this.row = $(evt.target).parents('tr').attr('id');

			if ( true === window.confirm( "Removing " + this.domain + " from the TLS confirmation list." ) ) {
				this.confirm_tls_domain( this.domain );
			}
		},

		handle_tls_check: function(evt) {
			this.domain = $(evt.target).attr('data-domain');

			var ajax_nonce = $('#tls_ajax_nonce').val();

			var data = {
				'action' : 'check_tls',
				'domain' : this.domain,
				'ajax_nonce' : ajax_nonce
			};
			$.post(window.ajaxurl,data,this.check_tls_response);
		},

		check_tls_response: function( response ) {
			response = $.parseJSON(response);
			if ( response.success ) {
				$('.tls-status-container-body').html( response.success );
				$('.tls-status-container-wrapper').show();
			}
		},

		/**
		 * Fire an ajax call to WordPress to find the generated CSR for display.
		 */
		view_csr: function(evt) {
			this.domain = $(evt.target).attr('data-domain');

			var ajax_nonce = $('#tls_ajax_nonce').val();

			var data = {
				'action' : 'view_csr',
				'domain' : this.domain,
				'ajax_nonce' : ajax_nonce
			};
			$.post(window.ajaxurl,data,this.view_csr_response);
		},

		/**
		 * Handle the response to the ajax request for viewing a CSR.
		 *
		 * @param response
		 */
		view_csr_response: function( response ) {
			response = $.parseJSON( response );
			if ( response.success ) {
				$('.view-csr-container-body').html('<textarea>' + response.success + '</textarea>');
				$('.view-csr-container-wrapper').show();
			}
		},

		/**
		 * Hide the container used to view the CSR.
		 */
		remove_csr_response: function() {
			$('.view-csr-container-body').html('');
			$('.view-csr-container-wrapper').hide();
		},

		/**
		 * Hide a container used to view the TLS status of a domain.
		 */
		remove_tls_response: function() {
			$('.tls-status-container-body').html('');
			$('.tls-status-container-wrapper').hide();
		},

		/**
		 * Generate a CSR for a new domain, outside of the new site creation process.
		 *
		 * @param domain
		 */
		unconfirm_tls_domain: function( domain ) {
			var ajax_nonce = $('#tls_ajax_nonce' ).val();
			var data = {
				'action' : 'unconfirm_tls',
				'domain' : domain,
				'ajax_nonce' : ajax_nonce
			};
			$.post(window.ajaxurl,data,this.handle_unconfirm_response);
		},

		/**
		 * Handle the response from the ajax call used to request a new CSR.
		 *
		 * @param response
		 */
		handle_unconfirm_response: function( response ) {
			response = $.parseJSON( response );
			if ( response.success ) {
				$('#add-domain' ).val('');
				window.location.reload();
			}
		},

		/**
		 * Close out an SSL request for a domain via ajax callback.
		 *
		 * @param domain
		 */
		confirm_tls_domain: function ( domain ) {
			var ajax_nonce = $('#tls_ajax_nonce' ).val();
			var data = {
				'action' : 'confirm_tls',
				'domain' : domain,
				'ajax_nonce' : ajax_nonce
			};

			$.post(window.ajaxurl,data,this.handle_confirm_response);
		},

		/**
		 * Handle the response from the ajax call used to close an SSL request.
		 *
		 * @param response
		 */
		handle_confirm_response: function( response ) {
			response = $.parseJSON( response );
			if ( response.success ) {
				$('#' + wsuTLS.app.row ).remove();
			}
		}

	});

	wsuTLS.app = new wsuTLS.appView();
})(window, Backbone, jQuery, _, wsuTLS);