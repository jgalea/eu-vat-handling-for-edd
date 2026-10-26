<?php

namespace VatrightEdd\Tests\Support;

use VatrightEdd\Checkout\CartState;
use VatrightEdd\Checkout\Decider;
use VatrightEdd\Checkout\ErrorChecks;
use VatrightEdd\Checkout\InputGatherer;
use VatrightEdd\Checkout\TaxApplier;
use VatrightEdd\Checkout\VatField;
use VatrightEdd\Domain\Rules;
use VatrightEdd\Ledger\Fx;
use VatrightEdd\Ledger\ThresholdTracker;
use VatrightEdd\Orders\OrderFinaliser;
use VatrightEdd\Rates\RateResolver;
use VatrightEdd\Rates\RateSync;
use VatrightEdd\Rates\Tedb;
use VatrightEdd\Registry\Router;
use VatrightEdd\Registry\ValidationCache;
use VatrightEdd\Registry\Validator;
use VatrightEdd\Registry\Vies;
use VatrightEdd\Settings;

/**
 * The checkout objects the plugin booted with, replaced by a set the test can
 * reach and whose VIES answers it controls. WP_UnitTestCase restores the
 * booted hooks after each test.
 */
class CheckoutHarness {

	/** @var CartState */
	public $state;

	/** @var Decider */
	public $decider;

	/** @var VatField */
	public $field;

	/** @var ErrorChecks */
	public $errors;

	/** @var OrderFinaliser */
	public $finaliser;

	/** @var ViesStub */
	public $vies;

	public static function install( ViesStub $vies, ?Fx $fx = null ): self {
		self::unhook_booted();

		$harness       = new self();
		$harness->vies = $vies;
		$settings      = Settings::get();
		$validator     = new Validator( new Router( new Vies( $vies ) ), new ValidationCache( $settings ), $settings );
		$rates         = new RateResolver( new RateSync( new Tedb( new FixtureTransport( sys_get_temp_dir() ) ) ) );

		$harness->state   = new CartState();
		$gatherer         = new InputGatherer( $validator, new ThresholdTracker(), $fx ?? new Fx( new FixtureTransport( sys_get_temp_dir() ) ), $harness->state, $rates );
		$harness->decider = new Decider( $gatherer, new Rules() );
		$harness->field   = new VatField( $validator, $harness->state, $harness->decider );
		$harness->errors  = new ErrorChecks( $harness->decider, $harness->field );

		$harness->state->register();
		$harness->decider->register();
		$harness->field->register();
		( new TaxApplier( $harness->decider, $rates ) )->register();
		$harness->errors->register();
		$harness->finaliser = new OrderFinaliser( $gatherer, new Rules(), $harness->state, $harness->decider );
		$harness->finaliser->register();

		edd_empty_cart();
		$harness->state->clear();
		$harness->decider->reset();
		return $harness;
	}

	private static function unhook_booted(): void {
		global $wp_filter;
		foreach ( $wp_filter as $hook => $filter ) {
			foreach ( $filter->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( is_array( $function ) && is_object( $function[0] ) && 1 === preg_match( '/^VatrightEdd\\\\(Checkout|Orders|Integrations|Ledger)\\\\/', get_class( $function[0] ) ) ) {
						remove_filter( $hook, $function, $priority );
					}
				}
			}
		}
	}
}
