<?php

namespace VatrightEdd\Rates;

class RatesScreen {

	const NONCE = 'vatright_edd_rates';

	const PAGE = 'vatright-edd';

	const CAPABILITY = 'manage_shop_settings';

	/** @var RateSync */
	private $sync;

	public function __construct( RateSync $sync ) {
		$this->sync = $sync;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_filter( 'edd_settings_page_title', array( $this, 'page_title' ), 10, 2 );
		add_action( 'admin_post_vatright_edd_rates_apply', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_vatright_edd_rates_apply_all', array( $this, 'handle_apply_all' ) );
		add_action( 'admin_post_vatright_edd_rates_refresh', array( $this, 'handle_refresh' ) );
		add_action( 'admin_notices', array( $this, 'result_message' ) );
		add_action( 'admin_notices', array( $this, 'pending_notice' ) );
	}

	public static function url(): string {
		return admin_url( 'edit.php?post_type=download&page=' . self::PAGE );
	}

	public function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=download',
			__( 'EU VAT rates', 'vat-right-for-edd' ),
			__( 'EU VAT rates', 'vat-right-for-edd' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * @param mixed $title
	 * @param mixed $current_page
	 * @return mixed
	 */
	public function page_title( $title, $current_page = '' ) {
		return self::PAGE === $current_page ? __( 'EU VAT rates', 'vat-right-for-edd' ) : $title;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$diff = $this->sync->plan( $this->sync->latest() );
		?>
		<div class="wrap">
		<h1><?php esc_html_e( 'EU VAT rates', 'vat-right-for-edd' ); ?></h1>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: rate source, 2: date the rates apply on. */
					__( 'Standard rates from %1$s, valid on %2$s. Nothing changes until you apply it. A replaced rate stays in EDD as an inactive rate.', 'vat-right-for-edd' ),
					strtoupper( $diff->source() ),
					$diff->source_date()
				)
			);
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="action" value="vatright_edd_rates_apply" />
			<table class="widefat striped">
				<thead>
					<tr>
						<th></th>
						<th><?php esc_html_e( 'Country', 'vat-right-for-edd' ); ?></th>
						<th><?php esc_html_e( 'Current', 'vat-right-for-edd' ); ?></th>
						<th><?php esc_html_e( 'New', 'vat-right-for-edd' ); ?></th>
						<th><?php esc_html_e( 'Status', 'vat-right-for-edd' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $diff->entries() as $entry ) : ?>
						<tr>
							<td>
								<?php if ( RateDiff::UNCHANGED !== $entry['status'] ) : ?>
									<input type="checkbox" name="vatright_edd_rate_keys[]" value="<?php echo esc_attr( $entry['key'] ); ?>" <?php checked( RateDiff::EDITED !== $entry['status'] ); ?> />
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $entry['country'] ); ?></td>
							<td><?php echo null === $entry['current'] ? '&ndash;' : esc_html( $entry['current'] . '%' ); ?></td>
							<td><?php echo esc_html( $entry['rate'] . '%' ); ?></td>
							<td><?php echo esc_html( $this->status_label( $entry['status'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<label>
					<input type="checkbox" name="vatright_edd_overwrite_edited" value="1" />
					<?php esc_html_e( 'Also overwrite rates I edited (tick the rows first)', 'vat-right-for-edd' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply selected rates', 'vat-right-for-edd' ); ?></button>
				<button type="submit" class="button" name="action" value="vatright_edd_rates_refresh"><?php esc_html_e( 'Fetch latest from EU TEDB', 'vat-right-for-edd' ); ?></button>
			</p>
		</form>
		</div>
		<?php
	}

	public function handle_apply(): void {
		$this->authorise();
		$keys      = isset( $_POST['vatright_edd_rate_keys'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['vatright_edd_rate_keys'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in authorise().
		$overwrite = ! empty( $_POST['vatright_edd_overwrite_edited'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$applied   = $this->sync->apply( $this->sync->plan( $this->sync->latest() ), $keys, $overwrite );
		wp_safe_redirect( add_query_arg( 'vatright_edd_applied', $applied, self::url() ) );
		exit;
	}

	public function handle_apply_all(): void {
		$this->authorise();
		$diff = $this->sync->plan( $this->sync->latest() );
		$keys = array();
		foreach ( $diff->entries() as $key => $entry ) {
			if ( in_array( $entry['status'], array( RateDiff::ADD, RateDiff::CHANGE ), true ) ) {
				$keys[] = $key;
			}
		}
		$applied = $this->sync->apply( $diff, $keys, false );
		wp_safe_redirect( add_query_arg( 'vatright_edd_applied', $applied, self::url() ) );
		exit;
	}

	public function handle_refresh(): void {
		$this->authorise();
		$ok = $this->sync->refresh();
		wp_safe_redirect( add_query_arg( 'vatright_edd_refreshed', $ok ? '1' : '0', self::url() ) );
		exit;
	}

	public function result_message(): void {
		if ( ! $this->on_screen( 'download_page_' . self::PAGE ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect's outcome.
		if ( isset( $_GET['vatright_edd_applied'] ) ) {
			$applied = absint( $_GET['vatright_edd_applied'] );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				0 === $applied ? 'warning' : 'success',
				esc_html(
					0 === $applied
						? __( 'No VAT rates were changed. Tick the rows you want to apply.', 'vat-right-for-edd' )
						/* translators: %d: number of tax rates written. */
						: sprintf( _n( '%d VAT rate applied.', '%d VAT rates applied.', $applied, 'vat-right-for-edd' ), $applied )
				)
			);
		}
		if ( isset( $_GET['vatright_edd_refreshed'] ) ) {
			if ( '1' === $_GET['vatright_edd_refreshed'] ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Fetched the latest rates from EU TEDB.', 'vat-right-for-edd' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'EU TEDB could not be reached, so the rates below haven\'t changed.', 'vat-right-for-edd' ) . '</p></div>';
			}
		}
		// phpcs:enable
	}

	public function pending_notice(): void {
		if ( ! $this->on_screen( 'download_page_edd-settings' ) ) {
			return;
		}
		$pending = (int) get_option( RateSync::OPTION_PENDING, 0 );
		if ( $pending < 1 ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of rate changes. */
					_n( '%d VAT rate change available.', '%d VAT rate changes available.', $pending, 'vat-right-for-edd' ),
					$pending
				)
			),
			esc_url( self::url() ),
			esc_html__( 'Review', 'vat-right-for-edd' )
		);
	}

	private function on_screen( string $screen_id ): bool {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen && $screen_id === $screen->id;
	}

	private function authorise(): void {
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'vat-right-for-edd' ), 403 );
		}
	}

	private function status_label( string $status ): string {
		$labels = array(
			RateDiff::ADD       => __( 'New', 'vat-right-for-edd' ),
			RateDiff::CHANGE    => __( 'Rate changed', 'vat-right-for-edd' ),
			RateDiff::EDITED    => __( 'Edited by you', 'vat-right-for-edd' ),
			RateDiff::UNCHANGED => __( 'Up to date', 'vat-right-for-edd' ),
		);
		return $labels[ $status ];
	}
}
