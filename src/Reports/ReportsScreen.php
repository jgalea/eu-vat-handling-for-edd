<?php

namespace VatrightEdd\Reports;

use DateTimeImmutable;

class ReportsScreen {

	const SLUG       = 'vatright-edd-reports';
	const CAPABILITY = 'view_shop_reports';
	const NONCE      = 'vatright_edd_export';

	/** @var OssReport */
	private $oss;

	/** @var EcSalesList */
	private $esl;

	public function __construct( OssReport $oss, EcSalesList $esl ) {
		$this->oss = $oss;
		$this->esl = $esl;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_filter( 'edd_settings_page_title', array( $this, 'page_title' ), 10, 2 );
		add_action( 'admin_post_vatright_edd_export_oss', array( $this, 'export_oss' ) );
		add_action( 'admin_post_vatright_edd_export_esl', array( $this, 'export_esl' ) );
	}

	public static function order_url( int $order_id ): string {
		return admin_url( 'edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=' . $order_id );
	}

	public function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=download',
			__( 'EU VAT reports', 'eu-vat-handling-for-edd' ),
			__( 'EU VAT reports', 'eu-vat-handling-for-edd' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * @param mixed $title
	 * @param mixed $current_page
	 * @return mixed
	 */
	public function page_title( $title, $current_page = '' ) {
		return self::SLUG === $current_page ? __( 'EU VAT reports', 'eu-vat-handling-for-edd' ) : $title;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$period    = $this->selection( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$missing   = array();
		$oss_error = '';
		try {
			$oss = $this->oss->build_for( $period );
		} catch ( \RuntimeException $e ) {
			$oss       = null;
			$oss_error = $e->getMessage();
		}
		$esl     = $this->esl_or_null( $period, $missing );
		$unknown = $this->esl->unknown_number_orders_for( $period );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EU VAT reports', 'eu-vat-handling-for-edd' ); ?></h1>
			<?php $this->period_form( $period ); ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: first day, 2: last day, both as Y-m-d. */
						__( 'Sales made from %1$s to %2$s.', 'eu-vat-handling-for-edd' ),
						$period->start(),
						$period->end()
					)
				);
				?>
			</p>

			<h2><?php esc_html_e( 'OSS return', 'eu-vat-handling-for-edd' ); ?></h2>
			<?php if ( null === $oss ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $oss_error ); ?></p></div>
			<?php else : ?>
				<p><?php echo esc_html( sprintf( /* translators: %s: conversion rule. */ __( 'Non-euro orders are converted at the %s.', 'eu-vat-handling-for-edd' ), $oss['rate_rule'] ) ); ?></p>
				<?php $this->oss_table( $oss ); ?>
				<?php $this->export_form( 'vatright_edd_export_oss', $period, __( 'Download OSS CSV', 'eu-vat-handling-for-edd' ) ); ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'EC Sales List', 'eu-vat-handling-for-edd' ); ?></h2>
			<?php if ( null === $esl ) : ?>
				<div class="notice notice-error inline"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: order numbers. */
							__( 'No ECB rate was available for these orders, so the list is not shown: %s', 'eu-vat-handling-for-edd' ),
							implode( ', ', $missing )
						)
					);
					?>
				</p></div>
			<?php else : ?>
				<?php $this->unknown_notice( $unknown ); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'VAT number', 'eu-vat-handling-for-edd' ); ?></th>
							<th><?php esc_html_e( 'Country', 'eu-vat-handling-for-edd' ); ?></th>
							<th><?php esc_html_e( 'Goods or services', 'eu-vat-handling-for-edd' ); ?></th>
							<th><?php esc_html_e( 'Net amount (EUR)', 'eu-vat-handling-for-edd' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $esl as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['customer_vat'] ); ?></td>
								<td><?php echo esc_html( $row['country'] ); ?></td>
								<td><?php echo esc_html( $row['type'] ); ?></td>
								<td><?php echo esc_html( number_format( $row['net_eur'], 2, '.', '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( array() === $esl ) : ?>
							<tr><td colspan="4"><?php esc_html_e( 'No intra-EU B2B sales in this period.', 'eu-vat-handling-for-edd' ); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
				<?php $this->export_form( 'vatright_edd_export_esl', $period, __( 'Download EC Sales List CSV', 'eu-vat-handling-for-edd' ) ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function export_oss(): void {
		$period = $this->authorised_selection();
		try {
			$report = $this->oss->build_for( $period );
		} catch ( \RuntimeException $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
		$csv = OssReport::csv( $report );
		Csv::send( sprintf( 'oss-%s.csv', $period->slug() ), $csv[0], $csv[1] );
	}

	public function export_esl(): void {
		$period = $this->authorised_selection();
		try {
			$rows = $this->esl->build_for( $period );
		} catch ( IncompleteReport $e ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: order numbers. */
						__( 'The EC Sales List cannot be exported: no ECB rate was available for orders %s.', 'eu-vat-handling-for-edd' ),
						implode( ', ', $e->order_ids() )
					)
				)
			);
		}
		$csv = EcSalesList::csv( $rows, $this->esl->unknown_number_orders_for( $period ) );
		Csv::send( sprintf( 'ec-sales-list-%s.csv', $period->slug() ), $csv[0], $csv[1] );
	}

	/**
	 * @param int[] $missing
	 * @return array<int,array{customer_vat:string,country:string,type:string,net_eur:float}>|null
	 */
	private function esl_or_null( ReportPeriod $period, array &$missing ): ?array {
		try {
			return $this->esl->build_for( $period );
		} catch ( IncompleteReport $e ) {
			$missing = $e->order_ids();
			return null;
		}
	}

	private function authorised_selection(): ReportPeriod {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export these reports.', 'eu-vat-handling-for-edd' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
		return $this->selection( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * What the form chose, with anything missing or out of range replaced by
	 * the current quarter, month or month-to-date.
	 *
	 * @param array<string,mixed> $source
	 */
	private function selection( array $source ): ReportPeriod {
		$text   = static function ( string $key ) use ( $source ): string {
			return isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? trim( wp_unslash( (string) $source[ $key ] ) ) : '';
		};
		$today  = new DateTimeImmutable( 'now', wp_timezone() );
		$number = static function ( string $key, int $fallback, int $min, int $max ) use ( $text ): int {
			$value = absint( $text( $key ) );
			return 0 === $value ? $fallback : max( $min, min( $max, $value ) );
		};
		$year   = $number( 'year', (int) $today->format( 'Y' ), 2000, 2100 );

		switch ( $text( 'period' ) ) {
			case ReportPeriod::MONTH:
				return ReportPeriod::month( $year, $number( 'month', (int) $today->format( 'n' ), 1, 12 ) );
			case ReportPeriod::RANGE:
				$range = ReportPeriod::range( $text( 'from' ), $text( 'to' ) );
				if ( null !== $range ) {
					return $range;
				}
				break;
		}

		return ReportPeriod::quarter( $year, $number( 'quarter', (int) ceil( (int) $today->format( 'n' ) / 3 ), 1, 4 ) );
	}

	private function period_form( ReportPeriod $period ): void {
		global $wp_locale;

		$today     = new DateTimeImmutable( 'now', wp_timezone() );
		$this_year = (int) $today->format( 'Y' );
		$quarter   = $period->quarter_number() ?? (int) ceil( (int) $today->format( 'n' ) / 3 );
		$month     = ReportPeriod::MONTH === $period->kind() ? (int) substr( $period->start(), 5, 2 ) : (int) $today->format( 'n' );
		$from      = ReportPeriod::RANGE === $period->kind() ? $period->start() : $today->format( 'Y-m-01' );
		$to        = ReportPeriod::RANGE === $period->kind() ? $period->end() : $today->format( 'Y-m-d' );
		$kinds     = array(
			ReportPeriod::QUARTER => __( 'Quarter', 'eu-vat-handling-for-edd' ),
			ReportPeriod::MONTH   => __( 'Month', 'eu-vat-handling-for-edd' ),
			ReportPeriod::RANGE   => __( 'Custom range', 'eu-vat-handling-for-edd' ),
		);
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="vatright-edd-period">
			<input type="hidden" name="post_type" value="download" />
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<label for="vatright-edd-period"><?php esc_html_e( 'Period', 'eu-vat-handling-for-edd' ); ?></label>
			<select name="period" id="vatright-edd-period">
				<?php foreach ( $kinds as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $period->kind() ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<span data-period-kinds="quarter month">
				<label class="screen-reader-text" for="vatright-edd-year"><?php esc_html_e( 'Year', 'eu-vat-handling-for-edd' ); ?></label>
				<select name="year" id="vatright-edd-year">
					<?php for ( $y = $this_year; $y >= $this_year - 5; $y-- ) : ?>
						<option value="<?php echo esc_attr( (string) $y ); ?>" <?php selected( $y, $period->year() ); ?>><?php echo esc_html( (string) $y ); ?></option>
					<?php endfor; ?>
				</select>
			</span>
			<span data-period-kinds="quarter">
				<label class="screen-reader-text" for="vatright-edd-quarter"><?php esc_html_e( 'Quarter', 'eu-vat-handling-for-edd' ); ?></label>
				<select name="quarter" id="vatright-edd-quarter">
					<?php for ( $q = 1; $q <= 4; $q++ ) : ?>
						<option value="<?php echo esc_attr( (string) $q ); ?>" <?php selected( $q, $quarter ); ?>><?php echo esc_html( 'Q' . $q ); ?></option>
					<?php endfor; ?>
				</select>
			</span>
			<span data-period-kinds="month">
				<label class="screen-reader-text" for="vatright-edd-month"><?php esc_html_e( 'Month', 'eu-vat-handling-for-edd' ); ?></label>
				<select name="month" id="vatright-edd-month">
					<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
						<option value="<?php echo esc_attr( (string) $m ); ?>" <?php selected( $m, $month ); ?>><?php echo esc_html( $wp_locale->get_month( $m ) ); ?></option>
					<?php endfor; ?>
				</select>
			</span>
			<span data-period-kinds="range">
				<label for="vatright-edd-from"><?php esc_html_e( 'From', 'eu-vat-handling-for-edd' ); ?></label>
				<input type="date" name="from" id="vatright-edd-from" value="<?php echo esc_attr( $from ); ?>" />
				<label for="vatright-edd-to"><?php esc_html_e( 'To', 'eu-vat-handling-for-edd' ); ?></label>
				<input type="date" name="to" id="vatright-edd-to" value="<?php echo esc_attr( $to ); ?>" />
			</span>
			<button type="submit" class="button"><?php esc_html_e( 'Show', 'eu-vat-handling-for-edd' ); ?></button>
		</form>
		<?php
		wp_print_inline_script_tag(
			"( function () {
	var form = document.querySelector( '.vatright-edd-period' );
	if ( ! form ) { return; }
	var kind = form.querySelector( '[name=period]' );
	function sync() {
		form.querySelectorAll( '[data-period-kinds]' ).forEach( function ( el ) {
			el.hidden = el.getAttribute( 'data-period-kinds' ).split( ' ' ).indexOf( kind.value ) === -1;
		} );
	}
	kind.addEventListener( 'change', sync );
	sync();
} )();"
		);
	}


	/**
	 * @param array<int,array{order_id:int,net_eur:float|null}> $unknown
	 */
	private function unknown_notice( array $unknown ): void {
		if ( array() === $unknown ) {
			return;
		}
		?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of orders. */
						_n(
							'%d order is left out of this list because its VAT number was not recorded. Add the number on the order, then run the report again.',
							'%d orders are left out of this list because their VAT numbers were not recorded. Add the numbers on the orders, then run the report again.',
							count( $unknown ),
							'eu-vat-handling-for-edd'
						),
						count( $unknown )
					)
				);
				?>
			</p>
			<p>
				<?php foreach ( $unknown as $i => $row ) : ?>
					<?php echo $i > 0 ? ', ' : ''; ?><a href="<?php echo esc_url( self::order_url( $row['order_id'] ) ); ?>">#<?php echo esc_html( (string) $row['order_id'] ); ?></a>
				<?php endforeach; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed> $oss
	 */
	private function oss_table( array $oss ): void {
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Member state', 'eu-vat-handling-for-edd' ); ?></th>
					<th><?php esc_html_e( 'VAT rate %', 'eu-vat-handling-for-edd' ); ?></th>
					<th><?php esc_html_e( 'Supply type', 'eu-vat-handling-for-edd' ); ?></th>
					<th><?php echo esc_html( sprintf( /* translators: %s: currency code. */ __( 'Taxable amount (%s)', 'eu-vat-handling-for-edd' ), $oss['currency'] ) ); ?></th>
					<th><?php echo esc_html( sprintf( /* translators: %s: currency code. */ __( 'VAT amount (%s)', 'eu-vat-handling-for-edd' ), $oss['currency'] ) ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $oss['rows'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['member_state'] ); ?></td>
						<td><?php echo esc_html( (string) $row['rate_percent'] ); ?></td>
						<td><?php echo esc_html( $row['supply_type'] ); ?></td>
						<td><?php echo esc_html( number_format( $row['taxable'], 2, '.', '' ) ); ?></td>
						<td><?php echo esc_html( number_format( $row['vat'], 2, '.', '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( array() === $oss['rows'] ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No OSS sales in this period.', 'eu-vat-handling-for-edd' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php foreach ( $oss['rates'] as $rate ) : ?>
			<?php if ( 'EUR' !== $rate['currency'] ) : ?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: currency, 2: rate, 3: date, 4: period. */
							__( '%1$s: %2$s per EUR on %3$s (%4$s).', 'eu-vat-handling-for-edd' ),
							$rate['currency'],
							$rate['rate'],
							$rate['rate_date'],
							$rate['period']
						)
					);
					?>
				</p>
			<?php endif; ?>
		<?php endforeach; ?>
		<?php if ( array() !== $oss['needs_correction'] ) : ?>
			<h3><?php echo esc_html( OssReport::needs_correction_title() ); ?></h3>
			<p><?php esc_html_e( 'Your tax rates had none for these destinations, so checkout charged your own country\'s rate. That VAT is not the destination VAT these sales owe, so they are left out of the figures above. Decide with your adviser how to declare each one, then add the missing rates.', 'eu-vat-handling-for-edd' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order', 'eu-vat-handling-for-edd' ); ?></th>
						<th><?php esc_html_e( 'Member state', 'eu-vat-handling-for-edd' ); ?></th>
						<th><?php esc_html_e( 'Rate charged %', 'eu-vat-handling-for-edd' ); ?></th>
						<th><?php esc_html_e( 'Supply type', 'eu-vat-handling-for-edd' ); ?></th>
						<th><?php echo esc_html( sprintf( /* translators: %s: currency code. */ __( 'Taxable amount (%s)', 'eu-vat-handling-for-edd' ), $oss['currency'] ) ); ?></th>
						<th><?php echo esc_html( sprintf( /* translators: %s: currency code. */ __( 'VAT charged (%s)', 'eu-vat-handling-for-edd' ), $oss['currency'] ) ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $oss['needs_correction'] as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( self::order_url( $row['order_id'] ) ); ?>">#<?php echo esc_html( (string) $row['order_id'] ); ?></a></td>
							<td><?php echo esc_html( $row['member_state'] ); ?></td>
							<td><?php echo esc_html( (string) $row['rate_percent'] ); ?></td>
							<td><?php echo esc_html( $row['supply_type'] ); ?></td>
							<td><?php echo esc_html( number_format( $row['taxable'], 2, '.', '' ) ); ?></td>
							<td><?php echo esc_html( number_format( $row['vat'], 2, '.', '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php if ( array() !== $oss['corrections'] ) : ?>
			<p><?php esc_html_e( 'Refunds and revoked or reinstated orders booked in this period against earlier periods are listed in the CSV so those returns can be amended.', 'eu-vat-handling-for-edd' ); ?></p>
		<?php endif; ?>
		<?php
	}

	private function export_form( string $action, ReportPeriod $period, string $label ): void {
		$fields = array(
			'period' => $period->kind(),
			'year'   => (string) $period->year(),
		);
		if ( ReportPeriod::QUARTER === $period->kind() ) {
			$fields['quarter'] = (string) $period->quarter_number();
		} elseif ( ReportPeriod::MONTH === $period->kind() ) {
			$fields['month'] = (string) (int) substr( $period->start(), 5, 2 );
		} else {
			$fields['from'] = $period->start();
			$fields['to']   = $period->end();
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
			<?php endforeach; ?>
			<p><button type="submit" class="button"><?php echo esc_html( $label ); ?></button></p>
		</form>
		<?php
	}
}
