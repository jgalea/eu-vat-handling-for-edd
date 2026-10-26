<?php

namespace VatrightEdd\Reports;

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
		list( $year, $quarter ) = $this->selection( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$missing                = array();
		$oss_error              = '';
		try {
			$oss = $this->oss->build( $year, $quarter );
		} catch ( \RuntimeException $e ) {
			$oss       = null;
			$oss_error = $e->getMessage();
		}
		$esl       = $this->esl_or_null( $year, $quarter, $missing );
		$unknown   = $this->esl->unknown_number_orders( $year, $quarter );
		$this_year = (int) wp_date( 'Y' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'EU VAT reports', 'eu-vat-handling-for-edd' ); ?></h1>
			<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
				<input type="hidden" name="post_type" value="download" />
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<select name="year">
					<?php for ( $y = $this_year; $y >= $this_year - 5; $y-- ) : ?>
						<option value="<?php echo esc_attr( (string) $y ); ?>" <?php selected( $y, $year ); ?>><?php echo esc_html( (string) $y ); ?></option>
					<?php endfor; ?>
				</select>
				<select name="quarter">
					<?php for ( $q = 1; $q <= 4; $q++ ) : ?>
						<option value="<?php echo esc_attr( (string) $q ); ?>" <?php selected( $q, $quarter ); ?>><?php echo esc_html( 'Q' . $q ); ?></option>
					<?php endfor; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Show', 'eu-vat-handling-for-edd' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'OSS return', 'eu-vat-handling-for-edd' ); ?></h2>
			<?php if ( null === $oss ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $oss_error ); ?></p></div>
			<?php else : ?>
				<p><?php echo esc_html( sprintf( /* translators: %s: conversion rule. */ __( 'Non-euro orders are converted at the %s.', 'eu-vat-handling-for-edd' ), $oss['rate_rule'] ) ); ?></p>
				<?php $this->oss_table( $oss ); ?>
				<?php $this->export_form( 'vatright_edd_export_oss', $year, $quarter, __( 'Download OSS CSV', 'eu-vat-handling-for-edd' ) ); ?>
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
				<?php $this->export_form( 'vatright_edd_export_esl', $year, $quarter, __( 'Download EC Sales List CSV', 'eu-vat-handling-for-edd' ) ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function export_oss(): void {
		list( $year, $quarter ) = $this->authorised_selection();
		try {
			$report = $this->oss->build( $year, $quarter );
		} catch ( \RuntimeException $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
		$csv = OssReport::csv( $report );
		Csv::send( sprintf( 'oss-%d-q%d.csv', $year, $quarter ), $csv[0], $csv[1] );
	}

	public function export_esl(): void {
		list( $year, $quarter ) = $this->authorised_selection();
		try {
			$rows = $this->esl->build( $year, $quarter );
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
		$csv = EcSalesList::csv( $rows, $this->esl->unknown_number_orders( $year, $quarter ) );
		Csv::send( sprintf( 'ec-sales-list-%d-q%d.csv', $year, $quarter ), $csv[0], $csv[1] );
	}

	/**
	 * @param int[] $missing
	 * @return array<int,array{customer_vat:string,country:string,type:string,net_eur:float}>|null
	 */
	private function esl_or_null( int $year, int $quarter, array &$missing ): ?array {
		try {
			return $this->esl->build( $year, $quarter );
		} catch ( IncompleteReport $e ) {
			$missing = $e->order_ids();
			return null;
		}
	}

	/**
	 * @return array{0:int,1:int}
	 */
	private function authorised_selection(): array {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export these reports.', 'eu-vat-handling-for-edd' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
		return $this->selection( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * @param array<string,mixed> $source
	 * @return array{0:int,1:int}
	 */
	private function selection( array $source ): array {
		$year    = isset( $source['year'] ) ? absint( $source['year'] ) : (int) wp_date( 'Y' );
		$quarter = isset( $source['quarter'] ) ? absint( $source['quarter'] ) : (int) ceil( (int) wp_date( 'n' ) / 3 );
		return array( max( 2000, min( 2100, $year ) ), max( 1, min( 4, $quarter ) ) );
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

	private function export_form( string $action, int $year, int $quarter, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="year" value="<?php echo esc_attr( (string) $year ); ?>" />
			<input type="hidden" name="quarter" value="<?php echo esc_attr( (string) $quarter ); ?>" />
			<p><button type="submit" class="button"><?php echo esc_html( $label ); ?></button></p>
		</form>
		<?php
	}
}
