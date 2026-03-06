<?php

namespace WPFSPaystack;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helpers for the plugin.
 */
final class Helpers {

	/**
	 * Gateway slug.
	 *
	 * @var string
	 */
	const GATEWAY = 'paystack';

	/**
	 * Supported currencies and their minimum charge amounts.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $supported_currencies = [
		'USD' => [
			'name'       => 'US Dollar',
			'symbol'     => '$',
			'minimum'    => 0.20,
			'decimals'   => 2,
			'definition' => [
				'name'                => 'US Dollar',
				'symbol'              => '&#36;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			],
		],
		'ZAR' => [
			'name'       => 'South African Rand',
			'symbol'     => 'R',
			'minimum'    => 1.00,
			'decimals'   => 2,
			'definition' => [
				'name'                => 'South African Rand',
				'symbol'              => 'R',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			],
		],
		'GHS' => [
			'name'       => 'Ghanaian Cedi',
			'symbol'     => 'GH&#8373;',
			'minimum'    => 0.10,
			'decimals'   => 2,
			'definition' => [
				'name'                => 'Ghanaian Cedi',
				'symbol'              => 'GH&#8373;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			],
		],
		'NGN' => [
			'name'       => 'Nigerian Naira',
			'symbol'     => '&#8358;',
			'minimum'    => 50.00,
			'decimals'   => 2,
			'definition' => [
				'name'                => 'Nigerian Naira',
				'symbol'              => '&#8358;',
				'symbol_pos'          => 'left',
				'thousands_separator' => ',',
				'decimal_separator'   => '.',
				'decimals'            => 2,
			],
		],
	];

	/**
	 * Whether delayed notifications are being dispatched.
	 *
	 * @var bool
	 */
	private static $dispatching_notifications = false;

	/**
	 * Get supported currencies.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_supported_currencies() {

		return self::$supported_currencies;
	}

	/**
	 * Add Paystack currencies to WPForms.
	 *
	 * @param array $currencies Existing currencies.
	 *
	 * @return array
	 */
	public static function add_currencies( $currencies ) {

		foreach ( self::get_supported_currencies() as $code => $details ) {
			if ( isset( $currencies[ $code ] ) ) {
				continue;
			}

			$currencies[ $code ] = $details['definition'];
		}

		return $currencies;
	}

	/**
	 * Check if the selected currency is supported by Paystack.
	 *
	 * @param string $currency Currency code.
	 *
	 * @return bool
	 */
	public static function is_supported_currency( $currency ) {

		return isset( self::get_supported_currencies()[ strtoupper( $currency ) ] );
	}

	/**
	 * Get the minimum amount for a Paystack currency.
	 *
	 * @param string $currency Currency code.
	 *
	 * @return float
	 */
	public static function get_minimum_amount( $currency ) {

		$currency = strtoupper( $currency );

		return isset( self::$supported_currencies[ $currency ]['minimum'] ) ? (float) self::$supported_currencies[ $currency ]['minimum'] : 0.0;
	}

	/**
	 * Get the active Paystack mode.
	 *
	 * @param string $mode Optional explicit mode.
	 *
	 * @return string
	 */
	public static function get_mode( $mode = '' ) {

		if ( in_array( $mode, [ 'live', 'test' ], true ) ) {
			return $mode;
		}

		$test_mode = wpforms_setting( 'paystack-test-mode', false );

		if ( is_string( $test_mode ) ) {
			$test_mode = trim( $test_mode );
		}

		return filter_var( $test_mode, FILTER_VALIDATE_BOOLEAN ) ? 'test' : 'live';
	}

	/**
	 * Get a Paystack option.
	 *
	 * @param string $key     Setting key.
	 * @param string $default Default value.
	 *
	 * @return string
	 */
	public static function get_setting( $key, $default = '' ) {

		$value = wpforms_setting( $key, $default );

		return is_string( $value ) ? trim( $value ) : $default;
	}

	/**
	 * Get a Paystack public key.
	 *
	 * @param string $mode Optional explicit mode.
	 *
	 * @return string
	 */
	public static function get_public_key( $mode = '' ) {

		$mode = self::get_mode( $mode );

		return self::get_setting( sprintf( 'paystack-%s-public-key', $mode ) );
	}

	/**
	 * Get a Paystack secret key.
	 *
	 * @param string $mode Optional explicit mode.
	 *
	 * @return string
	 */
	public static function get_secret_key( $mode = '' ) {

		$mode = self::get_mode( $mode );

		return self::get_setting( sprintf( 'paystack-%s-secret-key', $mode ) );
	}

	/**
	 * Check if API credentials exist.
	 *
	 * @param string $mode Optional explicit mode.
	 *
	 * @return bool
	 */
	public static function has_credentials( $mode = '' ) {

		return self::get_public_key( $mode ) !== '' && self::get_secret_key( $mode ) !== '';
	}

	/**
	 * Get the webhook URL.
	 *
	 * @return string
	 */
	public static function get_webhook_url() {

		return admin_url( 'admin-post.php?action=wpfs_paystack_webhook' );
	}

	/**
	 * Get the callback URL.
	 *
	 * @return string
	 */
	public static function get_callback_url() {

		return admin_url( 'admin-post.php?action=wpfs_paystack_callback' );
	}

	/**
	 * Get the plugin builder icon URL.
	 *
	 * @return string
	 */
	public static function get_icon_url() {

		return WPFS_PAYSTACK_URL . 'assets/images/paystack-icon.svg';
	}

	/**
	 * Check whether Paystack is enabled for the given form.
	 *
	 * @param array $form_data Form data.
	 *
	 * @return bool
	 */
	public static function is_form_enabled( $form_data ) {

		$settings = self::get_form_settings( $form_data );

		return ! empty( $settings['enable_one_time'] ) || ! empty( $settings['enable'] );
	}

	/**
	 * Get Paystack settings from a form.
	 *
	 * @param array $form_data Form data.
	 *
	 * @return array
	 */
	public static function get_form_settings( $form_data ) {

		return isset( $form_data['payments'][ self::GATEWAY ] ) && is_array( $form_data['payments'][ self::GATEWAY ] )
			? $form_data['payments'][ self::GATEWAY ]
			: [];
	}

	/**
	 * Get a mapped field value from submitted fields.
	 *
	 * @param array  $fields   Submitted fields.
	 * @param string $field_id Field ID.
	 *
	 * @return string
	 */
	public static function get_field_value( $fields, $field_id ) {

		if ( empty( $field_id ) || ! isset( $fields[ $field_id ] ) || ! is_array( $fields[ $field_id ] ) ) {
			return '';
		}

		$field = $fields[ $field_id ];

		if ( ! empty( $field['value'] ) && is_string( $field['value'] ) ) {
			return trim( wpforms_decode_string( $field['value'] ) );
		}

		if ( ! empty( $field['first'] ) || ! empty( $field['last'] ) ) {
			return trim( implode( ' ', array_filter( [ $field['first'] ?? '', $field['last'] ?? '' ] ) ) );
		}

		if ( ! empty( $field['name'] ) && is_string( $field['name'] ) ) {
			return trim( $field['name'] );
		}

		return '';
	}

	/**
	 * Convert an amount to subunits.
	 *
	 * @param string $amount   Amount.
	 * @param string $currency Currency code.
	 *
	 * @return int
	 */
	public static function amount_to_subunits( $amount, $currency ) {

		$sanitized  = (float) wpforms_sanitize_amount( $amount, $currency );
		$multiplier = wpforms_get_currency_multiplier( $currency );

		return (int) round( $sanitized * $multiplier );
	}

	/**
	 * Convert subunits to a standard amount.
	 *
	 * @param int|string $amount   Amount in subunits.
	 * @param string     $currency Currency code.
	 *
	 * @return float
	 */
	public static function amount_from_subunits( $amount, $currency ) {

		$multiplier = wpforms_get_currency_multiplier( $currency );

		if ( $multiplier < 1 ) {
			return 0.0;
		}

		return (float) $amount / $multiplier;
	}

	/**
	 * Generate a unique Paystack reference.
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return string
	 */
	public static function generate_reference( $form_id ) {

		return sprintf(
			'wpfps_%d_%s',
			(int) $form_id,
			strtolower( wp_generate_password( 16, false, false ) )
		);
	}

	/**
	 * Store a pending submission before sending the customer to Paystack.
	 *
	 * @param string $reference Transaction reference.
	 * @param array  $payload   Pending submission payload.
	 *
	 * @return void
	 */
	public static function store_pending_submission( $reference, array $payload ) {

		if ( empty( $reference ) || empty( $payload ) ) {
			return;
		}

		set_transient( self::get_pending_submission_key( $reference ), $payload, DAY_IN_SECONDS );
	}

	/**
	 * Get a stored pending submission.
	 *
	 * @param string $reference Transaction reference.
	 *
	 * @return array
	 */
	public static function get_pending_submission( $reference ) {

		if ( empty( $reference ) ) {
			return [];
		}

		$payload = get_transient( self::get_pending_submission_key( $reference ) );

		return is_array( $payload ) ? $payload : [];
	}

	/**
	 * Delete a stored pending submission.
	 *
	 * @param string $reference Transaction reference.
	 *
	 * @return void
	 */
	public static function delete_pending_submission( $reference ) {

		if ( empty( $reference ) ) {
			return;
		}

		delete_transient( self::get_pending_submission_key( $reference ) );
	}

	/**
	 * Build the transient key for a pending submission.
	 *
	 * @param string $reference Transaction reference.
	 *
	 * @return string
	 */
	private static function get_pending_submission_key( $reference ) {

		return 'wpfs_paystack_pending_' . md5( sanitize_text_field( $reference ) );
	}

	/**
	 * Add a payment log entry.
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $message    Log message.
	 *
	 * @return void
	 */
	public static function add_payment_log( $payment_id, $message ) {

		if ( empty( $payment_id ) || empty( $message ) ) {
			return;
		}

		wpforms()->obj( 'payment_meta' )->add_log( (int) $payment_id, wp_kses_post( $message ) );
	}

	/**
	 * Get a payment by Paystack reference.
	 *
	 * @param string $reference Paystack reference.
	 *
	 * @return object|null
	 */
	public static function get_payment_by_reference( $reference ) {

		if ( empty( $reference ) ) {
			return null;
		}

		return wpforms()->obj( 'payment' )->get_by( 'transaction_id', sanitize_text_field( $reference ) );
	}

	/**
	 * Mark delayed notifications as being sent.
	 *
	 * @param bool $state Notification dispatch state.
	 *
	 * @return void
	 */
	public static function set_dispatching_notifications( $state ) {

		self::$dispatching_notifications = (bool) $state;
	}

	/**
	 * Check if delayed notifications are being sent.
	 *
	 * @return bool
	 */
	public static function is_dispatching_notifications() {

		return self::$dispatching_notifications;
	}

	/**
	 * Get a readable dashboard link for Paystack.
	 *
	 * @return string
	 */
	public static function get_dashboard_url() {

		return 'https://dashboard.paystack.com/#/transactions';
	}

	/**
	 * Get a transaction link for Paystack.
	 *
	 * @param string $reference Transaction reference.
	 *
	 * @return string
	 */
	public static function get_transaction_url( $reference = '' ) {

		if ( empty( $reference ) ) {
			return self::get_dashboard_url();
		}

		return trailingslashit( self::get_dashboard_url() ) . rawurlencode( $reference );
	}

	/**
	 * Log a Paystack error into the WPForms log.
	 *
	 * @param string          $title   Error title.
	 * @param string|WP_Error $message Error details.
	 * @param int             $form_id Form ID.
	 *
	 * @return void
	 */
	public static function log_error( $title, $message = '', $form_id = 0 ) {

		if ( $message instanceof WP_Error ) {
			$message = $message->get_error_message();
		}

		wpforms_log(
			$title,
			$message,
			[
				'type'    => [ 'payment', 'error' ],
				'form_id' => (int) $form_id,
			]
		);
	}
}
