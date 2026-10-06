<?php
/**
 * Private operation reports.
 *
 * @package Pricing_Manager_for_WooCommerce
 */

namespace Andiya\PriceGuard;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Merchant-only exports read indexed plugin rows in bounded chunks without caching private prices.

/** Private, streaming operation reports; no public file is created. */
final class Reports {

	/**
	 * Register the authenticated download action.
	 */
	public static function register(): void {
		add_action( 'admin_post_apg_export_batch', array( self::class, 'download' ) );
	}

	/**
	 * Build an operation-specific authenticated export URL.
	 *
	 * @param int $id Operation ID.
	 * @return string
	 */
	public static function url( int $id ): string {
		return add_query_arg(
			array(
				'action'   => 'apg_export_batch',
				'batch_id' => $id,
				'_wpnonce' => wp_create_nonce( 'apg_export_batch_' . $id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Return stable export column identifiers.
	 *
	 * @return array
	 */
	public static function headers(): array {
		return array( 'operation_id', 'product_id', 'sku', 'product_name', 'currency', 'regular_before', 'regular_planned', 'sale_before', 'sale_planned', 'stored_state_before', 'stored_state_planned', 'row_status', 'reason' );
	}

	/**
	 * Preserve text identifiers and neutralize spreadsheet formula prefixes.
	 *
	 * @param mixed $value Cell value.
	 * @param bool  $identifier Whether the value is an identifier.
	 * @return string
	 */
	public static function cell( $value, bool $identifier = false ): string {
		$value = (string) $value;
		if ( preg_match( '/^[\x00-\x20]*[=+\-@]/', $value ) || preg_match( '/^[\t\r\n]/', $value ) || ( $identifier && preg_match( '/^0\d/', $value ) ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Yield all frozen operation rows in bounded cursor pages.
	 *
	 * @param int $id Operation ID.
	 * @return \Generator
	 * @throws \InvalidArgumentException When inputs or operation state are invalid.
	 */
	public static function rows( int $id ): \Generator {
		global $wpdb;
		$batch = Storage::get( 'batches', $id );
		if ( ! $batch ) {
			throw new \InvalidArgumentException( esc_html__( 'عملیات پیدا نشد.', 'andiya-price-guard' ) );
		}
		$cursor = 0;
		do {
			$rows       = $wpdb->get_results( $wpdb->prepare( 'SELECT id,product_id,before_data,after_data,status,reason FROM %i WHERE batch_id=%d AND id>%d ORDER BY id LIMIT 200', Storage::table( 'rows' ), $id, $cursor ), ARRAY_A );
			$page_count = count( $rows );
			foreach ( $rows as $row ) {
				$cursor  = (int) $row['id'];
				$product = wc_get_product( $row['product_id'] );
				$before  = Support::decode( $row['before_data'] );
				$after   = array_merge( $before, Support::decode( $row['after_data'] ) );
				yield array(
					(string) $id,
					(string) $row['product_id'],
					self::cell( $product ? $product->get_sku( 'edit' ) : '', true ),
					self::cell( $product ? $product->get_name() : __( 'محصول حذف‌شده', 'andiya-price-guard' ) ),
					self::cell( $batch['currency'] ),
					self::cell( $before['regular_price'] ?? '' ),
					self::cell( $after['regular_price'] ?? '' ),
					self::cell( $before['sale_price'] ?? '' ),
					self::cell( $after['sale_price'] ?? '' ),
					self::cell( $before['_apg_state'] ?? '' ),
					self::cell( $after['_apg_state'] ?? '' ),
					self::cell( $row['status'] ),
					self::cell( $row['reason'] ),
				);
			}
		} while ( 200 === $page_count );
	}

	/**
	 * Stream a private UTF-8 CSV response for an authorized merchant.
	 */
	public static function download(): void {
		if ( ! current_user_can( 'apg_manage_prices' ) && ! current_user_can( 'apg_view_history' ) ) {
			wp_die( esc_html__( 'اجازه دریافت گزارش قیمت را ندارید.', 'andiya-price-guard' ), '', array( 'response' => 403 ) );
		}
		$id = isset( $_GET['batch_id'] ) ? absint( wp_unslash( $_GET['batch_id'] ) ) : 0;
		check_admin_referer( 'apg_export_batch_' . $id );
		if ( ! $id || ! Storage::get( 'batches', $id ) ) {
			wp_die( esc_html__( 'عملیات پیدا نشد.', 'andiya-price-guard' ), '', array( 'response' => 404 ) );
		}
		$stream = fopen( 'php://output', 'w' );
		if ( false === $stream ) {
			wp_die( esc_html__( 'گزارش قابل دریافت نیست. دوباره تلاش کنید.', 'andiya-price-guard' ), '', array( 'response' => 500 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="andiya-prices-' . $id . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write UTF-8 BOM only to the authenticated response stream, not the filesystem.
		fwrite( $stream, "\xEF\xBB\xBF" );
		fputcsv( $stream, self::headers(), ',', '"', '' );
		foreach ( self::rows( $id ) as $row ) {
			fputcsv( $stream, $row, ',', '"', '' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close only the response stream opened above.
		fclose( $stream );
		exit;
	}
}
