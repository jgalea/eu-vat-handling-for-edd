import { expect, test } from '@playwright/test';
import { BASE_URL } from './base-url';
import { CHECKOUTS, NUMBERS, chooseCountry, downloadId, enterVat, fillCustomer, latestOrder, notice, openCheckout, purchase, resetState, taxAmount, vatInput } from './helpers';

let software: number;

test.beforeAll( () => {
	software = downloadId( 'software' );
} );

test.beforeEach( () => {
	resetState();
} );

for ( const [ name, path ] of Object.entries( CHECKOUTS ) ) {
	test.describe( `${ name } checkout`, () => {
		test( 'a valid VAT number drops the tax and a change of country clears it', async ( { page } ) => {
			await openCheckout( page, path, software );
			await chooseCountry( page, 'DE' );
			await expect( taxAmount( page ) ).toHaveAttribute( 'data-tax', '18.00' );

			await enterVat( page, NUMBERS.validDe );
			await expect( notice( page ) ).toContainText( 'VAT number verified. Reverse charge applies.' );
			await expect( taxAmount( page ) ).toHaveAttribute( 'data-tax', '0.00' );
			await expect( taxAmount( page ) ).toContainText( 'reverse charge' );

			await chooseCountry( page, 'FR' );
			await expect( vatInput( page ) ).toHaveValue( '' );
			await expect( notice( page ) ).toBeEmpty();
			await expect( taxAmount( page ) ).toHaveAttribute( 'data-tax', '18.00' );

			// The server dropped the number too: a reload shows the empty field and the charged cart.
			await page.reload();
			await page.waitForLoadState( 'networkidle' );
			await expect( vatInput( page ) ).toHaveValue( '' );
		} );

		test( 'a reverse-charged purchase reaches the gateway without VAT', async ( { page } ) => {
			await openCheckout( page, path, software );
			await chooseCountry( page, 'DE' );
			await enterVat( page, NUMBERS.validDe );
			await expect( taxAmount( page ) ).toHaveAttribute( 'data-tax', '0.00' );

			await purchase( page );

			const order = latestOrder();
			expect( order.tax ).toBe( 0 );
			expect( order.total ).toBe( 100 );
			expect( order.country ).toBe( 'DE' );
		} );

		test( 'an invalid VAT number blocks the purchase', async ( { page } ) => {
			await openCheckout( page, path, software );
			await chooseCountry( page, 'DE' );
			await enterVat( page, NUMBERS.invalidDe );
			await fillCustomer( page );
			const before = latestOrder().id;

			await page.locator( '#edd-purchase-button' ).click();

			await expect( page.locator( '.edd_errors' ) ).toContainText( 'This VAT number is not valid.' );
			expect( latestOrder().id ).toBe( before );
		} );

		test( 'an invalid VAT number says so and keeps the tax', async ( { page } ) => {
			await openCheckout( page, path, software );
			await chooseCountry( page, 'DE' );

			await enterVat( page, NUMBERS.invalidDe );

			await expect( notice( page ) ).toContainText( 'This VAT number is not valid.' );
			await expect( taxAmount( page ) ).toHaveAttribute( 'data-tax', '18.00' );
		} );

		test( 'a location that disagrees with the billing country asks for self-certification', async ( { page, context } ) => {
			await context.addCookies( [ { name: 'vatright_edd_e2e_ip_country', value: 'FR', url: BASE_URL } ] );
			await openCheckout( page, path, software );
			await chooseCountry( page, 'DE' );

			const checkbox = page.locator( '#vatright-edd-self-certify' );
			await expect( checkbox ).toBeVisible();
			await expect( page.locator( 'label[for="vatright-edd-self-certify"]' ) ).toHaveText( 'I confirm I am located in Germany.' );

			const checked = page.waitForResponse( ( r ) => ( r.request().postData() || '' ).includes( 'action=vatright_edd_check' ) );
			await checkbox.check();
			await checked;
			await expect( checkbox ).toBeChecked();
		} );
	} );
}
