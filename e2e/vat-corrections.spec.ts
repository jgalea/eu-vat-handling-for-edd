import { expect, test, type Browser, type Page } from '@playwright/test';
import { BASE_URL } from './base-url';
import { CHECKOUTS, NUMBERS, chooseCountry, downloadId, enterVat, latestOrder, openCheckout, purchase, resetState, wpEval } from './helpers';

const SHOTS = process.env.SHOTS || 'test-results';
const OUTAGE_NUMBER = 'DE129273398';

let software: number;

test.beforeAll( () => {
	software = downloadId( 'software' );
} );

test.beforeEach( () => {
	resetState();
} );

async function adminPage( browser: Browser ): Promise<Page> {
	const page = await ( await browser.newContext( { baseURL: BASE_URL, viewport: { width: 1280, height: 1100 } } ) ).newPage();
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await Promise.all( [ page.waitForURL( ( url ) => ! url.pathname.includes( 'wp-login' ) ), page.locator( '#wp-submit' ).click() ] );
	return page;
}

function orderScreen( id: number ) {
	return `/wp-admin/edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=${ id }`;
}

async function openVatSection( page: Page, id: number ) {
	await page.goto( orderScreen( id ) );
	await page.locator( '.section-nav a', { hasText: 'EU VAT' } ).click();
	await expect( page.locator( '#vatright-edd-recheck' ) ).toBeVisible();
}

function ledgerRows( id: number ): Array<{ entry_type: string; scheme: string; vat: string; customer_vat: string | null }> {
	return wpEval( `
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT entry_type, scheme, vat, customer_vat FROM {$wpdb->prefix}vatright_edd_ledger WHERE order_id = %d ORDER BY id", ${ id } ), ARRAY_A );
		echo "@@" . wp_json_encode( $rows ) . "@@";
	` );
}

function orderFacts( id: number ): { status: string; tax: number; total: number; refunds: number; refunded: number } {
	return wpEval( `
		$order   = edd_get_order( ${ id } );
		$refunds = edd_get_order_refunds( ${ id } );
		echo "@@" . wp_json_encode( array(
			'status'   => $order->status,
			'tax'      => (float) $order->tax,
			'total'    => (float) $order->total,
			'refunds'  => count( $refunds ),
			'refunded' => (float) array_sum( array_map( function ( $r ) { return (float) $r->total; }, $refunds ) ),
		) ) . "@@";
	` );
}

test.describe( 'correcting the VAT record on an order', () => {
	let orderId: number;

	test.beforeEach( async ( { page } ) => {
		await openCheckout( page, CHECKOUTS.shortcode, software );
		await chooseCountry( page, 'FR' );
		await purchase( page );
		orderId = latestOrder().id;
		expect( latestOrder().tax ).toBeGreaterThan( 0 );
	} );

	test( 'an admin corrects number, country and treatment with a reason; the log and the ledger follow and the amounts stay', async ( { browser } ) => {
		const before = orderFacts( orderId );
		const admin = await adminPage( browser );
		await openVatSection( admin, orderId );

		const box = admin.locator( '.vatright-edd-edit' );
		await expect( box ).toContainText( 'does not change the amounts charged' );
		await expect( box ).toContainText( 'does not refund anything' );

		await box.locator( '#vatright-edd-edit-number' ).fill( NUMBERS.validDe );
		await box.locator( '#vatright-edd-edit-country' ).selectOption( 'DE' );
		await box.locator( '#vatright-edd-edit-treatment' ).selectOption( 'reverse_charge' );
		await box.locator( '#vatright-edd-edit-reason' ).fill( 'Customer sent their VAT certificate' );
		await admin.screenshot( { path: `${ SHOTS }/edit-form-filled.png`, fullPage: true } );
		await Promise.all( [ admin.waitForURL( /vatright_edited=1/ ), box.locator( '#vatright-edd-edit-save' ).click() ] );

		await admin.locator( '.section-nav a', { hasText: 'EU VAT' } ).click();
		const section = admin.locator( '.section-wrap [id$="vatright_edd"]' ).first();
		await expect( section ).toContainText( 'The VAT record was corrected' );
		await expect( section ).toContainText( 'reverse charge (Article 196)' );
		await expect( section ).toContainText( 'An admin corrected this VAT record by hand' );
		const entry = section.locator( 'tr', { hasText: 'admin_vat_edit' } );
		await expect( entry ).toContainText( 'Reason: Customer sent their VAT certificate' );
		await expect( entry ).toContainText( `VAT number: (none) to ${ NUMBERS.validDe }` );
		await expect( entry ).toContainText( 'Country of evidence: FR to DE' );
		await expect( section.locator( 'tr', { hasText: 'order_built' } ) ).toHaveCount( 1 );
		await admin.screenshot( { path: `${ SHOTS }/edit-log.png`, fullPage: true } );

		expect( orderFacts( orderId ) ).toEqual( before );
		const rows = ledgerRows( orderId );
		expect( rows.map( ( r ) => r.scheme ) ).toEqual( [ 'reverse_charge_services' ] );
		expect( rows[ 0 ].customer_vat ).toBe( NUMBERS.validDe );
		await admin.context().close();
	} );

	test( 'a correction without a reason is refused on the screen and nothing changes', async ( { browser } ) => {
		const admin = await adminPage( browser );
		await openVatSection( admin, orderId );

		const box = admin.locator( '.vatright-edd-edit' );
		await box.locator( '#vatright-edd-edit-number' ).fill( NUMBERS.validDe );
		await box.locator( '#vatright-edd-edit-country' ).selectOption( 'DE' );
		await box.locator( '#vatright-edd-edit-treatment' ).selectOption( 'reverse_charge' );
		await box.locator( '#vatright-edd-edit-save' ).click();

		await expect( box.locator( '.vatright-edd-edit__result' ) ).toHaveText( 'Give a reason for the change.' );
		await expect( box.locator( '#vatright-edd-edit-save' ) ).toBeEnabled();
		await admin.reload();
		await admin.locator( '.section-nav a', { hasText: 'EU VAT' } ).click();
		await expect( admin.locator( 'td', { hasText: /^admin_vat_edit$/ } ) ).toHaveCount( 0 );
		expect( ledgerRows( orderId ).map( ( r ) => r.scheme ) ).not.toContain( 'reverse_charge_services' );
		await admin.context().close();
	} );

	test( 'the endpoint refuses a user who cannot edit orders', async ( { browser } ) => {
		wpEval( `
			if ( ! username_exists( 'plainsub' ) ) { wp_insert_user( array( 'user_login' => 'plainsub', 'user_pass' => 'sub-pass', 'user_email' => 'plainsub@example.com', 'role' => 'subscriber' ) ); }
			echo "@@1@@";
		` );
		const context = await browser.newContext( { baseURL: BASE_URL } );
		const page = await context.newPage();
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( 'plainsub' );
		await page.locator( '#user_pass' ).fill( 'sub-pass' );
		await Promise.all( [ page.waitForURL( ( url ) => ! url.pathname.includes( 'wp-login' ) ), page.locator( '#wp-submit' ).click() ] );

		const response = await page.request.post( '/wp-admin/admin-ajax.php', {
			form: { action: 'vatright_edd_edit', nonce: 'nope', order_id: String( orderId ), vat_number: NUMBERS.validDe, country: 'DE', treatment: 'reverse_charge', reason: 'x' },
		} );
		expect( response.status() ).toBe( 403 );
		expect( ledgerRows( orderId ).map( ( r ) => r.scheme ) ).not.toContain( 'reverse_charge_services' );
		await context.close();
	} );
} );

test.describe( 'refunding the VAT when a number validates after the order', () => {
	async function pendingOrder( page: Page ): Promise<number> {
		await openCheckout( page, CHECKOUTS.shortcode, software );
		await chooseCountry( page, 'DE' );
		await enterVat( page, OUTAGE_NUMBER );
		await purchase( page );
		const order = latestOrder();
		expect( order.tax ).toBeGreaterThan( 0 );
		return order.id;
	}

	function validateLater( id: number ) {
		wpEval( `
			update_option( 'vatright_edd_e2e_outage_over', '1' );
			do_action( 'vatright_edd_revalidate', ${ id }, 1 );
			do_action( 'vatright_edd_revalidate', ${ id }, 2 );
			echo "@@1@@";
		` );
	}

	test( 'with the setting off the order is left as it is', async ( { page } ) => {
		const id = await pendingOrder( page );
		const before = orderFacts( id );

		validateLater( id );

		expect( orderFacts( id ) ).toEqual( before );
		expect( before.refunds ).toBe( 0 );
	} );

	test( 'with the setting on exactly the VAT is refunded once and shows on the order', async ( { page, browser } ) => {
		wpEval( `update_option( 'vatright_edd_settings', array( 'auto_refund_on_validation' => true ) ); echo "@@1@@";` );
		const id = await pendingOrder( page );
		const before = orderFacts( id );
		expect( before.refunds ).toBe( 0 );

		validateLater( id );

		const after = orderFacts( id );
		expect( after.refunds, 'one refund even though the re-check ran twice' ).toBe( 1 );
		expect( after.refunded ).toBe( -before.tax );
		expect( after.tax ).toBe( before.tax );
		expect( after.status ).toBe( 'partially_refunded' );
		const rows = ledgerRows( id );
		expect( rows.map( ( r ) => r.entry_type ) ).toContain( 'refund' );
		expect( rows.reduce( ( sum, r ) => sum + parseFloat( r.vat ), 0 ) ).toBeCloseTo( 0, 4 );

		const admin = await adminPage( browser );
		await admin.goto( orderScreen( id ) );
		await expect( admin.locator( 'body' ) ).toContainText( /refund/i );
		await admin.screenshot( { path: `${ SHOTS }/refunded-order.png`, fullPage: true } );
		await admin.locator( '.section-nav a', { hasText: 'EU VAT' } ).click();
		const section = admin.locator( '.section-wrap [id$="vatright_edd"]' ).first();
		await expect( section ).toContainText( 'The VAT was refunded automatically after the number validated.' );
		await expect( section.locator( 'tr', { hasText: 'auto_vat_refund' } ) ).toContainText( 'Refund' );
		await admin.screenshot( { path: `${ SHOTS }/refunded-order-eu-vat.png`, fullPage: true } );
		await admin.context().close();
	} );

	test( 'an order on a gateway the plugin does not refund through is flagged, not refunded', async ( { page } ) => {
		wpEval( `update_option( 'vatright_edd_settings', array( 'auto_refund_on_validation' => true ) ); echo "@@1@@";` );
		const id = await pendingOrder( page );
		wpEval( `edd_update_order( ${ id }, array( 'gateway' => 'stripe' ) ); echo "@@1@@";` );

		validateLater( id );

		expect( orderFacts( id ).refunds ).toBe( 0 );
		const flags = wpEval( `echo "@@" . wp_json_encode( \\VatrightEdd\\Orders\\DecisionStore::get( ${ id } )->flags() ) . "@@";` );
		expect( flags ).toContain( 'auto_refund_skipped' );
	} );
} );
