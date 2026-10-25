/* Demfilz Shipping Rates for Australia Post — settings page behaviour. */
( function ( $ ) {
	'use strict';

	var cfg  = window.dsraSettings || {};
	var i18n = cfg.i18n || {};

	/* Show only the credential fields for the selected rate source. */
	function toggleMode() {
		var mode = $( '#dsra_api_mode' ).val();
		var pac  = $( '#dsra_api_key' ).closest( 'tr' );
		var acc  = $( '#dsra_account_number, #dsra_account_api_key, #dsra_account_api_password' ).closest( 'tr' );
		if ( 'account' === mode ) {
			pac.hide();
			acc.show();
		} else {
			pac.show();
			acc.hide();
		}
	}

	$( document ).on( 'change', '#dsra_api_mode', toggleMode );
	toggleMode();

	/* Test-credentials button. */
	$( document ).on( 'click', '#dsra-test-btn', function ( e ) {
		e.preventDefault();
		var $btn    = $( this );
		var $result = $( '#dsra-test-result' );
		$btn.prop( 'disabled', true );
		$result.css( 'color', '#666' ).text( i18n.testing || 'Testing…' );

		$.post( cfg.ajaxUrl, {
			action:               cfg.action,
			nonce:                cfg.nonce,
			api_mode:             $( '#dsra_api_mode' ).val(),
			api_key:              $( '#dsra_api_key' ).val(),
			account_number:       $( '#dsra_account_number' ).val(),
			account_api_key:      $( '#dsra_account_api_key' ).val(),
			account_api_password: $( '#dsra_account_api_password' ).val()
		} ).done( function ( res ) {
			if ( res && res.success ) {
				$result.css( 'color', '#46b450' ).text( res.data.message );
			} else {
				$result.css( 'color', '#dc3232' ).text( ( res && res.data ) ? res.data.message : 'Error' );
			}
		} ).fail( function () {
			$result.css( 'color', '#dc3232' ).text( i18n.requestFailed || 'Request failed.' );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );
} )( jQuery );
