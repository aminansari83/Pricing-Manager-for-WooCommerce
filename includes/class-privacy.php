<?php
namespace Andiya\PriceGuard;
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plugin-owned tables use fresh reads for locks, version checks and private offers; caching would defeat concurrency guards.
final class Privacy {

	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action(
			'admin_init',
			static function () {
				if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
					wp_add_privacy_policy_content( 'Pricing Manager for WooCommerce', wpautop( esc_html__( 'برای پاسخ به استعلام، نام، شماره موبایل، ایمیل اختیاری و کالاهای انتخاب‌شده ذخیره می‌شود. اطلاعات به آندیا ارسال نمی‌شود. پیوند پیگیری خصوصی است. فهرست کالاهای استعلام در مرورگر شما نگهداری می‌شود. ایمیل و دریافت از تأمین‌کننده فقط با تنظیم مدیر فعال می‌شوند. درخواست‌های بسته یا منقضی‌شده پس از مدت نگهداری انتخابی حذف می‌شوند؛ درخواست‌های باز تا رسیدگی نگهداری می‌شوند. حذف اطلاعات شخصی سفارش‌ها از ابزار حریم خصوصی خود ووکامرس انجام می‌شود.', 'andiya-price-guard' ) ) );
				}
			}
		);
	}

	public static function exporters( array $items ): array {
		$items['andiya-price-guard'] = array(
			'exporter_friendly_name' => 'Pricing Manager for WooCommerce',
			'callback'               => array( self::class, 'export' ),
		);
		return $items;
	}

	public static function erasers( array $items ): array {
		$items['andiya-price-guard'] = array(
			'eraser_friendly_name' => 'Pricing Manager for WooCommerce',
			'callback'             => array( self::class, 'erase' ),
		);
		return $items;
	}

	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$t = Storage::table( 'quotes' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email=%s ORDER BY id LIMIT 50 OFFSET %d', $t, $email, max( 0, $page - 1 ) * 50 ), ARRAY_A );
		$data = array();
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $q ) {
			$fields = array();
			foreach ( array( 'name', 'phone', 'email', 'note', 'items', 'offer', 'created', 'status', 'order_id' ) as $key ) {
				$fields[] = array(
					'name'  => $key,
					'value' => $q[ $key ],
				);
			}
			$data[] = array(
				'group_id'    => 'apg-quotes',
				'group_label' => __( 'استعلام‌ها', 'andiya-price-guard' ),
				'item_id'     => 'apg-' . $q['id'],
				'data'        => $fields,
			);
		}
		return array(
			'data' => $data,
			'done' => count( $rows ) < 50,
		);
	}

	public static function anonymize( int $id ): void {
		global $wpdb;
		$q = Storage::get( 'quotes', $id );
		if ( ! $q ) {
			return;
		}
		$offer = Support::decode( $q['offer'] );
		if ( $offer ) {
			$offer['note'] = '';
			$offer['delivery'] = '';
		}
		$wpdb->update(
			Storage::table( 'quotes' ),
			array(
				'name'         => __( 'اطلاعات حذف‌شده', 'andiya-price-guard' ),
				'phone'        => '',
				'email'        => '',
				'note'         => '',
				'offer'        => Support::json( $offer ),
				'user_id'      => 0,
				'secret'       => bin2hex( random_bytes( 24 ) ),
				'intake_key'   => hash( 'sha256', random_bytes( 24 ) ),
				'payload_hash' => hash( 'sha256', random_bytes( 24 ) ),
				'status'       => 'closed',
			),
			array( 'id' => $id )
		);
		Storage::audit( 'quote_anonymized', $id );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		$t = Storage::table( 'quotes' );
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE email=%s ORDER BY id LIMIT 50', $t, $email ) );
		foreach ( $rows as $id ) {
			self::anonymize( (int) $id );
		}
		return array(
			'items_removed'  => ! empty( $rows ),
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $rows ) < 50,
		);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function cleanup(): void {
		global $wpdb;
		$t = Storage::table( 'quotes' );
		$cutoff = time() - (int) Support::settings()['retention'] * DAY_IN_SECONDS;
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE created < %d AND (status IN ('closed','accepted') OR (status='offered' AND expires<%d)) LIMIT 100", $t, $cutoff, time() ) );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$t = Storage::table( 'audit' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created < %d LIMIT 100', $t, time() - YEAR_IN_SECONDS ) );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
