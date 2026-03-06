<?php

namespace WPFSPaystack;

use WPFSPaystack\Api\Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle Paystack payment processing during WPForms submission.
 */
class Process {

	/**
	 * Form ID.
	 *
	 * @var int
	 */
	private $form_id = 0;

	/**
	 * Submitted fields.
	 *
	 * @var array
	 */
	private $fields = [];

	/**
	 * Raw entry payload.
	 *
	 * @var array
	 */
	private $entry = [];

	/**
	 * Form data.
	 *
	 * @var array
	 */
	private $form_data = [];

	/**
	 * Paystack form settings.
	 *
	 * @var array
	 */
	private $settings = [];

	/**
	 * Total amount.
	 *
	 * @var string
	 */
	private $amount = '';

	/**
	 * Customer email.
	 *
	 * @var string
	 */
	private $customer_email = '';

	/**
	 * Customer name.
	 *
	 * @var string
	 */
	private $customer_name = '';

	/**
	 * Generated Paystack reference.
	 *
	 * @var string
	 */
	private $reference = '';

	/**
	 * Paystack access code.
	 *
	 * @var string
	 */
	private $access_code = '';

	/**
	 * Resume token used for the verified submit.
	 *
	 * @var string
	 */
	private $resume_token = '';

	/**
	 * Whether the transaction has been verified.
	 *
	 * @var bool
	 */
	private $payment_verified = false;

	/**
	 * Verified Paystack transaction payload.
	 *
	 * @var array
	 */
	private $verified_transaction = [];

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {

		add_action( 'wpforms_process', [ $this, 'process_entry' ], 10, 3 );
		add_filter( 'wpforms_process_bypass_captcha', [ $this, 'bypass_spam_checks_on_paid_submit' ], 10, 3 );
		add_filter( 'wpforms_process_time_limit_check_bypass', [ $this, 'bypass_time_limit_on_paid_submit' ], 10, 2 );
		add_filter( 'wpforms_forms_submission_prepare_payment_data', [ $this, 'prepare_payment_data' ], 10, 3 );
		add_filter( 'wpforms_forms_submission_prepare_payment_meta', [ $this, 'prepare_payment_meta' ], 10, 3 );
		add_action( 'wpforms_process_payment_saved', [ $this, 'process_payment_saved' ], 10, 3 );
		add_action( 'wpforms_process_entry_saved', [ $this, 'process_entry_data' ], 10, 4 );
	}

	/**
	 * Handle a Paystack-enabled WPForms submission.
	 *
	 * @param array $fields    Submitted fields.
	 * @param array $entry     Raw entry payload.
	 * @param array $form_data Form data.
	 *
	 * @return void
	 */
	public function process_entry( $fields, $entry, $form_data ) {

		if ( ! Helpers::is_form_enabled( $form_data ) ) {
			return;
		}

		$this->fields               = (array) $fields;
		$this->entry                = (array) $entry;
		$this->form_data            = (array) $form_data;
		$this->form_id              = ! empty( $form_data['id'] ) ? (int) $form_data['id'] : 0;
		$this->settings             = Helpers::get_form_settings( $form_data );
		$this->amount               = (string) wpforms_get_total_payment( $this->fields );
		$this->reference            = '';
		$this->access_code          = '';
		$this->resume_token         = '';
		$this->payment_verified     = false;
		$this->verified_transaction = [];

		if ( ! empty( wpforms()->obj( 'process' )->errors[ $this->form_id ] ) ) {
			return;
		}

		$error = $this->get_validation_error();

		if ( $error ) {
			Helpers::log_error( $error, '', $this->form_id );
			$this->display_error( $error );

			return;
		}

		$submitted_reference = $this->get_submitted_reference( $this->entry );

		if ( $submitted_reference ) {
			$this->verify_submitted_payment( $submitted_reference );

			return;
		}

		if ( ! wpforms_is_frontend_ajax() ) {
			$message = esc_html__( 'This form must use AJAX submission for Paystack popup payments.', 'wpfs-paystack' );

			Helpers::log_error( $message, '', $this->form_id );
			$this->display_error( $message );

			return;
		}

		$this->initialize_popup_transaction();
	}

	/**
	 * Add Paystack data to the WPForms payment row.
	 *
	 * @param array $payment_data Payment data.
	 *
	 * @return array
	 */
	public function prepare_payment_data( $payment_data, $fields, $form_data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		if ( ! $this->payment_verified || empty( $this->reference ) ) {
			return $payment_data;
		}

		$mode = $this->get_verified_mode();

		$payment_data['status']         = 'processed';
		$payment_data['gateway']        = Helpers::GATEWAY;
		$payment_data['mode']           = $mode;
		$payment_data['transaction_id'] = $this->reference;
		$payment_data['title']          = $this->customer_name ? $this->customer_name : $this->customer_email;

		if ( ! empty( $this->verified_transaction['customer']['customer_code'] ) ) {
			$payment_data['customer_id'] = sanitize_text_field( $this->verified_transaction['customer']['customer_code'] );
		}

		return $payment_data;
	}

	/**
	 * Add Paystack data to payment meta.
	 *
	 * @param array $payment_meta Payment meta.
	 *
	 * @return array
	 */
	public function prepare_payment_meta( $payment_meta, $fields, $form_data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		if ( ! $this->payment_verified || empty( $this->reference ) ) {
			return $payment_meta;
		}

		$transaction = $this->verified_transaction;
		$channel     = ! empty( $transaction['channel'] ) ? sanitize_text_field( $transaction['channel'] ) : '';

		$payment_meta['customer_email']     = $this->customer_email;
		$payment_meta['customer_name']      = $this->customer_name;
		$payment_meta['paystack_reference'] = $this->reference;
		$payment_meta['paystack_status']    = strtolower( (string) ( $transaction['status'] ?? 'success' ) );
		$payment_meta['paystack_paid_at']   = ! empty( $transaction['paid_at'] ) ? sanitize_text_field( $transaction['paid_at'] ) : '';
		$payment_meta['paystack_domain']    = ! empty( $transaction['domain'] ) ? sanitize_text_field( $transaction['domain'] ) : '';
		$payment_meta['paystack_channel']   = $channel;
		$payment_meta['method_type']        = $channel;
		$payment_meta['log']                = wp_json_encode(
			[
				'value' => sprintf(
					/* translators: %s - Paystack reference. */
					__( 'Payment verified with Paystack before form submission. Reference: %s.', 'wpfs-paystack' ),
					$this->reference
				),
				'date'  => gmdate( 'Y-m-d H:i:s' ),
			]
		);

		if ( ! empty( $transaction['id'] ) ) {
			$payment_meta['paystack_transaction_id'] = sanitize_text_field( (string) $transaction['id'] );
		}

		if ( ! empty( $transaction['gateway_response'] ) ) {
			$payment_meta['paystack_gateway_response'] = sanitize_text_field( $transaction['gateway_response'] );
		}

		if ( ! empty( $transaction['authorization']['last4'] ) ) {
			$payment_meta['credit_card_last4']  = sanitize_text_field( $transaction['authorization']['last4'] );
			$payment_meta['credit_card_method'] = ! empty( $transaction['authorization']['card_type'] )
				? sanitize_text_field( $transaction['authorization']['card_type'] )
				: 'card';
			$payment_meta['method_type']        = 'card';

			if ( ! empty( $transaction['authorization']['exp_month'] ) && ! empty( $transaction['authorization']['exp_year'] ) ) {
				$payment_meta['credit_card_expires'] = sprintf(
					'%s/%s',
					sanitize_text_field( $transaction['authorization']['exp_month'] ),
					sanitize_text_field( $transaction['authorization']['exp_year'] )
				);
			}
		}

		return $payment_meta;
	}

	/**
	 * Add a post-save log entry.
	 *
	 * @param int $payment_id Payment ID.
	 *
	 * @return void
	 */
	public function process_payment_saved( $payment_id, $fields, $form_data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		if ( empty( $payment_id ) || ! $this->payment_verified || empty( $this->reference ) ) {
			return;
		}

		Helpers::delete_pending_submission( $this->reference );
		wpforms()->obj( 'payment_meta' )->update_or_add( (int) $payment_id, 'paystack_notifications_sent', 1 );
		Helpers::add_payment_log(
			$payment_id,
			__( 'Paystack payment verified and form submission completed.', 'wpfs-paystack' )
		);
	}

	/**
	 * Mark the related entry as a payment entry.
	 *
	 * @param array  $fields    Submitted fields.
	 * @param array  $entry     Raw entry payload.
	 * @param array  $form_data Form data.
	 * @param string $entry_id  Entry ID.
	 *
	 * @return void
	 */
	public function process_entry_data( $fields, $entry, $form_data, $entry_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		if ( ! $this->payment_verified || empty( $entry_id ) ) {
			return;
		}

		wpforms()->obj( 'entry' )->update(
			$entry_id,
			[
				'type' => 'payment',
			],
			'',
			'',
			[ 'cap' => false ]
		);
	}

	/**
	 * Dispatch delayed notifications once the payment succeeds.
	 *
	 * @param object $payment Payment object.
	 *
	 * @return void
	 */
	public static function dispatch_notifications( $payment ) {

		if ( empty( $payment ) || empty( $payment->entry_id ) || empty( $payment->form_id ) ) {
			return;
		}

		if ( wpforms()->obj( 'payment_meta' )->get_single( $payment->id, 'paystack_notifications_sent' ) ) {
			return;
		}

		$entry = wpforms()->obj( 'entry' )->get( (int) $payment->entry_id, [ 'cap' => false ] );

		if ( empty( $entry ) || empty( $entry->fields ) ) {
			return;
		}

		$form_data = wpforms()->obj( 'form' )->get(
			(int) $payment->form_id,
			[
				'content_only' => true,
			]
		);

		if ( ! is_array( $form_data ) ) {
			return;
		}

		$fields = wpforms_decode( $entry->fields );

		if ( ! is_array( $fields ) ) {
			return;
		}

		$processor           = wpforms()->obj( 'process' );
		$processor->entry_id = (int) $payment->entry_id;

		Helpers::set_dispatching_notifications( true );

		try {
			$processor->entry_email( $fields, [], $form_data, (int) $payment->entry_id, 'entry' );
		} finally {
			Helpers::set_dispatching_notifications( false );
		}

		wpforms()->obj( 'payment_meta' )->update_or_add( (int) $payment->id, 'paystack_notifications_sent', 1 );
		Helpers::add_payment_log( (int) $payment->id, __( 'WPForms notifications sent after Paystack payment confirmation.', 'wpfs-paystack' ) );
	}

	/**
	 * Bypass spam filtering on the verified follow-up submit.
	 *
	 * @param bool  $bypass    Whether the check is already bypassed.
	 * @param array $entry     Submitted entry payload.
	 * @param array $form_data Form data.
	 *
	 * @return bool
	 */
	public function bypass_spam_checks_on_paid_submit( $bypass, $entry, $form_data ) {

		if ( $bypass ) {
			return true;
		}

		return $this->is_verified_submission_request( (array) $entry, (array) $form_data );
	}

	/**
	 * Bypass the time-limit anti-spam check on the verified follow-up submit.
	 *
	 * @param bool  $bypass    Whether the check is already bypassed.
	 * @param array $form_data Form data.
	 *
	 * @return bool
	 */
	public function bypass_time_limit_on_paid_submit( $bypass, $form_data ) {

		if ( $bypass ) {
			return true;
		}

		return $this->is_verified_submission_request( [], (array) $form_data );
	}

	/**
	 * Initialize a popup Paystack transaction and request frontend action.
	 *
	 * @return void
	 */
	private function initialize_popup_transaction() {

		$this->reference    = Helpers::generate_reference( $this->form_id );
		$this->resume_token = strtolower( wp_generate_password( 24, false, false ) );
		$mode               = Helpers::get_mode();

		$description = ! empty( $this->settings['payment_description'] )
			? sanitize_text_field( $this->settings['payment_description'] )
			: sanitize_text_field( $this->form_data['settings']['form_title'] ?? __( 'WPForms Payment', 'wpfs-paystack' ) );

		$client   = new Client();
		$response = $client->initialize_transaction(
			[
				'email'        => $this->customer_email,
				'amount'       => Helpers::amount_to_subunits( $this->amount, wpforms_get_currency() ),
				'currency'     => wpforms_get_currency(),
				'reference'    => $this->reference,
				'callback_url' => Helpers::get_callback_url(),
				'metadata'     => [
					'form_id'       => $this->form_id,
					'form_name'     => sanitize_text_field( $this->form_data['settings']['form_title'] ?? '' ),
					'customer_name' => $this->customer_name,
					'plugin'        => 'Paystack WPForms Payment Gateway',
					'description'   => $description,
				],
			],
			$mode
		);

		if ( is_wp_error( $response ) ) {
			Helpers::log_error( __( 'Could not start the Paystack payment.', 'wpfs-paystack' ), $response, $this->form_id );
			$this->display_error(
				sprintf(
					/* translators: %s - gateway error message. */
					esc_html__( 'Payment error: %s', 'wpfs-paystack' ),
					$response->get_error_message()
				)
			);

			return;
		}

		$this->access_code = ! empty( $response['data']['access_code'] ) ? sanitize_text_field( $response['data']['access_code'] ) : '';

		if ( empty( $this->access_code ) ) {
			Helpers::log_error(
				__( 'Could not start the Paystack payment.', 'wpfs-paystack' ),
				__( 'Paystack did not return an access code.', 'wpfs-paystack' ),
				$this->form_id
			);
			$this->display_error( esc_html__( 'Could not connect to Paystack. Please try again.', 'wpfs-paystack' ) );

			return;
		}

		Helpers::store_pending_submission( $this->reference, $this->build_pending_submission_payload( $mode ) );

		wp_send_json_success(
			[
				'action_required'       => true,
				'paystack_action'       => 'popup',
				'paystack_access_code'  => $this->access_code,
				'paystack_reference'    => $this->reference,
				'paystack_resume_token' => $this->resume_token,
			]
		);
	}

	/**
	 * Verify a completed Paystack payment before allowing WPForms to save it.
	 *
	 * @param string $reference Transaction reference.
	 *
	 * @return void
	 */
	private function verify_submitted_payment( $reference ) {

		if ( ! $this->is_verified_submission_request( $this->entry, $this->form_data ) ) {
			$message = esc_html__( 'Could not resume this payment session. Please submit the form again.', 'wpfs-paystack' );

			Helpers::log_error( $message, '', $this->form_id );
			$this->display_error( $message );

			return;
		}

		if ( Helpers::get_payment_by_reference( $reference ) ) {
			$message = esc_html__( 'This Paystack payment has already been used for a form submission.', 'wpfs-paystack' );

			Helpers::log_error( $message, '', $this->form_id );
			$this->display_error( $message );

			return;
		}

		$pending  = Helpers::get_pending_submission( $reference );
		$mode     = ! empty( $pending['mode'] ) ? sanitize_text_field( $pending['mode'] ) : Helpers::get_mode();
		$client   = new Client();
		$response = $client->verify_transaction( $reference, $mode );

		if ( is_wp_error( $response ) ) {
			Helpers::log_error( __( 'Could not verify the Paystack payment.', 'wpfs-paystack' ), $response, $this->form_id );
			$this->display_error( esc_html__( 'Could not verify your Paystack payment. Please try again.', 'wpfs-paystack' ) );

			return;
		}

		$transaction = $response['data'] ?? [];
		$status      = strtolower( (string) ( $transaction['status'] ?? '' ) );
		$currency    = ! empty( $transaction['currency'] ) ? strtoupper( sanitize_text_field( $transaction['currency'] ) ) : '';
		$amount      = ! empty( $transaction['amount'] ) ? (int) $transaction['amount'] : 0;
		$expected    = Helpers::amount_to_subunits( $this->amount, wpforms_get_currency() );

		if ( $status !== 'success' ) {
			$this->display_error(
				! empty( $transaction['gateway_response'] )
					? sanitize_text_field( $transaction['gateway_response'] )
					: esc_html__( 'Paystack reported that the payment was not successful.', 'wpfs-paystack' )
			);

			return;
		}

		if ( $currency !== strtoupper( wpforms_get_currency() ) || $amount !== $expected ) {
			$message = esc_html__( 'The Paystack payment amount did not match this form submission.', 'wpfs-paystack' );

			Helpers::log_error( $message, wp_json_encode( $transaction ), $this->form_id );
			$this->display_error( $message );

			return;
		}

		$this->reference            = $reference;
		$this->payment_verified     = true;
		$this->verified_transaction = $transaction;
		$this->customer_email       = ! empty( $transaction['customer']['email'] )
			? sanitize_email( $transaction['customer']['email'] )
			: $this->customer_email;
		$this->customer_name        = ! empty( $transaction['metadata']['customer_name'] )
			? sanitize_text_field( $transaction['metadata']['customer_name'] )
			: $this->customer_name;
	}

	/**
	 * Build the payload stored before requesting popup authorization.
	 *
	 * @return array
	 */
	private function build_pending_submission_payload( $mode ) {

		return [
			'entry'        => $this->entry,
			'form_id'      => $this->form_id,
			'mode'         => Helpers::get_mode( $mode ),
			'resume_token' => $this->resume_token,
			'page_url'     => isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '',
			'page_title'   => isset( $_POST['page_title'] ) ? sanitize_text_field( wp_unslash( $_POST['page_title'] ) ) : '',
			'page_id'      => isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0,
			'url_referer'  => isset( $_POST['url_referer'] ) ? esc_url_raw( wp_unslash( $_POST['url_referer'] ) ) : '',
		];
	}

	/**
	 * Determine whether the current request is a trusted follow-up submit.
	 *
	 * @param array $entry     Submitted entry payload.
	 * @param array $form_data Form data.
	 *
	 * @return bool
	 */
	private function is_verified_submission_request( array $entry = [], array $form_data = [] ) {

		$reference = $this->get_submitted_reference( $entry );
		$token     = $this->get_resume_token( $entry );

		if ( empty( $reference ) || empty( $token ) || ! Helpers::is_form_enabled( $form_data ) ) {
			return false;
		}

		$pending = Helpers::get_pending_submission( $reference );

		if ( empty( $pending ) || empty( $pending['resume_token'] ) ) {
			return false;
		}

		if ( ! hash_equals( (string) $pending['resume_token'], $token ) ) {
			return false;
		}

		if ( ! empty( $pending['form_id'] ) && ! empty( $form_data['id'] ) && (int) $pending['form_id'] !== (int) $form_data['id'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the submitted Paystack reference.
	 *
	 * @param array $entry Entry payload.
	 *
	 * @return string
	 */
	private function get_submitted_reference( array $entry ) {

		if ( isset( $entry['paystack_reference'] ) ) {
			return sanitize_text_field( $entry['paystack_reference'] );
		}

		if ( isset( $entry['paystack_reference_confirmed'] ) ) {
			return sanitize_text_field( $entry['paystack_reference_confirmed'] );
		}

		$posted = isset( $_POST['wpforms'] ) && is_array( $_POST['wpforms'] ) ? wp_unslash( $_POST['wpforms'] ) : [];

		if ( ! empty( $posted['paystack_reference'] ) ) {
			return sanitize_text_field( $posted['paystack_reference'] );
		}

		return ! empty( $posted['paystack_reference_confirmed'] ) ? sanitize_text_field( $posted['paystack_reference_confirmed'] ) : '';
	}

	/**
	 * Get the submitted resume token.
	 *
	 * @param array $entry Entry payload.
	 *
	 * @return string
	 */
	private function get_resume_token( array $entry ) {

		if ( isset( $entry['paystack_resume_token'] ) ) {
			return sanitize_text_field( $entry['paystack_resume_token'] );
		}

		$posted = isset( $_POST['wpforms'] ) && is_array( $_POST['wpforms'] ) ? wp_unslash( $_POST['wpforms'] ) : [];

		return ! empty( $posted['paystack_resume_token'] ) ? sanitize_text_field( $posted['paystack_resume_token'] ) : '';
	}

	/**
	 * Get the verified Paystack mode.
	 *
	 * @return string
	 */
	private function get_verified_mode() {

		$pending = Helpers::get_pending_submission( $this->reference );

		return ! empty( $pending['mode'] ) ? sanitize_text_field( $pending['mode'] ) : Helpers::get_mode();
	}

	/**
	 * Get a validation error, if any.
	 *
	 * @return string
	 */
	private function get_validation_error() {

		if ( ! Helpers::has_credentials() ) {
			return esc_html__( 'Paystack could not start because the API keys are missing.', 'wpfs-paystack' );
		}

		if ( ! Helpers::is_supported_currency( wpforms_get_currency() ) ) {
			return esc_html__( 'Paystack does not support the selected currency.', 'wpfs-paystack' );
		}

		if ( ! wpforms_has_payment( 'form', $this->form_data ) || ! wpforms_has_payment( 'entry', $this->fields ) ) {
			return esc_html__( 'Payment fields are missing for this form.', 'wpfs-paystack' );
		}

		if ( empty( $this->amount ) || wpforms_sanitize_amount( 0, wpforms_get_currency() ) === $this->amount ) {
			return esc_html__( 'The payment amount is empty or invalid.', 'wpfs-paystack' );
		}

		if ( (float) $this->amount < Helpers::get_minimum_amount( wpforms_get_currency() ) ) {
			return sprintf(
				/* translators: %s - minimum amount. */
				esc_html__( 'The payment amount is below the minimum charge of %s.', 'wpfs-paystack' ),
				wpforms_format_amount( Helpers::get_minimum_amount( wpforms_get_currency() ), true, wpforms_get_currency() )
			);
		}

		if ( empty( $this->settings['customer_email'] ) ) {
			return esc_html__( 'Select a customer email field in the Paystack payment settings.', 'wpfs-paystack' );
		}

		$this->customer_email = sanitize_email( Helpers::get_field_value( $this->fields, $this->settings['customer_email'] ) );

		if ( empty( $this->customer_email ) || ! is_email( $this->customer_email ) ) {
			return esc_html__( 'A valid customer email is required.', 'wpfs-paystack' );
		}

		if ( ! empty( $this->settings['customer_name'] ) ) {
			$this->customer_name = sanitize_text_field( Helpers::get_field_value( $this->fields, $this->settings['customer_name'] ) );
		}

		if ( empty( $this->customer_name ) ) {
			$this->customer_name = $this->customer_email;
		}

		return '';
	}

	/**
	 * Display an error in WPForms.
	 *
	 * @param string $message Error message.
	 *
	 * @return void
	 */
	private function display_error( $message ) {

		if ( empty( $message ) ) {
			return;
		}

		wpforms()->obj( 'process' )->errors[ $this->form_id ]['footer'] = $message;
	}
}
