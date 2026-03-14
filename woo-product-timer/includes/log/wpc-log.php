<?php
defined( 'ABSPATH' ) || exit;

register_activation_hook( defined( 'WOOPT_LITE' ) ? WOOPT_LITE : WOOPT_FILE, 'woopt_activate' );
register_deactivation_hook( defined( 'WOOPT_LITE' ) ? WOOPT_LITE : WOOPT_FILE, 'woopt_deactivate' );
add_action( 'admin_init', 'woopt_check_version' );

function woopt_check_version() {
	if ( ! empty( get_option( 'woopt_version' ) ) && ( get_option( 'woopt_version' ) < WOOPT_VERSION ) ) {
		wpc_log( 'woopt', 'upgraded' );
		update_option( 'woopt_version', WOOPT_VERSION, false );
	}
}

function woopt_activate() {
	wpc_log( 'woopt', 'installed' );
	update_option( 'woopt_version', WOOPT_VERSION, false );
}

function woopt_deactivate() {
	wpc_log( 'woopt', 'deactivated' );
}

if ( ! function_exists( 'wpc_log' ) ) {
	function wpc_log( $prefix, $action ) {
		$logs = get_option( 'wpc_logs', [] );
		$user = wp_get_current_user();

		if ( ! isset( $logs[ $prefix ] ) ) {
			$logs[ $prefix ] = [];
		}

		$logs[ $prefix ][] = [
			'time'   => current_time( 'mysql' ),
			'user'   => $user->display_name . ' (ID: ' . $user->ID . ')',
			'action' => $action
		];

		update_option( 'wpc_logs', $logs, false );
	}
}