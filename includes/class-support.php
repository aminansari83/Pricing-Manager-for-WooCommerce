<?php
namespace Andiya\PriceGuard;
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plugin-owned tables use fresh reads for locks, version checks and private offers; caching would defeat concurrency guards.
final class Support {
	public const META = array( '_apg_state', '_apg_exclude', '_apg_checked', '_apg_expires', '_apg_source' );

	public static function defaults(): array {
		return array(
			'mode'           => 'normal',
			'categories'     => array(),
			'deactivation'   => 'restore',
			'valid_hours'    => 0,
			'offer_hours'    => 24,
			'retention'      => 90,
			'email_admin'    => false,
			'email_customer' => false,
			'delete_data'    => false,
			'label'          => __( 'استعلام قیمت و موجودی', 'andiya-price-guard' ),
			'message'        => __( 'قیمت این کالا نیاز به تأیید دارد. درخواست شما بدون پرداخت ثبت می‌شود.', 'andiya-price-guard' ),
			'stop_message'   => __( 'فروش این کالا موقتاً متوقف شده است.', 'andiya-price-guard' ),
		);
	}

	public static function settings(): array {
		return wp_parse_args( get_option( 'apg_settings', array() ), self::defaults() );
	}

	public static function digits( $value ): string {
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			throw new \InvalidArgumentException( esc_html__( 'عدد واردشده معتبر نیست.', 'andiya-price-guard' ) );
		}
		return str_replace( array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩', '٫', '٬', ',', "\xE2\x80\x8C" ), array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '', '', '' ), trim( (string) $value ) );
	}

	public static function number( $value, float $min = 0, float $max = 9000000000000 ): float {
		$v = self::digits( $value );
		if ( ! preg_match( '/^-?\d+(?:\.\d+)?$/D', $v ) || ! is_finite( (float) $v ) || (float) $v < $min || (float) $v > $max ) {
			throw new \InvalidArgumentException( esc_html__( 'عدد واردشده معتبر نیست.', 'andiya-price-guard' ) );
		}
		return (float) $v;
	}

	public static function integer( $value, int $min, int $max ): int {
		$n = self::number( $value, $min, $max );
		if ( floor( $n ) !== $n ) {
			throw new \InvalidArgumentException( esc_html__( 'تعداد باید عدد صحیح باشد.', 'andiya-price-guard' ) );
		}
		return (int) $n;
	}

	public static function money( $n ): string {
		$value = wc_format_decimal( $n, wc_get_price_decimals() );
		self::number( $value, 0.01 );
		return $value;
	}

	public static function phone( $v ): string {
		$v = preg_replace( '/[\s()\-]/', '', self::digits( $v ) );
		$v = preg_replace( '/^(?:\+98|0098)/', '0', $v );
		if ( preg_match( '/^9\d{9}$/D', $v ) ) {
			$v = '0' . $v;
		}
		if ( ! preg_match( '/^09\d{9}$/D', $v ) ) {
			throw new \InvalidArgumentException( esc_html__( 'شماره موبایل ایران را درست وارد کنید.', 'andiya-price-guard' ) );
		}
		return $v;
	}

	public static function convert( $n, string $from, string $to ): string {
		$units = array(
			'IRR'  => 1,
			'IRT'  => 10,
			'IRHR' => 1000,
			'IRHT' => 10000,
		);
		if ( $from === $to ) {
			return self::money( self::number( $n, 0.01 ) );
		}
		if ( ! isset( $units[ $from ], $units[ $to ] ) ) {
			throw new \InvalidArgumentException( esc_html__( 'تبدیل این دو واحد پول پشتیبانی نمی‌شود.', 'andiya-price-guard' ) );
		}
		return self::money( self::number( $n, 0.01 ) * $units[ $from ] / $units[ $to ] );
	}

	public static function json( $v ): string {
		return (string) wp_json_encode( $v, JSON_UNESCAPED_UNICODE );
	}

	public static function decode( $v ): array {
		$v = json_decode( (string) $v, true );
		return is_array( $v ) ? $v : array();
	}

	public static function snapshot( \WC_Product $p ): array {
		$r = array(
			'regular_price' => $p->get_regular_price( 'edit' ),
			'sale_price'    => $p->get_sale_price( 'edit' ),
		);
		foreach ( self::META as $k ) {
			$r[ $k ] = (string) $p->get_meta( $k, true, 'edit' );
		}
		return $r;
	}

	public static function fingerprint( array $v ): string {
		return hash( 'sha256', self::json( $v ) );
	}

	public static function set( \WC_Product $p, string $k, $v ): void {
		if ( 'regular_price' === $k ) {
			$p->set_regular_price( $v );
		} elseif ( 'sale_price' === $k ) {
			$p->set_sale_price( $v );
		} elseif ( in_array( $k, self::META, true ) ) {
			'' === (string) $v ? $p->delete_meta_data( $k ) : $p->update_meta_data( $k, $v );
		} else {
				throw new \InvalidArgumentException( 'Invalid field' );
		}
	}

	public static function fresh( int $id ) {
		clean_post_cache( $id );
		return wc_get_product( $id );
	}

	public static function purge( array $ids, bool $pages = true ): void {
		foreach ( array_unique( $ids ) as $id ) {
			wc_delete_product_transients( $id );
			$p = wc_get_product( $id );
			if ( $p && $p->is_type( 'variation' ) ) {
				\WC_Product_Variable::sync( $p->get_parent_id() );
				wc_delete_product_transients( $p->get_parent_id() );
			}
		}
		if ( $pages ) {
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
			}
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public LiteSpeed cache integration hook.
		}
		do_action( 'andiya_pg_catalog_changed', $ids );
	}

	public static function lock( string $key, int $ttl = 60 ): string {
		global $wpdb;
		$name = 'apg_lock_' . hash( 'sha256', $key );
		$token = wp_generate_uuid4();
		$value = self::json(
			array(
				'token'   => $token,
				'expires' => time() + $ttl,
			)
		);
		if ( add_option( $name, $value, '', false ) ) {
			return $token;
		}
		$old = get_option( $name );
		if ( ( self::decode( $old )['expires'] ?? PHP_INT_MAX ) < time() ) {
			$ok = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value=%s WHERE option_name=%s AND option_value=%s', $wpdb->options, $value, $name, $old ) );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_delete( $name, 'options' );
			if ( $ok ) {
				return $token;
			}
		}
		return '';
	}

	public static function unlock( string $key, string $token ): void {
		global $wpdb;
		$name = 'apg_lock_' . hash( 'sha256', $key );
		$old = get_option( $name );
		if ( ( self::decode( $old )['token'] ?? '' ) === $token ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name=%s AND option_value=%s', $wpdb->options, $name, $old ) );
			wp_cache_delete( $name, 'options' );
		}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function running(): bool {
		global $wpdb;
		return '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, 'apg_running' ) );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function public_product( $p ): bool {
		if ( ! $p || ! $p->is_type( array( 'simple', 'variable', 'variation' ) ) ) {
			return false;
		}
		$parent = $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : $p;
		return $parent && 'publish' === $p->get_status() && 'publish' === $parent->get_status() && ! post_password_required( $parent->get_id() );
	}

	public static function result( $data ) {
		return rest_ensure_response( $data );
	}

	public static function error( \Throwable $e ) {
		return new \WP_Error( 'apg_invalid', $e->getMessage(), array( 'status' => 400 ) );
	}
}
