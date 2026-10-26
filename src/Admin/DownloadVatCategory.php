<?php

namespace VatrightEdd\Admin;

use VatrightEdd\Rates\RateResolver;
use WP_Post;

class DownloadVatCategory {

	const META_KEY = '_vatright_edd_category';

	const NONCE = 'vatright_edd_category_nonce';

	public function register(): void {
		add_action( 'add_meta_boxes_download', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_download', array( $this, 'save' ) );
	}

	public static function for_download( int $download_id ): string {
		$stored = (string) get_post_meta( $download_id, self::META_KEY, true );
		return in_array( $stored, RateResolver::categories(), true ) ? $stored : RateResolver::STANDARD;
	}

	public function add_meta_box(): void {
		add_meta_box( 'vatright_edd_category', __( 'EU VAT rate', 'eu-vat-handling-for-edd' ), array( $this, 'render' ), 'download', 'side' );
	}

	public function render( WP_Post $post ): void {
		$current = self::for_download( $post->ID );
		$labels  = array(
			RateResolver::STANDARD      => __( 'Standard rate', 'eu-vat-handling-for-edd' ),
			RateResolver::REDUCED       => __( 'Reduced rate (for example e-books)', 'eu-vat-handling-for-edd' ),
			RateResolver::SUPER_REDUCED => __( 'Super-reduced rate', 'eu-vat-handling-for-edd' ),
		);
		wp_nonce_field( self::NONCE, self::NONCE );
		echo '<p><label for="vatright_edd_category_field" class="screen-reader-text">' . esc_html__( 'EU VAT rate', 'eu-vat-handling-for-edd' ) . '</label>';
		echo '<select name="vatright_edd_category" id="vatright_edd_category_field">';
		foreach ( $labels as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'Which EU rate applies to this product when VAT is charged at the customer\'s country rate.', 'eu-vat-handling-for-edd' ) . '</p>';
	}

	public function save( int $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$category = isset( $_POST['vatright_edd_category'] ) ? sanitize_key( wp_unslash( $_POST['vatright_edd_category'] ) ) : RateResolver::STANDARD;
		if ( ! in_array( $category, RateResolver::categories(), true ) ) {
			$category = RateResolver::STANDARD;
		}
		if ( RateResolver::STANDARD === $category ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}
		update_post_meta( $post_id, self::META_KEY, $category );
	}
}
