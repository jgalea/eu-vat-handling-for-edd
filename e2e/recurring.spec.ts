import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { BASE_URL } from './base-url';
import { NUMBERS, chooseCountry, downloadId, enterVat, latestOrder, openCheckout, purchase, wp } from './helpers';

const PLUGIN_DIR = process.env.E2E_PLUGIN_DIR || 'eu-vat-handling-for-edd';

test.use( { baseURL: BASE_URL } );

function evalFile( file: string, extra: string[] = [] ): any {
	const args = [ 'wp-env', 'run', 'cli', 'wp', 'eval-file', `wp-content/plugins/${ PLUGIN_DIR }/e2e/${ file }`, ...extra ];
	const out = execFileSync( 'npx', args, { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] } );
	const match = out.match( /@@([\s\S]*?)@@/ );
	if ( ! match ) {
		throw new Error( 'eval-file returned no marker: ' + out.slice( 0, 400 ) );
	}
	return JSON.parse( match[ 1 ] );
}

function reset() {
	evalFile( 'reset.php' );
}

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await Promise.all( [ page.waitForURL( ( url ) => ! url.pathname.includes( 'wp-login' ) ), page.locator( '#wp-submit' ).click() ] );
}

function renew( parent: number, gross: number ) {
	return evalFile( 'recurring-renew.php', [ String( parent ), String( gross ) ] );
}

// A guest order from another spec may own the default email, and EDD rejects it for a logged-in buyer.
const ADMIN_EMAIL = 'wordpress@example.com';

let monthly: number;
let ebook: number;

test.beforeAll( () => {
	evalFile( 'recurring-seed.php' );
	monthly = downloadId( 'monthly' );
	ebook = downloadId( 'monthly-ebook' );
} );

test.beforeEach( () => {
	reset();
} );

test( 'a reverse-charged subscription renews with no VAT inside the gross', async ( { page } ) => {
	await login( page );
	await openCheckout( page, '/classic-checkout/', monthly );
	await chooseCountry( page, 'DE' );
	await enterVat( page, NUMBERS.validDe );
	await purchase( page, ADMIN_EMAIL );
	const parent = latestOrder();
	expect( parent.tax ).toBe( 0 );

	const result = renew( parent.id, 100 );

	expect( result.order.status ).toBe( 'edd_subscription' );
	expect( result.order.tax ).toBe( 0 );
	expect( result.order.total ).toBe( 100 );
	expect( result.decision.exempt ).toBe( true );
	expect( result.rows ).toHaveLength( 1 );
	expect( Number( result.rows[ 0 ].vat ) ).toBe( 0 );
	expect( result.sub.unchanged ).toBe( true );
} );

test( 'a consumer subscription renews with the store country VAT inside the gross', async ( { page } ) => {
	await login( page );
	await openCheckout( page, '/classic-checkout/', monthly );
	await chooseCountry( page, 'FR' );
	await purchase( page, ADMIN_EMAIL );
	const parent = latestOrder();
	expect( parent.tax ).toBe( 18 );

	const result = renew( parent.id, 118 );

	expect( result.order.tax ).toBe( 18 );
	expect( result.decision.exempt ).toBe( false );
	expect( result.decision.flags ).not.toContain( 'renewal_rate_changed' );
	expect( Number( result.rows[ 0 ].vat ) ).toBe( 18 );
	expect( result.sub.unchanged ).toBe( true );
} );

test( 'a reduced-rate subscription is signed up and renewed at the reduced rate', async ( { page } ) => {
	evalFile( 'recurring-seed.php' );
	wp( 'eval', 'edd_update_option("x", 1); update_option("vatright_edd_settings", array("oss_registered" => true));' );
	await login( page );
	await openCheckout( page, '/classic-checkout/', ebook );
	await chooseCountry( page, 'FR' );
	await purchase( page, ADMIN_EMAIL );
	const parent = latestOrder();
	expect( parent.tax ).toBe( 5.5 );

	const result = renew( parent.id, parent.total );

	expect( result.order.tax ).toBe( 5.5 );
	expect( Number( result.sub.rate ) ).toBeCloseTo( 0.055, 4 );
} );
