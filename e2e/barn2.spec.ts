import { expect, test, type Page } from '@playwright/test';
import { wp, wpEval } from './helpers';

const SECRETS = [ 'e2e-secret-7f3a91c0d2b84e56', 'e2e-client-id-5d1e88a4c7f2', 'e2e-vatsense-0b6c4d9e1a7f' ];

const IMPORT_PAGE = '/wp-admin/edit.php?post_type=download&page=vatright-edd-import';

function seed() {
	expect( wp( 'eval-file', 'wp-content/plugins/eu-vat-handling-for-edd/e2e/barn2-seed.php' ) ).toContain( '@@1@@' );
}

function reset() {
	expect( wp( 'eval-file', 'wp-content/plugins/eu-vat-handling-for-edd/e2e/barn2-reset.php' ) ).toContain( '@@1@@' );
}

function ledgerRows(): number {
	return wpEval( 'global $wpdb; echo "@@" . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vatright_edd_ledger" ) . "@@";' );
}

/**
 * WP-Cron cannot loop back to itself inside wp-env, so the queued batches
 * are run the way a real request's cron spawn would.
 */
function runQueue() {
	expect( wp( 'eval-file', 'wp-content/plugins/eu-vat-handling-for-edd/e2e/run-queue.php' ) ).toContain( '@@1@@' );
}

async function login( page: Page ) {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await Promise.all( [ page.waitForURL( ( url ) => ! url.pathname.includes( 'wp-login' ) ), page.locator( '#wp-submit' ).click() ] );
}

test.describe( 'Barn2 import', () => {
	test.afterAll( () => reset() );

	test( 'the CLI dry run and run report the same counts, and a second run writes nothing', () => {
		seed();
		const dry = wp( 'eu-vat-handling-for-edd', 'import-barn2', '--history-only', '--dry-run', '--since=2025-01-01' );
		const wouldWrite = dry.match( /(\d+) orders \((\d+) with Barn2 data, (\d+) without\) would write (\d+) ledger rows/ );
		expect( wouldWrite, dry ).not.toBeNull();
		expect( ledgerRows() ).toBe( 0 );
		expect( wouldWrite![ 1 ] ).toBe( '4' );
		expect( wouldWrite![ 2 ] ).toBe( '3' );

		const run = wp( 'eu-vat-handling-for-edd', 'import-barn2', '--history-only', '--since=2025-01-01' );
		expect( run ).toContain( `Imported ${ wouldWrite![ 1 ] } orders and ${ wouldWrite![ 4 ] } ledger rows; 0 failed.` );
		expect( ledgerRows() ).toBe( Number( wouldWrite![ 4 ] ) );

		const again = wp( 'eu-vat-handling-for-edd', 'import-barn2', '--history-only', '--since=2025-01-01' );
		expect( again ).toContain( 'Imported 0 orders and 0 ledger rows; 0 failed.' );
		expect( ledgerRows() ).toBe( Number( wouldWrite![ 4 ] ) );
	} );

	test( 'the CLI settings import names fields and never prints a value', () => {
		seed();
		const out = wp( 'eu-vat-handling-for-edd', 'import-barn2', '--settings-only' );
		expect( out ).toContain( 'edd_vat_hmrc_client_secret: imported' );
		expect( out ).toContain( 'edd_vat_vatsense_api_key: skipped' );
		for ( const secret of SECRETS ) {
			expect( out ).not.toContain( secret );
		}
	} );

	test( 'the screen offers the import, previews it, runs it and asks to deactivate Barn2', async ( { page } ) => {
		seed();
		await login( page );

		await page.goto( '/wp-admin/index.php' );
		const offer = page.locator( '.notice-info', { hasText: 'Barn2 EDD EU VAT is active' } );
		await expect( offer ).toBeVisible();
		await Promise.all( [ page.waitForURL( /vatright-edd-import/ ), offer.getByRole( 'link', { name: 'Import from Barn2' } ).click() ] );

		await page.locator( '#vatright-edd-since' ).selectOption( '2025-01-01' );
		await Promise.all( [ page.waitForURL( /vatright-edd-import/ ), page.getByRole( 'button', { name: 'Dry run' } ).click() ] );
		await expect( page.getByText( '4 orders from 2025-01-01 (3 with Barn2 data, 1 without) would write' ) ).toBeVisible();
		await expect( page.locator( 'table tbody tr', { hasText: '2025-Q3' } ) ).toBeVisible();
		await expect( page.locator( 'table tbody tr', { hasText: '2025-Q4' } ) ).toBeVisible();
		expect( ledgerRows() ).toBe( 0 );

		await page.locator( '#vatright-edd-since' ).selectOption( '2025-01-01' );
		await Promise.all( [ page.waitForURL( /vatright-edd-import/ ), page.getByRole( 'button', { name: 'Import', exact: true } ).click() ] );
		await expect( page.getByText( 'History import running' ) ).toBeVisible();

		runQueue();
		await page.reload();
		await expect( page.getByText( 'History import finished: 4 orders imported' ) ).toBeVisible();
		await expect( page.getByText( 'Deactivate Barn2 EDD EU VAT now' ) ).toBeVisible();
		await expect( page.locator( 'table', { hasText: 'edd_vat_hmrc_client_secret' } ) ).toContainText( 'imported' );
		expect( ledgerRows() ).toBeGreaterThan( 0 );

		const html = await page.content();
		for ( const secret of SECRETS ) {
			expect( html ).not.toContain( secret );
		}
	} );

	test( 'after Barn2 is gone the import remains available and the notice disappears', async ( { page } ) => {
		seed();
		wpEval( 'delete_option( "vatright_edd_e2e_barn2" ); echo "@@1@@";' );
		await login( page );

		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '.notice-info', { hasText: 'Barn2 EDD EU VAT is active' } ) ).toHaveCount( 0 );
		await page.goto( IMPORT_PAGE );
		await expect( page.getByRole( 'heading', { name: 'Import from Barn2 EDD EU VAT' } ) ).toBeVisible();
	} );
} );
