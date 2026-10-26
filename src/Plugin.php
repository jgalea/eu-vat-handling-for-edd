<?php

namespace VatrightEdd;

use VatrightEdd\Admin\DownloadVatCategory;
use VatrightEdd\Admin\SettingsSection;
use VatrightEdd\Admin\SetupCheck;
use VatrightEdd\Checkout\CartState;
use VatrightEdd\Checkout\Decider;
use VatrightEdd\Checkout\ErrorChecks;
use VatrightEdd\Checkout\InputGatherer;
use VatrightEdd\Checkout\TaxApplier;
use VatrightEdd\Checkout\VatField;
use VatrightEdd\Domain\Rules;
use VatrightEdd\Integrations\EddInvoices;
use VatrightEdd\Import\Barn2History;
use VatrightEdd\Import\Barn2Settings;
use VatrightEdd\Import\ImportRunner;
use VatrightEdd\Import\Importer;
use VatrightEdd\Import\ImportScreen;
use VatrightEdd\Integrations\EddPdfInvoices;
use VatrightEdd\Integrations\Recurring;
use VatrightEdd\Ledger\Backfill;
use VatrightEdd\Ledger\Fx;
use VatrightEdd\Ledger\LedgerWriter;
use VatrightEdd\Ledger\Schema;
use VatrightEdd\Ledger\ThresholdTracker;
use VatrightEdd\Orders\InvoiceText;
use VatrightEdd\Orders\OrderDetails;
use VatrightEdd\Orders\OrderFinaliser;
use VatrightEdd\Orders\Revalidation;
use VatrightEdd\Privacy\Privacy;
use VatrightEdd\Privacy\Retention;
use VatrightEdd\Rates\RateResolver;
use VatrightEdd\Rates\RateSync;
use VatrightEdd\Rates\RatesScreen;
use VatrightEdd\Rates\Tedb;
use VatrightEdd\Registry\Brreg;
use VatrightEdd\Registry\Hmrc;
use VatrightEdd\Registry\Router;
use VatrightEdd\Registry\SwissUid;
use VatrightEdd\Registry\ValidationCache;
use VatrightEdd\Registry\Validator;
use VatrightEdd\Registry\Vies;
use VatrightEdd\Registry\WpHttpTransport;
use VatrightEdd\Reports\EcSalesList;
use VatrightEdd\Reports\OssReport;
use VatrightEdd\Reports\ReportsScreen;
use VatrightEdd\Reports\RestController;

class Plugin {

	public static function boot(): void {
		if ( ! class_exists( 'Easy_Digital_Downloads' ) ) {
			add_action( 'admin_notices', array( self::class, 'missing_edd_notice' ) );
			return;
		}

		$blocker = Coexistence::blocker();
		if ( 'barn2' === $blocker ) {
			add_action( 'admin_notices', array( Coexistence::class, 'notice' ) );
			self::boot_passive_import();
			return;
		}
		if ( 'edd_vat' === $blocker ) {
			add_action( 'admin_notices', array( Coexistence::class, 'edd_vat_notice' ) );
			return;
		}

		Scheduler::register();
		add_action( 'init', array( Schema::class, 'upgrade' ), 1 );

		$transport = new WpHttpTransport();
		$sync      = new RateSync( new Tedb( $transport ) );
		$sync->register();

		$settings  = Settings::get();
		$cache     = new ValidationCache( $settings );
		$validator = new Validator(
			new Router(
				new Vies( $transport ),
				new Hmrc( $transport, $settings->hmrc_client_id(), $settings->hmrc_client_secret() ),
				new SwissUid( $transport ),
				new Brreg( $transport )
			),
			$cache,
			$settings
		);

		$rates    = new RateResolver( $sync );
		$state    = new CartState();
		$gatherer = new InputGatherer( $validator, new ThresholdTracker(), new Fx( $transport ), $state, $rates );
		$decider  = new Decider( $gatherer, new Rules() );
		$field    = new VatField( $validator, $state, $decider );
		$state->register();
		$decider->register();
		$field->register();
		( new TaxApplier( $decider, $rates ) )->register();
		( new ErrorChecks( $decider, $field ) )->register();

		$ledger   = new LedgerWriter( new Fx( $transport ), $rates );
		$backfill = new Backfill( $ledger, $rates );
		$ledger->register();
		$backfill->register();
		self::wire_import( $backfill, $ledger, new Fx( $transport ) );

		$finaliser = new OrderFinaliser( $gatherer, new Rules(), $state, $decider );
		$finaliser->register();
		$revalidation = new Revalidation( $validator );
		$revalidation->register();
		( new Recurring( $gatherer, new Rules(), $rates, $ledger, $backfill, $decider, Recurring::is_installed() ) )->register();
		// Renewals are decided after edd_built_order, so their pending numbers are queued here.
		add_action(
			'edd_recurring_add_subscription_order',
			static function ( $order ) use ( $revalidation ): void {
				if ( is_object( $order ) && isset( $order->id ) ) {
					$revalidation->schedule( $order->id );
				}
			},
			20
		);
		( new Privacy( $cache ) )->register();
		( new Retention() )->register();
		( new InvoiceText() )->register();
		( new EddInvoices() )->register();
		( new EddPdfInvoices() )->register();

		$esl = new EcSalesList();
		$oss = new OssReport( new Fx( $transport ) );
		( new RestController( $oss, $esl ) )->register();

		if ( is_admin() ) {
			( new ReportsScreen( $oss, $esl ) )->register();
			( new OrderDetails( $finaliser ) )->register();
			( new RatesScreen( $sync ) )->register();
			( new SettingsSection( new SetupCheck() ) )->register();
			( new DownloadVatCategory() )->register();
		}
	}

	/**
	 * With Barn2 active only the import runs: no checkout, tax or order hooks.
	 */
	private static function boot_passive_import(): void {
		add_action( 'init', array( Schema::class, 'upgrade' ), 1 );

		$transport = new WpHttpTransport();
		$rates     = new RateResolver( new RateSync( new Tedb( $transport ) ) );
		$ledger    = new LedgerWriter( new Fx( $transport ), $rates );
		self::wire_import( new Backfill( $ledger, $rates ), $ledger, new Fx( $transport ) );
	}

	private static function wire_import( Backfill $backfill, LedgerWriter $ledger, Fx $fx ): void {
		$settings = new Barn2Settings();
		$importer = new Importer( $backfill, new Barn2History( $backfill ), $ledger, $fx );
		$importer->register();
		( new ImportRunner( $settings, $importer ) )->register();
		if ( is_admin() ) {
			( new ImportScreen( $settings, $importer ) )->register();
		}
	}

	public static function activate(): void {
		Schema::install();
		if ( class_exists( 'Easy_Digital_Downloads' ) && null === Coexistence::blocker() ) {
			Backfill::schedule();
		}
	}

	public static function missing_edd_notice(): void {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'EU VAT Handling for EDD requires Easy Digital Downloads to be active.', 'eu-vat-handling-for-edd' ) . '</p></div>';
	}
}
