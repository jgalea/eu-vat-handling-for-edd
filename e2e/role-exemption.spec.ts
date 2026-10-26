import { expect, test, type Page } from '@playwright/test';
import { BASE_URL } from './base-url';
import { CHECKOUTS, adminLogin, chooseCountry, decisionOf, downloadId, latestOrder, openCheckout, purchase, resetState, setSettings, taxAmount } from './helpers';

const EMAIL = 'wholesale@example.com';

let software: number;

test.beforeAll( () => {
	software = downloadId( 'software' );
} );

test.beforeEach( async ( { page } ) => {
	resetState();
	setSettings( { exempt_roles: [ 'subscriber' ] } );
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'vatright-edd-customer' );
	await page.locator( '#user_pass' ).fill( 'vatright-pass' );
	await Promise.all( [ page.waitForURL( ( url ) => ! url.pathname.includes( 'wp-login' ) ), page.locator( '#wp-submit' ).click() ] );
} );

test.afterAll( () => {
	resetState();
} );

for ( const [ name, path ] of Object.entries( CHECKOUTS ) ) {
	test( `${ name } checkout: a customer with an exempt role pays no VAT and the rule is recorded`, async ( { page } ) => {
		await openCheckout( page, path, software );
		await chooseCountry( page, 'DE' );
		await expect( taxAmount( page ) ).toHaveAttribute( 'data-tax', '0.00' );

		await purchase( page, EMAIL );

		const order = latestOrder();
		expect( order.tax ).toBe( 0 );
		const decision = decisionOf( order.id );
		expect( decision.rule ).toBe( 'ROLE_EXEMPT' );
		expect( decision.customer.exempt_roles ).toEqual( [ 'subscriber' ] );
	} );
}

test( 'the same customer is charged when their role is not listed', async ( { page } ) => {
	setSettings( { exempt_roles: [ 'shop_manager' ] } );
	await openCheckout( page, CHECKOUTS.shortcode, software );
	await chooseCountry( page, 'DE' );
	await expect( taxAmount( page ) ).toHaveAttribute( 'data-tax', '18.00' );

	await purchase( page, EMAIL );

	expect( latestOrder().tax ).toBe( 18 );
	expect( decisionOf( latestOrder().id ).rule ).toBe( 'B2C_ORIGIN_BELOW_THRESHOLD' );
} );

test( 'the admin order screen shows the role exemption as its own rule', async ( { page, browser } ) => {
	await openCheckout( page, CHECKOUTS.shortcode, software );
	await chooseCountry( page, 'DE' );
	await purchase( page, EMAIL );
	const id = latestOrder().id;

	const admin: Page = await ( await browser.newContext( { baseURL: BASE_URL } ) ).newPage();
	await adminLogin( admin );
	await admin.goto( `/wp-admin/edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=${ id }` );
	await admin.locator( '.section-nav a', { hasText: 'EU VAT' } ).click();
	const box = admin.locator( '.section-content', { hasText: 'VAT-exempt because of the customer role' } ).first();
	await expect( box ).toBeVisible();
	await expect( box ).toContainText( 'Exempt customer roles' );
	await expect( box ).toContainText( 'subscriber' );
	if ( process.env.PORT_SHOTS ) {
		await admin.screenshot( { path: `${ process.env.PORT_SHOTS }/admin-order-role-exempt.png`, fullPage: true } );
	}
	await admin.context().close();
} );
