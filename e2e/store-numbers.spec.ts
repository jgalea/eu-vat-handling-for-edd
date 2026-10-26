import { expect, test, type Page } from '@playwright/test';
import { chooseCountry, downloadId, latestOrder, openCheckout, purchase, resetState, wpEval } from './helpers';

const SETTINGS = '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=taxes&section=vatright';
const EU = 'MT20000010';
const UK = 'GB333289454';

let software: number;

test.beforeAll( () => {
	software = downloadId( 'software' );
} );

test.beforeEach( () => {
	resetState();
} );

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await Promise.all( [ page.waitForURL( ( url ) => ! url.pathname.includes( 'wp-login' ) ), page.locator( '#wp-submit' ).click() ] );
}

async function openReceipt( page: Page ) {
	const order = latestOrder();
	await page.goto( wpEval( `echo "@@" . wp_json_encode( edd_get_receipt_page_uri( ${ order.id } ) ) . "@@";` ) );
	await expect( page.locator( 'body' ) ).toContainText( 'Subtotal' );
}

async function buyFrom( page: Page, country: string ) {
	await openCheckout( page, '/classic-checkout/', software );
	await chooseCountry( page, country );
	// EDD swaps the free-text state for a list of counties when the country has one.
	const counties = page.locator( 'select#card_state' );
	if ( await counties.count() ) {
		await counties.selectOption( { index: 1 } );
	}
	await purchase( page );
	await openReceipt( page );
}

test( 'the settings form saves an EU and a UK store number', async ( { page } ) => {
	await login( page );
	await page.goto( SETTINGS );

	await page.locator( '#vatright_edd_settings_store_vat_number' ).fill( EU );
	await page.locator( '#vatright_edd_settings_store_vat_number_gb' ).fill( 'gb 333 2894 54' );
	await Promise.all( [ page.waitForURL( /settings-updated|section=vatright/ ), page.getByRole( 'button', { name: 'Save Changes' } ).click() ] );

	await expect( page.locator( '#vatright_edd_settings_store_vat_number' ) ).toHaveValue( EU );
	await expect( page.locator( '#vatright_edd_settings_store_vat_number_gb' ) ).toHaveValue( UK );
	await page.screenshot( { path: `${ process.env.SHOTS || 'test-results' }/store-numbers-settings.png` } );
} );

test( 'a UK customer is shown the UK number and a French one the EU number', async ( { page } ) => {
	wpEval( `update_option( 'vatright_edd_settings', array( 'store_vat_number' => '${ EU }', 'store_vat_number_gb' => '${ UK }' ) ); echo "@@1@@";` );

	await buyFrom( page, 'GB' );
	const uk = page.locator( '.vatright-edd-receipt__seller' );
	await expect( uk ).toContainText( UK );
	await expect( uk ).not.toContainText( EU );

	await buyFrom( page, 'FR' );
	const fr = page.locator( '.vatright-edd-receipt__seller' );
	await expect( fr ).toContainText( EU );
	await expect( fr ).not.toContainText( UK );
} );

test( 'with only the EU number set a UK customer sees it, as before', async ( { page } ) => {
	wpEval( `update_option( 'vatright_edd_settings', array( 'store_vat_number' => '${ EU }' ) ); echo "@@1@@";` );

	await buyFrom( page, 'GB' );

	await expect( page.locator( '.vatright-edd-receipt__seller' ) ).toContainText( EU );
} );
