<?php
namespace Andiya\PriceGuard;
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plugin-owned tables use fresh reads for locks, version checks and private offers; caching would defeat concurrency guards.
final class Quotes {
	private static array $accepting = array();
	private static ?\WeakMap $authorized = null;

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'template_redirect', array( self::class, 'page' ) );
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'cart_prices' ), 98 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( self::class, 'session' ), 99, 3 );
		add_filter( 'woocommerce_cart_item_is_purchasable', array( self::class, 'session_purchasable' ), 99, 4 );
		add_filter( 'woocommerce_update_cart_validation', array( self::class, 'quantity' ), 99, 4 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( self::class, 'coupon' ), 99, 4 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'order_line' ), 90, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'bind' ), 99, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'bind' ), 99 );
		add_action( 'woocommerce_account_dashboard', array( self::class, 'account' ) );
		add_shortcode( 'apg_quote_list', array( self::class, 'shortcode' ) );
	}

	public static function accepting( int $id ): bool {
		return isset( self::$accepting[ $id ] );
	}

	public static function accepted_price( int $id ): ?string {
		return self::$accepting[ $id ] ?? null;
	}

	/** Cart authorization belongs to this object and request, never to saved product metadata. */
	public static function authorized( \WC_Product $product ): bool {
		return self::$authorized && isset( self::$authorized[ $product ] );
	}

	private static function authorize( \WC_Product $product, ?string $price ): void {
		self::$authorized = self::$authorized ?? new \WeakMap();
		unset( self::$authorized[ $product ] );
		if ( null !== $price ) {
			self::$authorized[ $product ] = true;
			$product->set_price( $price );
		}
	}

	public static function routes(): void {
		register_rest_route(
			'andiya-price-guard/v1',
			'/nonce',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$r = new \WP_REST_Response( array( 'nonce' => wp_create_nonce( 'apg_intake' ) ) );
					$r->header( 'Cache-Control', 'no-store, private' );
					return $r;
				},
			)
		);
		foreach ( array(
			'/quotes'             => 'intake',
			'/cart/accept-prices' => 'accept_prices',
		) as $route => $method ) {
			register_rest_route(
				'andiya-price-guard/v1',
				$route,
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => array( self::class, $method ),
				)
			);
		}
	}

	public static function nonce( $request ): void {
		if ( ! wp_verify_nonce( $request->get_header( 'X-APG-Nonce' ), 'apg_intake' ) ) {
			throw new \InvalidArgumentException( esc_html__( 'نشست فرم منقضی شده است. صفحه را تازه کنید.', 'andiya-price-guard' ) );
		}
		if ( strlen( (string) $request->get_body() ) > 65536 ) {
			throw new \InvalidArgumentException( esc_html__( 'درخواست بیش از اندازه بزرگ است.', 'andiya-price-guard' ) );
		}
	}

	public static function intake( $request ) {
		try {
			self::nonce( $request );
			return Support::result( self::create( (array) $request->get_json_params() ) );
		} catch ( \Throwable $e ) {
			return Support::error( $e );
		}
	}

	public static function create( array $data ): array {
		global $wpdb;
		if ( ! empty( $data['website'] ) ) {
			throw new \InvalidArgumentException( esc_html__( 'درخواست نامعتبر است.', 'andiya-price-guard' ) );
		}
		$name = sanitize_text_field( $data['name'] ?? '' );
		$phone = Support::phone( $data['phone'] ?? '' );
		$email = sanitize_email( $data['email'] ?? '' );
		$note = sanitize_textarea_field( $data['note'] ?? '' );
		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 180 || mb_strlen( $note ) > 2000 || ( ! empty( $data['email'] ) && ! is_email( $email ) ) ) {
			throw new \InvalidArgumentException( esc_html__( 'نام، ایمیل یا توضیح فرم معتبر نیست.', 'andiya-price-guard' ) );
		}
		$items = array();
		if ( empty( $data['items'] ) || count( (array) $data['items'] ) > 50 ) {
			throw new \InvalidArgumentException( esc_html__( 'از یک تا پنجاه کالا انتخاب کنید.', 'andiya-price-guard' ) );
		}
		foreach ( $data['items'] as $line ) {
			$id = absint( $line['id'] ?? 0 );
			$p = wc_get_product( $id );
			$qty = Support::integer( $line['qty'] ?? 1, 1, 999 );
			if ( ! Support::public_product( $p ) || ! $p->is_type( array( 'simple', 'variation' ) ) || 'stop' === Policy::effective( $p )['state'] || isset( $items[ $id ] ) ) {
				throw new \InvalidArgumentException( esc_html__( 'مدل دقیق کالا را انتخاب کنید؛ کالای متوقف یا تکراری قابل استعلام نیست.', 'andiya-price-guard' ) );
			}
			$items[ $id ] = array(
				'id'   => $id,
				'qty'  => $qty,
				'name' => $p->get_name(),
				'sku'  => $p->get_sku( 'edit' ),
			);
		}
		$key = (string) ( $data['request_key'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/D', $key ) ) {
			throw new \InvalidArgumentException( esc_html__( 'شناسه درخواست معتبر نیست.', 'andiya-price-guard' ) );
		}
		$intake = hash_hmac( 'sha256', $phone . ':' . $key, wp_salt( 'auth' ) );
		$hash = Support::fingerprint( array( $name, $phone, $email, $items, $note, get_current_user_id() ) );
		$t = Storage::table( 'quotes' );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE intake_key=%s', $t, $intake ), ARRAY_A );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $existing ) {
			if ( ! hash_equals( $existing['payload_hash'], $hash ) ) {
				throw new \InvalidArgumentException( esc_html__( 'این درخواست قبلاً با اطلاعات دیگری ثبت شده است.', 'andiya-price-guard' ) );
			}
			return self::receipt( $existing );
		}
		self::rate( 'phone:' . $phone, 5 );
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		self::rate( 'ip:' . $ip, 60 );
		$wpdb->insert(
			$t,
			array(
				'secret'       => bin2hex( random_bytes( 24 ) ),
				'intake_key'   => $intake,
				'payload_hash' => $hash,
				'user_id'      => get_current_user_id(),
				'name'         => $name,
				'phone'        => $phone,
				'email'        => $email,
				'items'        => Support::json( array_values( $items ) ),
				'note'         => $note,
				'offer'        => '{}',
				'status'       => 'new',
				'created'      => time(),
			)
		);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE intake_key=%s', $t, $intake ), ARRAY_A );
			if ( $existing && hash_equals( $existing['payload_hash'], $hash ) ) {
				return self::receipt( $existing );
			}
			throw new \RuntimeException( esc_html__( 'درخواست ثبت نشد؛ دوباره تلاش کنید.', 'andiya-price-guard' ) );
		}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Storage::audit( 'quote_created', $id );
		$q = Storage::get( 'quotes', $id );
		self::notify( $q, false );
		return self::receipt( $q );
	}

	public static function rate( string $value, int $limit ): void {
		$key = 'apg_rate_' . hash_hmac( 'sha256', $value, wp_salt( 'nonce' ) );
		$token = Support::lock( $key );
		if ( ! $token ) {
			throw new \RuntimeException( esc_html__( 'کمی صبر کنید و دوباره تلاش کنید.', 'andiya-price-guard' ) );
		}
		try {
			$n = (int) get_transient( $key );
			if ( $n >= $limit ) {
				throw new \RuntimeException( esc_html__( 'تعداد درخواست زیاد است. ده دقیقه دیگر تلاش کنید.', 'andiya-price-guard' ) );
			}
			set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		} finally {
			Support::unlock( $key, $token );
		}
	}

	public static function signature( array $q ): string {
		return hash_hmac( 'sha256', $q['id'] . ':' . $q['secret'], wp_salt( 'auth' ) );
	}

	public static function url( array $q ): string {
		return add_query_arg(
			array(
				'apg_quote' => $q['id'],
				'apg_token' => self::signature( $q ),
			),
			home_url( '/' )
		);
	}

	public static function receipt( array $q ): array {
		return array(
			'id'      => (int) $q['id'],
			'url'     => self::url( $q ),
			'message' => __( 'درخواست ثبت شد. این پیوند خصوصی را برای پیگیری نگه دارید؛ فروشگاه پس از بررسی با شما تماس می‌گیرد.', 'andiya-price-guard' ),
		);
	}

	public static function offer( int $id, array $data ): array {
		global $wpdb;
		$key = 'quote:' . $id;
		$token = Support::lock( $key );
		if ( ! $token ) {
			throw new \RuntimeException( esc_html__( 'این استعلام هم‌زمان در حال تغییر است.', 'andiya-price-guard' ) );
		}
		try {
			$q = Storage::get( 'quotes', $id );
			if ( ! $q || $q['order_id'] || 'closed' === $q['status'] || empty( $data['confirmed'] ) ) {
				throw new \InvalidArgumentException( esc_html__( 'استعلام بسته شده یا تأیید قیمت و موجودی انجام نشده است.', 'andiya-price-guard' ) );
			}
			$items = Support::decode( $q['items'] );
			$prices = (array) ( $data['prices'] ?? array() );
			foreach ( $items as &$item ) {
				$p = wc_get_product( $item['id'] );
				if ( ! Support::public_product( $p ) || 'stop' === Policy::effective( $p )['state'] || ! $p->is_in_stock() || ! $p->has_enough_stock( $item['qty'] ) ) {
					throw new \InvalidArgumentException( esc_html__( 'موجودی یا امکان فروش یکی از کالاها کافی نیست.', 'andiya-price-guard' ) );
				}
				$item['price'] = Support::money( Support::number( $prices[ $item['id'] ] ?? '', 0.01 ) );
			}
			$hours = Support::integer( $data['hours'] ?? Support::settings()['offer_hours'], 1, 720 );
			$offer = array(
				'items'    => $items,
				'currency' => get_woocommerce_currency(),
				'delivery' => sanitize_text_field( $data['delivery'] ?? '' ),
				'note'     => sanitize_textarea_field( $data['note'] ?? '' ),
			);
			$wpdb->update(
				Storage::table( 'quotes' ),
				array(
					'offer'   => Support::json( $offer ),
					'expires' => time() + $hours * HOUR_IN_SECONDS,
					'version' => (int) $q['version'] + 1,
					'status'  => 'offered',
				),
				array(
					'id'       => $id,
					'order_id' => 0,
				)
			);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$q = Storage::get( 'quotes', $id );
			Storage::audit( 'quote_offered', $id );
			self::notify( $q, true );
			return array(
				'id'      => $id,
				'url'     => self::url( $q ),
				'status'  => $q['status'],
				'expires' => (int) $q['expires'],
			);
		} finally {
			Support::unlock( $key, $token );
		}
	}

	public static function notify( array $q, bool $offered ): void {
		$s = Support::settings();
		$subject = $offered ? __( 'پیشنهاد قیمت آماده است', 'andiya-price-guard' ) : __( 'استعلام جدید ثبت شد', 'andiya-price-guard' );
		$body = $subject . "\n" . __( 'شماره پیگیری:', 'andiya-price-guard' ) . ' ' . $q['id'] . "\n" . self::url( $q );
		$fail = false;
		if ( $s['email_admin'] && ! $offered ) {
			$fail = ! wp_mail( get_option( 'admin_email' ), $subject, $body );
		}
		if ( $s['email_customer'] && $q['email'] ) {
			$fail = ! wp_mail( $q['email'], $subject, $body ) || $fail;
		}
		if ( $fail ) {
			Storage::audit( 'email_failed', (int) $q['id'] );
		}
	}

	public static function proof( array $q, array $item ): string {
		return hash_hmac( 'sha256', $q['id'] . ':' . $q['version'] . ':' . $item['id'] . ':' . $item['qty'], $q['secret'] . wp_salt( 'auth' ) );
	}

	public static function cart_error( array $line ): string {
		$q = Storage::get( 'quotes', absint( $line['apg_quote'] ?? 0 ) );
		if ( ! $q || 'offered' !== $q['status'] || $q['order_id'] || (int) $q['expires'] <= time() || (int) ( $line['apg_version'] ?? 0 ) !== (int) $q['version'] ) {
			return __( 'پیشنهاد قیمت منقضی، تغییرکرده یا قبلاً به سفارش تبدیل شده است.', 'andiya-price-guard' );
		}
		$offer = Support::decode( $q['offer'] );
		if ( ( $offer['currency'] ?? '' ) !== get_woocommerce_currency() ) {
			return __( 'واحد پول فروشگاه تغییر کرده است؛ پیشنهاد جدید لازم است.', 'andiya-price-guard' );
		}
		foreach ( $offer['items'] ?? array() as $item ) {
			if ( (int) $item['id'] === (int) ( $line['variation_id'] ?: $line['product_id'] ) ) {
				$p = Support::fresh( (int) $item['id'] );
				if ( $p && (int) $item['qty'] === (int) $line['quantity'] && hash_equals( self::proof( $q, $item ), (string) ( $line['apg_proof'] ?? '' ) ) && 'stop' !== Policy::effective( $p )['state'] ) {
					return '';
				}
			}
		}
		return __( 'محتوای سبد با پیشنهاد معتبر مطابقت ندارد.', 'andiya-price-guard' );
	}

	public static function line_price( array $line ): ?string {
		if ( self::cart_error( $line ) ) {
			return null;
		}
		$q = Storage::get( 'quotes', (int) $line['apg_quote'] );
		foreach ( Support::decode( $q['offer'] )['items'] as $item ) {
			if ( (int) $item['id'] === (int) ( $line['variation_id'] ?: $line['product_id'] ) ) {
				return $item['price'];
			}
		}
		return null;
	}

	public static function session( $line, $values, $key ): array {
		if ( isset( $line['apg_quote'] ) ) {
			$price = self::line_price( $line );
			self::authorize( $line['data'], $price );
		}
		return $line;
	}

	/** WooCommerce checks purchasability before the cart-item session filter runs. */
	public static function session_purchasable( $ok, $key, $values, $product ): bool {
		if ( ! isset( $values['apg_quote'] ) ) {
			return (bool) $ok;
		}
		$price = self::line_price( $values );
		self::authorize( $product, $price );
		return null !== $price && Support::public_product( $product ) && $product->is_purchasable();
	}

	public static function cart_prices( $cart ): void {
		foreach ( $cart->get_cart() as $line ) {
			if ( isset( $line['apg_quote'] ) ) {
				$price = self::line_price( $line );
				self::authorize( $line['data'], $price );
			}
		}
	}

	public static function quantity( $ok, $key, $line, $qty ): bool {
		if ( isset( $line['apg_quote'] ) && (int) $qty !== (int) $line['quantity'] && 0 !== (int) $qty ) {
			wc_add_notice( __( 'تعداد پیشنهاد ثابت است. برای تعداد دیگر استعلام جدید ثبت کنید.', 'andiya-price-guard' ), 'error' );
			return false;
		}
		return (bool) $ok;
	}

	public static function coupon( $valid, $p, $coupon, $values ): bool {
		return isset( $values['apg_quote'] ) ? false : (bool) $valid;
	}

	public static function accept( array $q ): string {
		if ( 'offered' !== $q['status'] || $q['order_id'] || (int) $q['expires'] <= time() ) {
			throw new \RuntimeException( esc_html__( 'این پیشنهاد دیگر قابل پذیرش نیست.', 'andiya-price-guard' ) );
		}
		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		$offer = Support::decode( $q['offer'] );
		if ( ( $offer['currency'] ?? '' ) !== get_woocommerce_currency() ) {
			throw new \RuntimeException( esc_html__( 'واحد پول تغییر کرده است.', 'andiya-price-guard' ) );
		}
		foreach ( WC()->cart->get_cart() as $line ) {
			if ( isset( $line['apg_quote'] ) && (int) $line['apg_quote'] !== (int) $q['id'] ) {
				throw new \RuntimeException( esc_html__( 'پیشنهاد قبلی سبد را تکمیل یا حذف کنید، سپس پیشنهاد جدید را بپذیرید.', 'andiya-price-guard' ) );
			}
		}
		$added = array();
		try {
			foreach ( $offer['items'] as $item ) {
				$p = Support::fresh( (int) $item['id'] );
				if ( ! Support::public_product( $p ) || 'stop' === Policy::effective( $p )['state'] ) {
					throw new \RuntimeException( esc_html__( 'فروش یکی از کالاها متوقف شده است.', 'andiya-price-guard' ) );
				}
				$exists = false;
				foreach ( WC()->cart->get_cart() as $line ) {
					if ( (int) ( $line['apg_quote'] ?? 0 ) === (int) $q['id'] && (int) ( $line['apg_version'] ?? 0 ) === (int) $q['version'] && (int) ( $line['variation_id'] ?: $line['product_id'] ) === (int) $item['id'] ) {
						$exists = true;
						break;
					}
				}
				if ( $exists ) {
					continue;
				}
				self::$accepting[ $p->get_id() ] = $item['price'];
				$variation = $p->is_type( 'variation' );
				$key = WC()->cart->add_to_cart(
					$variation ? $p->get_parent_id() : $p->get_id(),
					$item['qty'],
					$variation ? $p->get_id() : 0,
					$variation ? $p->get_variation_attributes() : array(),
					array(
						'apg_quote'   => (int) $q['id'],
						'apg_version' => (int) $q['version'],
						'apg_proof'   => self::proof( $q, $item ),
					)
				);
				unset( self::$accepting[ $p->get_id() ] );
				if ( ! $key ) {
					throw new \RuntimeException( esc_html__( 'موجودی کافی نیست یا افزودن کالا انجام نشد.', 'andiya-price-guard' ) );
				}
				$added[] = $key;
			}
			WC()->cart->calculate_totals();
			WC()->cart->set_session();
			return wc_get_checkout_url();
		} catch ( \Throwable $e ) {
			self::$accepting = array();
			foreach ( $added as $key ) {
				WC()->cart->remove_cart_item( $key );
			}
			throw $e;
		}
	}

	public static function order_line( $item, $key, $values, $order ): void {
		if ( isset( $values['apg_quote'] ) ) {
			$e = self::cart_error( $values );
			if ( $e ) {
				throw new \Exception( esc_html( $e ) );
			}
			$item->add_meta_data( '_apg_quote', $values['apg_quote'], true );
			$item->add_meta_data( '_apg_version', $values['apg_version'], true );
		}
	}

	public static function bind( $order_or_id, $data = array(), $order = null ): void {
		global $wpdb;
		$order = $order_or_id instanceof \WC_Order ? $order_or_id : ( $order ?: wc_get_order( $order_or_id ) );
		if ( ! $order ) {
			return;
		}
		$ids = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item->get_meta( '_apg_quote' ) ) {
				$ids[] = (int) $item->get_meta( '_apg_quote' );
			}
		}
		$ids = array_unique( $ids );
		if ( count( $ids ) > 1 ) {
			$order->update_status( 'failed' );
			throw new \Exception( esc_html__( 'هر سفارش باید شامل یک پیشنهاد استعلام باشد.', 'andiya-price-guard' ) );
		}
		foreach ( $ids as $id ) {
			$key = 'quote:' . $id;
			$token = Support::lock( $key );
			if ( ! $token ) {
				$order->update_status( 'failed' );
				throw new \Exception( esc_html__( 'استعلام هم‌زمان در حال پردازش است؛ دوباره تلاش کنید.', 'andiya-price-guard' ) );
			}
			try {
				$q = Storage::get( 'quotes', $id );
				if ( $q && (int) $q['order_id'] === $order->get_id() ) {
					continue;
				}
				$versions = array();
				foreach ( $order->get_items() as $item ) {
					if ( (int) $item->get_meta( '_apg_quote' ) === $id ) {
						$versions[] = (int) $item->get_meta( '_apg_version' );
					}
				}
				if ( ! $q || $q['order_id'] || 'offered' !== $q['status'] || (int) $q['expires'] <= time() || array_values( array_unique( $versions ) ) !== array( (int) $q['version'] ) ) {
					$order->update_status( 'failed' );
					throw new \Exception( esc_html__( 'این پیشنهاد قبلاً استفاده شده یا اعتبار آن پایان یافته است.', 'andiya-price-guard' ) );
				}
				$t = Storage::table( 'quotes' );
				$ok = $wpdb->query( $wpdb->prepare( "UPDATE %i SET order_id=%d,status='accepted' WHERE id=%d AND order_id=0 AND version=%d AND expires>%d", $t, $order->get_id(), $id, $q['version'], time() ) );
				if ( ! $ok ) {
					$order->update_status( 'failed' );
					throw new \Exception( esc_html__( 'تبدیل پیشنهاد به سفارش انجام نشد.', 'andiya-price-guard' ) );
				}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$order->update_meta_data( '_apg_quote', $id );
				$order->save();
				Storage::audit( 'quote_order', $id, array( 'order_id' => $order->get_id() ) );
			} finally {
				Support::unlock( $key, $token );
			}
		}
	}

	public static function accept_prices( $request ) {
		try {
			self::nonce( $request );
			if ( ! WC()->cart ) {
				wc_load_cart();
			}
			foreach ( WC()->cart->get_cart() as $key => $line ) {
				if ( ! isset( $line['apg_quote'] ) ) {
					$p = Support::fresh( $line['variation_id'] ?: $line['product_id'] );
					if ( $p && 'buy' === Policy::effective( $p )['state'] ) {
							WC()->cart->cart_contents[ $key ]['apg_seen'] = (string) $p->get_price( 'edit' );
							WC()->cart->cart_contents[ $key ]['data'] = $p;
					}
				}
			}
			WC()->cart->calculate_totals();
			WC()->cart->set_session();
			return Support::result( array( 'ok' => true ) );
		} catch ( \Throwable $e ) {
			return Support::error( $e );
		}
	}

	public static function page(): void {
		// The signed URL authenticates only this request. Acceptance additionally uses a nonce.
		$id = isset( $_GET['apg_quote'] ) ? absint( $_GET['apg_quote'] ) : 0;
		if ( ! $id ) {
			return;
		}
 // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['apg_token'] ) ? sanitize_text_field( wp_unslash( $_GET['apg_token'] ) ) : '';
		$q = Storage::get( 'quotes', $id );
 // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		if ( ! $q || ! hash_equals( self::signature( $q ), $token ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'پیوند پیگیری معتبر نیست.', 'andiya-price-guard' ) );
		}
		$error = '';
		$nonce = isset( $_POST['apg_accept_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['apg_accept_nonce'] ) ) : '';
		if ( 'POST' === ( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			try {
				if ( ! wp_verify_nonce( $nonce, 'apg_accept_' . $id ) ) {
					throw new \Exception( esc_html__( 'فرم منقضی شده است.', 'andiya-price-guard' ) );
				}
				wp_safe_redirect( self::accept( $q ) );
				exit;
			} catch ( \Throwable $e ) {
				$error = $e->getMessage();
			}
		}
		get_header();
		echo '<main class="apg-tracking" dir="rtl"><h1>' . esc_html__( 'پیگیری استعلام', 'andiya-price-guard' ) . ' #' . esc_html( $id ) . '</h1><p>' . esc_html( $q['name'] ) . '</p>';
		if ( $error ) {
			echo '<p role="alert">' . esc_html( $error ) . '</p>';
		}
		$offer = Support::decode( $q['offer'] );
		$active = 'offered' === $q['status'] && (int) $q['expires'] > time() && ! $q['order_id'];
		if ( ! $offer ) {
			echo '<p>' . esc_html__( 'درخواست در صف بررسی فروشگاه است. پس از بررسی با شما تماس می‌گیریم.', 'andiya-price-guard' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'اعتبار پیشنهاد:', 'andiya-price-guard' ) . ' ' . esc_html( wp_date( 'Y/m/d H:i', (int) $q['expires'] ) ) . '</p><ul>';
			foreach ( $offer['items'] as $item ) {
				echo '<li>' . esc_html( $item['name'] . ' × ' . $item['qty'] ) . ' — ' . wp_kses_post( wc_price( $item['price'], array( 'currency' => $offer['currency'] ) ) ) . '</li>';
			}
			echo '</ul><p>' . esc_html( $offer['delivery'] ) . '</p><p>' . esc_html( $offer['note'] ) . '</p>';
		}
		if ( $active ) {
			echo '<p>' . esc_html__( 'قیمت هر واحد طبق تنظیم مالیات فروشگاه است. مالیات و هزینه ارسال نهایی در پرداخت ووکامرس مشخص می‌شود؛ موجودی هنوز رزرو نشده است.', 'andiya-price-guard' ) . '</p><form method="post">';
			wp_nonce_field( 'apg_accept_' . $id, 'apg_accept_nonce' );
			echo '<button class="button" type="submit">' . esc_html__( 'پذیرش پیشنهاد و ادامه به پرداخت', 'andiya-price-guard' ) . '</button></form>';
		} elseif ( $offer ) {
						echo '<p>' . esc_html__( 'پیشنهاد قابل پذیرش نیست یا قبلاً به سفارش تبدیل شده است.', 'andiya-price-guard' ) . '</p>';
		}
				echo '</main>';
				get_footer();
				exit;
	}

	public static function account(): void {
		global $wpdb;
		$t = Storage::table( 'quotes' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id=%d ORDER BY id DESC LIMIT 20', $t, get_current_user_id() ), ARRAY_A );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $rows ) {
			echo '<h3>' . esc_html__( 'استعلام‌های من', 'andiya-price-guard' ) . '</h3><ul>';
			foreach ( $rows as $q ) {
				echo '<li><a href="' . esc_url( self::url( $q ) ) . '">#' . esc_html( $q['id'] ) . '</a></li>';
			}
			echo '</ul>';
		}
	}

	public static function shortcode(): string {
		return '<div class="apg-quote-list" dir="rtl"><h2>' . esc_html__( 'فهرست استعلام', 'andiya-price-guard' ) . '</h2><div class="apg-list-items"></div><button type="button" class="button apg-request-list">' . esc_html__( 'استعلام کالاهای فهرست', 'andiya-price-guard' ) . '</button></div>';
	}
}
