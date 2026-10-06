<?php
/**
 * Capability-separated merchant administration.
 *
 * @package Pricing_Manager_for_WooCommerce
 */

namespace Andiya\PriceGuard;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plugin-owned tables use fresh reads for locks, version checks and private offers; caching would defeat concurrency guards.
/** Manage native admin navigation and protected management routes. */
final class Admin {

	/**
	 * Register native management hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'woocommerce_prevent_admin_access', array( self::class, 'allow_panel_access' ) );
		add_filter(
			'woocommerce_currencies',
			static function ( $c ) {
				return $c + array(
					'IRT'  => __( 'تومان', 'andiya-price-guard' ),
					'IRHR' => __( 'هزار ریال', 'andiya-price-guard' ),
					'IRHT' => __( 'هزار تومان', 'andiya-price-guard' ),
				);
			}
		);
		add_filter(
			'woocommerce_currency_symbol',
			static function ( $symbol, $currency ) {
				$map = array(
					'IRT'  => __( 'تومان', 'andiya-price-guard' ),
					'IRHR' => __( 'هزار ریال', 'andiya-price-guard' ),
					'IRHT' => __( 'هزار تومان', 'andiya-price-guard' ),
				);
				return $map[ $currency ] ?? $symbol;
			},
			10,
			2
		);
		add_filter(
			'plugin_action_links_' . plugin_basename( APG_FILE ),
			static function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=andiya-price-guard' ) ) . '">' . esc_html__( 'مدیریت قیمت', 'andiya-price-guard' ) . '</a>' );
				return $links;
			}
		);
		add_filter(
			'woocommerce_product_data_tabs',
			static function ( $tabs ) {
				if ( current_user_can( 'apg_manage_prices' ) ) {
					$tabs['apg'] = array(
						'label'    => __( 'قیمت و استعلام', 'andiya-price-guard' ),
						'target'   => 'apg_product_data',
						'class'    => array(),
						'priority' => 80,
					);
				}
				return $tabs;
			}
		);
		add_action( 'woocommerce_product_data_panels', array( self::class, 'native_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'native_save' ) );
		add_action(
			'admin_notices',
			static function () {
				$r = get_option( 'apg_deactivation_report', array() );
				if ( current_user_can( 'apg_manage_prices' ) && ! empty( $r['remaining'] ) ) {
					echo '<div class="notice notice-warning"><p>' . esc_html__( 'بازگردانی قبلی کامل نشده است. از تنظیمات مدیریت قیمت، بازگردانی باقی‌مانده را اجرا کنید.', 'andiya-price-guard' ) . '</p></div>';
				}
			}
		);
	}

	/**
	 * Register a menu for an authorized management user.
	 */
	public static function menu(): void {
		if ( ! self::allowed() ) {
			return;
		}
		add_submenu_page( self::menu_parent(), __( 'مدیریت قیمت ووکامرس', 'andiya-price-guard' ), __( 'مدیریت قیمت ووکامرس', 'andiya-price-guard' ), 'read', 'andiya-price-guard', array( self::class, 'page' ) );
	}

	/**
	 * Select an accessible parent without granting WooCommerce administration.
	 *
	 * @return string
	 */
	public static function menu_parent(): string {
		return current_user_can( 'manage_woocommerce' ) ? 'woocommerce' : 'index.php';
	}

	/**
	 * Permit authorized panel navigation through the customer admin guard.
	 *
	 * @param bool $prevent Existing WooCommerce access restriction.
	 * @return bool
	 */
	public static function allow_panel_access( bool $prevent ): bool {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation to this capability-protected panel, never a state-changing action.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return 'admin.php' === $pagenow && 'andiya-price-guard' === $page && self::allowed() ? false : $prevent;
	}

	/**
	 * Determine whether this user holds a management capability.
	 *
	 * @return bool
	 */
	public static function allowed(): bool {
		return current_user_can( 'apg_manage_prices' ) || current_user_can( 'apg_manage_quotes' ) || current_user_can( 'apg_view_history' );
	}

	/**
	 * Enqueue assets only on the authorized plugin screen.
	 *
	 * @param string $hook Current admin screen hook.
	 */
	public static function assets( $hook ): void {
		if ( get_plugin_page_hookname( 'andiya-price-guard', self::menu_parent() ) !== $hook || ! self::allowed() ) {
			return;
		}
		wp_enqueue_style( 'apg-admin', APG_URL . 'assets/admin.css', array(), APG_VERSION );
		wp_enqueue_script( 'apg-workbench', APG_URL . 'assets/admin-workbench.js', array( 'wp-i18n' ), APG_VERSION, true );
		wp_set_script_translations( 'apg-workbench', 'andiya-price-guard', APG_DIR . 'languages' );
		wp_enqueue_script( 'apg-product-search', APG_URL . 'assets/admin-product-search.js', array( 'wp-i18n' ), APG_VERSION, true );
		wp_set_script_translations( 'apg-product-search', 'andiya-price-guard', APG_DIR . 'languages' );
		wp_enqueue_script( 'apg-admin', APG_URL . 'assets/admin.js', array( 'wp-i18n', 'apg-workbench', 'apg-product-search' ), APG_VERSION, true );
		wp_set_script_translations( 'apg-admin', 'andiya-price-guard', APG_DIR . 'languages' );
		wp_localize_script(
			'apg-admin',
			'APGAdmin',
			array(
				'api'      => rest_url( 'andiya-price-guard/v1/admin/' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'currency' => get_woocommerce_currency(),
				'symbol'   => get_woocommerce_currency_symbol(),
				'caps'     => array(
					'prices'  => current_user_can( 'apg_manage_prices' ),
					'quotes'  => current_user_can( 'apg_manage_quotes' ),
					'history' => current_user_can( 'apg_view_history' ),
				),
			)
		);
	}

	/**
	 * Register capability-protected management REST routes.
	 */
	public static function routes(): void {
		$routes = array(
			'/bootstrap'                => array( 'GET', 'bootstrap', 'read' ),
			'/products'                 => array( 'GET', 'products', 'prices' ),
			'/product/(?P<id>\d+)'      => array( array( 'GET', 'POST' ), 'product', 'prices' ),
			'/preview'                  => array( 'POST', 'preview', 'prices' ),
			'/batch/(?P<id>\d+)'        => array( 'GET', 'batch', 'history' ),
			'/batch/(?P<id>\d+)/(?P<action>apply|step|undo|cancel)' => array( 'POST', 'batch_action', 'prices' ),
			'/groups'                   => array( array( 'GET', 'POST', 'DELETE' ), 'groups', 'prices' ),
			'/settings'                 => array( 'POST', 'settings', 'prices' ),
			'/quotes'                   => array( 'GET', 'quotes', 'quotes' ),
			'/quote/(?P<id>\d+)'        => array( array( 'GET', 'POST' ), 'quote', 'quotes' ),
			'/history'                  => array( 'GET', 'history', 'history' ),
			'/import'                   => array( 'POST', 'import', 'prices' ),
			'/sources'                  => array( array( 'POST', 'DELETE' ), 'sources', 'prices' ),
			'/source/(?P<id>\d+)/fetch' => array( 'POST', 'fetch', 'prices' ),
			'/restore'                  => array( 'POST', 'restore', 'prices' ),
		);
		foreach ( $routes as $path => $route ) {
			$method = $route[1];
			$cap    = $route[2];
			register_rest_route(
				'andiya-price-guard/v1',
				'/admin' . $path,
				array(
					'methods'             => $route[0],
					'permission_callback' => static function () use ( $cap ) {
							return 'read' === $cap ? self::allowed() : ( 'history' === $cap ? ( current_user_can( 'apg_view_history' ) || current_user_can( 'apg_manage_prices' ) ) : current_user_can( 'apg_manage_' . $cap ) );
					},
					'callback'            => static function ( $request ) use ( $method ) {
						try {
							return Support::result( self::$method( $request ) );
						} catch ( \Throwable $e ) {
							return Support::error( $e );
						}
					},
				)
			);
		}
	}

	/**
	 * Return capability-dependent workbench configuration.
	 *
	 * @param \WP_REST_Request $request REST callback request.
	 * @return array
	 */
	public static function bootstrap( $request ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Keep the common REST callback signature.
		global $wpdb;
		$data = array(
			'version'        => APG_VERSION,
			'server_time'    => time(),
			'currency'       => get_woocommerce_currency(),
			'timezone'       => wp_timezone_string(),
			'caps'           => array(
				'prices'  => current_user_can( 'apg_manage_prices' ),
				'quotes'  => current_user_can( 'apg_manage_quotes' ),
				'history' => current_user_can( 'apg_view_history' ),
			),
			'health'         => array(
				'wp'           => get_bloginfo( 'version' ),
				'wc'           => WC_VERSION,
				'php'          => PHP_VERSION,
				'cron'         => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
				'queue'        => function_exists( 'as_enqueue_async_action' ),
				'deactivation' => get_option( 'apg_deactivation_report', array() ),
			),
			'categories'     => array(),
			'taxonomies'     => array(),
			'groups'         => array(),
			'sources'        => array(),
			'recent_batches' => array(),
		);
		if ( $data['caps']['prices'] ) {
			$data['settings']   = Support::settings();
			$terms              = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'number'     => 1000,
				)
			);
			$data['categories'] = is_wp_error( $terms ) ? array() : array_map(
				static fn( $t ) => array(
					'id'     => $t->term_id,
					'name'   => $t->name,
					'parent' => $t->parent,
				),
				$terms
			);
			foreach ( get_object_taxonomies( 'product', 'objects' ) as $tax ) {
				if ( str_starts_with( $tax->name, 'pa_' ) || in_array( $tax->name, array( 'product_brand', 'product_tag' ), true ) ) {
					$terms                = get_terms(
						array(
							'taxonomy'   => $tax->name,
							'hide_empty' => false,
							'number'     => 1000,
						)
					);
					$data['taxonomies'][] = array(
						'name'  => $tax->name,
						'label' => $tax->label,
						'terms' => is_wp_error( $terms ) ? array() : array_map(
							static fn( $t ) => array(
								'id'   => $t->term_id,
								'name' => $t->name,
							),
							$terms
						),
					);
				}
			}
			$data['groups']  = self::groups( new \WP_REST_Request( 'GET' ) );
			$t               = Storage::table( 'sources' );
			$data['sources'] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 100', $t ), ARRAY_A );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( $data['caps']['quotes'] ) {
			$t                  = Storage::table( 'quotes' );
			$data['new_quotes'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='new'", $t ) );
		}
		if ( $data['caps']['prices'] || $data['caps']['history'] ) {
			$data['recent_batches'] = $wpdb->get_results( $wpdb->prepare( 'SELECT id,created,status,currency,config FROM %i ORDER BY id DESC LIMIT 5', Storage::table( 'batches' ) ), ARRAY_A );
			foreach ( $data['recent_batches'] as &$recent ) {
				$recent['config'] = Support::decode( $recent['config'] );
			}
		}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $data;
	}

	/**
	 * Build a native product management snapshot.
	 *
	 * @param \WC_Product $p Native WooCommerce product.
	 * @return array
	 */
	public static function product_row( $p ): array {
		return array(
			'id'          => $p->get_id(),
			'name'        => $p->get_name(),
			'sku'         => $p->get_sku( 'edit' ),
			'type'        => $p->get_type(),
			'price'       => $p->get_price( 'edit' ),
			'data'        => Support::snapshot( $p ),
			'fingerprint' => Support::fingerprint( Support::snapshot( $p ) ),
			'state'       => Policy::effective( $p ),
			'stock'       => $p->get_stock_status( 'edit' ),
			'edit_url'    => get_edit_post_link( $p->get_id(), 'raw' ),
		);
	}

	/**
	 * List searchable native products.
	 *
	 * @param \WP_REST_Request $request REST callback request.
	 * @return array
	 * @throws \InvalidArgumentException When inputs or operation state are invalid.
	 */
	public static function products( $request ): array {
		$page   = max( 1, absint( $request['page'] ?? 1 ) );
		$search = trim( sanitize_text_field( $request['search'] ?? '' ) );
		$names  = 'names' === ( $request['mode'] ?? '' );
		if ( strlen( $search ) > 720 ) {
			throw new \InvalidArgumentException( esc_html__( 'عبارت جست‌وجو بیش از حد طولانی است.', 'andiya-price-guard' ) );
		}
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'DESC',
		);
		if ( '' !== $search ) {
			if ( $names ) {
				// Match the existing bulk/group text filter; suggestions never select an implicit product ID.
				$args['s'] = $search;
			} else {
				$id = wc_get_product_id_by_sku( $search );
				if ( $id ) {
					// An exact variation SKU must open that variation, not its parent or siblings.
					$parent = wp_get_post_parent_id( $id );
					$ids    = $parent && 'publish' !== get_post_status( $parent ) ? array() : array( $id );
				} else {
					$store = \WC_Data_Store::load( 'product' );
					$ids   = $store->search_products( $search, '', true, false );
				}
				$args['post_type'] = array( 'product', 'product_variation' );
				// An empty post__in array means ALL posts in WP_Query, so explicitly use the impossible ID 0.
				$args['post__in'] = $ids ? array_map( 'absint', $ids ) : array( 0 );
			}
		}
		$q    = new \WP_Query( $args );
		$rows = array();
		foreach ( $q->posts as $post ) {
			$p = wc_get_product( $post->ID );
			if ( $p ) {
				$rows[] = $names ? array(
					'id'   => $p->get_id(),
					'name' => $p->get_name(),
					'sku'  => $p->get_sku( 'edit' ),
				) : self::product_row( $p );
			}
		}
		return array(
			'rows'  => $rows,
			'total' => $q->found_posts,
			'pages' => $q->max_num_pages,
			'page'  => $page,
		);
	}

	/**
	 * Read or optimistically update a native product.
	 *
	 * @param \WP_REST_Request $request REST callback request.
	 * @return array
	 * @throws \InvalidArgumentException When inputs or operation state are invalid.
	 * @throws \RuntimeException When permission, version or lock checks fail.
	 */
	public static function product( $request ): array {
		$id = absint( $request['id'] );
		$p  = Support::fresh( $id );
		if ( ! $p ) {
			throw new \InvalidArgumentException( esc_html__( 'محصول پیدا نشد.', 'andiya-price-guard' ) );
		}
		if ( 'POST' === $request->get_method() ) {
			$d     = (array) $request->get_json_params();
			$key   = 'product:' . $id;
			$token = Support::lock( $key );
			if ( ! $token ) {
				throw new \RuntimeException( esc_html__( 'محصول در حال تغییر است.', 'andiya-price-guard' ) );
			}
			try {
				$p      = Support::fresh( $id );
				$before = Support::snapshot( $p );
				if ( ! hash_equals( Support::fingerprint( $before ), (string) ( $d['fingerprint'] ?? '' ) ) ) {
					throw new \RuntimeException( esc_html__( 'محصول تغییر کرده است. دوباره باز کنید.', 'andiya-price-guard' ) );
				}
				$after = array();
				if ( $p->is_type( array( 'simple', 'variation' ) ) ) {
					$after['regular_price'] = Support::money( Support::number( $d['regular_price'] ?? '', 0.01 ) );
					$after['sale_price']    = '' === trim( (string) ( $d['sale_price'] ?? '' ) ) ? '' : Support::money( Support::number( $d['sale_price'], 0.01 ) );
				}
				$after['_apg_state']   = in_array( $d['state'] ?? '', array( 'buy', 'quote', 'stop' ), true ) ? $d['state'] : '';
				$after['_apg_exclude'] = empty( $d['exclude'] ) ? '' : '1';
				$after['_apg_source']  = sanitize_text_field( $d['source'] ?? '' );
				if ( ! empty( $d['confirmed'] ) ) {
					$after['_apg_checked'] = (string) time();
					$h                     = Support::integer( $d['hours'] ?? 0, 0, 8760 );
					$after['_apg_expires'] = $h ? (string) ( time() + $h * HOUR_IN_SECONDS ) : '';
				}
				Operations::write( $p, $after );
				Support::purge( array( $id ) );
				Storage::audit( 'product_changed', $id );
			} finally {
				Support::unlock( $key, $token );
			}
		}
		$row             = self::product_row( Support::fresh( $id ) );
		$row['children'] = array();
		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_children() as $child ) {
				$cp = wc_get_product( $child );
				if ( $cp ) {
					$row['children'][] = self::product_row( $cp );
				}
			}
		}
		return $row;
	}

	/**
	 * Create a frozen pricing preview.
	 *
	 * @param \WP_REST_Request $request REST callback request.
	 * @return array
	 */
	public static function preview( $request ): array {
		return self::with_export( Operations::preview( (array) $request->get_json_params() ) );
	}

	/**
	 * Read one operation report.
	 *
	 * @param \WP_REST_Request $request REST callback request.
	 * @return array
	 */
	public static function batch( $request ): array {
		return self::with_export( Operations::report( absint( $request['id'] ), max( 1, absint( $request['page'] ?? 1 ) ) ) );
	}

	/**
	 * Attach an operation-specific authenticated report URL.
	 *
	 * @param array $report Operation report.
	 * @return array
	 */
	private static function with_export( array $report ): array {
		$report['export_url'] = Reports::url( (int) $report['id'] );
		return $report;
	}

	/**
	 * Execute a bounded native operation action.
	 *
	 * @param \WP_REST_Request $request REST callback request.
	 * @return array
	 */
	public static function batch_action( $request ): array {
		$id     = absint( $request['id'] );
		$action = $request['action'];
		if ( 'apply' === $action ) {
			return self::with_export( Operations::commit( $id, (array) ( $request['exclude'] ?? array() ) ) );
		}
		if ( 'step' === $action ) {
			Operations::process( $id );
			return self::with_export( Operations::report( $id ) );
		}
		return self::with_export( Operations::$action( $id ) );
	}

	/**
	 * Fingerprint a saved selection for optimistic editing.
	 *
	 * @param array $row Stored saved-group row.
	 * @return string
	 */
	public static function group_revision( array $row ): string {
		return hash( 'sha256', Support::json( array( $row['name'], is_array( $row['filters'] ) ? $row['filters'] : Support::decode( $row['filters'] ) ) ) );
	}

	/**
	 * Read or lock and update saved pricing scopes.
	 *
	 * @param \WP_REST_Request $request REST callback request.
	 * @return array
	 * @throws \InvalidArgumentException When inputs or operation state are invalid.
	 * @throws \RuntimeException When permission, version or lock checks fail.
	 */
	public static function groups( $request ): array {
		global $wpdb;
		$t      = Storage::table( 'groups' );
		$method = $request->get_method();
		if ( in_array( $method, array( 'POST', 'DELETE' ), true ) ) {
			$d = (array) $request->get_json_params();
			// Keep query-parameter deletion compatible with the first public API.
			if ( 'DELETE' === $method ) {
				$d = array_merge( (array) $request->get_query_params(), $d );
			}
			$id    = absint( $d['id'] ?? 0 );
			$key   = 'group:' . $id;
			$token = Support::lock( $key );
			if ( ! $token ) {
				throw new \RuntimeException( esc_html__( 'گروه در حال ویرایش است. کمی بعد دوباره تلاش کنید.', 'andiya-price-guard' ) );
			}
			try {
				if ( $id || 'DELETE' === $method ) {
					$current = Storage::get( 'groups', $id );
					if ( ! $current ) {
						throw new \InvalidArgumentException( esc_html__( 'گروه پیدا نشد. فهرست را تازه کنید.', 'andiya-price-guard' ) );
					}
					if ( isset( $d['revision'] ) && ( ! is_string( $d['revision'] ) || ! hash_equals( self::group_revision( $current ), $d['revision'] ) ) ) {
						throw new \RuntimeException( esc_html__( 'گروه پس از بازشدن تغییر کرده است. فهرست را تازه و دوباره گروه را برای ویرایش باز کنید.', 'andiya-price-guard' ) );
					}
				}
				if ( 'DELETE' === $method ) {
					$changed = $wpdb->delete( $t, array( 'id' => $id ) );
					$event   = 'group_deleted';
				} else {
					$name = sanitize_text_field( $d['name'] ?? '' );
					if ( ! $name || mb_strlen( $name ) > 180 ) {
						throw new \InvalidArgumentException( esc_html__( 'نام گروه باید بین ۱ تا ۱۸۰ نویسه باشد.', 'andiya-price-guard' ) );
					}
					$row     = array(
						'name'    => $name,
						'filters' => Support::json( Operations::filters( (array) ( $d['filters'] ?? array() ) ) ),
					);
					$changed = $id ? $wpdb->update( $t, $row, array( 'id' => $id ) ) : $wpdb->insert( $t, $row );
					$id      = $id ? $id : (int) $wpdb->insert_id;
					$event   = 'group_saved';
				}
				if ( false === $changed ) {
					throw new \RuntimeException( esc_html__( 'ذخیره تغییر گروه انجام نشد. دوباره تلاش کنید.', 'andiya-price-guard' ) );
				}
				Storage::audit( $event, $id );
			} finally {
				Support::unlock( $key, $token );
			}
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 200', $t ), ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['filters']  = Support::decode( $r['filters'] );
			$r['revision'] = self::group_revision( $r );
		}
		return $rows;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Save explicit merchant control settings.
	 *
	 * @param \WP_REST_Request $request REST callback request.
	 * @return array
	 */
	public static function settings( $request ): array {
		$d = (array) $request->get_json_params();
		$s = Support::settings();
		foreach ( array(
			'mode'         => array( 'normal', 'quote', 'stop' ),
			'deactivation' => array( 'restore', 'keep' ),
		) as $k => $values ) {
			if ( isset( $d[ $k ] ) && in_array( $d[ $k ], $values, true ) ) {
				$s[ $k ] = $d[ $k ];
			}
		}
		$s['categories'] = array_values( array_filter( array_map( 'absint', (array) ( $d['categories'] ?? array() ) ) ) );
		foreach ( array(
			'valid_hours' => array( 0, 8760 ),
			'offer_hours' => array( 1, 720 ),
			'retention'   => array( 1, 3650 ),
		) as $k => $bounds ) {
			$s[ $k ] = Support::integer( $d[ $k ] ?? $s[ $k ], $bounds[0], $bounds[1] );
		}
		foreach ( array( 'email_admin', 'email_customer', 'delete_data' ) as $k ) {
			$s[ $k ] = ! empty( $d[ $k ] );
		}
		foreach ( array( 'label', 'message', 'stop_message' ) as $k ) {
			if ( ! empty( $d[ $k ] ) ) {
				$s[ $k ] = sanitize_text_field( $d[ $k ] );
			}
		}
		update_option( 'apg_settings', $s, false );
		Support::purge( array() );
		Storage::audit( 'settings_changed', 0 );
		return $s;
	}

	/**
	 * List private requests for an authorized operator.
	 *
	 * @param \WP_REST_Request $request REST callback request.
	 * @return array
	 */
	public static function quotes( $request ): array {
		global $wpdb;
		$t    = Storage::table( 'quotes' );
		$page = max( 1, absint( $request['page'] ?? 1 ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,name,phone,status,created,expires,order_id FROM %i ORDER BY id DESC LIMIT 30 OFFSET %d', $t, ( $page - 1 ) * 30 ), ARRAY_A );
		return array(
			'rows'  => $rows,
			'total' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) ),
			'page'  => $page,
		);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Read or update a private request.
	 *
	 * @param \WP_REST_Request $request REST callback request.
	 * @return array
	 * @throws \InvalidArgumentException When inputs or operation state are invalid.
	 * @throws \RuntimeException When permission, version or lock checks fail.
	 */
	public static function quote( $request ): array {
		global $wpdb;
		$id = absint( $request['id'] );
		$q  = Storage::get( 'quotes', $id );
		if ( ! $q ) {
			throw new \InvalidArgumentException( esc_html__( 'استعلام پیدا نشد.', 'andiya-price-guard' ) );
		}
		if ( 'POST' === $request->get_method() ) {
			$d = (array) $request->get_json_params();
			if ( 'erase' === ( $d['action'] ?? '' ) ) {
				Privacy::anonymize( $id );
			} elseif ( 'offer' === ( $d['action'] ?? '' ) ) {
				Quotes::offer( $id, $d );
			} else {
				$key   = 'quote:' . $id;
				$token = Support::lock( $key );
				if ( ! $token ) {
					throw new \RuntimeException( esc_html__( 'استعلام در حال تغییر است.', 'andiya-price-guard' ) );
				}
				try {
					$status = $d['status'] ?? '';
					if ( ! in_array( $status, array( 'review', 'closed' ), true ) || Storage::get( 'quotes', $id )['order_id'] ) {
						throw new \InvalidArgumentException( esc_html__( 'وضعیت قابل تغییر نیست.', 'andiya-price-guard' ) );
					}
					$wpdb->update(
						Storage::table( 'quotes' ),
						array( 'status' => $status ),
						array(
							'id'       => $id,
							'order_id' => 0,
						)
					);
				} finally {
					Support::unlock( $key, $token );
				}
			}
			$q = Storage::get( 'quotes', $id );
		}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$q['url']   = Quotes::url( $q );
		$q['items'] = Support::decode( $q['items'] );
		$q['offer'] = Support::decode( $q['offer'] );
		unset( $q['secret'], $q['intake_key'], $q['payload_hash'] );
		return $q;
	}

	/**
	 * Return paginated operation and audit history.
	 *
	 * @param \WP_REST_Request $request REST callback request.
	 * @return array
	 */
	public static function history( $request ): array {
		global $wpdb;
		$page    = max( 1, absint( $request['page'] ?? 1 ) );
		$t       = Storage::table( 'batches' );
		$batches = $wpdb->get_results( $wpdb->prepare( 'SELECT id,actor,created,status,currency FROM %i ORDER BY id DESC LIMIT 30 OFFSET %d', $t, ( $page - 1 ) * 30 ), ARRAY_A );
		$t       = Storage::table( 'audit' );
		$audit   = $wpdb->get_results( $wpdb->prepare( 'SELECT id,actor,created,event,object_id,details FROM %i ORDER BY id DESC LIMIT 30 OFFSET %d', $t, ( $page - 1 ) * 30 ), ARRAY_A );
		$total   = max( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Storage::table( 'batches' ) ) ), (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Storage::table( 'audit' ) ) ) );
		return array(
			'batches' => $batches,
			'audit'   => $audit,
			'page'    => $page,
			'pages'   => (int) ceil( $total / 30 ),
		);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Validate an uploaded price file and create a preview.
	 *
	 * @param \WP_REST_Request $request REST callback request.
	 * @return array
	 * @throws \InvalidArgumentException When the uploaded file is invalid.
	 * @throws \RuntimeException When permission, version or lock checks fail.
	 */
	public static function import( $request ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new \RuntimeException( esc_html__( 'اجازه بارگذاری فایل ندارید.', 'andiya-price-guard' ) );
		}
		$files = $request->get_file_params();
		$f     = $files['file'] ?? array();
		$ext   = strtolower( pathinfo( $f['name'] ?? '', PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'xlsx' ), true ) || ! empty( $f['error'] ) || ! is_uploaded_file( $f['tmp_name'] ?? '' ) ) {
			throw new \InvalidArgumentException( esc_html__( 'یک فایل CSV یا XLSX سالم انتخاب کنید.', 'andiya-price-guard' ) );
		}
		$map  = array(
			'sku'           => sanitize_text_field( $request['sku_column'] ?? 'sku' ),
			'regular_price' => sanitize_text_field( $request['price_column'] ?? 'regular_price' ),
			'sale_price'    => sanitize_text_field( $request['sale_column'] ?? 'sale_price' ),
		);
		$rows = Sources::file_data( $f['tmp_name'], $ext, $map );
		$data = Sources::data( $rows, sanitize_text_field( $request['currency'] ?? '' ) );
		return self::with_export(
			Operations::preview(
				array(
					'kind'  => 'import',
					'hours' => $request['hours'] ?? 0,
				),
				$data
			)
		);
	}

	/**
	 * Save or remove an explicitly configured supplier source.
	 *
	 * @param \WP_REST_Request $request REST callback request.
	 * @return array
	 * @throws \InvalidArgumentException When inputs or operation state are invalid.
	 */
	public static function sources( $request ): array {
		global $wpdb;
		if ( 'DELETE' === $request->get_method() ) {
			$wpdb->delete( Storage::table( 'sources' ), array( 'id' => absint( $request['id'] ) ) );
			return array( 'ok' => true );
		}
		return Sources::save( (array) $request->get_json_params() );
	}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	/**
	 * Fetch one supplier and return a preview with its private export URL.
	 *
	 * @param \WP_REST_Request $request REST callback request.
	 * @return array
	 */
	public static function fetch( $request ): array {
		return self::with_export( Sources::fetch( absint( $request['id'] ) ) );
	}

	/**
	 * Finish still-owned restoration from an earlier deactivation.
	 *
	 * @param \WP_REST_Request $request REST callback request.
	 * @return array
	 */
	public static function restore( $request ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Keep the common REST callback signature.
		update_option( 'apg_deactivating', '1', false );
		try {
			$r = Storage::restore();
			update_option( 'apg_deactivation_report', $r, false );
			return $r;
		} finally {
			delete_option( 'apg_deactivating' );
		}
	}

	/**
	 * Render the protected native WooCommerce product tab.
	 */
	public static function native_panel(): void {
		global $product_object;
		if ( ! current_user_can( 'apg_manage_prices' ) || ! $product_object ) {
			return;
		}
		echo '<div id="apg_product_data" class="panel woocommerce_options_panel hidden">';
		woocommerce_wp_select(
			array(
				'id'      => '_apg_state',
				'label'   => __( 'وضعیت فروش', 'andiya-price-guard' ),
				'options' => array(
					''      => __( 'پیروی از تنظیمات', 'andiya-price-guard' ),
					'buy'   => __( 'خرید با قیمت معتبر', 'andiya-price-guard' ),
					'quote' => __( 'استعلام', 'andiya-price-guard' ),
					'stop'  => __( 'توقف فروش', 'andiya-price-guard' ),
				),
				'value'   => $product_object->get_meta( '_apg_state', true, 'edit' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'      => '_apg_exclude',
				'label'   => __( 'استثنا از تغییر گروهی و خودکار', 'andiya-price-guard' ),
				'cbvalue' => '1',
				'value'   => $product_object->get_meta( '_apg_exclude', true, 'edit' ),
			)
		);
		echo '<p class="form-field">' . esc_html__( 'برای قیمت و اعتبار مستقل هر تنوع، از پنل قیمت و استعلام استفاده کنید. ویرایش دستی قیمت کالای متصل به منبع، آن را از به‌روزرسانی خودکار مستثنا و نیازمند تأیید می‌کند.', 'andiya-price-guard' ) . '</p></div>';
	}

	/**
	 * Record policy changes after WooCommerce product-edit nonce verification.
	 *
	 * @param \WC_Product $p Native WooCommerce product.
	 */
	public static function native_save( $p ): void {
		if ( ! current_user_can( 'apg_manage_prices' ) || ! isset( $_POST['_apg_state'], $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}
		// WooCommerce validates the parent product-edit nonce before this action.
		$state   = sanitize_text_field( wp_unslash( $_POST['_apg_state'] ) );
		$exclude = isset( $_POST['_apg_exclude'] ) ? '1' : '';
 // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$after = array(
			'_apg_state'   => in_array( $state, array( 'buy', 'quote', 'stop' ), true ) ? $state : '',
			'_apg_exclude' => $exclude,
		);
		$fresh = wc_get_product( $p->get_id() );
		if ( $fresh ) {
			Operations::baseline( $p->get_id(), Support::snapshot( $fresh ), $after );
		}
		foreach ( $after as $k => $v ) {
			Support::set( $p, $k, $v );
		}
	}

	/**
	 * Render an authorized Persian management panel.
	 */
	public static function page(): void {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'اجازه دسترسی به این پنل ندارید.', 'andiya-price-guard' ) );
		}
		require APG_DIR . 'admin-page.php';
	}
}
