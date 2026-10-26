<?php

/**
 * Keeps the last email WordPress was asked to send instead of sending it, so
 * a test can read what the plugin wrote.
 */
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		update_option(
			'vatright_edd_e2e_mail',
			array(
				'to'      => $atts['to'],
				'subject' => $atts['subject'],
				'message' => $atts['message'],
			),
			false
		);
		return true;
	},
	10,
	2
);
