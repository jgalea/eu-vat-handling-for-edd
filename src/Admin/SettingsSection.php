<?php

namespace VatrightEdd\Admin;

use VatrightEdd\Domain\B2bScope;
use VatrightEdd\Domain\FormatCheck;
use VatrightEdd\Domain\VatNumber;
use VatrightEdd\Geo\IpCountry;
use VatrightEdd\Rates\RateSync;
use VatrightEdd\Rates\RatesScreen;
use VatrightEdd\Settings;

class SettingsSection {

	const SECTION = 'vatright';

	const FIELD = 'vatright_edd_settings';

	const PRESENT = 'vatright_edd_settings_present';

	const WP_NONCE_ACTION = 'edd_settings-options';

	const CAPABILITY = 'manage_shop_settings';

	/** @var SetupCheck|null */
	private $setup_check;

	public function __construct( ?SetupCheck $setup_check = null ) {
		$this->setup_check = $setup_check;
	}

	public function register(): void {
		add_filter( 'edd_settings_sections_taxes', array( $this, 'add_section' ) );
		add_action( 'admin_notices', array( $this, 'setup_notice' ) );
		add_action( 'edd_settings_tab_top_taxes_' . self::SECTION, array( $this, 'render' ) );
		add_filter( 'edd_settings_taxes-' . self::SECTION . '_sanitize', array( $this, 'save' ) );
	}

	public static function url(): string {
		return admin_url( 'edit.php?post_type=download&page=edd-settings&tab=taxes&section=' . self::SECTION );
	}

	/**
	 * @param mixed $sections
	 * @return array<string,string>
	 */
	public function add_section( $sections ): array {
		$sections                  = is_array( $sections ) ? $sections : array();
		$sections[ self::SECTION ] = __( 'EU VAT', 'eu-vat-handling-for-edd' );
		return $sections;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$s = Settings::get();
		$this->render_issues( $this->issues() );
		if ( 'none' === $s->ip_country_source() && IpCountry::cdn_header_present() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'This request carries a country header from a CDN. If your site is behind Cloudflare or CloudFront, choose it under "IP country source" so the visitor\'s location counts as evidence.', 'eu-vat-handling-for-edd' ) . '</p></div>';
		}
		?>
		<input type="hidden" name="<?php echo esc_attr( self::PRESENT ); ?>" value="1" />
		<table class="form-table" role="presentation">
			<tbody>
				<?php
				$this->text_row( 'store_vat_number', __( 'Store VAT number', 'eu-vat-handling-for-edd' ), $s->store_vat_number(), __( 'Sent to VIES as the requester, which returns a consultation number as proof of each check.', 'eu-vat-handling-for-edd' ) );
				$this->text_row( 'store_vat_number_gb', __( 'UK store VAT number', 'eu-vat-handling-for-edd' ), $s->store_vat_number_gb(), __( 'Optional. Printed on receipts and invoices for UK customers instead of the number above, and sent to HMRC as the requester. Leave it empty to use one number everywhere.', 'eu-vat-handling-for-edd' ) );
				$this->select_row(
					'ip_country_source',
					__( 'IP country source', 'eu-vat-handling-for-edd' ),
					$s->ip_country_source(),
					array(
						'none'       => __( 'None, billing country and self-certification only', 'eu-vat-handling-for-edd' ),
						'cloudflare' => __( 'Cloudflare (CF-IPCountry header)', 'eu-vat-handling-for-edd' ),
						'cloudfront' => __( 'Amazon CloudFront (CloudFront-Viewer-Country header)', 'eu-vat-handling-for-edd' ),
					),
					__( 'Only pick a CDN your site is really behind. Without one, visitors could send the header themselves.', 'eu-vat-handling-for-edd' )
				);
				$this->select_row( 'report_currency', __( 'Report currency', 'eu-vat-handling-for-edd' ), $s->report_currency(), $this->currency_options() );
				$this->select_row(
					'outage_policy',
					__( 'When a registry is unavailable', 'eu-vat-handling-for-edd' ),
					$s->outage_policy(),
					array(
						'charge'         => __( 'Accept the order, charge VAT and flag it', 'eu-vat-handling-for-edd' ),
						'exempt_pending' => __( 'Accept the order, exempt VAT and re-check later', 'eu-vat-handling-for-edd' ),
						'reject'         => __( 'Do not accept the order', 'eu-vat-handling-for-edd' ),
					),
					__( 'Charging VAT and flagging the order is the safe choice: you can refund the VAT once the number validates. Exempting while a registry is down lets anyone enter a made-up number for that country, and the VAT is yours to recover if the re-check fails.', 'eu-vat-handling-for-edd' )
				);
				$this->select_row(
					'b2b_only',
					__( 'Sell to businesses only', 'eu-vat-handling-for-edd' ),
					$s->b2b_only(),
					array(
						B2bScope::OFF        => __( 'Off: a VAT number is optional', 'eu-vat-handling-for-edd' ),
						B2bScope::EVERYWHERE => __( 'Require a valid VAT number from every customer', 'eu-vat-handling-for-edd' ),
						B2bScope::EU         => __( 'Require a valid VAT number from customers billing in the EU', 'eu-vat-handling-for-edd' ),
						B2bScope::COUNTRIES  => __( 'Require a valid VAT number from customers billing in the countries chosen below', 'eu-vat-handling-for-edd' ),
					),
					__( 'The customer has to enter a VAT number that its registry confirms before the order is accepted. A registry outage follows "When a registry is unavailable" above: "Do not accept the order" refuses the order, while the two other choices accept it with VAT charged or exempted and flagged for a re-check. A number the registry has not checked, because no registry covers its country or the lookup was turned away for load, is never accepted unchecked. Orders that skip the checkout checks, such as Buy Now and wallet payments, cannot be refused: they are recorded and flagged on the order.', 'eu-vat-handling-for-edd' )
				);
				$this->multiselect_row( 'b2b_only_countries', __( 'Business-only countries', 'eu-vat-handling-for-edd' ), $s->b2b_only_countries(), edd_get_country_list(), __( 'Used when the setting above is "chosen countries". The country is the customer\'s billing country.', 'eu-vat-handling-for-edd' ) );
				$this->number_row( 'cache_ttl_valid_days', __( 'Keep valid results for (days)', 'eu-vat-handling-for-edd' ), $s->cache_ttl_valid_days(), 1, 90 );
				$this->checkbox_row( 'self_certification', __( 'Self-certification', 'eu-vat-handling-for-edd' ), __( 'Ask digital customers to confirm their country when the evidence conflicts', 'eu-vat-handling-for-edd' ), $s->self_certification() );
				$this->checkbox_row( 'allow_domestic_exemption', __( 'Domestic B2B', 'eu-vat-handling-for-edd' ), __( 'Allow VAT exemption for valid numbers from the store country', 'eu-vat-handling-for-edd' ), $s->allow_domestic_exemption() );
				$this->multiselect_row( 'exempt_roles', __( 'VAT-exempt roles', 'eu-vat-handling-for-edd' ), $s->exempt_roles(), self::role_options(), __( 'Orders from customers with these roles are VAT-exempt. A user role is not a basis for VAT exemption under EU VAT law on its own, so you are responsible for making sure each exemption is correct, for example that these accounts are checked VAT-registered businesses. The role is recorded on every order it exempts. A confirmed VAT number still takes precedence. Leave empty to exempt nobody.', 'eu-vat-handling-for-edd' ) );
				$this->checkbox_row( 'notify_admin_on_revalidation', __( 'Revalidation emails', 'eu-vat-handling-for-edd' ), __( 'Email the admin when a number validates after the order was placed', 'eu-vat-handling-for-edd' ), $s->notify_admin_on_revalidation() );
				$this->checkbox_row( 'auto_refund_on_validation', __( 'Automatic VAT refund', 'eu-vat-handling-for-edd' ), __( 'Refund the VAT automatically when a number validates after the order', 'eu-vat-handling-for-edd' ), $s->auto_refund_on_validation(), __( 'Off by default. Refunds exactly the VAT charged, once, through EDD, and only when EDD can refund the order and the gateway is one that supports it. Otherwise the order is flagged for you to handle.', 'eu-vat-handling-for-edd' ) );
				$this->checkbox_row( 'oss_registered', __( 'OSS', 'eu-vat-handling-for-edd' ), __( 'I am registered for the One Stop Shop (always charge destination VAT)', 'eu-vat-handling-for-edd' ), $s->oss_registered() );
				$this->checkbox_row( 'threshold_warning', __( 'Threshold warning', 'eu-vat-handling-for-edd' ), __( 'Email the admin when EU sales near and pass the EUR 10,000 OSS threshold', 'eu-vat-handling-for-edd' ), $s->threshold_warning() );
				$this->number_row( 'threshold_warning_percent', __( 'Warn at (% of the threshold)', 'eu-vat-handling-for-edd' ), $s->threshold_warning_percent(), 1, 99 );
				$this->text_row( 'hmrc_client_id', __( 'HMRC client ID (optional)', 'eu-vat-handling-for-edd' ), $s->hmrc_client_id(), __( 'From your own HMRC Developer Hub application. Without it GB numbers are format-checked only.', 'eu-vat-handling-for-edd' ) );
				$this->secret_row( 'hmrc_client_secret', __( 'HMRC client secret', 'eu-vat-handling-for-edd' ), '' !== $s->hmrc_client_secret() );
				$this->checkbox_row( 'receipt_vat_details', __( 'Receipts', 'eu-vat-handling-for-edd' ), __( 'Print the seller details and the VAT wording on receipts and invoices', 'eu-vat-handling-for-edd' ), $s->receipt_vat_details() );
				$this->text_row( 'seller_company', __( 'Company name', 'eu-vat-handling-for-edd' ), $s->text( 'seller_company' ) );
				$this->text_row( 'seller_email', __( 'Company email', 'eu-vat-handling-for-edd' ), $s->text( 'seller_email' ) );
				$this->text_row( 'seller_address_1', __( 'Address line 1', 'eu-vat-handling-for-edd' ), $s->text( 'seller_address_1' ) );
				$this->text_row( 'seller_address_2', __( 'Address line 2', 'eu-vat-handling-for-edd' ), $s->text( 'seller_address_2' ) );
				$this->text_row( 'seller_city', __( 'City', 'eu-vat-handling-for-edd' ), $s->text( 'seller_city' ) );
				$this->text_row( 'seller_postcode', __( 'Postcode', 'eu-vat-handling-for-edd' ), $s->text( 'seller_postcode' ) );
				$this->text_row( 'seller_country', __( 'Country', 'eu-vat-handling-for-edd' ), $s->text( 'seller_country' ) );
				$this->number_row( 'retention_years', __( 'Keep VAT records for (years)', 'eu-vat-handling-for-edd' ), $s->retention_years(), 1, 30 );
				$this->checkbox_row( 'delete_data_on_uninstall', __( 'Uninstall', 'eu-vat-handling-for-edd' ), __( 'Delete all plugin data when the plugin is deleted', 'eu-vat-handling-for-edd' ), $s->delete_data_on_uninstall() );
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'EU VAT rates', 'eu-vat-handling-for-edd' ); ?></th>
					<td><?php $this->rates_summary(); ?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	public function setup_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! $this->on_edd_settings() || $this->on_section() ) {
			return;
		}
		$blocking = array_values(
			array_filter(
				$this->issues(),
				static function ( array $issue ): bool {
					return SetupCheck::ERROR === $issue['severity'];
				}
			)
		);
		if ( array() === $blocking ) {
			return;
		}
		$this->render_issues( array( $blocking[0] ) );
	}

	/**
	 * @return array<int,array{id:string,severity:string,message:string,fix_url:string}>
	 */
	private function issues(): array {
		return null === $this->setup_check ? array() : $this->setup_check->issues();
	}

	/**
	 * @param array<int,array{id:string,severity:string,message:string,fix_url:string}> $issues
	 */
	private function render_issues( array $issues ): void {
		foreach ( $issues as $issue ) {
			printf(
				'<div class="notice notice-%1$s inline"><p>%2$s <a href="%3$s">%4$s</a></p></div>',
				esc_attr( SetupCheck::ERROR === $issue['severity'] ? 'error' : $issue['severity'] ),
				esc_html( $issue['message'] ),
				esc_url( $issue['fix_url'] ),
				esc_html__( 'Fix', 'eu-vat-handling-for-edd' )
			);
		}
	}

	private function on_edd_settings(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return null !== $screen && 'download_page_edd-settings' === $screen->id;
	}

	private function on_section(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which screen section is open.
		return isset( $_GET['section'] ) && self::SECTION === sanitize_key( wp_unslash( $_GET['section'] ) );
	}

	/**
	 * Runs inside EDD's settings sanitizer, which WordPress only calls from options.php after its own nonce and capability checks.
	 *
	 * @param mixed $input
	 * @return mixed
	 */
	public function save( $input ) {
		if ( ! isset( $_POST[ self::PRESENT ], $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::WP_NONCE_ACTION ) ) {
			return $input;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return $input;
		}
		$posted = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every key is sanitized in sanitize().
		update_option( Settings::OPTION, $this->sanitize( $posted ) );
		Settings::reset();
		return $input;
	}

	/**
	 * @param array<string,mixed> $posted
	 * @return array<string,mixed>
	 */
	public function sanitize( array $posted ): array {
		$current  = Settings::get();
		$defaults = Settings::defaults();
		$text     = static function ( string $key ) use ( $posted ): string {
			return isset( $posted[ $key ] ) && is_scalar( $posted[ $key ] ) ? trim( sanitize_text_field( (string) $posted[ $key ] ) ) : '';
		};
		$number   = static function ( string $key, int $min, int $max ) use ( $posted, $defaults ): int {
			$value = isset( $posted[ $key ] ) && is_numeric( $posted[ $key ] ) ? (int) $posted[ $key ] : (int) $defaults[ $key ];
			return max( $min, min( $max, $value ) );
		};

		$policy = $text( 'outage_policy' );
		if ( ! in_array( $policy, array( 'charge', 'exempt_pending', 'reject' ), true ) ) {
			$policy = $defaults['outage_policy'];
		}
		$currency = strtoupper( $text( 'report_currency' ) );
		if ( ! array_key_exists( $currency, $this->currency_options() ) ) {
			$currency = $defaults['report_currency'];
		}
		$ip_source = $text( 'ip_country_source' );
		if ( ! in_array( $ip_source, array( 'none', 'cloudflare', 'cloudfront' ), true ) ) {
			$ip_source = $defaults['ip_country_source'];
		}
		$b2b_only = $text( 'b2b_only' );
		if ( ! in_array( $b2b_only, B2bScope::MODES, true ) ) {
			$b2b_only = $defaults['b2b_only'];
		}
		$client_id = $text( 'hmrc_client_id' );
		$secret    = isset( $posted['hmrc_client_secret'] ) && is_scalar( $posted['hmrc_client_secret'] ) ? trim( (string) $posted['hmrc_client_secret'] ) : '';
		if ( '' === $client_id ) {
			$secret = '';
		} elseif ( '' === $secret ) {
			$secret = $current->hmrc_client_secret();
		}

		return array(
			'store_vat_number'             => $this->sanitize_store_vat_number( $text( 'store_vat_number' ), $current->store_vat_number() ),
			'store_vat_number_gb'          => $this->sanitize_uk_store_vat_number( $text( 'store_vat_number_gb' ), $current->store_vat_number_gb() ),
			'outage_policy'                => $policy,
			'b2b_only'                     => $b2b_only,
			'b2b_only_countries'           => $this->sanitize_countries( $posted['b2b_only_countries'] ?? array() ),
			'exempt_roles'                 => $this->sanitize_roles( $posted['exempt_roles'] ?? array() ),
			'cache_ttl_valid_days'         => $number( 'cache_ttl_valid_days', 1, 90 ),
			'allow_domestic_exemption'     => ! empty( $posted['allow_domestic_exemption'] ),
			'oss_registered'               => ! empty( $posted['oss_registered'] ),
			'threshold_warning'            => ! empty( $posted['threshold_warning'] ),
			'threshold_warning_percent'    => $number( 'threshold_warning_percent', 1, 99 ),
			'self_certification'           => ! empty( $posted['self_certification'] ),
			'notify_admin_on_revalidation' => ! empty( $posted['notify_admin_on_revalidation'] ),
			'auto_refund_on_validation'    => ! empty( $posted['auto_refund_on_validation'] ),
			'retention_years'              => $number( 'retention_years', 1, 30 ),
			'hmrc_client_id'               => $client_id,
			'hmrc_client_secret'           => $secret,
			'report_currency'              => $currency,
			'ip_country_source'            => $ip_source,
			'receipt_vat_details'          => ! empty( $posted['receipt_vat_details'] ),
			'seller_company'               => $text( 'seller_company' ),
			'seller_email'                 => sanitize_email( $text( 'seller_email' ) ),
			'seller_address_1'             => $text( 'seller_address_1' ),
			'seller_address_2'             => $text( 'seller_address_2' ),
			'seller_city'                  => $text( 'seller_city' ),
			'seller_postcode'              => $text( 'seller_postcode' ),
			'seller_country'               => $text( 'seller_country' ),
			'delete_data_on_uninstall'     => ! empty( $posted['delete_data_on_uninstall'] ),
		);
	}

	/**
	 * @param mixed $posted
	 * @return string[]
	 */
	private function sanitize_countries( $posted ): array {
		$known     = edd_get_country_list();
		$countries = array();
		foreach ( is_array( $posted ) ? $posted : array() as $code ) {
			$code = is_scalar( $code ) ? strtoupper( sanitize_text_field( (string) $code ) ) : '';
			if ( '' !== $code && isset( $known[ $code ] ) ) {
				$countries[ $code ] = $code;
			}
		}
		return array_values( $countries );
	}

	/**
	 * @param mixed $posted
	 * @return string[]
	 */
	private function sanitize_roles( $posted ): array {
		$roles = array();
		foreach ( is_array( $posted ) ? $posted : array() as $role ) {
			$role = is_scalar( $role ) ? sanitize_key( (string) $role ) : '';
			if ( '' !== $role && wp_roles()->is_role( $role ) ) {
				$roles[ $role ] = $role;
			}
		}
		return array_values( $roles );
	}

	/**
	 * @return array<string,string>
	 */
	private static function role_options(): array {
		$options = array();
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$options[ $slug ] = translate_user_role( $name );
		}
		return $options;
	}

	private function sanitize_store_vat_number( string $raw, string $kept ): string {
		if ( '' === $raw ) {
			return '';
		}
		$parsed = VatNumber::parse( $raw, edd_get_shop_country() );
		if ( null === $parsed || ! FormatCheck::is_plausible( $parsed ) ) {
			add_settings_error( 'vatright-edd', 'store_vat_number', __( 'The store VAT number does not look valid, so it was not saved.', 'eu-vat-handling-for-edd' ), 'error' );
			return $kept;
		}
		return $parsed->canonical();
	}

	private function sanitize_uk_store_vat_number( string $raw, string $kept ): string {
		if ( '' === $raw ) {
			return '';
		}
		$parsed = VatNumber::parse( $raw, 'GB' );
		if ( null === $parsed || 'GB' !== $parsed->prefix() || ! FormatCheck::is_plausible( $parsed ) ) {
			add_settings_error( 'vatright-edd', 'store_vat_number_gb', __( 'The UK store VAT number does not look like a GB number, so it was not saved.', 'eu-vat-handling-for-edd' ), 'error' );
			return $kept;
		}
		return $parsed->canonical();
	}

	/**
	 * @return array<string,string>
	 */
	private function currency_options(): array {
		return array_map( 'wp_strip_all_tags', edd_get_currencies() );
	}

	private function rates_summary(): void {
		$pending = (int) get_option( RateSync::OPTION_PENDING, 0 );
		printf(
			'<p>%1$s <a href="%2$s">%3$s</a></p>',
			esc_html(
				$pending > 0
					/* translators: %d: number of rate changes. */
					? sprintf( _n( '%d rate change is waiting for review.', '%d rate changes are waiting for review.', $pending, 'eu-vat-handling-for-edd' ), $pending )
					: __( 'Your tax rates match the latest EU rates.', 'eu-vat-handling-for-edd' )
			),
			esc_url( RatesScreen::url() ),
			esc_html__( 'Open the rates screen', 'eu-vat-handling-for-edd' )
		);
	}

	private function name( string $key ): string {
		return self::FIELD . '[' . $key . ']';
	}

	private function text_row( string $key, string $title, string $value, string $description = '' ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="%1$s" name="%3$s" value="%4$s" />%5$s</td></tr>',
			esc_attr( self::FIELD . '_' . $key ),
			esc_html( $title ),
			esc_attr( $this->name( $key ) ),
			esc_attr( $value ),
			'' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>'
		);
	}

	private function secret_row( string $key, string $title, bool $saved ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="password" class="regular-text" id="%1$s" name="%3$s" value="" autocomplete="new-password" placeholder="%4$s" /><p class="description">%5$s</p></td></tr>',
			esc_attr( self::FIELD . '_' . $key ),
			esc_html( $title ),
			esc_attr( $this->name( $key ) ),
			esc_attr( $saved ? __( 'Saved. Leave empty to keep it.', 'eu-vat-handling-for-edd' ) : '' ),
			esc_html__( 'The saved secret is never shown. Clear the client ID to remove it.', 'eu-vat-handling-for-edd' )
		);
	}

	private function number_row( string $key, string $title, int $value, int $min, int $max ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="number" class="small-text" id="%1$s" name="%3$s" value="%4$d" min="%5$d" max="%6$d" /></td></tr>',
			esc_attr( self::FIELD . '_' . $key ),
			esc_html( $title ),
			esc_attr( $this->name( $key ) ),
			absint( $value ),
			absint( $min ),
			absint( $max )
		);
	}

	private function checkbox_row( string $key, string $title, string $label, bool $checked, string $description = '' ): void {
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="%2$s" value="1"%3$s /> %4$s</label>%5$s</td></tr>',
			esc_html( $title ),
			esc_attr( $this->name( $key ) ),
			checked( $checked, true, false ),
			esc_html( $label ),
			'' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>'
		);
	}

	/**
	 * @param string[]             $selected
	 * @param array<string,string> $options
	 */
	private function multiselect_row( string $key, string $title, array $selected, array $options, string $description = '' ): void {
		$html = '';
		foreach ( $options as $option => $label ) {
			if ( '' === (string) $option ) {
				continue;
			}
			$html .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $option ), selected( in_array( (string) $option, $selected, true ), true, false ), esc_html( $label ) );
		}
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><select id="%1$s" name="%3$s[]" multiple="multiple" size="8" style="min-width:350px">%4$s</select>%5$s</td></tr>',
			esc_attr( self::FIELD . '_' . $key ),
			esc_html( $title ),
			esc_attr( $this->name( $key ) ),
			$html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- options are escaped above.
			'' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>'
		);
	}

	/**
	 * @param array<string,string> $options
	 */
	private function select_row( string $key, string $title, string $value, array $options, string $description = '' ): void {
		$html = '';
		foreach ( $options as $option => $label ) {
			$html .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $option ), selected( $value, (string) $option, false ), esc_html( $label ) );
		}
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><select id="%1$s" name="%3$s">%4$s</select>%5$s</td></tr>',
			esc_attr( self::FIELD . '_' . $key ),
			esc_html( $title ),
			esc_attr( $this->name( $key ) ),
			$html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- options are escaped above.
			'' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>'
		);
	}
}
