<?php

namespace VatrightEdd\Orders;

use EDD\Orders\Order;
use VatrightEdd\Domain\Rule;
use VatrightEdd\Domain\ValidationResult;

/**
 * The "EU VAT" section on the order screen: what was decided and why, and a
 * button that asks the registry again. Everything is escaped on output; the
 * registry's name and address and the customer's number are third-party data.
 */
class OrderDetails {

	const SECTION = 'vatright_edd';

	const ACTION = 'vatright_edd_recheck';

	const CAPABILITY = 'edit_shop_payments';

	/** @var OrderFinaliser */
	private $finaliser;

	public function __construct( OrderFinaliser $finaliser ) {
		$this->finaliser = $finaliser;
	}

	public function register(): void {
		add_filter( 'edd_get_order_details_sections', array( $this, 'add_section' ), 10, 2 );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax_recheck' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * @param mixed $sections
	 * @param mixed $order
	 * @return array<int,array<string,mixed>>
	 */
	public function add_section( $sections, $order ): array {
		$sections = is_array( $sections ) ? $sections : array();
		if ( ! $order instanceof Order || empty( $order->id ) || ( function_exists( 'edd_is_add_order_page' ) && edd_is_add_order_page() ) ) {
			return $sections;
		}
		$sections[] = array(
			'id'       => self::SECTION,
			'label'    => __( 'EU VAT', 'eu-vat-handling-for-edd' ),
			'icon'     => 'money-alt',
			'callback' => array( $this, 'render' ),
		);
		return $sections;
	}

	public function enqueue( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks the screen.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		if ( 'view-order-details' !== $view || false === strpos( $hook, 'edd-payment-history' ) ) {
			return;
		}
		$root = dirname( __DIR__, 2 );
		wp_enqueue_script( 'vatright-edd-order', plugins_url( 'assets/order.js', $root . '/eu-vat-handling-for-edd.php' ), array( 'jquery' ), (string) filemtime( $root . '/assets/order.js' ), true );
	}

	/**
	 * @param Order $order
	 */
	public function render( $order ): void {
		$order_id = (int) $order->id;
		$decision = DecisionStore::get( $order_id );
		if ( null === $decision ) {
			echo '<p>' . esc_html__( 'No VAT decision is recorded for this order.', 'eu-vat-handling-for-edd' ) . '</p>';
			return;
		}
		$record = $decision->to_array();

		echo '<p><strong>' . esc_html( self::headline( $record ) ) . '</strong></p>';
		$this->render_flags( $record['flags'] );
		$this->render_inputs( $record );
		$this->render_recheck( $order_id, (string) ( $record['vat_number']['canonical'] ?? '' ) );
		$this->render_log( DecisionStore::log( $order_id ) );
	}

	public function ajax_recheck(): void {
		check_ajax_referer( self::ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'eu-vat-handling-for-edd' ) ), 403 );
		}
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$number   = isset( $_POST['vat_number'] ) ? sanitize_text_field( wp_unslash( $_POST['vat_number'] ) ) : '';

		$decision = $this->finaliser->recheck( $order_id, $number, get_current_user_id() );
		if ( null === $decision ) {
			wp_send_json_error( array( 'message' => __( 'This order cannot carry a VAT decision.', 'eu-vat-handling-for-edd' ) ), 404 );
		}
		wp_send_json_success( array( 'headline' => self::headline( $decision->to_array() ) ) );
	}

	/**
	 * @param string[] $flags
	 */
	private function render_flags( array $flags ): void {
		if ( array() === $flags ) {
			return;
		}
		echo '<ul>';
		foreach ( $flags as $flag ) {
			$class = in_array( $flag, array( OrderFinaliser::TAX_DIFFERS_FLAG, 'validation_pending' ), true ) ? 'notice notice-warning inline' : '';
			echo '<li class="' . esc_attr( $class ) . '"><span>' . esc_html( self::flag_text( $flag ) ) . '</span></li>';
		}
		echo '</ul>';
	}

	/**
	 * @param array<string,mixed> $record
	 */
	private function render_inputs( array $record ): void {
		$validation = (array) ( $record['validation'] ?? array() );
		$evidence   = (array) $record['evidence'];
		$customer   = (array) $record['customer'];

		$rows = array(
			__( 'Billing country', 'eu-vat-handling-for-edd' ) => trim( $customer['billing']['country'] . ' ' . $customer['billing']['postcode'] ),
			__( 'VAT number as typed', 'eu-vat-handling-for-edd' ) => (string) ( $record['vat_number']['raw'] ?? '' ),
			__( 'VAT number', 'eu-vat-handling-for-edd' ) => (string) ( $record['vat_number']['canonical'] ?? '' ),
			__( 'Registry answer', 'eu-vat-handling-for-edd' ) => (string) ( $validation['status'] ?? '' ),
			__( 'Registry', 'eu-vat-handling-for-edd' )   => (string) ( $validation['registry'] ?? '' ),
			__( 'Registered name', 'eu-vat-handling-for-edd' ) => (string) ( $validation['name'] ?? '' ),
			__( 'Registered address', 'eu-vat-handling-for-edd' ) => (string) ( $validation['address'] ?? '' ),
			__( 'Consultation reference', 'eu-vat-handling-for-edd' ) => (string) ( $validation['consultation'] ?? '' ),
			__( 'Checked at', 'eu-vat-handling-for-edd' ) => (string) ( $validation['checked_at'] ?? '' ),
			__( 'Location evidence', 'eu-vat-handling-for-edd' ) => implode(
				', ',
				array_filter(
					array(
						'' !== (string) ( $evidence['billing'] ?? '' ) ? 'billing ' . $evidence['billing'] : '',
						'' !== (string) ( $evidence['ip'] ?? '' ) ? 'IP ' . $evidence['ip'] : '',
						'' !== (string) ( $evidence['self_certified'] ?? '' ) ? 'self-certified ' . $evidence['self_certified'] : '',
					)
				)
			),
			__( 'Decided at', 'eu-vat-handling-for-edd' ) => (string) $record['at'],
		);

		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			if ( '' !== trim( $value ) ) {
				echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $value ) ) . '</td></tr>';
			}
		}
		foreach ( (array) $record['lines'] as $line ) {
			echo '<tr><th scope="row">' . esc_html__( 'Line', 'eu-vat-handling-for-edd' ) . '</th><td>' . esc_html(
				sprintf(
					/* translators: 1: place of supply country, 2: country whose VAT rate applies, 3: scheme */
					__( 'Supplied in %1$s, VAT of %2$s, %3$s', 'eu-vat-handling-for-edd' ),
					$line['place_of_supply'],
					$line['tax_country'],
					$line['scheme']
				)
			) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_recheck( int $order_id, string $number ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		?>
		<p class="vatright-edd-recheck" data-order="<?php echo esc_attr( (string) $order_id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::ACTION ) ); ?>" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<label for="vatright-edd-recheck-number"><?php esc_html_e( 'VAT number', 'eu-vat-handling-for-edd' ); ?></label>
			<input type="text" id="vatright-edd-recheck-number" class="regular-text" value="<?php echo esc_attr( $number ); ?>" autocomplete="off" />
			<button type="button" class="button" id="vatright-edd-recheck"><?php esc_html_e( 'Check again', 'eu-vat-handling-for-edd' ); ?></button>
			<span class="vatright-edd-recheck__result" aria-live="polite"></span>
		</p>
		<p class="description"><?php esc_html_e( 'Asks the tax registry now and records the answer. The amounts on the order do not change: refund through EDD if VAT should come back.', 'eu-vat-handling-for-edd' ); ?></p>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $log
	 */
	private function render_log( array $log ): void {
		if ( array() === $log ) {
			return;
		}
		echo '<h4>' . esc_html__( 'Decision log', 'eu-vat-handling-for-edd' ) . '</h4>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'When (UTC)', 'eu-vat-handling-for-edd' ), __( 'Trigger', 'eu-vat-handling-for-edd' ), __( 'User', 'eu-vat-handling-for-edd' ), __( 'Rule', 'eu-vat-handling-for-edd' ), __( 'Flags', 'eu-vat-handling-for-edd' ) ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $log as $entry ) {
			$user = ! empty( $entry['actor_user_id'] ) ? get_userdata( (int) $entry['actor_user_id'] ) : false;
			echo '<tr><td>' . esc_html( str_replace( array( 'T', 'Z' ), array( ' ', '' ), (string) ( $entry['at'] ?? '' ) ) ) . '</td><td>' . esc_html( (string) ( $entry['trigger'] ?? '' ) ) . '</td><td>' . esc_html( $user ? $user->display_name : '' ) . '</td><td>' . esc_html( self::rule_text( (string) ( $entry['rule'] ?? '' ) ) ) . '</td><td>' . esc_html( implode( ', ', (array) ( $entry['flags'] ?? array() ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function rule_text( string $rule ): string {
		switch ( $rule ) {
			case Rule::EXPORT_NON_EU:
				return __( 'Customer outside the EU: no EU VAT.', 'eu-vat-handling-for-edd' );
			case Rule::OUTSIDE_TERRITORY:
				return __( 'Customer in a territory outside the EU VAT area: no EU VAT.', 'eu-vat-handling-for-edd' );
			case Rule::DOMESTIC:
				return __( 'Domestic sale: VAT of the store country.', 'eu-vat-handling-for-edd' );
			case Rule::DOMESTIC_B2B_GUARD:
				return __( 'Domestic business customer: VAT charged, a number from the store country never exempts.', 'eu-vat-handling-for-edd' );
			case Rule::DOMESTIC_B2B_ALLOWED:
				return __( 'Domestic business customer with a valid VAT number: exempt, as allowed in the settings.', 'eu-vat-handling-for-edd' );
			case Rule::RC_GOODS_ART138:
				return __( 'Goods to a business in another EU country with a valid VAT number: exempt intra-Community supply (Article 138).', 'eu-vat-handling-for-edd' );
			case Rule::RC_SERVICES_ART196:
				return __( 'Digital sale to a business in another EU country with a valid VAT number: reverse charge (Article 196).', 'eu-vat-handling-for-edd' );
			case Rule::B2C_ORIGIN_BELOW_THRESHOLD:
				return __( 'Consumer in another EU country, below the distance-selling threshold: VAT of the store country.', 'eu-vat-handling-for-edd' );
			case Rule::B2C_DESTINATION:
				return __( 'Consumer in another EU country: VAT of the destination country.', 'eu-vat-handling-for-edd' );
			case Rule::B2C_SERVICE_ORIGIN:
				return __( 'Sale to a consumer: VAT of the store country.', 'eu-vat-handling-for-edd' );
			case Rule::PREFIX_MISMATCH_CHARGED:
				return __( 'The VAT number belongs to a different country than the billing address: VAT charged.', 'eu-vat-handling-for-edd' );
			case Rule::PENDING_CHARGED:
				return __( 'The VAT number could not be checked: VAT charged until it is.', 'eu-vat-handling-for-edd' );
			case Rule::PENDING_EXEMPT:
				return __( 'The VAT number could not be checked: exempt until it is.', 'eu-vat-handling-for-edd' );
			case Rule::PENDING_REJECTED:
				return __( 'The VAT number could not be checked: the order was refused.', 'eu-vat-handling-for-edd' );
			case Rule::INVALID_NUMBER:
				return __( 'The VAT number is not valid: VAT charged.', 'eu-vat-handling-for-edd' );
			case Rule::NON_EU_NUMBER_RECORDED:
				return __( 'Non-EU VAT number recorded: no EU VAT.', 'eu-vat-handling-for-edd' );
			default:
				return $rule;
		}
	}

	/**
	 * A re-check keeps the rule the order was decided under and records the
	 * registry's answer, so a pending rule is reported with that answer.
	 *
	 * @param array<string,mixed> $record
	 */
	public static function headline( array $record ): string {
		$rule   = (string) $record['rule'];
		$status = (string) ( $record['validation']['status'] ?? '' );
		if ( ! in_array( $rule, array( Rule::PENDING_CHARGED, Rule::PENDING_EXEMPT ), true ) ) {
			return self::rule_text( $rule );
		}
		if ( ValidationResult::VALID === $status ) {
			return Rule::PENDING_CHARGED === $rule
				? __( 'The VAT number was checked later and is valid. VAT was charged at checkout.', 'eu-vat-handling-for-edd' )
				: __( 'The VAT number was checked later and is valid: exempt.', 'eu-vat-handling-for-edd' );
		}
		return self::rule_text( $rule );
	}

	public static function flag_text( string $flag ): string {
		switch ( $flag ) {
			case 'validation_pending':
				return __( 'Validation pending: the VAT number is checked again later.', 'eu-vat-handling-for-edd' );
			case 'validated_after_outage':
				return __( 'The VAT number was valid when checked later. VAT was charged at checkout, so a refund may be due.', 'eu-vat-handling-for-edd' );
			case 'invalid_after_exemption':
				return __( 'The VAT number was reported invalid when checked later. No VAT was charged, so VAT may be due.', 'eu-vat-handling-for-edd' );
			case 'evidence_conflict_self_certified':
				return __( 'The location evidence conflicted and the customer self-certified their country.', 'eu-vat-handling-for-edd' );
			case 'single_evidence':
				return __( 'Only one piece of location evidence was available.', 'eu-vat-handling-for-edd' );
			case 'prefix_mismatch':
				return __( 'The VAT number prefix does not match the billing country.', 'eu-vat-handling-for-edd' );
			case 'rate_missing':
				return __( 'No tax rate was set for the destination, the store rate was charged.', 'eu-vat-handling-for-edd' );
			case OrderFinaliser::TAX_DIFFERS_FLAG:
				return __( 'The tax charged on this order does not match the VAT decision. Check whether VAT should be refunded or collected.', 'eu-vat-handling-for-edd' );
			case OrderFinaliser::NO_ADDRESS_FLAG:
				return __( 'The order has no billing country, so the decision rests on the store country.', 'eu-vat-handling-for-edd' );
			case OrderFinaliser::MANUAL:
				return __( 'Added by hand: the tax was set by the person who created the order.', 'eu-vat-handling-for-edd' );
			default:
				return $flag;
		}
	}
}
