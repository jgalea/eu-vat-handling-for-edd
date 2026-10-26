<?php

namespace VatrightEdd\Orders;

use Throwable;
use VatrightEdd\Domain\Decision;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Domain\VatNumber;
use VatrightEdd\Registry\Validator;
use VatrightEdd\Scheduler;
use VatrightEdd\Settings;

/**
 * Settles orders placed while the registry was down. A later answer never
 * changes the order's totals: the merchant is told and decides on a refund or
 * a corrected invoice.
 */
class Revalidation {

	const HOOK = 'vatright_edd_revalidate';

	const PENDING_FLAG = 'validation_pending';

	const TRIGGER = 'revalidation';

	/**
	 * Wait before attempt n (1-based), counted from the previous attempt, or
	 * from the order for the first one.
	 */
	const DELAYS = array( 15 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS, 3 * DAY_IN_SECONDS );

	const VALID       = 'valid';
	const INVALID     = 'invalid';
	const UNAVAILABLE = 'unavailable';
	const NONE        = 'none';

	/** @var Validator */
	private $validator;

	/** @var RevalidatedEmail */
	private $email;

	/** @var VatRefund|null */
	private $refund;

	public function __construct( Validator $validator, ?RevalidatedEmail $email = null, ?VatRefund $refund = null ) {
		$this->validator = $validator;
		$this->email     = $email ?? new RevalidatedEmail();
		$this->refund    = $refund;
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ), 10, 2 );
		add_action( 'edd_built_order', array( $this, 'schedule' ), 20, 1 );
	}

	/**
	 * Queues the first re-check for an order whose decision is waiting on the
	 * registry. Runs after the order's own decision is stored.
	 *
	 * @param mixed $order_id
	 */
	public function schedule( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id > 0 && $this->is_pending( $order_id ) ) {
			Scheduler::once( self::HOOK, array( $order_id, 1 ), self::DELAYS[0] );
		}
	}

	public function run( int $order_id, int $attempt ): void {
		if ( ! $this->is_pending( $order_id ) ) {
			return;
		}
		if ( self::UNAVAILABLE !== $this->recheck( $order_id, self::TRIGGER ) ) {
			return;
		}
		if ( isset( self::DELAYS[ $attempt ] ) ) {
			Scheduler::once( self::HOOK, array( $order_id, $attempt + 1 ), self::DELAYS[ $attempt ] );
			return;
		}
		edd_insert_payment_note( $order_id, __( 'The VAT number could not be checked after repeated attempts. Check it by hand from the EU VAT box.', 'eu-vat-handling-for-edd' ) );
	}

	/**
	 * Asks the registry again, bypassing the cache, and settles the order's
	 * record. Returns what the registry said.
	 */
	public function recheck( int $order_id, string $trigger, int $actor = 0 ): string {
		$decision = DecisionStore::get( $order_id );
		if ( null === $decision ) {
			return self::NONE;
		}
		$canonical = (string) ( $decision->to_array()['vat_number']['canonical'] ?? '' );
		$number    = '' === $canonical ? null : VatNumber::parse( $canonical, '' );
		if ( null === $number ) {
			return self::NONE;
		}

		try {
			$result = $this->validator->validate( $number, true, true );
		} catch ( Throwable $e ) {
			edd_debug_log( 'EU VAT Handling: revalidation failed for order ' . $order_id . ': ' . $e->getMessage() );
			return self::UNAVAILABLE;
		}

		$status = $result->status();
		if ( ValidationResult::VALID === $status ) {
			$this->settle_valid( $order_id, $decision, $result, $trigger, $actor );
			return self::VALID;
		}
		if ( ValidationResult::INVALID === $status || ValidationResult::FORMAT_INVALID === $status ) {
			$this->settle_invalid( $order_id, $decision, $result, $trigger, $actor );
			return self::INVALID;
		}

		edd_insert_payment_note( $order_id, __( 'VAT number re-check: the registry is still unavailable.', 'eu-vat-handling-for-edd' ) );
		return self::UNAVAILABLE;
	}

	private function is_pending( int $order_id ): bool {
		$decision = DecisionStore::get( $order_id );
		return null !== $decision && in_array( self::PENDING_FLAG, $decision->flags(), true );
	}

	private function settle_valid( int $order_id, Decision $decision, ValidationResult $result, string $trigger, int $actor ): void {
		$refund_due = in_array( self::PENDING_FLAG, $decision->flags(), true ) && ! $decision->is_vat_exempt();
		DecisionStore::apply( $order_id, $this->patched( $decision, $result, $trigger, $refund_due ? 'validated_after_outage' : null ), $trigger, $actor );

		if ( ! $refund_due ) {
			edd_insert_payment_note( $order_id, __( 'VAT number re-check: the registry confirms the number is valid.', 'eu-vat-handling-for-edd' ) );
			return;
		}
		edd_insert_payment_note( $order_id, __( 'VAT was charged because the registry was unavailable. The number is valid, so the customer may be due a refund of the VAT.', 'eu-vat-handling-for-edd' ) );
		$outcome = null === $this->refund ? VatRefund::OFF : $this->refund->refund_if_enabled( $order_id );
		if ( VatRefund::REFUNDED === $outcome ) {
			$this->notify( $order_id, RevalidatedEmail::REFUNDED );
		} elseif ( ! in_array( $outcome, array( VatRefund::ALREADY, VatRefund::BUSY ), true ) ) {
			$this->notify( $order_id, RevalidatedEmail::REFUND );
		}
	}

	private function settle_invalid( int $order_id, Decision $decision, ValidationResult $result, string $trigger, int $actor ): void {
		$unfounded = $this->is_zero_rated( $decision );
		DecisionStore::apply( $order_id, $this->patched( $decision, $result, $trigger, $unfounded ? 'invalid_after_exemption' : null ), $trigger, $actor );

		if ( ! $unfounded ) {
			edd_insert_payment_note( $order_id, __( 'VAT number re-check: the registry reports the number as invalid. VAT stays charged.', 'eu-vat-handling-for-edd' ) );
			return;
		}
		edd_insert_payment_note( $order_id, __( 'VAT was not charged on this order, but the registry reports the VAT number as invalid. VAT may be due.', 'eu-vat-handling-for-edd' ) );
		$this->notify( $order_id, RevalidatedEmail::INVALID );
	}

	private function is_zero_rated( Decision $decision ): bool {
		if ( $decision->is_vat_exempt() ) {
			return true;
		}
		foreach ( $decision->lines() as $line ) {
			if ( in_array( $line->scheme(), array( Scheme::REVERSE_CHARGE_SERVICES, Scheme::INTRA_EU_GOODS ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The same tax outcome with the registry's answer recorded, as a new
	 * decision so the log shows the change.
	 */
	private function patched( Decision $decision, ValidationResult $result, string $trigger, ?string $flag ): Decision {
		$record               = $decision->to_array();
		$record['id']         = wp_generate_uuid4();
		$record['at']         = gmdate( 'Y-m-d\TH:i:s\Z' );
		$record['trigger']    = $trigger;
		$record['validation'] = $result->to_array();
		$record['flags']      = array_values( array_diff( $record['flags'], array( self::PENDING_FLAG ) ) );
		if ( null !== $flag && ! in_array( $flag, $record['flags'], true ) ) {
			$record['flags'][] = $flag;
		}
		return Decision::from_array( $record );
	}

	private function notify( int $order_id, string $kind ): void {
		if ( Settings::get()->notify_admin_on_revalidation() ) {
			$this->email->send( $order_id, $kind );
		}
	}
}
