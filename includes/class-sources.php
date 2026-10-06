<?php
namespace Andiya\PriceGuard;
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plugin-owned tables use fresh reads for locks, version checks and private offers; caching would defeat concurrency guards.
final class Sources {

	public static function register(): void {
		add_action( 'apg_sources_tick', array( self::class, 'tick' ) );
	}

	public static function public_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! $parts || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return false;
		}
		$host = trim( $parts['host'], '[]' );
		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
		return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) && (bool) wp_http_validate_url( $url );
	}

	public static function csv( string $path ): array {
		if ( filesize( $path ) > 5 * MB_IN_BYTES ) {
			throw new \InvalidArgumentException( esc_html__( 'حداکثر حجم فایل پنج مگابایت است.', 'andiya-price-guard' ) );
		}
		$f = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read-only CSV parsing of a validated upload; fgetcsv requires a stream.
		if ( ! $f ) {
			throw new \RuntimeException( esc_html__( 'فایل خوانده نشد.', 'andiya-price-guard' ) );
		}
		try {
			$first = fgets( $f );
			rewind( $f );
			$delims = array(
				','  => substr_count( $first, ',' ),
				';'  => substr_count( $first, ';' ),
				"\t" => substr_count( $first, "\t" ),
			);
			arsort( $delims );
			$delimiter = array_key_first( $delims );
			$rows = array();
			while ( false !== ( $row = fgetcsv( $f, 0, $delimiter ) ) ) {
				if ( count( $rows ) >= 50001 || count( $row ) > 100 ) {
					throw new \InvalidArgumentException( esc_html__( 'فایل بیش از حد ردیف یا ستون دارد.', 'andiya-price-guard' ) );
				}
				if ( $row === array( null ) ) {
					continue;
				}
				$rows[] = array_map(
					static function ( $v ) {
						return preg_replace( '/^\xEF\xBB\xBF/', '', (string) $v );
					},
					$row
				);
			}
			return $rows;
		} finally {
			fclose( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the read-only CSV parser stream.
		}
	}

	public static function xml( string $xml ): \DOMDocument {
		if ( stripos( $xml, '<!DOCTYPE' ) !== false || strlen( $xml ) > 30 * MB_IN_BYTES ) {
			throw new \InvalidArgumentException( esc_html__( 'ساختار فایل اکسل مجاز نیست.', 'andiya-price-guard' ) );
		}
		$doc = new \DOMDocument();
		$prior = libxml_use_internal_errors( true );
		try {
			if ( ! $doc->loadXML( $xml, LIBXML_NONET | LIBXML_NOBLANKS ) ) {
				throw new \InvalidArgumentException( esc_html__( 'فایل اکسل خراب است.', 'andiya-price-guard' ) );
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $prior );
		}
		return $doc;
	}

	public static function xlsx( string $path ): array {
		if ( ! class_exists( 'ZipArchive' ) || ! class_exists( 'DOMDocument' ) ) {
			throw new \RuntimeException( esc_html__( 'خواندن XLSX به افزونه‌های ZIP و DOM در PHP نیاز دارد. از CSV استفاده کنید.', 'andiya-price-guard' ) );
		}
		if ( filesize( $path ) > 5 * MB_IN_BYTES ) {
			throw new \InvalidArgumentException( esc_html__( 'حداکثر حجم فایل پنج مگابایت است.', 'andiya-price-guard' ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			throw new \InvalidArgumentException( esc_html__( 'فایل اکسل باز نشد.', 'andiya-price-guard' ) );
		}
		try {
			$total = 0;
			if ( $zip->numFiles > 200 ) {
				throw new \InvalidArgumentException( esc_html__( 'فایل اکسل بیش از حد پیچیده است.', 'andiya-price-guard' ) );
			}
			for ( $i = 0; $i < $zip->numFiles; ++$i ) {
				$s = $zip->statIndex( $i );
				$total += $s['size'];
				if ( $total > 30 * MB_IN_BYTES || ( $s['size'] > 100000 && $s['size'] / max( 1, $s['comp_size'] ) > 200 ) || str_contains( $s['name'], '..' ) || stripos( $s['name'], 'vbaProject' ) !== false || stripos( $s['name'], 'externalLinks' ) !== false ) {
					throw new \InvalidArgumentException( esc_html__( 'فایل دارای ماکرو، لینک خارجی یا فشردگی غیرمجاز است.', 'andiya-price-guard' ) );
				}
			}
			$strings = array();
			$raw = $zip->getFromName( 'xl/sharedStrings.xml' );
			if ( false !== $raw ) {
				$doc = self::xml( $raw );
				foreach ( $doc->getElementsByTagName( 'si' ) as $si ) {
					$strings[] = $si->textContent;
				}
			}
			$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
			if ( false === $sheet ) {
				throw new \InvalidArgumentException( esc_html__( 'برگه اول فایل اکسل پیدا نشد.', 'andiya-price-guard' ) );
			}
			$doc = self::xml( $sheet );
			if ( $doc->getElementsByTagName( 'f' )->length ) {
				throw new \InvalidArgumentException( esc_html__( 'فرمول اکسل مجاز نیست. مقدار نهایی را به صورت متن یا عدد ذخیره کنید.', 'andiya-price-guard' ) );
			}
			$rows = array();
			foreach ( $doc->getElementsByTagName( 'row' ) as $row ) {
				if ( count( $rows ) >= 50001 ) {
					throw new \InvalidArgumentException( esc_html__( 'تعداد ردیف‌های اکسل بیش از حد مجاز است.', 'andiya-price-guard' ) );
				}
				$cells = array();
				foreach ( $row->getElementsByTagName( 'c' ) as $cell ) {
					preg_match( '/^([A-Z]+)/', $cell->getAttribute( 'r' ), $match );
					$col = 0;
					foreach ( str_split( $match[1] ?? 'A' ) as $letter ) {
						$col = $col * 26 + ord( $letter ) - 64;
					}
					if ( $col > 100 ) {
						throw new \InvalidArgumentException( esc_html__( 'تعداد ستون‌های فایل زیاد است.', 'andiya-price-guard' ) );
					}
					$type = $cell->getAttribute( 't' );
					$v = $cell->getElementsByTagName( 'v' )->item( 0 );
					$value = $v ? $v->textContent : '';
					if ( 's' === $type ) {
						$value = $strings[ (int) $value ] ?? '';
					} elseif ( 'inlineStr' === $type ) {
						$is = $cell->getElementsByTagName( 'is' )->item( 0 );
						$value = $is ? $is->textContent : '';
					} elseif ( 'e' === $type ) {
						throw new \InvalidArgumentException( esc_html__( 'فایل اکسل دارای خطای سلول است.', 'andiya-price-guard' ) );
					}
					$cells[ $col - 1 ] = $value;
				}
				if ( $cells ) {
					$dense = array_fill( 0, max( array_keys( $cells ) ) + 1, '' );
					foreach ( $cells as $k => $v ) {
						$dense[ $k ] = $v;
					}
					$rows[] = $dense;
				}
			}
			return $rows;
		} finally {
			$zip->close();
		}
	}

	public static function file_data( string $path, string $ext, array $map = array() ): array {
		$rows = 'xlsx' === $ext ? self::xlsx( $path ) : self::csv( $path );
		$headers = array_map( 'trim', array_shift( $rows ) ?: array() );
		if ( count( array_unique( $headers ) ) !== count( $headers ) ) {
			throw new \InvalidArgumentException( esc_html__( 'نام ستون تکراری است.', 'andiya-price-guard' ) );
		}
		$sku = $map['sku'] ?? 'sku';
		$regular = $map['regular_price'] ?? 'regular_price';
		$sale = $map['sale_price'] ?? 'sale_price';
		if ( ! in_array( $sku, $headers, true ) || ! in_array( $regular, $headers, true ) ) {
			throw new \InvalidArgumentException( esc_html__( 'ستون SKU و قیمت عادی را درست نگاشت کنید.', 'andiya-price-guard' ) );
		}
		$result = array();
		foreach ( $rows as $i => $row ) {
			$entry = array();
			foreach ( $headers as $j => $h ) {
				$entry[ $h ] = (string) ( $row[ $j ] ?? '' );
			}
			$result[] = array(
				'sku'           => $entry[ $sku ],
				'regular_price' => $entry[ $regular ],
				'sale_price'    => $entry[ $sale ] ?? '',
			);
		}
		return $result;
	}

	public static function data( array $rows, string $currency, string $source = '', bool $automatic = false, float $threshold = 20 ): array {
		if ( count( $rows ) > 50000 || ! $rows ) {
			throw new \InvalidArgumentException( esc_html__( 'فهرست داده خالی یا بیش از حد بزرگ است.', 'andiya-price-guard' ) );
		}
		$result = array();
		$seen = array();
		$errors = array();
		foreach ( $rows as $i => $row ) {
			try {
				$sku = trim( (string) ( $row['sku'] ?? '' ) );
				if ( '' === $sku || isset( $seen[ $sku ] ) ) {
					throw new \InvalidArgumentException( esc_html__( 'SKU خالی یا تکراری است.', 'andiya-price-guard' ) );
				}
				$seen[ $sku ] = true;
				$id = wc_get_product_id_by_sku( $sku );
				$p = $id ? wc_get_product( $id ) : false;
				if ( ! $p || ! $p->is_type( array( 'simple', 'variation' ) ) || ! Support::public_product( $p ) ) {
					throw new \InvalidArgumentException( esc_html__( 'SKU به یک کالای ساده یا تنوع منتشرشده تعلق ندارد.', 'andiya-price-guard' ) );
				}
				if ( $automatic && $source !== (string) $p->get_meta( '_apg_source', true, 'edit' ) ) {
					continue;
				}
				$after = array();
				foreach ( array( 'regular_price', 'sale_price' ) as $key ) {
					if ( isset( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
						$after[ $key ] = Support::convert( $row[ $key ], $currency, get_woocommerce_currency() );
					}
				}
				if ( ! $after ) {
					continue;
				}
				Operations::validate_prices( array_merge( Support::snapshot( $p ), $after ) );
				$before = (float) $p->get_regular_price( 'edit' );
				$quarantine = $automatic && ( ! $before || ( isset( $after['regular_price'] ) && abs( (float) $after['regular_price'] / $before - 1 ) * 100 > $threshold ) );
				if ( $quarantine ) {
					$after = array( '_apg_state' => 'quote' );
				} else {
					$after['_apg_state'] = '';
				}
				if ( $source ) {
					$after['_apg_source'] = $source;
				}
				$result[ $id ] = $after;
			} catch ( \Throwable $e ) {
				if ( count( $errors ) < 20 ) {
					$errors[] = ( $i + 2 ) . ': ' . $e->getMessage();
				}
			}
		}
		if ( $errors ) {
			throw new \InvalidArgumentException( esc_html( implode( "\n", $errors ) ) );
		}
		if ( ! $result ) {
			throw new \InvalidArgumentException( esc_html__( 'هیچ کالای منطبق و دارای قیمت پیدا نشد.', 'andiya-price-guard' ) );
		}
		return $result;
	}

	public static function save( array $data ): array {
		global $wpdb;
		$id = absint( $data['id'] ?? 0 );
		$url = esc_url_raw( $data['url'] ?? '', array( 'https' ) );
		$parts = wp_parse_url( $url );
		if ( empty( $data['consent'] ) || ! self::public_url( $url ) ) {
			throw new \InvalidArgumentException( esc_html__( 'اتصال به منبع را تأیید و یک نشانی عمومی HTTPS بدون رمز یا کلید در URL وارد کنید.', 'andiya-price-guard' ) );
		}
		$currency = sanitize_text_field( $data['currency'] ?? '' );
		if ( ! isset( get_woocommerce_currencies()[ $currency ] ) ) {
			throw new \InvalidArgumentException( esc_html__( 'واحد پول منبع معتبر نیست.', 'andiya-price-guard' ) );
		}
		$row = array(
			'name'      => sanitize_text_field( $data['name'] ?? '' ),
			'url'       => $url,
			'currency'  => $currency,
			'automatic' => empty( $data['automatic'] ) ? 0 : 1,
			'threshold' => Support::number( $data['threshold'] ?? 20, 0, 1000 ),
			'hours'     => Support::integer( $data['hours'] ?? 6, 1, 168 ),
		);
		if ( ! $row['name'] ) {
			throw new \InvalidArgumentException( esc_html__( 'نام منبع لازم است.', 'andiya-price-guard' ) );
		}
		if ( $id ) {
			$wpdb->update( Storage::table( 'sources' ), $row, array( 'id' => $id ) );
		} else {
			$row['message'] = '';
			$wpdb->insert( Storage::table( 'sources' ), $row );
			$id = (int) $wpdb->insert_id;
		}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Storage::audit( 'source_saved', $id );
		return Storage::get( 'sources', $id );
	}

	public static function fetch( int $id, bool $automatic = false ): array {
		global $wpdb;
		$key = 'source:' . $id;
		$token = Support::lock( $key, 120 );
		if ( ! $token ) {
			throw new \RuntimeException( esc_html__( 'این منبع در حال بررسی است.', 'andiya-price-guard' ) );
		}
		try {
			$s = Storage::get( 'sources', $id );
			if ( ! $s || ! Support::running() || ( $automatic && ! $s['automatic'] ) ) {
				throw new \RuntimeException( esc_html__( 'منبع فعال نیست.', 'andiya-price-guard' ) );
			}
			$wpdb->update( Storage::table( 'sources' ), array( 'last_run' => time() ), array( 'id' => $id ) );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! self::public_url( $s['url'] ) ) {
				throw new \RuntimeException( esc_html__( 'نشانی منبع دیگر عمومی و مجاز نیست.', 'andiya-price-guard' ) );
			}
			$headers = array( 'Accept' => 'application/json' );
			$constant = 'APG_SOURCE_TOKEN_' . $id;
			if ( defined( $constant ) ) {
				$headers['Authorization'] = 'Bearer ' . constant( $constant );
			}
			$response = wp_safe_remote_get(
				$s['url'],
				array(
					'headers'             => $headers,
					'timeout'             => 20,
					'redirection'         => 0,
					'limit_response_size' => 5 * MB_IN_BYTES,
					'sslverify'           => true,
				)
			);
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				throw new \RuntimeException( esc_html__( 'دریافت معتبر از منبع انجام نشد؛ قیمت و موجودی قبلی حفظ شدند.', 'andiya-price-guard' ) );
			}
			$data = Support::decode( wp_remote_retrieve_body( $response ) );
			$updated = strtotime( $data['updated_at'] ?? '' );
			if ( ! $updated || $updated > time() + 300 || $updated < time() - 48 * HOUR_IN_SECONDS || ( $data['currency'] ?? '' ) !== $s['currency'] || ! is_array( $data['products'] ?? null ) ) {
				throw new \InvalidArgumentException( esc_html__( 'قالب، واحد پول یا زمان داده منبع قابل قبول نیست.', 'andiya-price-guard' ) );
			}
			$rows = self::data( $data['products'], $s['currency'], 'source:' . $id, $automatic, (float) $s['threshold'] );
			$preview = Operations::preview(
				array(
					'kind'  => 'import',
					'hours' => (int) $s['hours'],
					'sale'  => 'both',
				),
				$rows
			);
			if ( $automatic ) {
				$preview = Operations::commit( $preview['id'] );
			}
			$wpdb->update(
				Storage::table( 'sources' ),
				array(
					'last_success' => time(),
					'message'      => '',
				),
				array( 'id' => $id )
			);
			return $preview;
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} catch ( \Throwable $e ) {
			$wpdb->update( Storage::table( 'sources' ), array( 'message' => $e->getMessage() ), array( 'id' => $id ) );
			Storage::audit( 'source_failed', $id );
			throw $e;
		} finally {
			Support::unlock( $key, $token );
		}
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function tick(): void {
		global $wpdb;
		if ( ! Support::running() ) {
			return;
		}
		$t = Storage::table( 'sources' );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE automatic=1 AND last_run + hours*3600 < %d ORDER BY last_run LIMIT 5', $t, time() ), ARRAY_A );
 // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $s ) {
			try {
				self::fetch( (int) $s['id'], true );
			} catch ( \Throwable $e ) {
				/* Failure is recorded; expiry remains enforced on the server. */
			}
		}
	}
}
