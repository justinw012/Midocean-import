<?php
/**
 * Synchronisatie in kleine stappen via Action Scheduler (zit in WooCommerce).
 * Elke stap duurt maximaal ~20 seconden en plant daarna zichzelf opnieuw in.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Sync {
	const GROUP  = 'midocean-import';
	const STATE  = 'mdo_sync_state';
	const BUDGET = 20;

	public static function all_hooks() {
		return array( 'mdo_step', 'mdo_import_product', 'mdo_import_images', 'mdo_daily_sync', 'mdo_stock_sync', 'mdo_stock_step' );
	}

	public static function init() {
		add_action( 'mdo_step', array( __CLASS__, 'step' ) );
		add_action( 'mdo_import_product', array( 'MDO_Importer', 'import_product' ) );
		add_action( 'mdo_import_images', array( 'MDO_Importer', 'import_images' ) );
		add_action( 'mdo_daily_sync', array( __CLASS__, 'start_full' ) );
		add_action( 'mdo_stock_sync', array( __CLASS__, 'start_stock' ) );
		add_action( 'mdo_stock_step', array( __CLASS__, 'stock_step' ) );
		add_action( 'init', array( __CLASS__, 'schedule_recurring' ) );
	}

	public static function schedule_recurring() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! MDO_Settings::get( 'api_key' ) || ! MDO_Settings::get( 'categories' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( 'mdo_daily_sync', array(), self::GROUP ) ) {
			$tomorrow_3am = strtotime( 'tomorrow 03:00', current_time( 'timestamp' ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
			as_schedule_recurring_action( $tomorrow_3am, DAY_IN_SECONDS, 'mdo_daily_sync', array(), self::GROUP );
		}
		if ( ! as_has_scheduled_action( 'mdo_stock_sync', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, 'mdo_stock_sync', array(), self::GROUP );
		}
	}

	public static function state() {
		$s = get_option( self::STATE, array() );
		return is_array( $s ) ? $s : array();
	}

	private static function save( array $s ) {
		$s['updated'] = current_time( 'mysql' );
		update_option( self::STATE, $s, false );
	}

	private static function next_step() {
		as_enqueue_async_action( 'mdo_step', array(), self::GROUP );
	}

	/** Alleen categorieën inventariseren (eerste keer, of om de lijst te vernieuwen). */
	public static function start_categories() {
		self::save( array( 'mode' => 'categories', 'stage' => 'download_products', 'running' => 1 ) );
		MDO_Log::info( 'Categorieën ophalen gestart.' );
		self::next_step();
	}

	public static function start_full() {
		$cur = self::state();
		if ( ! empty( $cur['running'] ) && ! empty( $cur['updated'] ) && strtotime( $cur['updated'] ) > time() - HOUR_IN_SECONDS ) {
			MDO_Log::info( 'Synchronisatie loopt al; nieuwe start overgeslagen.' );
			return;
		}
		if ( ! MDO_Settings::get( 'categories' ) ) {
			MDO_Log::error( 'Geen categorieën gekozen; synchronisatie niet gestart.' );
			return;
		}
		self::save( array( 'mode' => 'full', 'stage' => 'download_products', 'running' => 1 ) );
		MDO_Log::info( 'Volledige synchronisatie gestart.' );
		self::next_step();
	}

	public static function stop() {
		as_unschedule_all_actions( 'mdo_step', array(), self::GROUP );
		as_unschedule_all_actions( 'mdo_import_product', array(), self::GROUP );
		as_unschedule_all_actions( 'mdo_import_images', array(), self::GROUP );
		$s            = self::state();
		$s['running'] = 0;
		$s['stage']   = 'gestopt';
		self::save( $s );
		MDO_Log::info( 'Synchronisatie gestopt.' );
	}

	private static function fail( array $s, $msg ) {
		$s['running'] = 0;
		$s['stage']   = 'fout';
		$s['error']   = $msg;
		self::save( $s );
		MDO_Log::error( $msg );
	}

	private static function selected( $path ) {
		foreach ( (array) MDO_Settings::get( 'categories' ) as $cat ) {
			if ( $path === $cat || 0 === strpos( $path, $cat . ' > ' ) ) {
				return true;
			}
		}
		return false;
	}

	public static function step() {
		$s = self::state();
		if ( empty( $s['running'] ) ) {
			return;
		}
		@set_time_limit( 300 );
		$deadline = time() + self::BUDGET;
		$dir      = MDO_Settings::cache_dir();

		switch ( $s['stage'] ) {

			case 'download_products':
				$f = MDO_API::download( 'products' );
				if ( is_wp_error( $f ) ) {
					return self::fail( $s, $f->get_error_message() );
				}
				$start = MDO_Json_Stream::find_array_start( $f );
				if ( false === $start ) {
					$start = MDO_Json_Stream::find_array_start( $f, 'products' );
				}
				if ( false === $start ) {
					return self::fail( $s, 'Productfeed heeft een onverwachte opbouw.' );
				}
				$s['offset']  = $start;
				$s['stage']   = 'scan_products';
				$s['cats']    = array();
				$s['masters'] = array();
				$s['skus']    = array();
				wp_mkdir_p( $dir . 'products/' );
				break;

			case 'scan_products':
				$mode = $s['mode'];
				$r    = MDO_Json_Stream::each( $dir . 'products.json', $s['offset'], $deadline, function ( $raw ) use ( &$s, $mode, $dir ) {
					$p = MDO_Normalize::product( $raw );
					if ( ! $p['master_code'] || ! $p['variants'] ) {
						return;
					}
					$path = $p['variants'][0]['category'];
					if ( 'categories' === $mode ) {
						$s['cats'][ $path ] = isset( $s['cats'][ $path ] ) ? $s['cats'][ $path ] + 1 : 1;
						return;
					}
					if ( ! self::selected( $path ) ) {
						return;
					}
					file_put_contents( $dir . 'products/' . sanitize_file_name( $p['master_code'] ) . '.json', wp_json_encode( $p ) );
					$s['masters'][] = $p['master_code'];
					foreach ( $p['variants'] as $v ) {
						$s['skus'][ $v['sku'] ] = 1;
					}
				} );
				$s['offset'] = $r['offset'];
				if ( $r['done'] ) {
					if ( 'categories' === $mode ) {
						ksort( $s['cats'] );
						update_option( 'mdo_categories', $s['cats'], false );
						MDO_Log::info( count( $s['cats'] ) . ' categorieën gevonden.' );
						$s['running'] = 0;
						$s['stage']   = 'klaar';
						unset( $s['cats'] );
						self::save( $s );
						return;
					}
					MDO_Log::info( count( $s['masters'] ) . ' producten in de gekozen categorieën.' );
					$s['stage'] = 'download_printdata';
				}
				break;

			case 'download_printdata':
				$f = MDO_API::download( 'printdata' );
				if ( is_wp_error( $f ) ) {
					return self::fail( $s, $f->get_error_message() );
				}
				$start = MDO_Json_Stream::find_array_start( $f, 'products' );
				if ( false === $start ) {
					return self::fail( $s, 'Printdata heeft een onverwachte opbouw.' );
				}
				// Technieknamen staan vooraan in hetzelfde bestand.
				$names = array();
				$t0    = MDO_Json_Stream::find_array_start( $f, 'printing_technique_descriptions' );
				if ( false !== $t0 ) {
					$lang = MDO_Settings::get( 'language' );
					MDO_Json_Stream::each( $f, $t0, time() + 60, function ( $d ) use ( &$names, $lang ) {
						if ( ! empty( $d['id'] ) ) {
							$names[ (string) $d['id'] ] = MDO_Normalize::translated( isset( $d['name'] ) ? $d['name'] : '', $lang );
						}
					} );
				}
				update_option( 'mdo_technique_names', $names, false );
				wp_mkdir_p( $dir . 'print/' );
				$s['offset'] = $start;
				$s['stage']  = 'scan_printdata';
				break;

			case 'scan_printdata':
				$wanted = array_flip( $s['masters'] );
				$r      = MDO_Json_Stream::each( $dir . 'printdata.json', $s['offset'], $deadline, function ( $raw ) use ( $wanted, $dir ) {
					$p = MDO_Normalize::printproduct( $raw );
					if ( isset( $wanted[ $p['master_code'] ] ) ) {
						file_put_contents( $dir . 'print/' . sanitize_file_name( $p['master_code'] ) . '.json', wp_json_encode( $p ) );
					}
				} );
				$s['offset'] = $r['offset'];
				if ( $r['done'] ) {
					$s['stage'] = 'printprices';
				}
				break;

			case 'printprices':
				$f = MDO_API::download( 'printpricelist' );
				if ( is_wp_error( $f ) ) {
					return self::fail( $s, $f->get_error_message() );
				}
				$data = json_decode( file_get_contents( $f ), true );
				if ( ! is_array( $data ) ) {
					return self::fail( $s, 'Printprijslijst kon niet gelezen worden.' );
				}
				$pp = MDO_Normalize::printpricelist( $data );
				update_option( 'mdo_printprices', $pp, false );
				MDO_Log::info( count( $pp['techniques'] ) . ' technieken en ' . count( $pp['manipulations'] ) . ' handlingcodes ingelezen.' );
				$s['stage'] = 'download_prices';
				break;

			case 'download_prices':
				$f = MDO_API::download( 'pricelist' );
				if ( is_wp_error( $f ) ) {
					return self::fail( $s, $f->get_error_message() );
				}
				$start = MDO_Json_Stream::find_array_start( $f, 'price' );
				if ( false === $start ) {
					$start = MDO_Json_Stream::find_array_start( $f );
				}
				if ( false === $start ) {
					return self::fail( $s, 'Prijslijst heeft een onverwachte opbouw.' );
				}
				$s['offset'] = $start;
				$s['prices'] = array();
				$s['stage']  = 'scan_prices';
				break;

			case 'scan_prices':
				$r = MDO_Json_Stream::each( $dir . 'pricelist.json', $s['offset'], $deadline, function ( $raw ) use ( &$s ) {
					list( $sku, $scales ) = MDO_Normalize::price( $raw );
					if ( isset( $s['skus'][ $sku ] ) ) {
						$s['prices'][ $sku ] = $scales;
					}
				} );
				$s['offset'] = $r['offset'];
				if ( $r['done'] ) {
					file_put_contents( $dir . 'prices.json', wp_json_encode( $s['prices'] ) );
					unset( $s['prices'] );
					$s['stage'] = 'download_stock';
				}
				break;

			case 'download_stock':
				$f = MDO_API::download( 'stock' );
				if ( is_wp_error( $f ) ) {
					return self::fail( $s, $f->get_error_message() );
				}
				$start = MDO_Json_Stream::find_array_start( $f, 'stock' );
				if ( false === $start ) {
					$start = MDO_Json_Stream::find_array_start( $f );
				}
				if ( false === $start ) {
					return self::fail( $s, 'Voorraadfeed heeft een onverwachte opbouw.' );
				}
				$s['offset'] = $start;
				$s['stock']  = array();
				$s['stage']  = 'scan_stock';
				break;

			case 'scan_stock':
				$r = MDO_Json_Stream::each( $dir . 'stock.json', $s['offset'], $deadline, function ( $raw ) use ( &$s ) {
					list( $sku, $qty ) = MDO_Normalize::stock( $raw );
					if ( isset( $s['skus'][ $sku ] ) ) {
						$s['stock'][ $sku ] = $qty;
					}
				} );
				$s['offset'] = $r['offset'];
				if ( $r['done'] ) {
					file_put_contents( $dir . 'stock.json', wp_json_encode( $s['stock'] ) );
					unset( $s['stock'] );
					$s['stage'] = 'queue';
				}
				break;

			case 'queue':
				foreach ( $s['masters'] as $m ) {
					as_enqueue_async_action( 'mdo_import_product', array( $m ), self::GROUP );
				}
				MDO_Log::info( count( $s['masters'] ) . ' producten ingepland voor import.' );
				$s['queued']  = count( $s['masters'] );
				$s['running'] = 0;
				$s['stage']   = 'producten importeren';
				unset( $s['skus'] );
				self::save( $s );
				return;

			default:
				return;
		}

		self::save( $s );
		self::next_step();
	}

	/** Voorraad elk uur: alleen de voorraadfeed. */
	public static function start_stock() {
		$f = MDO_API::download( 'stock' );
		if ( is_wp_error( $f ) ) {
			MDO_Log::error( $f->get_error_message() );
			return;
		}
		$start = MDO_Json_Stream::find_array_start( $f, 'stock' );
		if ( false === $start ) {
			$start = MDO_Json_Stream::find_array_start( $f );
		}
		if ( false !== $start ) {
			update_option( 'mdo_stock_offset', array( 'offset' => $start, 'updated' => 0 ), false );
			as_enqueue_async_action( 'mdo_stock_step', array(), self::GROUP );
		}
	}

	public static function stock_step() {
		$st = get_option( 'mdo_stock_offset' );
		if ( ! is_array( $st ) ) {
			return;
		}
		$updated = (int) $st['updated'];
		$r       = MDO_Json_Stream::each( MDO_Settings::cache_dir() . 'stock.json', $st['offset'], time() + self::BUDGET, function ( $raw ) use ( &$updated ) {
			list( $sku, $qty ) = MDO_Normalize::stock( $raw );
			$id = $sku ? wc_get_product_id_by_sku( $sku ) : 0;
			if ( $id && get_post_meta( wp_get_post_parent_id( $id ) ?: $id, '_mdo_master_code', true ) ) {
				$p = wc_get_product( $id );
				if ( $p && (int) $p->get_stock_quantity() !== $qty ) {
					wc_update_product_stock( $p, $qty );
					$updated++;
				}
			}
		} );
		if ( $r['done'] ) {
			delete_option( 'mdo_stock_offset' );
			MDO_Log::info( 'Voorraad bijgewerkt: ' . $updated . ' varianten gewijzigd.' );
			return;
		}
		update_option( 'mdo_stock_offset', array( 'offset' => $r['offset'], 'updated' => $updated ), false );
		as_enqueue_async_action( 'mdo_stock_step', array(), self::GROUP );
	}
}
