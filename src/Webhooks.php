<?php

namespace WPFSPaystack;

use WPForms\Db\Payments\UpdateHelpers;
use WPFSPaystack\Api\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle Paystack callback and webhook requests.
 */
class Webhooks {

	/**
	 * Register public request handlers.
	 *
	 * @return void
	 */
	public function init() {

		add_action( 'admin_post_nopriv_wpfs_paystack_callback', [ $this, 'handle_callback' ] );
		add_action( 'admin_post_wpfs_paystack_callback', [ $this, 'handle_callback' ] );
		add_action( 'admin_post_nopriv_wpfs_paystack_webhook', [ $this, 'handle_webhook' ] );
		add_action( 'admin_post_wpfs_paystack_webhook', [ $this, 'handle_webhook' ] );
	}

	/**
	 * Handle the browser callback after checkout.
	 *
	 * @return void
	 */
	public function handle_callback() {

		$reference = isset( $_GET['reference'] ) ? sanitize_text_field( wp_unslash( $_GET['reference'] ) ) : '';
		$payment   = Helpers::get_payment_by_reference( $reference );

		if ( empty( $reference ) ) {
			$this->render_status_page(
				esc_html__( 'Payment not found', 'wpfs-paystack' ),
				esc_html__( 'No Paystack reference was found in the callback request.', 'wpfs-paystack' )
			);
		}

		if ( ! empty( $payment ) ) {
			$client   = new Client();
			$response = $client->verify_transaction( $reference, $payment->mode );

			if ( is_wp_error( $response ) ) {
				Helpers::add_payment_log(
					(int) $payment->id,
					sprintf(
						/* translators: %s - error message. */
						__( 'Paystack callback verification failed: %s', 'wpfs-paystack' ),
						$response->get_error_message()
					)
				);

				Helpers::log_error( __( 'Paystack callback verification failed.', 'wpfs-paystack' ), $response, (int) $payment->form_id );

				$this->render_status_page(
					esc_html__( 'Could not verify payment', 'wpfs-paystack' ),
					esc_html__( 'We could not verify this payment right now. If it was charged, WPForms will update it when Paystack sends the webhook.', 'wpfs-paystack' )
				);
			}

			$transaction = $response['data'] ?? [];
			$status      = strtolower( (string) ( $transaction['status'] ?? '' ) );

			$this->sync_payment_from_transaction( $payment, $transaction, 'callback' );

			if ( $status === 'success' ) {
				$this->complete_confirmation( $payment );
			}

			if ( in_array( $status, [ 'pending', 'processing', 'ongoing', 'queued' ], true ) ) {
				$this->render_status_page(
					esc_html__( 'Payment pending', 'wpfs-paystack' ),
					esc_html__( 'This payment is still pending. WPForms will update it when Paystack sends the final status.', 'wpfs-paystack' )
				);
			}

			$this->render_status_page(
				esc_html__( 'Payment not completed', 'wpfs-paystack' ),
				! empty( $transaction['gateway_response'] )
					? sanitize_text_field( $transaction['gateway_response'] )
					: esc_html__( 'Paystack reported that the payment was not completed.', 'wpfs-paystack' )
			);
		}

		$pending = Helpers::get_pending_submission( $reference );

		if ( empty( $pending ) ) {
			$this->render_status_page(
				esc_html__( 'Payment not found', 'wpfs-paystack' ),
				esc_html__( 'We could not find a pending form submission for this Paystack transaction.', 'wpfs-paystack' )
			);
		}

		$mode     = ! empty( $pending['mode'] ) ? sanitize_text_field( $pending['mode'] ) : Helpers::get_mode();
		$client   = new Client();
		$response = $client->verify_transaction( $reference, $mode );

		if ( is_wp_error( $response ) ) {
			Helpers::log_error(
				__( 'Paystack callback verification failed.', 'wpfs-paystack' ),
				$response,
				! empty( $pending['form_id'] ) ? (int) $pending['form_id'] : 0
			);

			$this->render_status_page(
				esc_html__( 'Could not verify payment', 'wpfs-paystack' ),
				esc_html__( 'We could not verify this payment right now. Please refresh this page in a moment to finish the form submission.', 'wpfs-paystack' )
			);
		}

		$transaction = $response['data'] ?? [];
		$status      = strtolower( (string) ( $transaction['status'] ?? '' ) );

		if ( $status === 'success' ) {
			$this->render_finalize_submission_page( $reference, $pending );
		}

		if ( in_array( $status, [ 'pending', 'processing', 'ongoing', 'queued' ], true ) ) {
			$this->render_status_page(
				esc_html__( 'Payment pending', 'wpfs-paystack' ),
				esc_html__( 'This payment is still pending. Refresh this page after Paystack confirms the transaction.', 'wpfs-paystack' )
			);
		}

		$this->render_status_page(
			esc_html__( 'Payment not completed', 'wpfs-paystack' ),
			! empty( $transaction['gateway_response'] )
				? sanitize_text_field( $transaction['gateway_response'] )
				: esc_html__( 'Paystack reported that the payment was not completed.', 'wpfs-paystack' )
		);
	}

	/**
	 * Handle webhook events from Paystack.
	 *
	 * @return void
	 */
	public function handle_webhook() {

		$payload   = file_get_contents( 'php://input' );
		$signature = isset( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ) ) : '';

		if ( empty( $payload ) || empty( $signature ) ) {
			status_header( 400 );
			exit;
		}

		$mode = $this->detect_signature_mode( $payload, $signature );

		if ( empty( $mode ) ) {
			Helpers::log_error( __( 'Rejected Paystack webhook request.', 'wpfs-paystack' ), __( 'Signature validation failed.', 'wpfs-paystack' ) );
			status_header( 403 );
			exit;
		}

		$event = json_decode( $payload, true );

		if ( ! is_array( $event ) || empty( $event['event'] ) ) {
			status_header( 400 );
			exit;
		}

		switch ( $event['event'] ) {
			case 'charge.success':
				$this->handle_charge_success( $event, $mode );
				break;

			case 'refund.processed':
			case 'refund.pending':
			case 'refund.processing':
			case 'refund.failed':
				$this->handle_refund_event( $event );
				break;
		}

		status_header( 200 );
		echo 'OK';
		exit;
	}

	/**
	 * Detect which configured secret key matches the webhook signature.
	 *
	 * @param string $payload   Raw payload.
	 * @param string $signature Signature header.
	 *
	 * @return string
	 */
	private function detect_signature_mode( $payload, $signature ) {

		foreach ( [ 'live', 'test' ] as $mode ) {
			$secret = Helpers::get_secret_key( $mode );

			if ( empty( $secret ) ) {
				continue;
			}

			$hash = hash_hmac( 'sha512', $payload, $secret );

			if ( hash_equals( $hash, $signature ) ) {
				return $mode;
			}
		}

		return '';
	}

	/**
	 * Handle a successful charge webhook.
	 *
	 * @param array  $event Webhook event.
	 * @param string $mode  Paystack mode.
	 *
	 * @return void
	 */
	private function handle_charge_success( $event, $mode ) {

		$reference = $event['data']['reference'] ?? '';
		$payment   = Helpers::get_payment_by_reference( $reference );

		if ( empty( $payment ) ) {
			return;
		}

		$client   = new Client();
		$response = $client->verify_transaction( $reference, $mode );

		if ( is_wp_error( $response ) ) {
			Helpers::log_error( __( 'Paystack webhook verification failed.', 'wpfs-paystack' ), $response, (int) $payment->form_id );
			Helpers::add_payment_log(
				(int) $payment->id,
				sprintf(
					/* translators: %s - error message. */
					__( 'Paystack webhook received but verification failed: %s', 'wpfs-paystack' ),
					$response->get_error_message()
				)
			);

			return;
		}

		$this->sync_payment_from_transaction( $payment, $response['data'] ?? [], 'webhook' );
	}

	/**
	 * Handle a Paystack refund webhook.
	 *
	 * @param array $event Webhook event.
	 *
	 * @return void
	 */
	private function handle_refund_event( $event ) {

		$refund    = $event['data'] ?? [];
		$reference = $refund['transaction']['reference'] ?? ( $refund['reference'] ?? '' );
		$payment   = Helpers::get_payment_by_reference( $reference );

		if ( empty( $payment ) ) {
			return;
		}

		$refund_status = strtolower( (string) ( $refund['status'] ?? '' ) );
		$currency      = ! empty( $refund['currency'] ) ? strtoupper( $refund['currency'] ) : strtoupper( $payment->currency );
		$refund_id     = ! empty( $refund['id'] ) ? (string) $refund['id'] : '';
		$amount        = ! empty( $refund['amount'] ) ? Helpers::amount_from_subunits( $refund['amount'], $currency ) : 0;

		if ( $refund_status === 'processed' ) {
			$processed_refunds = (array) wpforms()->obj( 'payment_meta' )->get_single( $payment->id, 'paystack_processed_refunds' );

			if ( $refund_id && in_array( $refund_id, $processed_refunds, true ) ) {
				return;
			}

			$total_refunded = (float) wpforms()->obj( 'payment_meta' )->get_single( $payment->id, 'total_refunded_amount' );
			$total_refunded = min( (float) $payment->total_amount, $total_refunded + $amount );

			$log = sprintf(
				/* translators: %s - refunded amount. */
				__( 'Paystack refund processed: %s.', 'wpfs-paystack' ),
				wpforms_format_amount( $amount, true, $currency )
			);

			if ( UpdateHelpers::refund_payment( $payment, $total_refunded, $log ) ) {
				$processed_refunds[] = $refund_id;
				wpforms()->obj( 'payment_meta' )->update_or_add( $payment->id, 'paystack_processed_refunds', array_filter( $processed_refunds ) );
				wpforms()->obj( 'payment_meta' )->update_or_add( $payment->id, 'total_refunded_amount', $total_refunded );
			}

			return;
		}

		Helpers::add_payment_log(
			(int) $payment->id,
			sprintf(
				/* translators: %s - refund status. */
				__( 'Paystack refund update received. Status: %s.', 'wpfs-paystack' ),
				$refund_status ? $refund_status : __( 'unknown', 'wpfs-paystack' )
			)
		);
	}

	/**
	 * Sync a verified Paystack transaction into the WPForms payment record.
	 *
	 * @param object $payment     Payment row.
	 * @param array  $transaction Transaction data.
	 * @param string $source      Sync source.
	 *
	 * @return void
	 */
	private function sync_payment_from_transaction( $payment, $transaction, $source ) {

		if ( empty( $payment ) || empty( $transaction ) ) {
			return;
		}

		$paystack_status = strtolower( (string) ( $transaction['status'] ?? '' ) );
		$wpforms_status  = $this->map_status( $paystack_status );
		$previous_status = (string) wpforms()->obj( 'payment_meta' )->get_single( $payment->id, 'paystack_status' );
		$title           = $this->get_customer_name( $transaction );
		$should_dispatch = $wpforms_status === 'processed' && $payment->status !== 'processed';

		if ( empty( $title ) ) {
			$title = $this->get_customer_email( $transaction );
		}

		$update = [
			'date_updated_gmt' => gmdate( 'Y-m-d H:i:s' ),
		];

		if ( ! in_array( $payment->status, [ 'refunded', 'partrefund' ], true ) && $wpforms_status ) {
			$update['status'] = $wpforms_status;
		}

		if ( ! empty( $transaction['customer']['customer_code'] ) ) {
			$update['customer_id'] = sanitize_text_field( $transaction['customer']['customer_code'] );
		}

		if ( ! empty( $title ) ) {
			$update['title'] = sanitize_text_field( $title );
		}

		wpforms()->obj( 'payment' )->update( $payment->id, $update );

		$this->update_meta_if_present( $payment->id, 'paystack_status', $paystack_status );
		$this->update_meta_if_present( $payment->id, 'paystack_transaction_id', $transaction['id'] ?? '' );
		$this->update_meta_if_present( $payment->id, 'paystack_domain', $transaction['domain'] ?? '' );
		$this->update_meta_if_present( $payment->id, 'paystack_channel', $transaction['channel'] ?? '' );
		$this->update_meta_if_present( $payment->id, 'paystack_gateway_response', $transaction['gateway_response'] ?? '' );
		$this->update_meta_if_present( $payment->id, 'paystack_paid_at', $transaction['paid_at'] ?? '' );
		$this->update_meta_if_present( $payment->id, 'customer_email', $this->get_customer_email( $transaction ) );
		$this->update_meta_if_present( $payment->id, 'customer_name', $this->get_customer_name( $transaction ) );

		$method_type = ! empty( $transaction['channel'] ) ? sanitize_text_field( $transaction['channel'] ) : '';

		if ( ! empty( $transaction['authorization']['last4'] ) ) {
			$method_type = 'card';
			$this->update_meta_if_present( $payment->id, 'credit_card_last4', $transaction['authorization']['last4'] );
			$this->update_meta_if_present( $payment->id, 'credit_card_method', $transaction['authorization']['card_type'] ?? '' );

			if ( ! empty( $transaction['authorization']['exp_month'] ) && ! empty( $transaction['authorization']['exp_year'] ) ) {
				$this->update_meta_if_present(
					$payment->id,
					'credit_card_expires',
					sprintf(
						'%s/%s',
						sanitize_text_field( $transaction['authorization']['exp_month'] ),
						sanitize_text_field( $transaction['authorization']['exp_year'] )
					)
				);
			}
		}

		$this->update_meta_if_present( $payment->id, 'method_type', $method_type );

		if ( $previous_status !== $paystack_status ) {
			Helpers::add_payment_log(
				(int) $payment->id,
				sprintf(
					/* translators: 1: source label, 2: Paystack status, 3: transaction reference. */
					__( 'Paystack payment updated via %1$s. Status: %2$s. Reference: %3$s.', 'wpfs-paystack' ),
					$source,
					$paystack_status,
					$payment->transaction_id
				)
			);
		}

		if ( $should_dispatch ) {
			Process::dispatch_notifications( $payment );
		}
	}

	/**
	 * Render a finalize page that completes the original WPForms submission.
	 *
	 * @param string $reference Transaction reference.
	 * @param array  $pending   Pending submission payload.
	 *
	 * @return void
	 */
	private function render_finalize_submission_page( $reference, array $pending ) {

		$entry        = ! empty( $pending['entry'] ) && is_array( $pending['entry'] ) ? $pending['entry'] : [];
		$resume_token = ! empty( $pending['resume_token'] ) ? sanitize_text_field( $pending['resume_token'] ) : '';
		$return_url   = ! empty( $pending['page_url'] ) ? esc_url( $pending['page_url'] ) : esc_url( home_url( '/' ) );

		if ( empty( $entry ) || empty( $resume_token ) ) {
			$this->render_status_page(
				esc_html__( 'Could not finish submission', 'wpfs-paystack' ),
				esc_html__( 'The saved form data is no longer available. Go back to the form and submit it again.', 'wpfs-paystack' )
			);
		}

		$request_payload = [
			'action'      => 'wpforms_submit',
			'page_url'    => ! empty( $pending['page_url'] ) ? esc_url_raw( $pending['page_url'] ) : '',
			'page_title'  => ! empty( $pending['page_title'] ) ? sanitize_text_field( $pending['page_title'] ) : '',
			'page_id'     => ! empty( $pending['page_id'] ) ? absint( $pending['page_id'] ) : 0,
			'url_referer' => ! empty( $pending['url_referer'] ) ? esc_url_raw( $pending['url_referer'] ) : '',
			'wpforms'     => $entry,
		];

		$request_payload['wpforms']['paystack_reference']    = sanitize_text_field( $reference );
		$request_payload['wpforms']['paystack_resume_token'] = $resume_token;

		$request_body = http_build_query( $request_payload, '', '&' );
		$ajax_url     = admin_url( 'admin-ajax.php' );
		$title        = esc_html__( 'Finishing your submission', 'wpfs-paystack' );
		$message      = esc_html__( 'Your payment was successful. Please wait while we finish the form submission.', 'wpfs-paystack' );
		$error_text   = esc_html__( 'We could not save the form submission. Please go back to the form page.', 'wpfs-paystack' );

		status_header( 200 );
		nocache_headers();

		echo '<!doctype html><html><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>' . esc_html( $title ) . '</title>';
		echo '<style>';
		echo 'body{font-family:Arial,sans-serif;background:#f6f7f7;color:#1d2327;margin:0;padding:32px;}';
		echo '.wrap{max-width:720px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:32px;}';
		echo 'h1{margin-top:0;font-size:28px;}';
		echo '.message{line-height:1.6;font-size:16px;}';
		echo '.status{margin-top:18px;padding:12px 16px;background:#f0f6fc;border-left:4px solid #2271b1;}';
		echo '.error{margin-top:18px;padding:12px 16px;background:#fcf0f1;border-left:4px solid #d63638;}';
		echo '.actions{margin-top:24px;}';
		echo '.button{display:inline-block;background:#2271b1;color:#fff;text-decoration:none;padding:12px 18px;border-radius:4px;}';
		echo '</style></head><body><div class="wrap">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<div class="message">' . esc_html( $message ) . '</div>';
		echo '<div id="wpfs-paystack-status" class="status">' . esc_html__( 'Finishing the form submission...', 'wpfs-paystack' ) . '</div>';
		echo '<div class="actions"><a class="button" href="' . $return_url . '">' . esc_html__( 'Back to form', 'wpfs-paystack' ) . '</a></div>';
		echo '</div>';
		echo '<script>';
		echo '(() => {';
		echo 'const ajaxUrl = ' . wp_json_encode( $ajax_url ) . ';';
		echo 'const requestBody = ' . wp_json_encode( $request_body ) . ';';
		echo 'const defaultError = ' . wp_json_encode( $error_text ) . ';';
		echo 'const statusBox = document.getElementById("wpfs-paystack-status");';
		echo 'const setError = (message) => { statusBox.className = "error"; statusBox.textContent = message || defaultError; };';
		echo 'fetch(ajaxUrl, {';
		echo 'method: "POST",';
		echo 'headers: {"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8", "X-Requested-With": "XMLHttpRequest"},';
		echo 'credentials: "same-origin",';
		echo 'body: requestBody';
		echo '})';
		echo '.then((response) => response.json())';
		echo '.then((response) => {';
		echo 'if (response && response.success && response.data && response.data.redirect_url) { window.location.assign(response.data.redirect_url); return; }';
		echo 'if (response && response.success && response.data && response.data.confirmation) { statusBox.className = "message"; statusBox.innerHTML = response.data.confirmation; return; }';
		echo 'let errorMessage = defaultError;';
		echo 'if (response && response.data && response.data.errors) {';
		echo 'const errors = response.data.errors;';
		echo 'if (typeof errors.footer === "string" && errors.footer) { errorMessage = errors.footer; }';
		echo 'else if (typeof errors.header === "string" && errors.header) { errorMessage = errors.header; }';
		echo 'else if (typeof errors.recaptcha === "string" && errors.recaptcha) { errorMessage = errors.recaptcha; }';
		echo '}';
		echo 'setError(errorMessage);';
		echo '})';
		echo '.catch(() => setError(defaultError));';
		echo '})();';
		echo '</script>';
		echo '</body></html>';
		exit;
	}

	/**
	 * Render the form confirmation after a successful callback.
	 *
	 * @param object $payment Payment object.
	 *
	 * @return void
	 */
	private function complete_confirmation( $payment ) {

		$form_data = wpforms()->obj( 'form' )->get(
			(int) $payment->form_id,
			[
				'content_only' => true,
			]
		);
		$entry     = wpforms()->obj( 'entry' )->get( (int) $payment->entry_id, [ 'cap' => false ] );

		if ( ! is_array( $form_data ) || empty( $entry ) || empty( $entry->fields ) ) {
			$this->render_status_page(
				esc_html__( 'Payment successful', 'wpfs-paystack' ),
				esc_html__( 'Your payment was successful.', 'wpfs-paystack' )
			);
		}

		$fields = wpforms_decode( $entry->fields );

		if ( ! is_array( $fields ) ) {
			$this->render_status_page(
				esc_html__( 'Payment successful', 'wpfs-paystack' ),
				esc_html__( 'Your payment was successful.', 'wpfs-paystack' )
			);
		}

		$processor           = wpforms()->obj( 'process' );
		$processor->fields   = $fields;
		$processor->entry_id = (int) $payment->entry_id;
		$processor->form_data = $form_data;

		$processor->entry_confirmation_redirect( $form_data );

		$message = $processor->get_confirmation_message( $form_data, $fields, (int) $payment->entry_id );

		if ( empty( $message ) ) {
			$message = esc_html__( 'Your payment was successful.', 'wpfs-paystack' );
		}

		$this->render_status_page( esc_html__( 'Payment successful', 'wpfs-paystack' ), $message );
	}

	/**
	 * Map a Paystack status into a WPForms payment status.
	 *
	 * @param string $status Paystack status.
	 *
	 * @return string
	 */
	private function map_status( $status ) {

		switch ( $status ) {
			case 'success':
				return 'processed';

			case 'pending':
			case 'processing':
			case 'ongoing':
			case 'queued':
				return 'pending';

			case 'failed':
			case 'abandoned':
			case 'reversed':
				return 'failed';

			default:
				return '';
		}
	}

	/**
	 * Update payment meta only when the value is not empty.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $key        Meta key.
	 * @param mixed  $value      Meta value.
	 *
	 * @return void
	 */
	private function update_meta_if_present( $payment_id, $key, $value ) {

		if ( $value === '' || $value === null ) {
			return;
		}

		wpforms()->obj( 'payment_meta' )->update_or_add( (int) $payment_id, $key, $value );
	}

	/**
	 * Get customer email from a verified Paystack transaction.
	 *
	 * @param array $transaction Transaction data.
	 *
	 * @return string
	 */
	private function get_customer_email( $transaction ) {

		return ! empty( $transaction['customer']['email'] ) ? sanitize_email( $transaction['customer']['email'] ) : '';
	}

	/**
	 * Get customer name from a verified Paystack transaction.
	 *
	 * @param array $transaction Transaction data.
	 *
	 * @return string
	 */
	private function get_customer_name( $transaction ) {

		if ( ! empty( $transaction['customer']['first_name'] ) || ! empty( $transaction['customer']['last_name'] ) ) {
			return trim(
				implode(
					' ',
					array_filter(
						[
							sanitize_text_field( $transaction['customer']['first_name'] ?? '' ),
							sanitize_text_field( $transaction['customer']['last_name'] ?? '' ),
						]
					)
				)
			);
		}

		if ( ! empty( $transaction['metadata']['customer_name'] ) ) {
			return sanitize_text_field( $transaction['metadata']['customer_name'] );
		}

		return '';
	}

	/**
	 * Render a simple public status page.
	 *
	 * @param string $title   Page title.
	 * @param string $message Page message.
	 *
	 * @return void
	 */
	private function render_status_page( $title, $message ) {

		status_header( 200 );
		nocache_headers();

		$title   = wp_strip_all_tags( $title );
		$message = wp_kses_post( $message );
		$home    = esc_url( home_url( '/' ) );

		echo '<!doctype html><html><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<title>' . esc_html( $title ) . '</title>';
		echo '<style>';
		echo 'body{font-family:Arial,sans-serif;background:#f6f7f7;color:#1d2327;margin:0;padding:32px;}';
		echo '.wrap{max-width:720px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:32px;}';
		echo 'h1{margin-top:0;font-size:28px;}';
		echo '.message{line-height:1.6;font-size:16px;}';
		echo '.actions{margin-top:24px;}';
		echo '.button{display:inline-block;background:#2271b1;color:#fff;text-decoration:none;padding:12px 18px;border-radius:4px;}';
		echo '</style></head><body><div class="wrap">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<div class="message">' . $message . '</div>';
		echo '<div class="actions"><a class="button" href="' . $home . '">' . esc_html__( 'Return to site', 'wpfs-paystack' ) . '</a></div>';
		echo '</div></body></html>';
		exit;
	}
}
