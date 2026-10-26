<?php

namespace VatrightEdd\Import;

use DateTimeImmutable;
use VatrightEdd\Coexistence;
use VatrightEdd\Ledger\Backfill;

/**
 * Downloads > Import from Barn2: copies the settings, previews the history
 * import and runs it in the background.
 */
class ImportScreen {

	const PAGE = 'vatright-edd-import';

	const SCREEN_ID = 'download_page_vatright-edd-import';

	const CAPABILITY = 'manage_shop_settings';

	const NONCE = 'vatright_edd_import';

	const DRY_TRANSIENT = 'vatright_edd_import_dry';

	const QUARTERS = 20;

	/** @var Barn2Settings */
	private $settings;

	/** @var Importer */
	private $importer;

	public function __construct( Barn2Settings $settings, Importer $importer ) {
		$this->settings = $settings;
		$this->importer = $importer;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_filter( 'edd_settings_page_title', array( $this, 'page_title' ), 10, 2 );
		add_action( 'admin_post_vatright_edd_import_dry', array( $this, 'handle_dry_run' ) );
		add_action( 'admin_post_vatright_edd_import_run', array( $this, 'handle_run' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'load-' . self::SCREEN_ID, array( $this, 'refresh_while_running' ) );
	}

	public static function url(): string {
		return admin_url( 'edit.php?post_type=download&page=' . self::PAGE );
	}

	/**
	 * The screen is offered while Barn2 is active or has left data behind.
	 */
	public static function wanted(): bool {
		return Coexistence::barn2_active() || Barn2Settings::exists() || null !== Importer::state();
	}

	public function add_menu(): void {
		if ( ! self::wanted() ) {
			return;
		}
		add_submenu_page(
			'edit.php?post_type=download',
			__( 'Import from Barn2 EDD EU VAT', 'eu-vat-handling-for-edd' ),
			__( 'Import from Barn2', 'eu-vat-handling-for-edd' ),
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
		return self::PAGE === $current_page ? __( 'Import from Barn2 EDD EU VAT', 'eu-vat-handling-for-edd' ) : $title;
	}

	public function refresh_while_running(): void {
		if ( Importer::is_running() ) {
			header( 'Refresh: 5' );
		}
	}

	/**
	 * Offers the import while Barn2 is active, then asks for it to be
	 * deactivated once something was imported.
	 */
	public function notice(): void {
		if ( ! Coexistence::barn2_active() || ! current_user_can( self::CAPABILITY ) || $this->on_screen() ) {
			return;
		}
		if ( $this->imported() ) {
			$text = __( 'The Barn2 import has finished. Deactivate Barn2 EDD EU VAT now so EU VAT Handling for EDD can take over.', 'eu-vat-handling-for-edd' );
			$link = admin_url( 'plugins.php' );
			$more = __( 'Open Plugins', 'eu-vat-handling-for-edd' );
		} else {
			$text = __( 'Barn2 EDD EU VAT is active, so EU VAT Handling for EDD is waiting. You can import Barn2\'s settings and order history first.', 'eu-vat-handling-for-edd' );
			$link = self::url();
			$more = __( 'Import from Barn2', 'eu-vat-handling-for-edd' );
		}
		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html( $text ),
			esc_url( $link ),
			esc_html( $more )
		);
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$state = Importer::state();
		?>
		<div class="wrap">
		<h1><?php esc_html_e( 'Import from Barn2 EDD EU VAT', 'eu-vat-handling-for-edd' ); ?></h1>
		<p><?php esc_html_e( 'Copies Barn2\'s settings and records each past order\'s VAT treatment from the data Barn2 saved on it. Settings that are already filled in here are kept unless you choose to overwrite them. Importing twice changes nothing the second time.', 'eu-vat-handling-for-edd' ); ?></p>
		<?php
		$this->render_progress( $state );
		$this->render_form();
		$this->render_dry_run();
		$this->render_settings_report();
		if ( Coexistence::barn2_active() && $this->imported() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Deactivate Barn2 EDD EU VAT now so EU VAT Handling for EDD can take over. Until then it stays inactive.', 'eu-vat-handling-for-edd' ),
				esc_url( admin_url( 'plugins.php' ) ),
				esc_html__( 'Open Plugins', 'eu-vat-handling-for-edd' )
			);
		}
		echo '</div>';
	}

	public function handle_dry_run(): void {
		$this->authorise();
		$plan = array(
			'since'    => $this->posted_since()->format( 'Y-m-d' ),
			'settings' => ! empty( $_POST['vatright_edd_settings'] ) ? $this->settings->import( ! empty( $_POST['vatright_edd_overwrite'] ), false ) : null, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in authorise().
			'history'  => ! empty( $_POST['vatright_edd_history'] ) ? $this->importer->dry_run( $this->posted_since() ) : null, // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
		set_transient( self::DRY_TRANSIENT, $plan, HOUR_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	public function handle_run(): void {
		$this->authorise();
		delete_transient( self::DRY_TRANSIENT );
		if ( ! empty( $_POST['vatright_edd_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in authorise().
			$this->settings->import( ! empty( $_POST['vatright_edd_overwrite'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( ! empty( $_POST['vatright_edd_history'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->importer->start( $this->posted_since() );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	private function authorise(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'eu-vat-handling-for-edd' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
	}

	private function posted_since(): DateTimeImmutable {
		$posted = isset( $_POST['vatright_edd_since'] ) ? sanitize_text_field( wp_unslash( $_POST['vatright_edd_since'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in authorise().
		if ( array_key_exists( $posted, $this->since_options() ) ) {
			return new DateTimeImmutable( $posted . ' 00:00:00', wp_timezone() );
		}
		return Backfill::default_since();
	}

	/**
	 * Quarter starts from the default window back, newest first.
	 *
	 * @return array<string,string> Date => label.
	 */
	private function since_options(): array {
		$default = Backfill::default_since();
		$options = array();
		for ( $i = 0; $i < self::QUARTERS; $i++ ) {
			$start = $default->modify( '-' . ( 3 * $i ) . ' months' );
			$label = $start->format( 'Y' ) . '-Q' . (int) ceil( (int) $start->format( 'n' ) / 3 );
			if ( 0 === $i ) {
				/* translators: %s: quarter such as 2025-Q1. */
				$label = sprintf( __( '%s (default: the previous year onwards)', 'eu-vat-handling-for-edd' ), $label );
			}
			$options[ $start->format( 'Y-m-d' ) ] = $label;
		}
		return $options;
	}

	private function on_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return null !== $screen && self::SCREEN_ID === $screen->id;
	}

	private function imported(): bool {
		$state = Importer::state();
		return ( null !== $state && Importer::DONE === ( $state['status'] ?? '' ) ) || null !== Barn2Settings::last_report();
	}

	private function render_form(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'What to import', 'eu-vat-handling-for-edd' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'What to import', 'eu-vat-handling-for-edd' ); ?></legend>
								<label><input type="checkbox" name="vatright_edd_settings" value="1" checked="checked" /> <?php esc_html_e( 'Settings', 'eu-vat-handling-for-edd' ); ?></label><br />
								<label><input type="checkbox" name="vatright_edd_history" value="1" checked="checked" /> <?php esc_html_e( 'Order history', 'eu-vat-handling-for-edd' ); ?></label><br />
								<label><input type="checkbox" name="vatright_edd_overwrite" value="1" /> <?php esc_html_e( 'Overwrite settings that are already filled in', 'eu-vat-handling-for-edd' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vatright-edd-since"><?php esc_html_e( 'History from', 'eu-vat-handling-for-edd' ); ?></label></th>
						<td>
							<select id="vatright-edd-since" name="vatright_edd_since">
								<?php foreach ( $this->since_options() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Pick an earlier quarter to correct OSS returns you already filed.', 'eu-vat-handling-for-edd' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<p>
				<button type="submit" class="button" name="action" value="vatright_edd_import_dry"><?php esc_html_e( 'Dry run', 'eu-vat-handling-for-edd' ); ?></button>
				<button type="submit" class="button button-primary" name="action" value="vatright_edd_import_run" <?php disabled( Importer::is_running() ); ?>><?php esc_html_e( 'Import', 'eu-vat-handling-for-edd' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * @param array<string,mixed>|null $state
	 */
	private function render_progress( ?array $state ): void {
		if ( null === $state ) {
			return;
		}
		$running = Importer::RUNNING === ( $state['status'] ?? '' );
		?>
		<div class="notice notice-<?php echo $running ? 'info' : 'success'; ?> inline">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: running or finished, 2: orders imported, 3: ledger rows written, 4: orders that failed, 5: first day imported. */
						__( 'History import %1$s: %2$d orders imported, %3$d ledger rows written, %4$d failed (from %5$s).', 'eu-vat-handling-for-edd' ),
						$running ? __( 'running', 'eu-vat-handling-for-edd' ) : __( 'finished', 'eu-vat-handling-for-edd' ),
						(int) ( $state['orders'] ?? 0 ),
						(int) ( $state['rows'] ?? 0 ),
						(int) ( $state['failed'] ?? 0 ),
						(string) ( $state['since'] ?? '' )
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	private function render_dry_run(): void {
		$plan = get_transient( self::DRY_TRANSIENT );
		if ( ! is_array( $plan ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Dry run', 'eu-vat-handling-for-edd' ) . '</h2>';
		echo '<p>' . esc_html__( 'Nothing was written.', 'eu-vat-handling-for-edd' ) . '</p>';
		if ( is_array( $plan['settings'] ?? null ) ) {
			$this->render_fields( $plan['settings'] );
		}
		if ( ! is_array( $plan['history'] ?? null ) ) {
			return;
		}
		$history = $plan['history'];
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: orders, 2: orders with Barn2 data, 3: orders without, 4: ledger rows, 5: first day. */
				__( '%1$d orders from %5$s (%2$d with Barn2 data, %3$d without) would write %4$d ledger rows.', 'eu-vat-handling-for-edd' ),
				(int) $history['orders'],
				(int) $history['barn2_orders'],
				(int) $history['backfill_orders'],
				(int) $history['rows'],
				(string) ( $plan['since'] ?? '' )
			)
		) . '</p>';
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Quarter', 'eu-vat-handling-for-edd' ); ?></th>
					<th><?php esc_html_e( 'Orders', 'eu-vat-handling-for-edd' ); ?></th>
					<th><?php esc_html_e( 'Rows', 'eu-vat-handling-for-edd' ); ?></th>
					<th><?php esc_html_e( 'By scheme', 'eu-vat-handling-for-edd' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $history['quarters'] as $quarter => $counts ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $quarter ); ?></td>
						<td><?php echo esc_html( (string) $counts['orders'] ); ?></td>
						<td><?php echo esc_html( (string) $counts['rows'] ); ?></td>
						<td>
							<?php
							$parts = array();
							foreach ( $counts['schemes'] as $scheme => $rows ) {
								$parts[] = $scheme . ' ' . $rows;
							}
							echo esc_html( implode( ', ', $parts ) );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_settings_report(): void {
		$report = Barn2Settings::last_report();
		if ( null === $report ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Settings import', 'eu-vat-handling-for-edd' ) . '</h2>';
		$this->render_fields( $report );
	}

	/**
	 * Field names and what happened to them; the values stay on the server.
	 *
	 * @param array<string,string> $fields
	 */
	private function render_fields( array $fields ): void {
		if ( array() === $fields ) {
			echo '<p>' . esc_html__( 'Barn2 has no settings saved.', 'eu-vat-handling-for-edd' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><tbody>';
		foreach ( $fields as $field => $outcome ) {
			echo '<tr><td><code>' . esc_html( (string) $field ) . '</code></td><td>' . esc_html( (string) $outcome ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
