<?php

/**
 * Makes the plugin see Barn2 EDD EU VAT as active while the e2e option is set.
 */
if ( get_option( 'vatright_edd_e2e_barn2' ) ) {
	class_alias( 'stdClass', 'Barn2\Plugin\EDD_VAT\Plugin' );
}
