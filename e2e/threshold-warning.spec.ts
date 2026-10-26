import { expect, test } from '@playwright/test';
import { chooseCountry, downloadId, openCheckout, purchase, resetState, wpEval } from './helpers';

const SHOTS = process.env.SHOTS || 'test-results';

let software: number;

function lastMail(): { to: string; subject: string; message: string } | null {
	return wpEval( 'echo "@@" . wp_json_encode( get_option( "vatright_edd_e2e_mail", null ) ) . "@@";' );
}

function setPrice( price: string ) {
	wpEval( `update_post_meta( ${ software }, 'edd_price', '${ price }' ); echo "@@1@@";` );
}

function clearWarningState() {
	wpEval( `
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'vatright\\_edd\\_threshold\\_warning\\_%' OR option_name = 'vatright_edd_e2e_mail'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}vatright_edd_ledger" );
		\\VatrightEdd\\Ledger\\ThresholdTracker::flush( (int) wp_date( 'Y' ), (int) wp_date( 'Y' ) - 1 );
		echo "@@1@@";
	` );
}

test.beforeAll( () => {
	software = downloadId( 'software' );
} );

test.beforeEach( () => {
	resetState();
	clearWarningState();
} );

test.afterAll( () => {
	setPrice( '100.00' );
	clearWarningState();
} );

test( 'a checkout that takes EU sales to 80% of the threshold emails the admin through EDD', async ( { page } ) => {
	setPrice( '8000.00' );
	await openCheckout( page, '/classic-checkout/', software );
	await chooseCountry( page, 'FR' );
	await purchase( page );

	const mail = lastMail();
	expect( mail ).not.toBeNull();
	expect( mail!.subject ).toContain( '80% of the EUR 10,000 OSS threshold' );
	expect( mail!.message ).toContain( '<html' );
	expect( mail!.message ).toContain( 'EUR 8,000.00 of EUR 10,000.00 (80.0%)' );

	await page.setContent( mail!.message );
	await expect( page.locator( 'body' ) ).toContainText( 'Your cross-border EU sales to consumers have reached 80%' );
	await page.setViewportSize( { width: 760, height: 900 } );
	await page.screenshot( { path: `${ SHOTS }/threshold-warning-email.png`, fullPage: true } );
} );

test( 'a sale below the share sends nothing', async ( { page } ) => {
	setPrice( '100.00' );
	await openCheckout( page, '/classic-checkout/', software );
	await chooseCountry( page, 'FR' );
	await purchase( page );

	expect( lastMail() ).toBeNull();
} );
