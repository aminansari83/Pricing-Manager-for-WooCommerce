<?php
namespace Andiya\PriceGuard;
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plugin-owned tables use fresh reads for locks, version checks and private offers; caching would defeat concurrency guards.
final class Storage {

	public static function table( string $name ): string {
		global $wpdb;
		if ( ! in_array( $name, array( 'batches', 'rows', 'baselines', 'quotes', 'groups', 'sources', 'audit' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid table' );
		}
		return $wpdb->prefix . 'apg_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$schemas = array(
			'batches'   => 'id bigint unsigned NOT NULL AUTO_INCREMENT, actor bigint unsigned NOT NULL, created bigint unsigned NOT NULL, status varchar(24) NOT NULL, currency varchar(10) NOT NULL, config longtext NOT NULL, PRIMARY KEY  (id)',
			'rows'      => 'id bigint unsigned NOT NULL AUTO_INCREMENT, batch_id bigint unsigned NOT NULL, product_id bigint unsigned NOT NULL, before_data longtext NOT NULL, after_data longtext NOT NULL, fingerprint char(64) NOT NULL, status varchar(24) NOT NULL, reason text NOT NULL, PRIMARY KEY  (id), UNIQUE KEY batch_product (batch_id,product_id), KEY batch_status (batch_id,status)',
			'baselines' => 'product_id bigint unsigned NOT NULL, original longtext NOT NULL, expected longtext NOT NULL, PRIMARY KEY  (product_id)',
			'quotes'    => 'id bigint unsigned NOT NULL AUTO_INCREMENT, secret char(48) NOT NULL, intake_key char(64) NOT NULL, payload_hash char(64) NOT NULL, user_id bigint unsigned NOT NULL DEFAULT 0, name varchar(180) NOT NULL, phone varchar(20) NOT NULL, email varchar(190) NOT NULL, items longtext NOT NULL, note text NOT NULL, offer longtext NOT NULL, status varchar(20) NOT NULL, version int unsigned NOT NULL DEFAULT 0, created bigint unsigned NOT NULL, expires bigint unsigned NOT NULL DEFAULT 0, order_id bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (id), UNIQUE KEY intake (intake_key), KEY user_created (user_id,created), KEY email (email), KEY status (status)',
			'groups'    => 'id bigint unsigned NOT NULL AUTO_INCREMENT, name varchar(180) NOT NULL, filters longtext NOT NULL, PRIMARY KEY  (id)',
			'sources'   => 'id bigint unsigned NOT NULL AUTO_INCREMENT, name varchar(180) NOT NULL, url text NOT NULL, currency varchar(10) NOT NULL, automatic tinyint NOT NULL DEFAULT 0, threshold decimal(8,2) NOT NULL DEFAULT 20, hours int unsigned NOT NULL DEFAULT 6, last_run bigint unsigned NOT NULL DEFAULT 0, last_success bigint unsigned NOT NULL DEFAULT 0, message text NOT NULL, PRIMARY KEY  (id)',
			'audit'     => 'id bigint unsigned NOT NULL AUTO_INCREMENT, actor bigint unsigned NOT NULL, created bigint unsigned NOT NULL, event varchar(80) NOT NULL, object_id bigint unsigned NOT NULL DEFAULT 0, details longtext NOT NULL, PRIMARY KEY  (id), KEY created (created)',
		);
		foreach ( $schemas as $name => $schema ) {
			dbDelta( 'CREATE TABLE ' . self::table( $name ) . " (\n" . str_replace( ', ', ",\n", $schema ) . "\n) $c;" );
		}
		update_option( 'apg_schema', APG_VERSION, false );
	}

	public static function register(): void {
		if ( get_option( 'apg_schema' ) !== APG_VERSION ) {
			self::install();
		}
		add_action( 'apg_cleanup', array( Privacy::class, 'cleanup' ) );
	}

	public static function activate( bool $network = false ): void {
		if ( $network ) {
			wp_die( esc_html__( 'در شبکهٔ چندسایتی، افزونه را برای هر فروشگاه جدا فعال کنید.', 'andiya-price-guard' ) );
		}
		self::install();
		add_option( 'apg_settings', Support::defaults(), '', false );
		update_option( 'apg_running', '1', false );
		foreach ( array( 'administrator', 'shop_manager' ) as $r ) {
			$role = get_role( $r );
			if ( $role ) {
				foreach ( array( 'apg_manage_prices', 'apg_manage_quotes', 'apg_view_history' ) as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}
		if ( ! wp_next_scheduled( 'apg_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'apg_cleanup' );
		}
		if ( ! wp_next_scheduled( 'apg_sources_tick' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'apg_sources_tick' );
		}
	}

	public static function deactivate(): void {
		global $wpdb;
		update_option( 'apg_running', '0', false );
		update_option( 'apg_deactivating', '1', false );
		wp_clear_scheduled_hook( 'apg_cleanup' );
		wp_clear_scheduled_hook( 'apg_sources_tick' );
		wp_clear_scheduled_hook( 'apg_process' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'apg_process', null, 'andiya-price-guard' );
		}
		$t = self::table( 'batches' );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status='cancelled' WHERE status IN ('running','preview')", $t ) );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$settings = Support::settings();
		$report = array(
			'restored'  => 0,
			'preserved' => 0,
			'remaining' => 0,
			'created'   => time(),
		);
		if ( 'restore' === $settings['deactivation'] && function_exists( 'wc_get_product' ) ) {
			$report = self::restore();
		} elseif ( 'keep' === $settings['deactivation'] ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table( 'baselines' ) ) );
		}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$settings['mode'] = 'normal';
			update_option( 'apg_settings', $settings, false );
			update_option( 'apg_deactivation_report', $report, false );
			delete_option( 'apg_deactivating' );
			Support::purge( array() );
	}

	public static function restore(): array {
		global $wpdb;
		$t = self::table( 'baselines' );
		$restored = 0;
		$preserved = 0;
		$last = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE product_id>%d ORDER BY product_id LIMIT 100', $t, $last ), ARRAY_A );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $rows as $r ) {
				$id = (int) $r['product_id'];
				$last = $id;
				$key = 'product:' . $id;
				$token = Support::lock( $key );
				if ( ! $token ) {
					continue;
				}
				try {
					$p = Support::fresh( $id );
					if ( $p ) {
						$now = Support::snapshot( $p );
						$original = Support::decode( $r['original'] );
						$expected = Support::decode( $r['expected'] );
						$restore = array();
						foreach ( $expected as $k => $v ) {
							if ( ( $now[ $k ] ?? null ) === $v ) {
								$restore[ $k ] = $original[ $k ];
							} else {
								++$preserved;
							}
						}
						try {
							Operations::validate_prices( array_merge( $now, $restore ) );
						} catch ( \Throwable $e ) {
							foreach ( array( 'regular_price', 'sale_price' ) as $k ) {
								if ( isset( $restore[ $k ] ) ) {
									unset( $restore[ $k ] );
									++$preserved;
								}
							}
						}
						$was_writing = Operations::$writing;
						Operations::$writing = true;
						try {
							foreach ( $restore as $k => $v ) {
								Support::set( $p, $k, $v );
							}
							$p->save();
							$restored += count( $restore );
						} finally {
							Operations::$writing = $was_writing;
						}
						Support::purge( array( $id ), false );
					}
					$wpdb->delete( $t, array( 'product_id' => $id ), array( '%d' ) );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				} catch ( \Throwable $e ) {
					self::audit( 'restore_failed', $id, array( 'message' => $e->getMessage() ) );
				} finally {
					Support::unlock( $key, $token );
				}
			}
		} while ( count( $rows ) === 100 );
		return array(
			'restored'  => $restored,
			'preserved' => $preserved,
			'remaining' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $t ) ),
			'created'   => time(),
		);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function audit( string $event, int $id, array $details = array() ): void {
		global $wpdb;
		$wpdb->insert(
			self::table( 'audit' ),
			array(
				'actor'     => get_current_user_id(),
				'created'   => time(),
				'event'     => $event,
				'object_id' => $id,
				'details'   => Support::json( $details ),
			)
		);
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		do_action( 'andiya_pg_event', $event, $id, $details );
	}

	public static function get( string $table, int $id ): array {
		global $wpdb;
		$t = self::table( $table );
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $t, $id ), ARRAY_A ) ?: array();
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
