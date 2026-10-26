<?php

/**
 * Registry answers keyed by the number's digits; the DE numbers are
 * checksum-valid. The visitor's IP country comes from a cookie the test sets.
 */
function vatright_edd_e2e_vies_answer( $digits ) {
	if ( '114103379' === $digits && ! get_option( 'vatright_edd_e2e_force_invalid' ) ) {
		return array(
			'valid'             => true,
			'requestDate'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'requestIdentifier' => '',
			'name'              => 'Example GmbH',
			'address'           => 'Example Strasse 1',
		);
	}
	if ( '123475223' === $digits || '114103379' === $digits ) {
		return array(
			'valid'       => false,
			'requestDate' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
	}
	if ( '129273398' === $digits ) {
		return array(
			'actionSucceed' => false,
			'errorWrappers' => array( array( 'error' => 'MS_UNAVAILABLE' ) ),
		);
	}
	return null;
}

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		$hosts = array( 'ec.europa.eu', 'api.service.hmrc.gov.uk', 'test-api.service.hmrc.gov.uk', 'uid-wse.admin.ch', 'data.brreg.no', 'data-api.ecb.europa.eu' );
		$host  = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $host, $hosts, true ) ) {
			return $preempt;
		}
		if ( false !== strpos( $url, '/check-vat-number' ) ) {
			$body   = json_decode( (string) ( $args['body'] ?? '' ), true );
			$answer = is_array( $body ) ? vatright_edd_e2e_vies_answer( (string) ( $body['vatNumber'] ?? '' ) ) : null;
			if ( null !== $answer ) {
				update_option( 'vatright_edd_e2e_vies_calls', (int) get_option( 'vatright_edd_e2e_vies_calls', 0 ) + 1, false );
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $answer ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		return new WP_Error( 'vatright_edd_e2e_unstubbed', 'No e2e stub for ' . $url );
	},
	10,
	3
);

add_filter(
	'vatright_edd_ip_country',
	static function ( $country ) {
		$cookie = isset( $_COOKIE['vatright_edd_e2e_ip_country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_COOKIE['vatright_edd_e2e_ip_country'] ) ) ) : '';
		return preg_match( '/^[A-Z]{2}$/', $cookie ) ? $cookie : $country;
	}
);
