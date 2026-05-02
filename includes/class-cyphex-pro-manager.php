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

	/**
	 * Option name for the license key.
	 */
	const LICENSE_OPTION = 'cyphex_image_hunter_license_key';

	/**
	 * Option name for the license status.
	 */
	const STATUS_OPTION = 'cyphex_image_hunter_pro_status';

	/**
	 * Initialize the manager.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_cyphex_activate_license', array( $this, 'ajax_activate_license' ) );
	}

	/**
	 * Register Pro settings.
	 */
	public function register_settings() {
		register_setting( 'cyphex_image_hunter_settings', self::LICENSE_OPTION, array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}

	/**
	 * Check if the Pro version is active.
	 *
	 * @return bool
	 */
	public function is_pro() {
		// For now, any non-empty key activates Pro (Placeholder for testing)
		$license = get_option( self::LICENSE_OPTION );
		return ! empty( $license );
	}

	/**
	 * AJAX handler for license activation.
	 */
	public function ajax_activate_license() {
		check_ajax_referer( 'cyphex_image_hunter_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		if ( ! isset( $_POST['license_key'] ) ) {
			wp_send_json_error( 'Missing license key' );
		}

		$key = sanitize_text_field( wp_unslash( $_POST['license_key'] ) );

		if ( empty( $key ) ) {
			update_option( self::LICENSE_OPTION, '' );
			wp_send_json_success( array( 'message' => 'License deactivated.' ) );
		}

		// Placeholder for actual API validation
		update_option( self::LICENSE_OPTION, $key );
		update_option( self::STATUS_OPTION, 'active' );

		wp_send_json_success( array( 'message' => 'Cyphex Pro activated successfully!' ) );
	}
}
