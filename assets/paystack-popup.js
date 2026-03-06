( function( window, document, $ ) {
	'use strict';

	let scriptLoadingPromise = null;

	function getI18n() {
		if ( window.wpfs_paystack && window.wpfs_paystack.i18n ) {
			return window.wpfs_paystack.i18n;
		}

		return {
			launching: 'Opening Paystack...',
			loaded: 'Paystack popup opened.',
			processing: 'Payment received. Finishing the form submission...',
			cancelled: 'Payment window closed.',
			error: 'Could not open Paystack. Please try again.',
			missing_access_code: 'Could not start the Paystack payment. Please try again.',
			missing_wpforms: 'Could not continue the form submission after payment.',
		};
	}

	function loadPaystackScript() {
		if ( window.PaystackPop ) {
			return Promise.resolve( window.PaystackPop );
		}

		if ( scriptLoadingPromise ) {
			return scriptLoadingPromise;
		}

		scriptLoadingPromise = new Promise( function( resolve, reject ) {
			const existingScript = document.querySelector( 'script[data-wpfs-paystack-inline]' );

			if ( existingScript ) {
				existingScript.addEventListener( 'load', function() {
					resolve( window.PaystackPop );
				} );
				existingScript.addEventListener( 'error', reject );

				return;
			}

			const script = document.createElement( 'script' );
			script.src = 'https://js.paystack.co/v2/inline.js';
			script.async = true;
			script.defer = true;
			script.setAttribute( 'data-wpfs-paystack-inline', '1' );
			script.onload = function() {
				resolve( window.PaystackPop );
			};
			script.onerror = reject;

			document.head.appendChild( script );
		} );

		return scriptLoadingPromise;
	}

	function getSubmitButton( $form ) {
		return $form.find( '.wpforms-submit, button[type="submit"], input[type="submit"]' ).first();
	}

	function setButtonDisabled( $form, disabled ) {
		const $button = getSubmitButton( $form );

		if ( ! $button.length ) {
			return;
		}

		if ( disabled ) {
			$button.prop( 'disabled', true ).attr( 'aria-disabled', 'true' );
		} else {
			$button.prop( 'disabled', false ).removeAttr( 'aria-disabled' );
		}
	}

	function restoreButtonState( $form ) {
		const $button       = getSubmitButton( $form );
		const originalText  = $button.data( 'wpfs-original-text' );

		if ( ! $button.length ) {
			return;
		}

		setButtonDisabled( $form, false );

		if ( originalText ) {
			if ( $button.is( 'input' ) ) {
				$button.val( originalText );
			} else {
				$button.text( originalText );
			}
		}
	}

	function setProcessingButtonText( $form ) {
		const $button    = getSubmitButton( $form );
		const altText    = $button.data( 'alt-text' );
		const submitText = $button.data( 'submit-text' );

		if ( ! $button.length ) {
			return;
		}

		if ( submitText && ! $button.data( 'wpfs-original-text' ) ) {
			$button.data( 'wpfs-original-text', submitText );
		}

		setButtonDisabled( $form, true );

		if ( ! altText ) {
			return;
		}

		if ( $button.is( 'input' ) ) {
			$button.val( altText );
		} else {
			$button.text( altText );
		}
	}

	function restoreSubmitUi( $form ) {
		const $container = $form.closest( '.wpforms-container' );

		if ( window.wpforms && typeof window.wpforms.restoreSubmitButton === 'function' ) {
			window.wpforms.restoreSubmitButton( $form, $container );
		} else {
			if ( $container.length ) {
				$container.css( 'opacity', '' );
			}

			$form.find( '.wpforms-submit-spinner' ).hide();
		}

		restoreButtonState( $form );
	}

	function clearNotice( $form ) {
		$form.find( '.wpfs-paystack-popup-notice' ).remove();
	}

	function showNotice( $form, message, type ) {
		const noticeClass = type === 'error' ? 'wpforms-error-container' : 'wpforms-confirmation-container-full';
		const $notice     = $( '<div />', {
			class: noticeClass + ' wpfs-paystack-popup-notice',
			role: type === 'error' ? 'alert' : 'status',
			'aria-live': 'polite',
			text: message,
		} );
		const $submitWrap = $form.find( '.wpforms-submit-container' ).first();

		clearNotice( $form );

		if ( $submitWrap.length ) {
			$notice.insertBefore( $submitWrap );

			return;
		}

		$form.prepend( $notice );
	}

	function ensureHiddenInput( $form, name, value ) {
		let $input = $form.find( 'input[name="wpforms[' + name + ']"]' );

		if ( ! $input.length ) {
			$input = $( '<input />', {
				type: 'hidden',
				name: 'wpforms[' + name + ']',
			} );

			$form.append( $input );
		}

		$input.val( value || '' );

		return $input;
	}

	function clearPopupState( $form ) {
		ensureHiddenInput( $form, 'paystack_reference', '' );
		ensureHiddenInput( $form, 'paystack_resume_token', '' );
	}

	function getPopupInstance( PaystackPop ) {
		if ( typeof PaystackPop === 'function' ) {
			return new PaystackPop();
		}

		if ( PaystackPop && typeof PaystackPop.resumeTransaction === 'function' ) {
			return PaystackPop;
		}

		return null;
	}

	function openPopup( $form, data ) {
		const i18n = getI18n();

		if ( ! data.paystack_access_code ) {
			restoreSubmitUi( $form );
			showNotice( $form, i18n.missing_access_code, 'error' );

			return;
		}

		restoreSubmitUi( $form );
		setButtonDisabled( $form, true );
		showNotice( $form, i18n.launching, 'info' );

		loadPaystackScript()
			.then( function( PaystackPop ) {
				const paystack = getPopupInstance( PaystackPop );

				if ( ! paystack || typeof paystack.resumeTransaction !== 'function' ) {
					throw new Error( 'Paystack popup API is unavailable.' );
				}

				ensureHiddenInput( $form, 'paystack_resume_token', data.paystack_resume_token || '' );

				paystack.resumeTransaction(
					data.paystack_access_code,
					{
						onLoad: function() {
							setButtonDisabled( $form, true );
							showNotice( $form, i18n.loaded, 'info' );
						},
						onSuccess: function( transaction ) {
							const reference = transaction && transaction.reference ? transaction.reference : ( data.paystack_reference || '' );

							if ( ! reference ) {
								restoreSubmitUi( $form );
								showNotice( $form, i18n.error, 'error' );

								return;
							}

							ensureHiddenInput( $form, 'paystack_reference', reference );
							ensureHiddenInput( $form, 'paystack_resume_token', data.paystack_resume_token || '' );
							setProcessingButtonText( $form );
							showNotice( $form, i18n.processing, 'info' );

							if ( window.wpforms && typeof window.wpforms.formSubmitAjax === 'function' ) {
								window.wpforms.formSubmitAjax( $form );

								return;
							}

							restoreSubmitUi( $form );
							showNotice( $form, i18n.missing_wpforms, 'error' );
						},
						onCancel: function() {
							clearPopupState( $form );
							restoreSubmitUi( $form );
							showNotice( $form, i18n.cancelled, 'error' );
						},
						onError: function( error ) {
							const message = error && error.message ? error.message : i18n.error;

							clearPopupState( $form );
							restoreSubmitUi( $form );
							showNotice( $form, message, 'error' );
						},
					}
				);
			} )
			.catch( function( error ) {
				const message = error && error.message ? error.message : i18n.error;

				clearPopupState( $form );
				restoreSubmitUi( $form );
				showNotice( $form, message, 'error' );
			} );
	}

	function handleActionRequired( event, response ) {
		const $form = $( event.currentTarget );
		const data  = response && response.data ? response.data : response;

		if ( ! data || ! data.action_required || data.paystack_action !== 'popup' ) {
			return;
		}

		openPopup( $form, data );
	}

	function updateSubmitHandler( $form ) {
		const validator = $form.data( 'validator' );

		if ( ! validator || ! validator.settings || $form.data( 'wpfs-submit-handler-updated' ) ) {
			return;
		}

		const originalSubmitHandler = validator.settings.submitHandler;

		if ( typeof originalSubmitHandler !== 'function' ) {
			return;
		}

		validator.settings.submitHandler = function( form ) {
			clearNotice( $form );
			clearPopupState( $form );
			originalSubmitHandler.call( this, form );
		};

		$form.data( 'wpfs-submit-handler-updated', true );
	}

	function setupForm() {
		const $form = $( this );
		const $button = getSubmitButton( $form );

		if ( $form.data( 'wpfs-paystack-ready' ) ) {
			updateSubmitHandler( $form );

			return;
		}

		if ( ! $form.closest( '.wpforms-paystack, .wpfs-paystack-enabled' ).length ) {
			return;
		}

		if ( $button.length && ! $button.data( 'wpfs-original-text' ) ) {
			$button.data( 'wpfs-original-text', $button.is( 'input' ) ? $button.val() : $button.text() );
		}

		$form.on( 'wpformsAjaxSubmitActionRequired.wpfsPaystack', handleActionRequired );
		updateSubmitHandler( $form );
		clearPopupState( $form );
		$form.data( 'wpfs-paystack-ready', true );
	}

	function setupForms() {
		$( '.wpforms-paystack form, .wpfs-paystack-enabled form' ).each( setupForm );
	}

	$( document ).on( 'wpformsReady', setupForms );
	$( setupForms );
}( window, document, jQuery ) );
