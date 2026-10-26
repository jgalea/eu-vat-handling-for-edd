import { execFileSync } from 'node:child_process';
import { expect, type Page } from '@playwright/test';

export const NUMBERS = {
	validDe: 'DE114103379',
	invalidDe: 'DE123475223',
};

export const CHECKOUTS = {
	shortcode: '/classic-checkout/',
	block: '/checkout/',
};

export function wp( ...args: string[] ): string {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', ...args ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] } );
}

export function wpEval( php: string ): any {
	const out = wp( 'eval', php );
	const match = out.match( /@@([\s\S]*?)@@/ );
	if ( ! match ) {
		throw new Error( 'wp eval returned no marker: ' + out );
	}
	return JSON.parse( match[ 1 ] );
}

export function resetState() {
	const out = wp( 'eval-file', 'wp-content/plugins/eu-vat-handling-for-edd/e2e/reset.php' );
	expect( out ).toContain( '@@1@@' );
}

export function downloadId( slug: string ): number {
	return wpEval( `echo "@@" . (int) get_page_by_path( '${ slug }', OBJECT, 'download' )->ID . "@@";` );
}

export const taxAmount = ( page: Page ) => page.locator( '#edd_checkout_cart .edd_cart_tax_amount' ).first();
export const vatInput = ( page: Page ) => page.locator( '#vatright-edd-vat-number' );
export const notice = ( page: Page ) => page.locator( '#vatright-edd-notice' );

/**
 * EDD 3.1 renders the shortcode in its block layout, without the email
 * field, on any page other than the one set as the checkout page, so the
 * page under test is made the checkout page first.
 */
function useCheckoutPage( path: string ) {
	const slug = path.replace( /\//g, '' );
	wp( 'eval', `edd_update_option( 'purchase_page', get_page_by_path( '${ slug }' )->ID );` );
}

export async function openCheckout( page: Page, path: string, download: number ) {
	useCheckoutPage( path );
	await page.goto( `/?edd_action=add_to_cart&download_id=${ download }` );
	await page.goto( path );
	await expect( vatInput( page ) ).toBeVisible();
	await page.waitForLoadState( 'networkidle' );
}

/**
 * EDD hides the country select behind Chosen, so the value is set on the
 * select and its change event fired the way Chosen does.
 */
export async function chooseCountry( page: Page, country: string ) {
	const recalculated = page.waitForResponse( ( r ) => r.url().includes( 'admin-ajax.php' ) && ( r.request().postData() || '' ).includes( 'action=edd_recalculate_taxes' ) );
	await page.evaluate( ( code ) => {
		const $ = ( window as any ).jQuery;
		$( '#edd_cc_address #billing_country' ).val( code ).trigger( 'chosen:updated' ).trigger( 'change' );
	}, country );
	await recalculated;
	await page.waitForLoadState( 'networkidle' );
}

export async function enterVat( page: Page, value: string ) {
	const checked = page.waitForResponse( ( r ) => r.url().includes( 'admin-ajax.php' ) && ( r.request().postData() || '' ).includes( 'action=vatright_edd_check' ) );
	await vatInput( page ).fill( value );
	await checked;
	await page.waitForLoadState( 'networkidle' );
}

export async function fillCustomer( page: Page, email = 'ada@example.com' ) {
	await page.locator( '#edd-email' ).fill( email );
	await page.locator( '#edd-first' ).fill( 'Ada' );
	await page.locator( '#edd-last' ).fill( 'Lovelace' );
	for ( const [ id, value ] of [ [ '#card_address', 'Example Strasse 1' ], [ '#card_city', 'Berlin' ], [ '#card_state', 'Berlin' ], [ '#card_zip', '10115' ] ] ) {
		const field = page.locator( `#edd_cc_address input${ id }` );
		if ( await field.count() ) {
			await field.fill( value );
		}
	}
}

export async function purchase( page: Page, email?: string ) {
	await fillCustomer( page, email );
	await Promise.all( [ page.waitForURL( /\/confirmation\// ), page.locator( '#edd-purchase-button' ).click() ] );
}

export function latestOrder(): { id: number; tax: number; total: number; country: string } {
	return wpEval( `
		$orders = edd_get_orders( array( 'number' => 1, 'orderby' => 'id', 'order' => 'DESC', 'type' => 'sale' ) );
		$order  = $orders ? $orders[0] : null;
		echo "@@" . wp_json_encode( array(
			'id'      => $order ? (int) $order->id : 0,
			'tax'     => $order ? (float) $order->tax : 0,
			'total'   => $order ? (float) $order->total : 0,
			'country' => $order && $order->get_address() ? $order->get_address()->country : '',
		) ) . "@@";
	` );
}
