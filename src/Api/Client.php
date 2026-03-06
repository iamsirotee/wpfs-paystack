<?php

namespace WPFSPaystack\Api;

use WP_Error;
use WPFSPaystack\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal Paystack API client.
 */
class Client {

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	const BASE_URL = 'https://api.paystack.co';

	/**
	 * Send an API request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   API path.
	 * @param array  $data   Request data.
	 * @param string $mode   Paystack mode.
	 *
	 * @return array|WP_Error
	 */
	public function request( $method, $path, $data = [], $mode = '' ) {

		$secret_key = Helpers::get_secret_key( $mode );

		if ( empty( $secret_key ) ) {
			return new WP_Error( 'wpfs_paystack_missing_secret_key', __( 'The Paystack secret key is missing.', 'wpfs-paystack' ) );
		}

		$args = [
			'method'  => strtoupper( $method ),
			'timeout' => 45,
			'headers' => [
				'Authorization' => 'Bearer ' . $secret_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
		];

		if ( ! empty( $data ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( self::BASE_URL . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'wpfs_paystack_invalid_response', __( 'Paystack returned an invalid response.', 'wpfs-paystack' ) );
		}

		if ( $code < 200 || $code >= 300 || empty( $json['status'] ) ) {
			$message = ! empty( $json['message'] ) ? $json['message'] : __( 'Paystack rejected the request.', 'wpfs-paystack' );

			return new WP_Error(
				'wpfs_paystack_api_error',
				$message,
				[
					'status_code' => $code,
					'response'    => $json,
				]
			);
		}

		return $json;
	}

	/**
	 * Initialize a transaction.
	 *
	 * @param array  $data Request data.
	 * @param string $mode Paystack mode.
	 *
	 * @return array|WP_Error
	 */
	public function initialize_transaction( $data, $mode = '' ) {

		return $this->request( 'POST', '/transaction/initialize', $data, $mode );
	}

	/**
	 * Verify a transaction.
	 *
	 * @param string $reference Transaction reference.
	 * @param string $mode      Paystack mode.
	 *
	 * @return array|WP_Error
	 */
	public function verify_transaction( $reference, $mode = '' ) {

		return $this->request( 'GET', '/transaction/verify/' . rawurlencode( $reference ), [], $mode );
	}

	/**
	 * Initiate a refund.
	 *
	 * @param array  $data Refund data.
	 * @param string $mode Paystack mode.
	 *
	 * @return array|WP_Error
	 */
	public function refund_transaction( $data, $mode = '' ) {

		return $this->request( 'POST', '/refund', $data, $mode );
	}
}
