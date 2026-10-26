<?php

namespace VatrightEdd\Orders;

use VatrightEdd\Checkout\TaxApplier;
use VatrightEdd\Domain\Rule;
use VatrightEdd\Domain\Scheme;
use VatrightEdd\Domain\ValidationResult;
use VatrightEdd\Integrations\Recurring;
use VatrightEdd\Ledger\Backfill;

/**
 * A VAT column and a status filter on EDD's Orders list, read from the
 * decision stored on each order.
 */
class OrdersColumn {

	const KEY = 'vatright_edd_vat';

	const FILTER = 'vatright_edd_vat_filter';

	const PENDING = 'pending';

	const FLAGGED = 'flagged';

	/**
	 * The flags an admin may have to act on. The rest are record-keeping
	 * (single_evidence is normal for most consumer orders, a self-certified
	 * conflict, a hand-entered or hand-corrected order and a finished refund
	 * are done) or informational (prefix_mismatch already charged VAT).
	 */
	const ACTION_FLAGS = array(
		'validation_pending',
		'validated_after_outage',
		'invalid_after_exemption',
		OrderFinaliser::TAX_DIFFERS_FLAG,
		TaxApplier::MISSING_FLAG,
		OrderFinaliser::NO_ADDRESS_FLAG,
		OrderFinaliser::B2B_ONLY_FLAG,
		VatRefund::FLAG_SKIPPED,
		VatRefund::FLAG_FAILED,
		Recurring::RATE_CHANGED_FLAG,
		Recurring::TREATMENT_CHANGED_FLAG,
		Recurring::VAT_NOT_CHARGED_FLAG,
		Backfill::UNKNOWN_NUMBER_FLAG,
	);

	public function register(): void {
		add_filter( 'edd_payments_table_columns', array( $this, 'add_column' ) );
		add_filter( 'edd_payments_table_column', array( $this, 'render' ), 10, 3 );
		add_action( 'edd_payment_advanced_filters_after_fields', array( $this, 'render_dropdown' ) );
		add_filter( 'edd_payments_table_parse_args', array( $this, 'filter_args' ) );
	}

	/**
	 * @param mixed $columns
	 * @return array<string,string>
	 */
	public function add_column( $columns ): array {
		$columns = (array) $columns;
		$result  = array();
		foreach ( $columns as $key => $label ) {
			if ( 'amount' === $key ) {
				$result[ self::KEY ] = __( 'VAT', 'eu-vat-handling-for-edd' );
			}
			$result[ $key ] = $label;
		}
		if ( ! isset( $result[ self::KEY ] ) ) {
			$result[ self::KEY ] = __( 'VAT', 'eu-vat-handling-for-edd' );
		}
		return $result;
	}

	/**
	 * @param mixed $value
	 * @param mixed $order_id
	 * @param mixed $column
	 * @return mixed
	 */
	public function render( $value, $order_id = 0, $column = '' ) {
		if ( self::KEY !== $column ) {
			return $value;
		}
		$decision = DecisionStore::get( (int) $order_id );
		if ( null === $decision ) {
			return '';
		}
		$record = $decision->to_array();
		$flags  = (array) $record['flags'];
		$number = (string) ( $record['vat_number']['canonical'] ?? '' );

		$html = '<div class="vatright-edd-vat">';
		if ( '' !== $number ) {
			$html .= '<strong>' . esc_html( $number ) . '</strong> <span class="vatright-edd-vat-status">' . esc_html( $this->status( $record, $flags ) ) . '</span><br />';
		}
		$html      .= '<span class="description">' . esc_html( self::treatment( (string) $record['rule'], (string) $record['scheme'] ) ) . '</span>';
		$actionable = array_values( array_diff( array_intersect( $flags, self::ACTION_FLAGS ), array( 'validation_pending' ) ) );
		if ( array() !== $actionable && ! in_array( 'validation_pending', $flags, true ) ) {
			$titles = implode( ' ', array_map( array( OrderDetails::class, 'flag_text' ), $actionable ) );
			$html  .= ' <abbr class="vatright-edd-vat-flagged" title="' . esc_attr( $titles ) . '">' . esc_html__( 'Flagged', 'eu-vat-handling-for-edd' ) . '</abbr>';
		}
		return $html . '</div>';
	}

	public function render_dropdown(): void {
		$current = $this->selected_filter();
		echo '<label for="' . esc_attr( self::FILTER ) . '" class="screen-reader-text">' . esc_html__( 'VAT status', 'eu-vat-handling-for-edd' ) . '</label>';
		echo '<select id="' . esc_attr( self::FILTER ) . '" name="' . esc_attr( self::FILTER ) . '">';
		echo '<option value="">' . esc_html__( 'All VAT statuses', 'eu-vat-handling-for-edd' ) . '</option>';
		echo '<option value="' . esc_attr( self::PENDING ) . '" ' . selected( $current, self::PENDING, false ) . '>' . esc_html__( 'VAT pending validation', 'eu-vat-handling-for-edd' ) . '</option>';
		echo '<option value="' . esc_attr( self::FLAGGED ) . '" ' . selected( $current, self::FLAGGED, false ) . '>' . esc_html__( 'VAT flagged', 'eu-vat-handling-for-edd' ) . '</option>';
		echo '</select>';
	}

	/**
	 * @param mixed $args
	 * @return array<string,mixed>
	 */
	public function filter_args( $args ): array {
		$args  = (array) $args;
		$query = $this->meta_query();
		if ( array() !== $query ) {
			$args['meta_query'] = array_merge( (array) ( $args['meta_query'] ?? array() ), $query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		return $args;
	}

	public static function treatment( string $rule, string $scheme ): string {
		switch ( $rule ) {
			case Rule::RC_GOODS_ART138:
			case Rule::RC_SERVICES_ART196:
				return __( 'Reverse charge', 'eu-vat-handling-for-edd' );
			case Rule::B2C_DESTINATION:
				return __( 'OSS destination', 'eu-vat-handling-for-edd' );
			case Rule::B2C_ORIGIN_BELOW_THRESHOLD:
			case Rule::B2C_SERVICE_ORIGIN:
				return __( 'Origin', 'eu-vat-handling-for-edd' );
			case Rule::DOMESTIC:
			case Rule::DOMESTIC_B2B_GUARD:
			case Rule::DOMESTIC_B2B_ALLOWED:
			case Rule::LOCAL_PICKUP_DOMESTIC:
				return __( 'Domestic', 'eu-vat-handling-for-edd' );
			case Rule::EXPORT_NON_EU:
			case Rule::OUTSIDE_TERRITORY:
			case Rule::NON_EU_NUMBER_RECORDED:
				return __( 'No EU VAT', 'eu-vat-handling-for-edd' );
			case Rule::PENDING_EXEMPT:
				return __( 'Exempt', 'eu-vat-handling-for-edd' );
			case Rule::ROLE_EXEMPT:
				return __( 'Exempt by role', 'eu-vat-handling-for-edd' );
			case Rule::PENDING_CHARGED:
			case Rule::INVALID_NUMBER:
			case Rule::PREFIX_MISMATCH_CHARGED:
				return __( 'VAT charged', 'eu-vat-handling-for-edd' );
		}
		switch ( $scheme ) {
			case Scheme::OSS_DESTINATION:
				return __( 'OSS destination', 'eu-vat-handling-for-edd' );
			case Scheme::ORIGIN:
				return __( 'Origin', 'eu-vat-handling-for-edd' );
			case Scheme::DOMESTIC:
				return __( 'Domestic', 'eu-vat-handling-for-edd' );
			case Scheme::REVERSE_CHARGE_SERVICES:
			case Scheme::INTRA_EU_GOODS:
				return __( 'Reverse charge', 'eu-vat-handling-for-edd' );
		}
		return $rule;
	}

	/**
	 * @param array<string,mixed> $record
	 * @param string[]            $flags
	 */
	private function status( array $record, array $flags ): string {
		if ( in_array( 'validation_pending', $flags, true ) ) {
			return __( 'Pending validation', 'eu-vat-handling-for-edd' );
		}
		switch ( (string) ( $record['validation']['status'] ?? '' ) ) {
			case ValidationResult::VALID:
				return __( 'Valid', 'eu-vat-handling-for-edd' );
			case ValidationResult::INVALID:
			case ValidationResult::FORMAT_INVALID:
				return __( 'Invalid', 'eu-vat-handling-for-edd' );
		}
		return __( 'Not checked', 'eu-vat-handling-for-edd' );
	}

	private function selected_filter(): string {
		$value = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $value, array( self::PENDING, self::FLAGGED ), true ) ? $value : '';
	}

	/**
	 * Matches the JSON the decision is stored as; each flag is a quoted string in a list.
	 *
	 * @return array<int,array<int|string,mixed>>
	 */
	private function meta_query(): array {
		$filter = $this->selected_filter();
		if ( '' === $filter ) {
			return array();
		}
		$flags   = self::PENDING === $filter ? array( 'validation_pending' ) : self::ACTION_FLAGS;
		$clauses = array( 'relation' => 'OR' );
		foreach ( $flags as $flag ) {
			$clauses[] = array(
				'key'     => DecisionStore::META,
				'value'   => '"' . $flag . '"',
				'compare' => 'LIKE',
			);
		}
		return array( $clauses );
	}
}
