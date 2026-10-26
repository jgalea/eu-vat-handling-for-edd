import { expect, test, type Browser, type Page } from '@playwright/test';
import { CHECKOUTS, NUMBERS, chooseCountry, downloadId, enterVat, latestOrder, notice, openCheckout, purchase, resetState, taxAmount, vatInput, wp, wpEval } from './helpers';

const WORDING = 'Article 196 Council Directive 2006/112/EC';

let software: number;

test.beforeAll( () => {
	software = downloadId( 'software' );
} );

test.beforeEach( () => {
	resetState();
} );

async function login( page: Page, user: string, password: string ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( user );
	await page.locator( '#user_pass' ).fill( password );
	await Promise.all( [ page.waitForURL( ( url ) => ! url.pathname.includes( 'wp-login' ) ), page.locator( '#wp-submit' ).click() ] );
}

async function adminPage( browser: Browser ): Promise<Page> {
	const page = await ( await browser.newContext( { baseURL: 'http://localhost:8911' } ) ).newPage();
	await login( page, 'admin', 'password' );
	return page;
}

/**
 * The confirmation page only summarises the order; the receipt, which is
 * where the VAT text prints, is one click further. EDD 3.1's confirmation
 * block has no link to it, so the receipt page is opened directly.
 */
async function openReceipt( page: Page ) {
	const link = page.getByRole( 'link', { name: 'View Order Details' } );
	if ( await link.count() ) {
		await Promise.all( [ page.waitForURL( /receipt/ ), link.first().click() ] );
	} else {
		const order = latestOrder();
		await page.goto( wpEval( `echo "@@" . wp_json_encode( edd_get_receipt_page_uri( ${ order.id } ) ) . "@@";` ) );
	}
	await expect( page.locator( 'body' ) ).toContainText( 'Subtotal' );
}

function orderScreen( id: number ) {
	return `/wp-admin/edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=${ id }`;
}

for ( const [ name, path ] of Object.entries( CHECKOUTS ) ) {
	test.describe( `${ name } checkout orders`, () => {
		test( 'a reverse-charged receipt shows the customer number and the legal wording', async ( { page } ) => {
			await openCheckout( page, path, software );
			await chooseCountry( page, 'DE' );
			await enterVat( page, NUMBERS.validDe );
			await expect( taxAmount( page ) ).toHaveAttribute( 'data-tax', '0.00' );
			await purchase( page );
			await openReceipt( page );

			const receipt = page.locator( '.vatright-edd-receipt' );
			await expect( receipt ).toContainText( NUMBERS.validDe );
			await expect( receipt ).toContainText( WORDING );
		} );

		test( 'a receipt for an order charged VAT shows neither', async ( { page } ) => {
			await openCheckout( page, path, software );
			await chooseCountry( page, 'DE' );
			await purchase( page );
			await openReceipt( page );

			await expect( page.locator( 'body' ) ).not.toContainText( WORDING );
			await expect( page.locator( 'body' ) ).not.toContainText( NUMBERS.validDe );
			await expect( page.locator( '.vatright-edd-receipt' ) ).toHaveCount( 0 );
		} );
	} );
}

test( 'the seller details print under the receipt table', async ( { page } ) => {
	wpEval( `
		update_option( 'vatright_edd_settings', array( 'store_vat_number' => 'MT20000010', 'seller_company' => 'Example Shop Ltd', 'seller_address_1' => '1 High Street', 'seller_city' => 'Valletta', 'seller_country' => 'Malta' ) );
		echo "@@1@@";
	` );
	await openCheckout( page, CHECKOUTS.shortcode, software );
	await chooseCountry( page, 'DE' );
	await purchase( page );
	await openReceipt( page );

	await expect( page.locator( '.vatright-edd-receipt__seller' ) ).toContainText( 'Example Shop Ltd, 1 High Street, Valletta, Malta' );
	await expect( page.locator( '.vatright-edd-receipt__seller' ) ).toContainText( 'MT20000010' );
} );

test( 'the order screen shows the decision and a re-check records a new entry', async ( { page, browser } ) => {
	await openCheckout( page, CHECKOUTS.shortcode, software );
	await chooseCountry( page, 'DE' );
	await enterVat( page, NUMBERS.validDe );
	await purchase( page );
	const order = latestOrder();
	expect( order.tax ).toBe( 0 );

	const admin = await adminPage( browser );
	await admin.goto( orderScreen( order.id ) );
	await admin.locator( '.section-nav a', { hasText: 'EU VAT' } ).click();

	const section = admin.locator( '[id$="vatright_edd"].section-content, .section-wrap [id$="vatright_edd"]' ).first();
	await expect( section ).toContainText( 'reverse charge (Article 196)' );
	await expect( section ).toContainText( 'Example GmbH' );
	await expect( admin.locator( '#vatright-edd-recheck-number' ) ).toHaveValue( NUMBERS.validDe );

	// The registry now says the number is invalid: the re-check records that and keeps the amounts.
	wpEval( `
		update_option( 'vatright_edd_e2e_force_invalid', '1' );
		echo "@@1@@";
	` );
	await admin.locator( '#vatright-edd-recheck' ).click();
	await admin.waitForLoadState( 'networkidle' );
	await admin.locator( '.section-nav a', { hasText: 'EU VAT' } ).click();

	await expect( admin.getByText( 'Decision log' ) ).toBeVisible();
	await expect( admin.locator( 'td', { hasText: /^recheck$/ } ) ).toHaveCount( 1 );
	expect( latestOrder().tax ).toBe( 0 );
	await admin.context().close();
} );

test( 'a returning customer sees their last VAT number checked on load', async ( { browser } ) => {
	expect( wp( 'eval-file', 'wp-content/plugins/eu-vat-handling-for-edd/e2e/returning-customer.php' ) ).toMatch( /@@[1-9]\d*@@/ );

	const context = await browser.newContext( { baseURL: 'http://localhost:8911' } );
	const page = await context.newPage();
	await login( page, 'returning', 'returning-pass' );
	await page.goto( `/?edd_action=add_to_cart&download_id=${ software }` );
	await page.goto( CHECKOUTS.shortcode );
	await expect( vatInput( page ) ).toHaveValue( NUMBERS.validDe );
	await expect( notice( page ) ).toContainText( 'VAT number verified. Reverse charge applies.' );
	await expect( taxAmount( page ) ).toHaveAttribute( 'data-tax', '0.00' );
	await context.close();
} );
