<?php

namespace WPFSPaystack\Admin;

use WPFSPaystack\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builder payments panel integration.
 */
class Builder {

	/**
	 * Payment slug.
	 *
	 * @var string
	 */
	private $slug = Helpers::GATEWAY;

	/**
	 * Payment name.
	 *
	 * @var string
	 */
	private $name = 'Paystack';

	/**
	 * Form data.
	 *
	 * @var array
	 */
	private $form_data = [];

	/**
	 * Register builder hooks.
	 *
	 * @return void
	 */
	public function init() {

		$this->form_data = $this->get_form_data();

		add_filter( 'wpforms_payments_available', [ $this, 'register_payment' ] );
		add_action( 'wpforms_payments_panel_content', [ $this, 'builder_output' ], 5 );
		add_action( 'wpforms_payments_panel_sidebar', [ $this, 'builder_sidebar' ], 5 );
	}

	/**
	 * Add Paystack to available builder gateways.
	 *
	 * @param array $payments Available payments.
	 *
	 * @return array
	 */
	public function register_payment( $payments ) {

		$payments[ $this->slug ] = $this->name;

		return $payments;
	}

	/**
	 * Output the sidebar item.
	 *
	 * @return void
	 */
	public function builder_sidebar() {

		echo wpforms_render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'builder/payment/sidebar',
			[
				'configured'  => $this->is_payments_enabled() ? 'configured' : '',
				'slug'        => $this->slug,
				'icon'        => Helpers::get_icon_url(),
				'name'        => $this->name,
				'recommended' => false,
			],
			true
		);
	}

	/**
	 * Output the builder panel.
	 *
	 * @return void
	 */
	public function builder_output() {
		?>
		<div class="wpforms-panel-content-section wpforms-panel-content-section-<?php echo esc_attr( $this->slug ); ?>"
			id="<?php echo esc_attr( $this->slug ); ?>-provider"
			data-provider="<?php echo esc_attr( $this->slug ); ?>"
			data-provider-name="<?php echo esc_attr( $this->name ); ?>">

			<div class="wpforms-panel-content-section-title">
				<?php echo esc_html( $this->name ); ?>
			</div>

			<div class="wpforms-payment-settings wpforms-clear">
				<?php $this->builder_content(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Output the panel body.
	 *
	 * @return void
	 */
	private function builder_content() {

		if ( ! Helpers::has_credentials() ) {
			$this->alert(
				esc_html__( 'Paystack is not ready yet.', 'wpfs-paystack' ),
				sprintf(
					wp_kses(
						/* translators: %s - WPForms payments settings URL. */
						__( 'Add your Paystack API keys in the <a href="%s">WPForms Payments settings</a> first.', 'wpfs-paystack' ),
						[
							'a' => [
								'href' => [],
							],
						]
					),
					esc_url( admin_url( 'admin.php?page=wpforms-settings&view=payments' ) )
				)
			);

			return;
		}

		if ( ! Helpers::is_supported_currency( wpforms_get_currency() ) ) {
			$this->alert(
				esc_html__( 'Paystack does not support this currency.', 'wpfs-paystack' ),
				esc_html__( 'Change the WPForms currency to NGN, GHS, ZAR, or USD before enabling Paystack for this form.', 'wpfs-paystack' )
			);

			return;
		}
		?>
		<div class="wpforms-panel-content-section-payment">
			<h2 class="wpforms-panel-content-section-payment-subtitle">
				<?php esc_html_e( 'One-Time Payments', 'wpfs-paystack' ); ?>
			</h2>
			<?php
			wpforms_panel_field(
				'toggle',
				$this->slug,
				'enable_one_time',
				$this->form_data,
				esc_html__( 'Enable one-time payments', 'wpfs-paystack' ),
				[
					'parent'  => 'payments',
					'default' => '0',
					'tooltip' => esc_html__( 'Open the Paystack popup after the form is submitted.', 'wpfs-paystack' ),
					'class'   => 'wpforms-panel-content-section-payment-toggle wpforms-panel-content-section-payment-toggle-one-time',
				]
			);
			?>
			<div class="wpforms-panel-content-section-payment-one-time wpforms-panel-content-section-payment-toggled-body">
				<?php echo $this->get_one_time_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Get one-time payment content.
	 *
	 * @return string
	 */
	private function get_one_time_content() {

		$content  = '<p class="desc">';
		$content .= esc_html__( 'After the form is submitted, payment is collected in the Paystack popup. No card field is needed in the form.', 'wpfs-paystack' );
		$content .= '</p>';

		$content .= wpforms_panel_field(
			'text',
			$this->slug,
			'payment_description',
			$this->form_data,
			esc_html__( 'Payment Description', 'wpfs-paystack' ),
			[
				'parent'  => 'payments',
				'tooltip' => esc_html__( 'Optional text shown in Paystack. If left empty, the form title is used.', 'wpfs-paystack' ),
			],
			false
		);

		$is_missing_email = $this->is_payments_enabled() && empty( $this->form_data['payments'][ $this->slug ]['customer_email'] );

		$content .= wpforms_panel_field(
			'select',
			$this->slug,
			'customer_email',
			$this->form_data,
			esc_html__( 'Customer Email', 'wpfs-paystack' ),
			[
				'parent'      => 'payments',
				'field_map'   => [ 'email' ],
				'input_class' => $is_missing_email ? 'wpforms-required-field-error' : '',
				'placeholder' => esc_html__( '--- Select Email ---', 'wpfs-paystack' ),
				'tooltip'     => esc_html__( 'Choose the email field to send to Paystack. This field is required.', 'wpfs-paystack' ),
			],
			false
		);

		$content .= wpforms_panel_field(
			'select',
			$this->slug,
			'customer_name',
			$this->form_data,
			esc_html__( 'Customer Name', 'wpfs-paystack' ),
			[
				'parent'      => 'payments',
				'field_map'   => [ 'name' ],
				'placeholder' => esc_html__( '--- Select Name ---', 'wpfs-paystack' ),
				'tooltip'     => esc_html__( 'Optional name field to send to Paystack.', 'wpfs-paystack' ),
			],
			false
		);

		return $content;
	}

	/**
	 * Check whether the gateway is configured in the form.
	 *
	 * @return bool
	 */
	private function is_payments_enabled() {

		return ! empty( $this->form_data['payments'][ $this->slug ]['enable_one_time'] ) || ! empty( $this->form_data['payments'][ $this->slug ]['enable'] );
	}

	/**
	 * Get form data for the current builder view.
	 *
	 * @return array
	 */
	private function get_form_data() {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

		if ( ! $form_id ) {
			return [];
		}

		$form_data = wpforms()->obj( 'form' )->get(
			$form_id,
			[
				'content_only' => true,
			]
		);

		return is_array( $form_data ) ? $form_data : [];
	}

	/**
	 * Render an alert box.
	 *
	 * @param string $title   Alert title.
	 * @param string $message Alert message.
	 *
	 * @return void
	 */
	private function alert( $title, $message ) {
		?>
		<div class="wpforms-alert wpforms-alert-info">
			<h4><?php echo esc_html( $title ); ?></h4>
			<p><?php echo wp_kses_post( $message ); ?></p>
		</div>
		<?php
	}
}
