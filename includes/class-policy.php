<?php
namespace Andiya\PriceGuard;
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plugin-owned tables use fresh reads for locks, version checks and private offers; caching would defeat concurrency guards.
final class Policy {

	public static function register(): void {
		foreach ( array( 'woocommerce_product_get_price', 'woocommerce_product_get_regular_price', 'woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_price', 'woocommerce_product_variation_get_regular_price', 'woocommerce_product_variation_get_sale_price' ) as $hook ) {
			add_filter( $hook, array( self::class, 'price' ), 99, 2 );
		}
		add_filter( 'woocommerce_is_purchasable', array( self::class, 'purchasable' ), 99, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( self::class, 'purchasable' ), 99, 2 );
		add_filter( 'woocommerce_get_price_html', array( self::class, 'html' ), 99, 2 );
		add_filter( 'woocommerce_available_variation', array( self::class, 'variation' ), 99, 3 );
		add_filter( 'woocommerce_variation_is_visible', array( self::class, 'visible' ), 99, 4 );
		add_filter( 'woocommerce_variation_is_active', array( self::class, 'active' ), 99, 2 );
		add_filter( 'woocommerce_variation_prices', array( self::class, 'variation_prices' ), 99, 3 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( self::class, 'variation_hash' ), 99, 3 );
		add_filter( 'woocommerce_structured_data_product', array( self::class, 'schema' ), 99, 2 );
		add_filter( 'rest_post_dispatch', array( self::class, 'store_prices' ), 99, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( self::class, 'add_guard' ), 99, 5 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( self::class, 'store_add' ), 99, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( self::class, 'cart_data' ), 99, 4 );
		add_action( 'woocommerce_check_cart_items', array( self::class, 'cart_check' ), 99 );
		add_action( 'woocommerce_store_api_cart_errors', array( self::class, 'store_check' ), 99, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'checkout_check' ), 99, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'store_checkout' ), 99, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'order_line' ), 99, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'order_ready' ), 90, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'order_ready' ), 90 );
		add_action( 'woocommerce_before_pay_action', array( self::class, 'pay_guard' ), 99 );
		add_action( 'woocommerce_checkout_validate_order_before_payment', array( self::class, 'store_order_guard' ), 99, 2 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'wp_footer', array( self::class, 'dialog' ) );
		add_action( 'woocommerce_single_product_summary', array( self::class, 'single' ), 31 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( self::class, 'loop' ), 99, 3 );
		add_filter( 'render_block', array( self::class, 'block' ), 99, 3 );
		add_action( 'woocommerce_before_cart', array( self::class, 'price_notice' ) );
		add_action( 'woocommerce_before_checkout_form', array( self::class, 'price_notice' ) );
	}

	public static function effective( \WC_Product $p, bool $aggregate = true ): array {
		if ( get_option( 'apg_deactivating' ) ) {
			return array(
				'state'  => 'buy',
				'reason' => '',
			);
		}
		$s = Support::settings();
		$own = (string) $p->get_meta( '_apg_state', true, 'edit' );
		$parent = $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : null;
		$parent_state = $parent ? (string) $parent->get_meta( '_apg_state', true, 'edit' ) : '';
		$state = 'stop' === $parent_state ? 'stop' : ( $own ?: $parent_state );
		$state = $state ?: 'buy';
		$reason = '';
		$terms = wp_get_post_terms( $parent ? $parent->get_id() : $p->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
		$scope = empty( $s['categories'] );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $id ) {
			if ( array_intersect( $s['categories'], array_merge( array( $id ), get_ancestors( $id, 'product_cat' ) ) ) ) {
				$scope = true;
				break;
			}
		}
		if ( $scope && 'normal' !== $s['mode'] ) {
			if ( 'stop' === $s['mode'] || 'stop' !== $state ) {
				$state = $s['mode'];
			}
			$reason = __( 'کنترل سراسری فروشگاه', 'andiya-price-guard' );
		}
		$expires = (int) $p->get_meta( '_apg_expires', true, 'edit' );
		$checked = (int) $p->get_meta( '_apg_checked', true, 'edit' );
		if ( ! $expires && $checked && $s['valid_hours'] ) {
			$expires = $checked + (int) $s['valid_hours'] * HOUR_IN_SECONDS;
		}
		if ( 'stop' !== $state && ( ( $expires && $expires <= time() ) || ( $s['valid_hours'] && ! $checked && ! $p->is_type( 'variable' ) ) ) ) {
			$state = 'quote';
			$reason = __( 'قیمت تأیید نشده یا اعتبار آن پایان یافته است.', 'andiya-price-guard' );
		}
		global $wpdb;
		$r = Storage::table( 'rows' );
		$b = Storage::table( 'batches' );
		if ( 'stop' !== $state && $wpdb->get_var( $wpdb->prepare( "SELECT r.id FROM %i r INNER JOIN %i b ON r.batch_id=b.id WHERE r.product_id=%d AND b.status='running' AND r.status='pending' LIMIT 1", $r, $b, $p->get_id() ) ) ) {
			$state = 'quote';
			$reason = __( 'به‌روزرسانی قیمت در حال اجراست.', 'andiya-price-guard' );
		}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$mixed = false;
		if ( $aggregate && $p->is_type( 'variable' ) && 'buy' === $state ) {
			$states = array();
			foreach ( $p->get_children() as $id ) {
				$child = wc_get_product( $id );
				if ( $child && 'publish' === $child->get_status() ) {
					$states[] = self::effective( $child, false )['state'];
				}
			}
			$states = array_unique( $states );
			$mixed = count( $states ) > 1;
			if ( $states && ! in_array( 'buy', $states, true ) ) {
				$state = in_array( 'quote', $states, true ) ? 'quote' : 'stop';
			}
		}
		return array(
			'state'   => $state,
			'reason'  => $reason,
			'expires' => $expires,
			'mixed'   => $mixed,
		);
	}

	public static function public_context(): bool {
		return ! is_admin() || wp_doing_ajax();
	}

	public static function authorized( $p ): bool {
		return Quotes::authorized( $p ) || Quotes::accepting( $p->get_id() );
	}

	public static function price( $price, $p ) {
		$offer = Quotes::accepted_price( $p->get_id() );
		if ( null !== $offer ) {
			return $offer;
		}
		return self::public_context() && ! self::authorized( $p ) && 'buy' !== self::effective( $p )['state'] ? '' : $price;
	}

	public static function purchasable( $ok, $p ): bool {
		return self::authorized( $p ) ? 'publish' === $p->get_status() : ( $ok && 'buy' === self::effective( $p )['state'] );
	}

	public static function html( $html, $p ): string {
		return self::public_context() && 'buy' !== self::effective( $p )['state'] ? '' : $html;
	}

	public static function active( $ok, $p ): bool {
		return 'quote' === self::effective( $p, false )['state'] ? true : $ok;
	}

	public static function visible( $ok, $id, $parent, $p ): bool {
		return 'quote' === self::effective( $p, false )['state'] && 'publish' === $p->get_status() ? true : $ok;
	}

	public static function variation( $data, $parent, $p ): array {
		$state = self::effective( $p, false )['state'];
		$data['apg_state'] = $state;
		if ( 'buy' !== $state ) {
			$data['display_price'] = null;
			$data['display_regular_price'] = null;
			$data['price_html'] = '';
			$data['is_purchasable'] = false;
		}
		return $data;
	}

	public static function variation_prices( $prices, $p, $display ): array {
		if ( self::public_context() ) {
			foreach ( array_keys( $prices['price'] ?? array() ) as $id ) {
				$child = wc_get_product( $id );
				if ( $child && 'buy' !== self::effective( $child, false )['state'] ) {
					foreach ( array( 'price', 'regular_price', 'sale_price' ) as $k ) {
							unset( $prices[ $k ][ $id ] );
					}
				}
			}
		}
		return $prices;
	}

	public static function variation_hash( $hash, $p, $display ): array {
		$hash['apg'] = array( Support::settings(), (int) ( time() / 60 ), self::public_context() );
		return $hash;
	}

	public static function schema( $data, $p ): array {
		if ( 'buy' !== self::effective( $p )['state'] ) {
			unset( $data['offers'] );
		}
		return $data;
	}

	public static function store_prices( $response, $server, $request ) {
		if ( ! preg_match( '#^/wc/store(?:/v\d+)?/products(?:/\d+)?$#', $request->get_route() ) || ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		$data = $response->get_data();
		$single = isset( $data['id'] );
		$rows = $single ? array( $data ) : $data;
		if ( ! is_array( $rows ) ) {
			return $response;
		}
		foreach ( $rows as &$row ) {
			$p = wc_get_product( $row['id'] ?? 0 );
			if ( ! $p ) {
				continue;
			}
			$state = self::effective( $p )['state'];
			$row['extensions'] = (array) ( $row['extensions'] ?? array() );
			$row['extensions']['andiya-price-guard'] = array( 'state' => $state );
			if ( 'buy' !== $state ) {
				$row['prices'] = (array) ( $row['prices'] ?? array() );
				$row['add_to_cart'] = (array) ( $row['add_to_cart'] ?? array() );
				foreach ( array( 'price', 'regular_price', 'sale_price' ) as $k ) {
					$row['prices'][ $k ] = '';
				}
				$row['prices']['price_range'] = null;
				$row['price_html'] = '';
				$row['on_sale'] = false;
				$row['is_purchasable'] = false;
				$row['add_to_cart']['url'] = $p->get_permalink();
			}
		}
		$response->set_data( $single ? $rows[0] : $rows );
		return $response;
	}

	public static function add_guard( $ok, $id, $qty, $variation_id = 0, $attributes = array() ): bool {
		$p = Support::fresh( $variation_id ?: $id );
		if ( ! $p || ( ! Quotes::accepting( $p->get_id() ) && 'buy' !== self::effective( $p )['state'] ) ) {
			wc_add_notice( __( 'برای این کالا ابتدا استعلام ثبت کنید.', 'andiya-price-guard' ), 'error' );
			return false;
		}
		return (bool) $ok;
	}

	public static function store_add( $p, $request ): void {
		if ( 'buy' !== self::effective( Support::fresh( $p->get_id() ) )['state'] ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'apg_quote_required', esc_html__( 'این کالا در حال حاضر قابل خرید نیست. استعلام ثبت کنید.', 'andiya-price-guard' ), 409 );
		}
	}

	public static function cart_data( $data, $id, $vid, $qty ): array {
		if ( ! isset( $data['apg_quote'] ) ) {
			$p = wc_get_product( $vid ?: $id );
			if ( $p ) {
				$data['apg_seen'] = (string) $p->get_price( 'edit' );
			}
		}
		return $data;
	}

	public static function problems(): array {
		$errors = array();
		if ( ! WC()->cart ) {
			return $errors;
		}
		foreach ( WC()->cart->get_cart() as $key => $line ) {
			$p = Support::fresh( $line['variation_id'] ?: $line['product_id'] );
			if ( ! $p ) {
				$errors[] = __( 'یک محصول سبد خرید دیگر در دسترس نیست.', 'andiya-price-guard' );
				continue;
			}
			if ( isset( $line['apg_quote'] ) ) {
				$error = Quotes::cart_error( $line );
				if ( $error ) {
					$errors[] = $error;
				}
			} elseif ( 'buy' !== self::effective( $p )['state'] ) {
				$errors[] = $p->get_name() . ': ' . __( 'خرید نیازمند استعلام است.', 'andiya-price-guard' );
			} elseif ( (string) ( $line['apg_seen'] ?? '' ) !== (string) $p->get_price( 'edit' ) ) {
				$errors[] = __( 'قیمت سبد تغییر کرده است. سبد را بررسی و قیمت‌های جدید را تأیید کنید.', 'andiya-price-guard' );
			}
		}
		return array_unique( $errors );
	}

	public static function cart_check(): void {
		foreach ( self::problems() as $e ) {
			if ( ! wc_has_notice( $e, 'error' ) ) {
				wc_add_notice( $e, 'error' );
			}
		}
	}

	public static function store_check( $errors, $cart ): void {
		foreach ( self::problems() as $e ) {
			$errors->add( 'apg_cart', $e );
		}
	}

	public static function checkout_check( $data, $errors ): void {
		foreach ( self::problems() as $e ) {
			$errors->add( 'apg_cart', $e );
		}
	}

	public static function store_checkout( $order, $request ): void {
		$errors = self::problems();
		if ( $errors ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'apg_cart', esc_html( implode( ' ', $errors ) ), 409 );
		}
	}

	public static function order_line( $item, $key, $values, $order ): void {
		if ( ! isset( $values['apg_quote'] ) ) {
			$item->add_meta_data( '_apg_seen', $values['apg_seen'] ?? '', true );
		}
	}

	public static function order_ready( $order_or_id, $data = array(), $order = null ): void {
		$order = $order_or_id instanceof \WC_Order ? $order_or_id : ( $order ?: wc_get_order( $order_or_id ) );
		if ( ! $order ) {
			return;
		}
		$errors = self::problems();
		if ( $errors ) {
			$order->update_status( 'failed' );
			throw new \Exception( esc_html( implode( ' ', $errors ) ) );
		}
		$order->update_meta_data( '_apg_payment_started', time() );
		$order->save();
	}

	public static function pay_guard( $order ): void {
		try {
			self::before_pay( $order );
		} catch ( \Throwable $error ) {
			wc_add_notice( $error->getMessage(), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}
	}

	public static function store_order_guard( $order, $errors ): void {
		// New Store API drafts are validated against the cart before atomic quote binding.
		if ( $order->has_status( 'checkout-draft' ) ) {
			return;
		}
		try {
			self::before_pay( $order, false );
		} catch ( \Throwable $error ) {
			$errors->add( 'apg_order_price', $error->getMessage() );
		}
	}

	public static function before_pay( $order, bool $mark_started = true ): void {
		if ( $order->is_paid() || ( (int) $order->get_meta( '_apg_payment_started' ) > time() - 15 * MINUTE_IN_SECONDS ) ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$p = $item->get_product();
			if ( ! $p ) {
				throw new \Exception( esc_html__( 'محصول سفارش در دسترس نیست.', 'andiya-price-guard' ) );
			}
			if ( $item->get_meta( '_apg_quote' ) ) {
				$q = Storage::get( 'quotes', (int) $item->get_meta( '_apg_quote' ) );
				$offer = $q ? Support::decode( $q['offer'] ) : array();
				if ( ! $q || (int) $q['order_id'] !== $order->get_id() || 'accepted' !== $q['status'] || ( $offer['currency'] ?? '' ) !== $order->get_currency() || $order->get_currency() !== get_woocommerce_currency() || (int) $q['expires'] <= time() || (int) $q['version'] !== (int) $item->get_meta( '_apg_version' ) || 'stop' === self::effective( $p )['state'] ) {
					throw new \Exception( esc_html__( 'اعتبار پیشنهاد پایان یافته است. استعلام جدید لازم است.', 'andiya-price-guard' ) );
				}
			} elseif ( 'buy' !== self::effective( $p )['state'] || (string) $item->get_meta( '_apg_seen' ) !== (string) $p->get_price( 'edit' ) ) {
				throw new \Exception( esc_html__( 'قیمت این سفارش باید دوباره تأیید شود.', 'andiya-price-guard' ) );
			}
		}
		if ( $mark_started ) {
			$order->update_meta_data( '_apg_payment_started', time() );
			$order->save();
		}
	}

	public static function assets(): void {
		wp_enqueue_style( 'apg-front', APG_URL . 'assets/front.css', array(), APG_VERSION );
		wp_enqueue_script( 'apg-front', APG_URL . 'assets/front.js', array( 'jquery', 'wp-i18n' ), APG_VERSION, true );
		wp_set_script_translations( 'apg-front', 'andiya-price-guard', APG_DIR . 'languages' );
		wp_localize_script(
			'apg-front',
			'APGFront',
			array(
				'api'       => rest_url( 'andiya-price-guard/v1/' ),
				'nonce'     => wp_create_nonce( 'apg_intake' ),
				'restNonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'label'     => Support::settings()['label'],
				'listLabel' => __( 'فهرست استعلام', 'andiya-price-guard' ),
			)
		);
	}

	public static function button( $p ): string {
		return '<button type="button" class="button apg-request" data-id="' . esc_attr( $p->get_id() ) . '" data-name="' . esc_attr( $p->get_name() ) . '">' . esc_html( Support::settings()['label'] ) . '</button> <button type="button" class="button apg-add-list" data-id="' . esc_attr( $p->get_id() ) . '" data-name="' . esc_attr( $p->get_name() ) . '">' . esc_html__( 'افزودن به فهرست استعلام', 'andiya-price-guard' ) . '</button>';
	}

	public static function single(): void {
		global $product;
		if ( ! $product ) {
			return;
		}
		$s = Support::settings();
		$state = self::effective( $product );
		if ( $product->is_type( 'variable' ) ) {
			echo '<div class="apg-variation-actions" data-name="' . esc_attr( $product->get_name() ) . '" hidden><p class="apg-var-message"></p>' . wp_kses( self::button( $product ), array( 'button' => array( 'type' => true, 'class' => true, 'data-id' => true, 'data-name' => true ) ) ) . '</div>';
			return;
		}
 // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped HTML builder.
		if ( 'buy' !== $state['state'] ) {
			echo '<div class="apg-product-message"><p>' . esc_html( 'stop' === $state['state'] ? $s['stop_message'] : $s['message'] ) . '</p>';
			if ( 'quote' === $state['state'] ) {
				echo wp_kses( self::button( $product ), array( 'button' => array( 'type' => true, 'class' => true, 'data-id' => true, 'data-name' => true ) ) );
			}
			echo '</div>';
		}
 // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped HTML builder.
	}

	public static function loop( $html, $p, $args ): string {
		$s = self::effective( $p )['state'];
		if ( 'stop' === $s ) {
			return '<span class="apg-stopped">' . esc_html( Support::settings()['stop_message'] ) . '</span>';
		}
		if ( 'quote' === $s ) {
			return $p->is_type( 'variable' ) ? '<a class="button" href="' . esc_url( $p->get_permalink() ) . '">' . esc_html__( 'انتخاب مدل و استعلام', 'andiya-price-guard' ) . '</a>' : self::button( $p );
		}
		return $html;
	}

	public static function block( string $html, array $block, $instance = null ): string {
		$name = $block['blockName'] ?? '';
		$id = absint( $block['attrs']['productId'] ?? ( $block['attrs']['postId'] ?? ( $instance->context['postId'] ?? get_the_ID() ) ) );
		$p = $id ? wc_get_product( $id ) : false;
		if ( ! $p ) {
			return $html;
		}
		$policy = self::effective( $p );
		// Keep exact model selection for guarded variable products in both Woo block forms.
		if ( $p->is_type( 'variable' ) && ( 'quote' === $policy['state'] || $policy['mixed'] ) && in_array( $name, array( 'woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options' ), true ) ) {
			global $product;
			$previous = $product;
			$product = $p; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce's native template requires its product global.
			ob_start();
			try {
				echo '<div class="apg-block-variable">';
				woocommerce_variable_add_to_cart();
				self::single();
				echo '</div>';
				return (string) ob_get_contents();
			} finally {
				ob_end_clean();
				$product = $previous; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restore the WooCommerce product global after native template rendering.
			}
		}
		if ( 'buy' === $policy['state'] ) {
			return $html;
		}
		if ( in_array( $name, array( 'woocommerce/product-price', 'woocommerce/product-sale-badge' ), true ) ) {
			return '';
		}
		if ( in_array( $name, array( 'woocommerce/product-button', 'woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options' ), true ) ) {
			return self::loop( '', $p, array() );
		}
		return $html;
	}

	public static function price_notice(): void {
		if ( WC()->cart && self::problems() ) {
			echo '<div class="woocommerce-info apg-price-notice">' . esc_html__( 'وضعیت و قیمت سبد را بررسی کنید.', 'andiya-price-guard' ) . ' <button type="button" class="button apg-accept-prices">' . esc_html__( 'تأیید قیمت‌های جدید سبد', 'andiya-price-guard' ) . '</button></div>';
		}
	}

	public static function dialog(): void {
		if ( ! wp_script_is( 'apg-front', 'enqueued' ) ) {
			return;
		}
		?>
		<button type="button" class="apg-open-list" hidden></button>
		<dialog id="apg-dialog" dir="rtl" aria-labelledby="apg-dialog-title"><form id="apg-intake"><button type="button" class="apg-close" aria-label="
		<?php
		esc_attr_e( 'بستن', 'andiya-price-guard' );
		?>
">×</button><h2 id="apg-dialog-title">
		<?php
		esc_html_e( 'استعلام قیمت و موجودی', 'andiya-price-guard' );
		?>
</h2><p>
		<?php
		esc_html_e( 'در این مرحله پرداخت و رزرو موجودی انجام نمی‌شود.', 'andiya-price-guard' );
		?>
</p><div id="apg-items"></div><label>
		<?php
		esc_html_e( 'نام و نام خانوادگی', 'andiya-price-guard' );
		?>
<input name="name" required maxlength="180" autocomplete="name"></label><label>
		<?php
		esc_html_e( 'شماره موبایل', 'andiya-price-guard' );
		?>
<input name="phone" required maxlength="24" inputmode="tel" autocomplete="tel" dir="ltr"></label><label>
		<?php
		esc_html_e( 'ایمیل (اختیاری)', 'andiya-price-guard' );
		?>
<input name="email" type="email" maxlength="190" autocomplete="email" dir="ltr"></label><label>
		<?php
		esc_html_e( 'توضیح (اختیاری)', 'andiya-price-guard' );
		?>
<textarea name="note" maxlength="2000"></textarea></label><div class="apg-honey" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div><p class="apg-form-status" role="status" aria-live="polite"></p><button class="button apg-submit" type="submit">
		<?php
		esc_html_e( 'ثبت درخواست بدون پرداخت', 'andiya-price-guard' );
		?>
</button></form></dialog>
		<?php
	}
}
