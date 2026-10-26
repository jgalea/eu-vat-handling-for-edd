import { expect, test } from '@playwright/test';
import { CHECKOUTS, NUMBERS, adminLogin, chooseCountry, decisionOf, downloadId, enterVat, fillCustomer, latestOrder, openCheckout, purchase, resetState, setSettings, shot, vatInput, wpEval } from './helpers';

const MESSAGE = 'This shop sells to businesses only. Enter a valid VAT number to place your order.';

let software: number;

test.beforeAll( () => {
	software = downloadId( 'software' );
} );

test.beforeEach( () => {
	resetState();
	setSettings( { b2b_only: 'everywhere' } );
} );

test.afterAll( () => {
	resetState();
} );

const label = ( page: import( '@playwright/test' ).Page ) => page.locator( 'label[for="vatright-edd-vat-number"]' );

for ( const [ name, path ] of Object.entries( CHECKOUTS ) ) {
	test.describe( `${ name } checkout`, () => {
		test( 'refuses an order without a VAT number and takes one with a verified number', async ( { page } ) => {
			await openCheckout( page, path, software );
			await chooseCountry( page, 'DE' );
			await fillCustomer( page );
			const before = latestOrder().id;

			await page.locator( '#edd-purchase-button' ).click();
			await expect( page.locator( '.edd_errors' ) ).toContainText( MESSAGE );
			expect( latestOrder().id ).toBe( before );
			await shot( page, `${ name }-b2b-only-refusal` );

			await enterVat( page, NUMBERS.validDe );
			await purchase( page );
			const order = latestOrder();
			expect( order.id ).toBeGreaterThan( before );
			expect( order.tax ).toBe( 0 );
			expect( decisionOf( order.id ).vat_number.canonical ).toBe( NUMBERS.validDe );
		} );

		test( 'shows the business-only message for an invalid number', async ( { page } ) => {
			await openCheckout( page, path, software );
			await chooseCountry( page, 'DE' );
			await enterVat( page, NUMBERS.invalidDe );
			await fillCustomer( page );

			await page.locator( '#edd-purchase-button' ).click();

			await expect( page.locator( '.edd_errors' ) ).toContainText( 'this shop sells to businesses only' );
			await expect( page.locator( '.edd_errors' ) ).not.toContainText( 'leave the field empty' );
		} );

		test( 'marks the VAT field required only for the countries the mode covers', async ( { page } ) => {
			setSettings( { b2b_only: 'countries', b2b_only_countries: [ 'DE' ] } );
			await openCheckout( page, path, software );
			await chooseCountry( page, 'FR' );
			await expect( label( page ) ).toHaveText( 'VAT number (optional)' );
			await expect( label( page ).locator( '.edd-required-indicator' ) ).toHaveCount( 0 );

			await chooseCountry( page, 'DE' );
			await expect( label( page ) ).toHaveText( 'VAT number*Required' );
			await expect( label( page ).locator( '.edd-required-indicator' ) ).toHaveCount( 1 );
			const color = ( selector: string ) => page.locator( selector ).first().evaluate( ( el ) => getComputedStyle( el ).color );
			expect( await color( 'label[for="vatright-edd-vat-number"] .edd-required-indicator' ) ).toBe( await color( 'label[for="edd-email"] .edd-required-indicator, #edd_cc_address label[for="billing_country"] .edd-required-indicator' ) );
			await expect( vatInput( page ) ).toHaveAttribute( 'aria-required', 'true' );
			await shot( page, `${ name }-b2b-only-required-label` );

			await chooseCountry( page, 'FR' );
			await expect( label( page ) ).toHaveText( 'VAT number (optional)' );
			await expect( vatInput( page ) ).not.toHaveAttribute( 'aria-required', 'true' );
		} );

		test( 'keeps the VAT field optional and takes an order without a number while the mode is off', async ( { page } ) => {
			setSettings( {} );
			await openCheckout( page, path, software );
			await chooseCountry( page, 'DE' );
			await expect( label( page ) ).toHaveText( 'VAT number (optional)' );

			const before = latestOrder().id;
			await purchase( page );
			expect( latestOrder().id ).toBeGreaterThan( before );
		} );
	} );
}

test( 'the field is required before a country is chosen when the mode is everywhere', async ( { page } ) => {
	await openCheckout( page, CHECKOUTS.shortcode, software );
	await expect( label( page ) ).toHaveText( 'VAT number*Required' );
} );

test( 'the setting is saved from the EU VAT settings page', async ( { page } ) => {
	setSettings( {} );
	await adminLogin( page );
	await page.goto( '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=taxes&section=vatright' );
	const mode = page.locator( '[name="vatright_edd_settings[b2b_only]"]' );
	await expect( mode ).toHaveValue( 'off' );
	await expect( page.getByText( 'is never accepted unchecked' ) ).toBeVisible();

	await mode.selectOption( 'countries' );
	await page.locator( '[name="vatright_edd_settings[b2b_only_countries][]"]' ).selectOption( [ 'DE', 'FR' ] );
	await shot( page, 'settings-b2b-only' );
	await page.locator( '#submit, input[type="submit"][name="submit"]' ).first().click();
	await page.waitForLoadState( 'networkidle' );

	const stored = wpEval( 'echo "@@" . wp_json_encode( array( \\VatrightEdd\\Settings::get()->b2b_only(), \\VatrightEdd\\Settings::get()->b2b_only_countries() ) ) . "@@";' );
	expect( stored[ 0 ] ).toBe( 'countries' );
	expect( [ ...stored[ 1 ] ].sort() ).toEqual( [ 'DE', 'FR' ] );
} );
