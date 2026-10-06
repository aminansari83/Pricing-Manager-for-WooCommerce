<?php
namespace Andiya\PriceGuard;
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plugin-owned tables use fresh reads for locks, version checks and private offers; caching would defeat concurrency guards.
final class Operations {
	public static bool $writing = false;

	public static function register(): void {
		add_action( 'apg_process', array( self::class, 'process' ) );
		add_action( 'woocommerce_before_product_object_save', array( self::class, 'manual_change' ) );
	}

	public static function manual_change( $p ): void {
		if ( self::$writing || get_option( 'apg_deactivating' ) || ! $p->get_id() || ! $p->is_type( array( 'simple', 'variation' ) ) ) {
			return;
		}
		$changes = $p->get_changes();
		if ( $p->get_meta( '_apg_source', true, 'edit' ) && ( isset( $changes['regular_price'] ) || isset( $changes['sale_price'] ) ) ) {
			$p->update_meta_data( '_apg_exclude', '1' );
			$p->delete_meta_data( '_apg_checked' );
			$p->update_meta_data( '_apg_state', 'quote' );
		}
	}

	public static function filters( array $f ): array {
		$r = array(
			'categories' => array_values( array_filter( array_map( 'absint', (array) ( $f['categories'] ?? array() ) ) ) ),
			'search'     => sanitize_text_field( $f['search'] ?? '' ),
			'source'     => sanitize_text_field( $f['source'] ?? '' ),
			'terms'      => array(),
		);
		foreach ( (array) ( $f['terms'] ?? array() ) as $tax => $terms ) {
			if ( taxonomy_exists( $tax ) && ( str_starts_with( $tax, 'pa_' ) || in_array( $tax, array( 'product_brand', 'product_tag' ), true ) ) ) {
				$r['terms'][ $tax ] = array_values( array_filter( array_map( 'absint', (array) $terms ) ) );
			}
		}
		return $r;
	}

	public static function ids( array $f ): \Generator {
		$f = self::filters( $f );
		$tax = array( 'relation' => 'AND' );
		if ( $f['categories'] ) {
			$tax[] = array(
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => $f['categories'],
				'include_children' => true,
			);
		}
		foreach ( $f['terms'] as $taxonomy => $terms ) {
			if ( $terms ) {
				$tax[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $terms,
				);
			}
		}
		$page = 1;
		$seen = array();
		do {
			$args = array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 100,
				'paged'          => $page++,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			);
			if ( count( $tax ) > 1 ) {
				$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Saved merchant category and attribute filters use core indexed taxonomy relationships.
			}
 // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			if ( $f['search'] ) {
				$args['s'] = $f['search'];
			}
			$q = new \WP_Query( $args );
			foreach ( $q->posts as $id ) {
				$p = wc_get_product( $id );
				if ( ! $p ) {
					continue;
				}
				$ids = $p->is_type( 'variable' ) ? $p->get_children() : array( $id );
				foreach ( $ids as $pid ) {
					if ( isset( $seen[ $pid ] ) ) {
						continue;
					}
					$child = wc_get_product( $pid );
					if ( $f['source'] && (string) $child->get_meta( '_apg_source', true, 'edit' ) !== $f['source'] ) {
						continue;
					}
					$seen[ $pid ] = true;
					yield (int) $pid;
				}
			}
		} while ( count( $q->posts ) === 100 );
	}

	public static function calculate( string $base, array $cfg ): string {
		self::validate_calculation( $cfg );
		$n = 'set' === $cfg['kind'] ? 0 : Support::number( $base, 0.01 );
		$v = Support::number( $cfg['value'] ?? 0, 'percent' === $cfg['kind'] ? -99.99 : -9000000000000 );
		switch ( $cfg['kind'] ) {
			case 'percent':
				$n *= 1 + $v / 100;
				break;
			case 'fixed':
				$n += Support::number( $cfg['value'], -9000000000000 );
				break;
			case 'set':
				$n = Support::number( $cfg['value'], 0.01 );
				break;
			default:
				throw new \InvalidArgumentException( esc_html__( 'روش محاسبه معتبر نیست.', 'andiya-price-guard' ) );
		}
		$step = Support::number( $cfg['round'] ?? 0 );
		if ( $step ) {
			$mode = $cfg['round_mode'] ?? 'up';
			$n = ( 'down' === $mode ? floor( $n / $step ) : ( 'nearest' === $mode ? round( $n / $step ) : ceil( $n / $step ) ) ) * $step;
		}
		return Support::money( Support::number( (string) $n, 0.01 ) );
	}

	private static function validate_calculation( array $cfg ): void {
		$kind = $cfg['kind'] ?? '';
		if ( ! in_array( $kind, array( 'percent', 'fixed', 'set' ), true ) ) {
			throw new \InvalidArgumentException( esc_html__( 'روش محاسبه معتبر نیست.', 'andiya-price-guard' ) );
		}
		$min = 'percent' === $kind ? -99.99 : ( 'set' === $kind ? 0.01 : -9000000000000 );
		Support::number( $cfg['value'] ?? 0, $min );
		Support::number( $cfg['round'] ?? 0 );
		if ( ! in_array( $cfg['round_mode'] ?? 'up', array( 'up', 'down', 'nearest' ), true ) ) {
			throw new \InvalidArgumentException( esc_html__( 'روش گردکردن معتبر نیست.', 'andiya-price-guard' ) );
		}
	}

	public static function preview( array $cfg, ?array $import = null ): array {
		global $wpdb;
		$kind = $cfg['kind'] ?? 'percent';
		if ( ! in_array( $kind, array( 'percent', 'fixed', 'set', 'state', 'confirm', 'import' ), true ) ) {
			throw new \InvalidArgumentException( esc_html__( 'عملیات معتبر نیست.', 'andiya-price-guard' ) );
		}
		$cfg['kind'] = $kind;
		$cfg['filters'] = self::filters( (array) ( $cfg['filters'] ?? array() ) );
		$cfg['hours'] = Support::integer( $cfg['hours'] ?? Support::settings()['valid_hours'], 0, 8760 );
		$cfg['sale'] = in_array( $cfg['sale'] ?? '', array( 'both', 'ratio', 'clear' ), true ) ? $cfg['sale'] : 'skip';
		$cfg['state'] = in_array( $cfg['state'] ?? '', array( 'buy', 'quote', 'stop', 'inherit' ), true ) ? $cfg['state'] : 'inherit';
		$cfg['base_id'] = absint( $cfg['base_id'] ?? 0 );
		if ( in_array( $kind, array( 'percent', 'fixed', 'set' ), true ) ) {
			self::validate_calculation( $cfg );
		}
		$wpdb->insert(
			Storage::table( 'batches' ),
			array(
				'actor'    => get_current_user_id(),
				'created'  => time(),
				'status'   => 'preview',
				'currency' => get_woocommerce_currency(),
				'config'   => Support::json( $cfg ),
			)
		);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			throw new \RuntimeException( esc_html__( 'ثبت پیش‌نمایش انجام نشد.', 'andiya-price-guard' ) );
		}
		$ids = null !== $import ? array_keys( $import ) : self::ids( $cfg['filters'] );
		$count = 0;
		try {
			foreach ( $ids as $pid ) {
				if ( ++$count > 20000 ) {
					throw new \RuntimeException( esc_html__( 'دامنه را به گروه‌های کوچک‌تر از بیست هزار کالا تقسیم کنید.', 'andiya-price-guard' ) );
				}
				$p = wc_get_product( $pid );
				if ( ! $p ) {
					continue;
				}
				$before = Support::snapshot( $p );
				$base = $before;
				$after = array();
				$reason = '';
				$parent = $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : null;
				if ( ! $p->is_type( array( 'simple', 'variation' ) ) ) {
					$reason = __( 'این نوع محصول پشتیبانی نمی‌شود.', 'andiya-price-guard' );
				} elseif ( empty( $cfg['include_excluded'] ) && ( '1' === $before['_apg_exclude'] || ( $parent && '1' === $parent->get_meta( '_apg_exclude', true, 'edit' ) ) ) ) {
					$reason = __( 'محصول از تغییرات گروهی مستثنا شده است.', 'andiya-price-guard' );
				} elseif ( 'skip' === $cfg['sale'] && '' !== $before['sale_price'] && in_array( $kind, array( 'percent', 'fixed', 'set' ), true ) ) {
						$reason = __( 'قیمت ویژه؛ طبق انتخاب شما کنار گذاشته شد.', 'andiya-price-guard' );
				} else {
					if ( $cfg['base_id'] ) {
						$t = Storage::table( 'rows' );
						$root = $wpdb->get_row( $wpdb->prepare( "SELECT before_data FROM %i WHERE batch_id=%d AND product_id=%d AND status IN ('applied','undone')", $t, $cfg['base_id'], $pid ), ARRAY_A );
						if ( ! $root ) {
											$reason = __( 'این کالا در مبنای انتخابی وجود ندارد.', 'andiya-price-guard' );
						} else {
											$base = Support::decode( $root['before_data'] );
						}
					}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					if ( ! $reason ) {
						try {
							if ( 'state' === $kind ) {
								$after['_apg_state'] = 'inherit' === $cfg['state'] ? '' : $cfg['state'];
							} elseif ( 'import' === $kind ) {
								$after = $import[ $pid ];
							} elseif ( 'confirm' !== $kind ) {
								$after['regular_price'] = self::calculate( $base['regular_price'], $cfg );
								if ( '' !== $base['sale_price'] ) {
									if ( 'clear' === $cfg['sale'] ) {
										$after['sale_price'] = '';
									} elseif ( 'ratio' === $cfg['sale'] ) {
										$regular = Support::number( $base['regular_price'], 0.01 );
										$after['sale_price'] = Support::money( (float) $after['regular_price'] * (float) $base['sale_price'] / $regular );
									} else {
										$after['sale_price'] = self::calculate( $base['sale_price'], $cfg );
									}
								}
							}
							if ( 'state' !== $kind && ! ( 'quote' === ( $after['_apg_state'] ?? '' ) && ! isset( $after['regular_price'] ) ) ) {
								$after['_apg_checked'] = (string) time();
								$after['_apg_expires'] = $cfg['hours'] ? (string) ( time() + $cfg['hours'] * HOUR_IN_SECONDS ) : '';
							}
							if ( ! in_array( $kind, array( 'state', 'import' ), true ) ) {
								$after['_apg_state'] = '';
							}
							self::validate_prices( array_merge( $before, $after ) );
						} catch ( \InvalidArgumentException $e ) {
							$after = array();
							$reason = in_array( $kind, array( 'percent', 'fixed' ), true ) && '' === $base['regular_price']
								? __( 'قیمت پایه خالی است؛ ابتدا قیمت این کالا را تعیین کنید.', 'andiya-price-guard' )
								: $e->getMessage();
						}
					}
				}
				$inserted = $wpdb->insert(
					Storage::table( 'rows' ),
					array(
						'batch_id'    => $id,
						'product_id'  => $pid,
						'before_data' => Support::json( $before ),
						'after_data'  => Support::json( $after ),
						'fingerprint' => Support::fingerprint( $before ),
						'status'      => $reason ? 'skipped' : 'pending',
						'reason'      => $reason,
					)
				);
				if ( false === $inserted ) {
					throw new \RuntimeException( esc_html__( 'ثبت ردیف پیش‌نمایش انجام نشد؛ دوباره تلاش کنید.', 'andiya-price-guard' ) );
				}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		} catch ( \Throwable $e ) {
			$wpdb->update( Storage::table( 'batches' ), array( 'status' => 'failed' ), array( 'id' => $id ) );
			throw $e;
		}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Storage::audit( 'preview_created', $id );
		return self::report( $id );
	}

	public static function validate_prices( array $v ): void {
		if ( '' !== $v['sale_price'] && ( (float) $v['sale_price'] <= 0 || (float) $v['sale_price'] >= (float) $v['regular_price'] ) ) {
			throw new \InvalidArgumentException( esc_html__( 'قیمت ویژه باید مثبت و کمتر از قیمت عادی باشد.', 'andiya-price-guard' ) );
		}
	}

	public static function baseline( int $id, array $before, array $after ): void {
		global $wpdb;
		$t = Storage::table( 'baselines' );
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE product_id=%d', $t, $id ), ARRAY_A );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$original = $r ? Support::decode( $r['original'] ) : array();
		$expected = $r ? Support::decode( $r['expected'] ) : array();
		foreach ( $after as $k => $v ) {
			if ( ! array_key_exists( $k, $expected ) || ( $before[ $k ] ?? null ) !== $expected[ $k ] ) {
				$original[ $k ] = $before[ $k ];
			}
			$expected[ $k ] = (string) $v;
		}
		$wpdb->replace(
			$t,
			array(
				'product_id' => $id,
				'original'   => Support::json( $original ),
				'expected'   => Support::json( $expected ),
			)
		);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function write( \WC_Product $p, array $after ): void {
		global $wpdb;
		self::$writing = true;
		$wpdb->query( 'START TRANSACTION' );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		try {
			self::validate_prices( array_merge( Support::snapshot( $p ), $after ) );
			self::baseline( $p->get_id(), Support::snapshot( $p ), $after );
			foreach ( $after as $k => $v ) {
				Support::set( $p, $k, $v );
			}
			$p->save();
			$wpdb->query( 'COMMIT' );
		}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			clean_post_cache( $p->get_id() );
			throw $e;
		}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		finally {
			self::$writing = false;
		}
	}

	public static function commit( int $id, array $exclude = array() ): array {
		global $wpdb;
		$token = Support::lock( 'batch:' . $id );
		if ( ! $token ) {
			return self::report( $id );
		}
		try {
			$b = Storage::get( 'batches', $id );
			if ( ! $b ) {
				throw new \RuntimeException( esc_html__( 'عملیات پیدا نشد.', 'andiya-price-guard' ) );
			}
			if ( 'preview' !== $b['status'] ) {
				return self::report( $id );
			}
			if ( ! Support::running() || time() - (int) $b['created'] > HOUR_IN_SECONDS || get_woocommerce_currency() !== $b['currency'] ) {
				throw new \RuntimeException( esc_html__( 'پیش‌نمایش منقضی شده یا واحد پول تغییر کرده است. دوباره پیش‌نمایش بگیرید.', 'andiya-price-guard' ) );
			}
			foreach ( array_slice( array_unique( array_map( 'absint', $exclude ) ), 0, 20000 ) as $pid ) {
				$wpdb->update(
					Storage::table( 'rows' ),
					array(
						'status' => 'skipped',
						'reason' => __( 'انتخاب مدیر برای کنارگذاشتن از این عملیات.', 'andiya-price-guard' ),
					),
					array(
						'batch_id'   => $id,
						'product_id' => $pid,
						'status'     => 'pending',
					)
				);
			}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				Storage::table( 'batches' ),
				array( 'status' => 'running' ),
				array(
					'id'     => $id,
					'status' => 'preview',
				)
			);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} finally {
			Support::unlock( 'batch:' . $id, $token );
		}
		self::process( $id );
		return self::report( $id );
	}

	public static function schedule( int $id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'apg_process', array( $id ), 'andiya-price-guard', true );
		} elseif ( ! wp_next_scheduled( 'apg_process', array( $id ) ) ) {
			wp_schedule_single_event( time() + 5, 'apg_process', array( $id ) );
		}
	}

	public static function process( int $id ): void {
		global $wpdb;
		if ( ! Support::running() ) {
			return;
		}
		$key = 'batch:' . $id;
		$token = Support::lock( $key, 120 );
		if ( ! $token ) {
			return;
		}
		try {
			$b = Storage::get( 'batches', $id );
			if ( 'running' !== ( $b['status'] ?? '' ) ) {
				return;
			}
			if ( get_woocommerce_currency() !== $b['currency'] ) {
				self::cancel( $id );
				return;
			}
			$cfg = Support::decode( $b['config'] );
			$t = Storage::table( 'rows' );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE batch_id=%d AND status='pending' ORDER BY id LIMIT 50", $t, $id ), ARRAY_A );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $rows as $r ) {
				if ( ! Support::running() || 'running' !== ( Storage::get( 'batches', $id )['status'] ?? '' ) ) {
					return;
				}
				$pid = (int) $r['product_id'];
				$pk = 'product:' . $pid;
				$pt = Support::lock( $pk );
				if ( ! $pt ) {
					continue;
				}
				try {
					$p = Support::fresh( $pid );
					$after = Support::decode( $r['after_data'] );
					$parent = $p && $p->is_type( 'variation' ) ? Support::fresh( $p->get_parent_id() ) : null;
					if ( ! $p || Support::fingerprint( Support::snapshot( $p ) ) !== $r['fingerprint'] ) {
						$status = 'conflict';
						$reason = __( 'محصول پس از پیش‌نمایش تغییر کرده است.', 'andiya-price-guard' );
					} elseif ( empty( $cfg['include_excluded'] ) && $parent && '1' === $parent->get_meta( '_apg_exclude', true, 'edit' ) ) {
						$status = 'skipped';
						$reason = __( 'محصول از تغییرات گروهی مستثنا شده است.', 'andiya-price-guard' );
					} else {
						self::write( $p, $after );
						$status = 'applied';
						$reason = '';
						Support::purge( array( $pid ), false );
					}
						$wpdb->update(
							$t,
							array(
								'status' => $status,
								'reason' => $reason,
							),
							array(
								'id'     => $r['id'],
								'status' => 'pending',
							)
						);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				} catch ( \Throwable $e ) {
					$wpdb->update(
						$t,
						array(
							'status' => 'failed',
							'reason' => $e->getMessage(),
						),
						array( 'id' => $r['id'] )
					);
				} finally {
					Support::unlock( $pk, $pt );
				}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			Support::purge( array() );
			$counts = self::counts( $id );
			if ( $counts['pending'] ) {
				self::schedule( $id );
			} else {
				$status = ( $counts['conflict'] || $counts['failed'] ) ? 'partial' : 'completed';
				$wpdb->update(
					Storage::table( 'batches' ),
					array( 'status' => $status ),
					array(
						'id'     => $id,
						'status' => 'running',
					)
				);
							Storage::audit( $status, $id, $counts );
			}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} finally {
			Support::unlock( $key, $token );
		}
	}

	public static function cancel( int $id ): array {
		global $wpdb;
		$wpdb->update( Storage::table( 'batches' ), array( 'status' => 'cancelled' ), array( 'id' => $id ) );
		Storage::audit( 'cancelled', $id );
		return self::report( $id );
	}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	public static function undo( int $id ): array {
		global $wpdb;
		$key = 'batch:' . $id;
		$token = Support::lock( $key );
		if ( ! $token ) {
			return self::report( $id );
		}
		try {
			$b = Storage::get( 'batches', $id );
			if ( ! $b || in_array( $b['status'], array( 'running', 'preview' ), true ) ) {
				throw new \RuntimeException( esc_html__( 'ابتدا عملیات را متوقف کنید.', 'andiya-price-guard' ) );
			}
			$t = Storage::table( 'rows' );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE batch_id=%d AND status='applied' ORDER BY id DESC LIMIT 50", $t, $id ), ARRAY_A );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $rows as $r ) {
				$pid = (int) $r['product_id'];
				$pt = Support::lock( 'product:' . $pid );
				if ( ! $pt ) {
					continue;
				}
				try {
					$p = Support::fresh( $pid );
					$before = Support::decode( $r['before_data'] );
					$after = Support::decode( $r['after_data'] );
					$now = $p ? Support::snapshot( $p ) : array();
					$restore = array();
					foreach ( $after as $k => $v ) {
						if ( ( $now[ $k ] ?? null ) === (string) $v ) {
							$restore[ $k ] = $before[ $k ];
						}
					}
					if ( $p && $restore ) {
						try {
							self::validate_prices( array_merge( $now, $restore ) );
						} catch ( \Throwable $e ) {
							unset( $restore['regular_price'], $restore['sale_price'] );
						}
						if ( $restore ) {
							self::write( $p, $restore );
							Support::purge( array( $pid ) );
						}
					}
					$status = count( $restore ) === count( $after ) ? 'undone' : 'preserved';
					$wpdb->update(
						$t,
						array(
							'status' => $status,
							'reason' => 'preserved' === $status ? __( 'فیلدهای دارای تغییر جدید حفظ شدند.', 'andiya-price-guard' ) : '',
						),
						array( 'id' => $r['id'] )
					);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				} finally {
					Support::unlock( 'product:' . $pid, $pt );
				}
			}
			if ( ! self::counts( $id )['applied'] ) {
				$wpdb->update( Storage::table( 'batches' ), array( 'status' => 'undone' ), array( 'id' => $id ) );
				Storage::audit( 'undone', $id );
			}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} finally {
			Support::unlock( $key, $token );
		}
		return self::report( $id );
	}

	public static function counts( int $id ): array {
		global $wpdb;
		$t = Storage::table( 'rows' );
		$counts = array_fill_keys( array( 'pending', 'applied', 'conflict', 'failed', 'skipped', 'undone', 'preserved' ), 0 );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status,COUNT(*) total FROM %i WHERE batch_id=%d GROUP BY status', $t, $id ), ARRAY_A );
		foreach ( $rows as $r ) {
			$counts[ $r['status'] ] = (int) $r['total'];
		}
		$counts['total'] = array_sum( $counts );
		return $counts;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function report( int $id, int $page = 1 ): array {
		global $wpdb;
		$b = Storage::get( 'batches', $id );
		if ( ! $b ) {
			throw new \RuntimeException( esc_html__( 'عملیات پیدا نشد.', 'andiya-price-guard' ) );
		}
		$t = Storage::table( 'rows' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE batch_id=%d ORDER BY id LIMIT 50 OFFSET %d', $t, $id, max( 0, $page - 1 ) * 50 ), ARRAY_A );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as &$r ) {
			$p = wc_get_product( $r['product_id'] );
			$r['name'] = $p ? $p->get_name() : __( 'محصول حذف‌شده', 'andiya-price-guard' );
			$r['sku'] = $p ? $p->get_sku( 'edit' ) : '';
			$r['before'] = Support::decode( $r['before_data'] );
			$r['after'] = array_merge( $r['before'], Support::decode( $r['after_data'] ) );
			unset( $r['before_data'], $r['after_data'], $r['fingerprint'] );
		}
		return array(
			'id'       => $id,
			'status'   => $b['status'],
			'currency' => $b['currency'],
			'created'  => (int) $b['created'],
			'config'   => Support::decode( $b['config'] ),
			'counts'   => self::counts( $id ),
			'rows'     => $rows,
			'page'     => $page,
		);
	}
}
