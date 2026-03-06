<?php

namespace WPFSPaystack;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend Paystack popup integration.
 */
class Frontend {

	/**
	 * Frontend script handle.
	 *
	 * @var string
	 */
	const HANDLE = 'wpfs-paystack-popup';

	/**
	 * Whether the script data has been localized.
	 *
	 * @var bool
	 */
	private $localized = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {

		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
		add_filter( 'wpforms_frontend_form_data', [ $this, 'force_ajax_submit' ] );
		add_filter( 'wpforms_frontend_container_class', [ $this, 'add_container_class' ], 10, 2 );
		add_action( 'wpforms_frontend_output', [ $this, 'maybe_enqueue_form_assets' ], 30, 5 );
	}

	/**
	 * Register the popup script.
	 *
	 * @return void
	 */
	public function register_assets() {

		wp_register_script(
			self::HANDLE,
			WPFS_PAYSTACK_URL . 'assets/paystack-popup.js',
			[ 'jquery' ],
			WPFS_PAYSTACK_VERSION,
			true
		);
	}

	/**
	 * Force AJAX submissions for Paystack-enabled forms.
	 *
	 * @param array $form_data Form data.
	 *
	 * @return array
	 */
	public function force_ajax_submit( $form_data ) {

		if ( ! Helpers::is_form_enabled( $form_data ) ) {
			return $form_data;
		}

		$form_data['settings']['ajax_submit'] = 1;

		return $form_data;
	}

	/**
	 * Add container classes for Paystack-enabled forms.
	 *
	 * @param array $classes   Form container classes.
	 * @param array $form_data Form data.
	 *
	 * @return array
	 */
	public function add_container_class( $classes, $form_data ) {

		$classes = (array) $classes;

		if ( ! Helpers::is_form_enabled( $form_data ) ) {
			return $classes;
		}

		$classes[] = 'wpforms-paystack';
		$classes[] = 'wpfs-paystack-enabled';

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Enqueue assets only for Paystack-enabled forms being rendered.
	 *
	 * @param array $form_data Form data.
	 *
	 * @return void
	 */
	public function maybe_enqueue_form_assets( $form_data ) {

		if ( ! Helpers::is_form_enabled( $form_data ) ) {
			return;
		}

		$this->enqueue_assets();
	}

	/**
	 * Enqueue and localize popup assets.
	 *
	 * @return void
	 */
	private function enqueue_assets() {

		wp_enqueue_script( self::HANDLE );

		if ( $this->localized ) {
			return;
		}

		wp_localize_script(
			self::HANDLE,
			'wpfs_paystack',
			[
				'i18n' => [
					'launching'           => esc_html__( 'Opening Paystack...', 'wpfs-paystack' ),
					'loaded'              => esc_html__( 'Paystack popup opened.', 'wpfs-paystack' ),
					'processing'          => esc_html__( 'Payment received. Finishing the form submission...', 'wpfs-paystack' ),
					'cancelled'           => esc_html__( 'Payment window closed.', 'wpfs-paystack' ),
					'error'               => esc_html__( 'Could not open Paystack. Please try again.', 'wpfs-paystack' ),
					'missing_access_code' => esc_html__( 'Could not start the Paystack payment. Please try again.', 'wpfs-paystack' ),
					'missing_wpforms'     => esc_html__( 'Could not continue the form submission after payment.', 'wpfs-paystack' ),
				],
			]
		);

		$this->localized = true;
	}
}
