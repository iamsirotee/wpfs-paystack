<?php

namespace WPFSPaystack;

use WPFSPaystack\Admin\Builder;
use WPFSPaystack\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin bootstrapper.
 */
class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance;

	/**
	 * Get the shared plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {

		if ( self::$instance === null ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Initialize plugin hooks.
	 *
	 * @return void
	 */
	private function init() {

		add_filter( 'wpforms_currencies', [ $this, 'add_currencies' ] );
		add_filter( 'wpforms_db_payments_value_validator_get_allowed_gateways', [ $this, 'register_gateway' ] );
		add_filter( 'wpforms_has_payment_gateway', [ $this, 'has_payment_gateway' ], 10, 2 );
		add_filter( 'wpforms_admin_payments_views_single_gateway_dashboard_link', [ $this, 'gateway_dashboard_link' ], 10, 2 );
		add_filter( 'wpforms_admin_payments_views_single_gateway_transaction_link', [ $this, 'gateway_transaction_link' ], 10, 2 );
		add_filter( 'wpforms_admin_payments_views_overview_page_gateway_is_configured', [ $this, 'is_gateway_configured' ] );

		( new Settings() )->init();
		( new Builder() )->init();
		( new Frontend() )->init();
		( new Process() )->init();
		( new Webhooks() )->init();
	}

	/**
	 * Add Paystack currencies to WPForms.
	 *
	 * @param array $currencies Existing currencies.
	 *
	 * @return array
	 */
	public function add_currencies( $currencies ) {

		return Helpers::add_currencies( $currencies );
	}

	/**
	 * Register Paystack as an allowed gateway in WPForms payments.
	 *
	 * @param array $gateways Allowed gateways.
	 *
	 * @return array
	 */
	public function register_gateway( $gateways ) {

		$gateways[ Helpers::GATEWAY ] = esc_html__( 'Paystack', 'wpfs-paystack' );

		return $gateways;
	}

	/**
	 * Make Paystack-enabled forms count as payment forms in admin views.
	 *
	 * @param bool  $result    Current result.
	 * @param array $form_data Form data.
	 *
	 * @return bool
	 */
	public function has_payment_gateway( $result, $form_data ) {

		if ( Helpers::is_form_enabled( $form_data ) ) {
			return true;
		}

		return (bool) $result;
	}

	/**
	 * Provide a dashboard link for the single payment screen.
	 *
	 * @param string $link    Existing link.
	 * @param object $payment Payment object.
	 *
	 * @return string
	 */
	public function gateway_dashboard_link( $link, $payment ) {

		if ( empty( $payment->gateway ) || $payment->gateway !== Helpers::GATEWAY ) {
			return $link;
		}

		return Helpers::get_dashboard_url();
	}

	/**
	 * Provide a transaction link for the single payment screen.
	 *
	 * @param string $link    Existing link.
	 * @param object $payment Payment object.
	 *
	 * @return string
	 */
	public function gateway_transaction_link( $link, $payment ) {

		if ( empty( $payment->gateway ) || $payment->gateway !== Helpers::GATEWAY ) {
			return $link;
		}

		return Helpers::get_transaction_url( $payment->transaction_id ?? '' );
	}

	/**
	 * Treat Paystack as configured when credentials are available.
	 *
	 * @param bool $configured Existing state.
	 *
	 * @return bool
	 */
	public function is_gateway_configured( $configured ) {

		return (bool) $configured || Helpers::has_credentials();
	}
}
