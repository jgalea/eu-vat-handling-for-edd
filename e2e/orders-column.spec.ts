import { expect, test } from '@playwright/test';
import { BASE_URL } from './base-url';
import { CHECKOUTS, NUMBERS, adminLogin, chooseCountry, downloadId, enterVat, latestOrder, openCheckout, purchase, resetState, shot } from './helpers';

const LIST = '/wp-admin/edit.php?post_type=download&page=edd-payment-history';

let software: number;
let valid: number;
let pending: number;
let consumer: number;

test.beforeAll( async ( { browser } ) => {
	software = downloadId( 'software' );
	resetState();

	const buy = async ( country: string, number?: string ) => {
		const page = await ( await browser.newContext( { baseURL: BASE_URL } ) ).newPage();
		await openCheckout( page, CHECKOUTS.shortcode, software );
		await chooseCountry( page, country );
		if ( number ) {
			await enterVat( page, number );
		}
		await purchase( page );
		await page.context().close();
		return latestOrder().id;
	};
	valid = await buy( 'DE', NUMBERS.validDe );
	pending = await buy( 'DE', NUMBERS.outageDe );
	consumer = await buy( 'FR' );
} );

test.afterAll( () => {
	resetState();
} );

const row = ( page: import( '@playwright/test' ).Page, id: number ) => page.locator( `tr:has(a[href*="id=${ id }"])` ).first();

test( 'the orders list shows the VAT number, its status and the treatment', async ( { page } ) => {
	await adminLogin( page );
	await page.goto( LIST );

	await expect( page.locator( 'th#vatright_edd_vat' ) ).toHaveText( 'VAT' );
	await expect( row( page, valid ).locator( '.vatright-edd-vat' ) ).toContainText( `${ NUMBERS.validDe } Valid` );
	await expect( row( page, valid ).locator( '.vatright-edd-vat' ) ).toContainText( 'Reverse charge' );
	await expect( row( page, pending ).locator( '.vatright-edd-vat' ) ).toContainText( 'Pending validation' );
	await expect( row( page, pending ).locator( '.vatright-edd-vat' ) ).toContainText( 'VAT charged' );
	await expect( row( page, consumer ).locator( '.vatright-edd-vat' ) ).toContainText( 'Origin' );
	await expect( row( page, consumer ).locator( '.vatright-edd-vat-flagged' ) ).toHaveCount( 0 );
	await shot( page, 'orders-list-vat-column' );
} );

test( 'the filter narrows the list to orders pending validation or flagged', async ( { page } ) => {
	await adminLogin( page );
	await page.goto( LIST );
	await expect( page.locator( 'select[name="vatright_edd_vat_filter"]' ) ).toHaveCount( 1 );

	await page.goto( `${ LIST }&vatright_edd_vat_filter=pending` );
	await expect( row( page, pending ) ).toBeVisible();
	await expect( page.locator( `a[href*="id=${ valid }"]` ) ).toHaveCount( 0 );
	await expect( page.locator( `a[href*="id=${ consumer }"]` ) ).toHaveCount( 0 );
	await shot( page, 'orders-list-vat-filter-pending' );

	await page.goto( `${ LIST }&vatright_edd_vat_filter=flagged` );
	await expect( row( page, pending ) ).toBeVisible();
	await expect( page.locator( `a[href*="id=${ consumer }"]` ) ).toHaveCount( 0 );
	await expect( page.locator( `a[href*="id=${ valid }"]` ) ).toHaveCount( 0 );
} );
