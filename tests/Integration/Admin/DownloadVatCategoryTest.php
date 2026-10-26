<?php

namespace VatrightEdd\Tests\Integration\Admin;

use VatrightEdd\Admin\DownloadVatCategory;
use VatrightEdd\Rates\RateResolver;
use VatrightEdd\Tests\Support\IntegrationTestCase;

class DownloadVatCategoryTest extends IntegrationTestCase {

	/** @var int */
	private $download;

	public function set_up() {
		parent::set_up();
		$this->download = self::factory()->post->create( array( 'post_type' => 'download' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new DownloadVatCategory() )->register();
	}

	public function tear_down() {
		unset( $_POST[ DownloadVatCategory::NONCE ], $_POST['vatright_edd_category'] );
		parent::tear_down();
	}

	private function post_category( string $value, bool $with_nonce = true ): void {
		$_POST['vatright_edd_category'] = $value;
		if ( $with_nonce ) {
			$_POST[ DownloadVatCategory::NONCE ] = wp_create_nonce( DownloadVatCategory::NONCE );
		}
	}

	public function test_a_download_defaults_to_the_standard_category(): void {
		$this->assertSame( RateResolver::STANDARD, DownloadVatCategory::for_download( $this->download ) );
	}

	public function test_saving_stores_the_chosen_category(): void {
		$this->post_category( RateResolver::REDUCED );
		do_action( 'save_post_download', $this->download );

		$this->assertSame( RateResolver::REDUCED, DownloadVatCategory::for_download( $this->download ) );
		$this->assertSame( 'reduced', get_post_meta( $this->download, DownloadVatCategory::META_KEY, true ) );
	}

	public function test_saving_standard_removes_the_meta(): void {
		update_post_meta( $this->download, DownloadVatCategory::META_KEY, RateResolver::SUPER_REDUCED );

		$this->post_category( RateResolver::STANDARD );
		do_action( 'save_post_download', $this->download );

		$this->assertSame( '', get_post_meta( $this->download, DownloadVatCategory::META_KEY, true ) );
	}

	public function test_an_unknown_value_is_stored_as_standard(): void {
		update_post_meta( $this->download, DownloadVatCategory::META_KEY, RateResolver::REDUCED );

		$this->post_category( 'zero' );
		do_action( 'save_post_download', $this->download );

		$this->assertSame( RateResolver::STANDARD, DownloadVatCategory::for_download( $this->download ) );
		$this->assertSame( '', get_post_meta( $this->download, DownloadVatCategory::META_KEY, true ) );
	}

	public function test_a_corrupt_stored_value_reads_as_standard(): void {
		update_post_meta( $this->download, DownloadVatCategory::META_KEY, 'bogus' );

		$this->assertSame( RateResolver::STANDARD, DownloadVatCategory::for_download( $this->download ) );
	}

	public function test_saving_without_the_nonce_changes_nothing(): void {
		$this->post_category( RateResolver::REDUCED, false );
		do_action( 'save_post_download', $this->download );

		$this->assertSame( RateResolver::STANDARD, DownloadVatCategory::for_download( $this->download ) );
	}

	public function test_a_user_who_cannot_edit_the_download_changes_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->post_category( RateResolver::REDUCED );
		do_action( 'save_post_download', $this->download );

		$this->assertSame( RateResolver::STANDARD, DownloadVatCategory::for_download( $this->download ) );
	}

	public function test_the_meta_box_renders_the_current_choice_with_a_nonce(): void {
		update_post_meta( $this->download, DownloadVatCategory::META_KEY, RateResolver::SUPER_REDUCED );

		ob_start();
		( new DownloadVatCategory() )->render( get_post( $this->download ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="' . DownloadVatCategory::NONCE . '"', $html );
		$this->assertMatchesRegularExpression( '/<option value="super_reduced"\s+selected=\'selected\'>/', $html );
		$this->assertStringNotContainsString( 'value="reduced" selected', $html );
	}
}
