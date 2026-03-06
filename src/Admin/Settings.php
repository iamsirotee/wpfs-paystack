<?php

namespace WPFSPaystack\Admin;

use WPForms\Admin\Notice;
use WPFSPaystack\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPForms Payments settings integration.
 */
class Settings {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {

		add_filter( 'wpforms_settings_defaults', [ $this, 'register_settings_fields' ], 7 );
		add_action( 'wpforms_settings_init', [ $this, 'maybe_show_currency_notice' ] );
	}

	/**
	 * Register settings fields under WPForms > Settings > Payments.
	 *
	 * @param array $settings Existing settings.
	 *
	 * @return array
	 */
	public function register_settings_fields( $settings ) {

		if ( ! isset( $settings['payments'] ) ) {
			return $settings;
		}

		$settings['payments'] = array_merge(
			$settings['payments'],
			[
				'paystack-heading'         => [
					'id'       => 'paystack-heading',
					'content'  => $this->get_heading_content(),
					'type'     => 'content',
					'no_label' => true,
					'class'    => [ 'section-heading' ],
				],
				'paystack-test-mode'       => [
					'id'     => 'paystack-test-mode',
					'name'   => esc_html__( 'Test Mode', 'wpfs-paystack' ),
					'type'   => 'toggle',
					'status' => true,
					'desc'   => esc_html__( 'Use your Paystack test keys.', 'wpfs-paystack' ),
				],
				'paystack-test-public-key' => [
					'id'   => 'paystack-test-public-key',
					'name' => esc_html__( 'Test Public Key', 'wpfs-paystack' ),
					'type' => 'text',
					'desc' => esc_html__( 'Enter your Paystack test public key.', 'wpfs-paystack' ),
				],
				'paystack-test-secret-key' => [
					'id'   => 'paystack-test-secret-key',
					'name' => esc_html__( 'Test Secret Key', 'wpfs-paystack' ),
					'type' => 'password',
					'desc' => esc_html__( 'Enter your Paystack test secret key.', 'wpfs-paystack' ),
				],
				'paystack-live-public-key' => [
					'id'   => 'paystack-live-public-key',
					'name' => esc_html__( 'Live Public Key', 'wpfs-paystack' ),
					'type' => 'text',
					'desc' => esc_html__( 'Enter your Paystack live public key.', 'wpfs-paystack' ),
				],
				'paystack-live-secret-key' => [
					'id'   => 'paystack-live-secret-key',
					'name' => esc_html__( 'Live Secret Key', 'wpfs-paystack' ),
					'type' => 'password',
					'desc' => esc_html__( 'Enter your Paystack live secret key.', 'wpfs-paystack' ),
				],
				'paystack-webhook-endpoint' => [
					'id'       => 'paystack-webhook-endpoint',
					'name'     => esc_html__( 'Webhook Endpoint', 'wpfs-paystack' ),
					'type'     => 'webhook_endpoint',
					'url'      => Helpers::get_webhook_url(),
					'provider' => Helpers::GATEWAY,
					'desc'     => esc_html__( 'Add this URL to your Paystack webhook settings so WPForms can update payments and refunds.', 'wpfs-paystack' ),
				],
				'paystack-callback-endpoint' => [
					'id'      => 'paystack-callback-endpoint',
					'name'    => esc_html__( 'Callback URL', 'wpfs-paystack' ),
					'type'    => 'content',
					'content' => sprintf(
						'<p><code>%s</code></p><p class="desc">%s</p>',
						esc_html( Helpers::get_callback_url() ),
						esc_html__( 'Use this as the Paystack callback URL for popup payments.', 'wpfs-paystack' )
					),
				],
			]
		);

		return $settings;
	}

	/**
	 * Show an admin notice when the selected currency is not supported by Paystack.
	 *
	 * @return void
	 */
	public function maybe_show_currency_notice() {

		if ( ! Helpers::has_credentials() ) {
			return;
		}

		$currency = wpforms_get_currency();

		if ( Helpers::is_supported_currency( $currency ) ) {
			return;
		}

		if ( class_exists( '\WPForms\Admin\Notice' ) ) {
			Notice::error(
				sprintf(
					/* translators: %s - currency code. */
					esc_html__( 'Paystack does not support the current WPForms currency (%s). Use NGN, GHS, ZAR, or USD.', 'wpfs-paystack' ),
					esc_html( $currency )
				)
			);
		}
	}

	/**
	 * Get section header content.
	 *
	 * @return string
	 */
	private function get_heading_content() {

		return '<h4>' . esc_html__( 'Paystack', 'wpfs-paystack' ) . '</h4><p>' .
			sprintf(
				wp_kses(
					/* translators: %s - Paystack documentation URL. */
					__( 'Use Paystack to collect payments in WPForms. See the <a href="%s" target="_blank" rel="noopener noreferrer">Paystack API docs</a> for setup details.', 'wpfs-paystack' ),
					[
						'a' => [
							'href'   => [],
							'target' => [],
							'rel'    => [],
						],
					]
				),
				esc_url( 'https://paystack.com/docs/api/' )
			) .
		'</p>';
	}
}
