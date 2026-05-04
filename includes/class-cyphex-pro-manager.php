<?php
/**
 * Pro Version Manager for Cyphex Image Hunter
 *
 * @package CyphexImageHunter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cyphex_Pro_Manager
 * Handles license validation and Pro feature toggling.
 */
class Cyphex_Pro_Manager {

	const LICENSE_OPTION    = 'cyphex_image_hunter_license_key';
	const STATUS_OPTION     = 'cyphex_image_hunter_pro_status';
	const STORE_URL         = 'https://cyphex.agency'; // Your official store URL
	const ITEM_ID           = 12345; // Your EDD Download ID

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_cyphex_activate_license', array( $this, 'ajax_activate_license' ) );
		add_action( 'wp_ajax_cyphex_deactivate_license', array( $this, 'ajax_deactivate_license' ) );
	}

	public function register_settings() {
		register_setting( 'cyphex_image_hunter_settings', self::LICENSE_OPTION, array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}

	/**
	 * Check if the Pro version is active.
	 */
	public function is_pro() {
		$status = get_option( self::STATUS_OPTION );
		return ( 'valid' === $status || 'active' === $status );
	}

	/**
	 * AJAX: Activate License
	 */
	public function ajax_activate_license() {
		check_ajax_referer( 'cyphex_image_hunter_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$license = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		
		if ( empty( $license ) ) {
			wp_send_json_error( 'Please enter a license key.' );
		}

		// Prepare API Request
		$api_params = array(
			'edd_action' => 'activate_license',
			'license'    => $license,
			'item_id'    => self::ITEM_ID,
			'url'        => home_url()
		);

		$response = wp_remote_post( self::STORE_URL, array(
			'timeout'   => 15,
			'sslverify' => true,
			'body'      => $api_params
		) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Connection to licensing server failed.' );
		}

		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( isset( $license_data->license ) && 'valid' === $license_data->license ) {
			update_option( self::LICENSE_OPTION, $license );
			update_option( self::STATUS_OPTION, 'valid' );
			wp_send_json_success( array( 'message' => 'Cyphex Pro activated successfully!' ) );
		} else {
			$message = ( isset( $license_data->error ) ) ? $this->get_error_message( $license_data->error ) : 'Activation failed.';
			update_option( self::STATUS_OPTION, 'invalid' );
			wp_send_json_error( $message );
		}
	}

	/**
	 * AJAX: Deactivate License
	 */
	public function ajax_deactivate_license() {
		check_ajax_referer( 'cyphex_image_hunter_nonce', 'nonce' );
		
		$license = get_option( self::LICENSE_OPTION );
		
		$api_params = array(
			'edd_action' => 'deactivate_license',
			'license'    => $license,
			'item_id'    => self::ITEM_ID,
			'url'        => home_url()
		);

		wp_remote_post( self::STORE_URL, array( 'body' => $api_params, 'timeout' => 15, 'sslverify' => true ) );

		delete_option( self::LICENSE_OPTION );
		delete_option( self::STATUS_OPTION );

		wp_send_json_success( array( 'message' => 'License deactivated.' ) );
	}

	private function get_error_message( $error ) {
		switch( $error ) {
			case 'expired' : return 'Your license key has expired.';
			case 'revoked' : return 'Your license key has been disabled.';
			case 'missing' : return 'Invalid license.';
			case 'invalid' :
			case 'site_inactive' : return 'Your license is not active for this URL.';
			case 'item_name_mismatch' : return 'This appears to be a license key for a different product.';
			case 'no_activations_left': return 'Your license key has reached its activation limit.';
			default : return 'An unknown error occurred.';
		}
	}
}
