<?php
/**
 * Winkelwagen en bestelling: keuzes controleren, prijs aan de serverkant berekenen,
 * logo-uploads opslaan en alles bij de bestelregel bewaren.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Cart {
	const MAX_LOGO = 20971520; // 20 MB
	const LOGO_EXT = array( 'pdf', 'eps', 'ai', 'svg', 'png', 'jpg', 'jpeg' );
	const FONTS    = array(
		'poppins'      => 'Poppins',
		'montserrat'   => 'Montserrat',
		'opensans'     => 'Open Sans',
		'oswald'       => 'Oswald',
		'anton'        => 'Anton',
		'bebasneue'    => 'Bebas Neue',
		'robotoslab'   => 'Roboto Slab',
		'playfair'     => 'Playfair Display',
		'courierprime' => 'Courier Prime',
		'pacifico'     => 'Pacifico',
		'lobster'      => 'Lobster',
		'dancing'      => 'Dancing Script',
		'marker'       => 'Permanent Marker',
	);

	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'item_data' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_prices' ), 9999 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'show' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_meta' ), 10, 4 );
		add_action( 'wp_ajax_mdo_logo', array( __CLASS__, 'upload' ) );
		add_action( 'wp_ajax_nopriv_mdo_logo', array( __CLASS__, 'upload' ) );
	}

	/** Leest en controleert de keuzes uit het formulier tegen de printdata van het product. */
	private static function parse( $product_id, $variation_id, $raw ) {
		$product = wc_get_product( $product_id );
		if ( ! MDO_Frontend::configurable( $product ) ) {
			return new WP_Error( 'mdo', 'Dit product kan niet samengesteld worden.' );
		}
		$cfg   = MDO_Frontend::config( $product );
		$color = null;
		foreach ( $cfg['colors'] as $c ) {
			if ( (int) $c['id'] === (int) $variation_id ) {
				$color = $c;
			}
		}
		if ( ! $color ) {
			return new WP_Error( 'mdo', 'Kies een kleur.' );
		}
		$data = json_decode( (string) $raw, true );
		$sels = array();
		foreach ( (array) ( $data['prints'] ?? array() ) as $p ) {
			$pos = null;
			foreach ( $cfg['faces'] as $f ) {
				foreach ( $f['positions'] as $cand ) {
					if ( $cand['id'] === ( $p['position'] ?? '' ) ) {
						$pos  = $cand;
						$face = $f;
					}
				}
			}
			if ( ! $pos ) {
				return new WP_Error( 'mdo', 'Onbekende drukpositie.' );
			}
			$tech = null;
			foreach ( $pos['techs'] as $t ) {
				if ( $t['id'] === ( $p['tech'] ?? '' ) ) {
					$tech = $t;
				}
			}
			if ( ! $tech || empty( $cfg['techniques'][ $tech['id'] ] ) ) {
				return new WP_Error( 'mdo', 'Deze techniek kan niet op deze positie.' );
			}
			$mode    = ( 'text' === ( $p['mode'] ?? '' ) ) ? 'text' : 'logo';
			$colours = ( 'text' === $mode ) ? 1 : max( 1, min( 8, (int) ( $p['colours'] ?? 1 ) ) );
			if ( $tech['max'] > 0 && $colours > $tech['max'] ) {
				return new WP_Error( 'mdo', 'Te veel kleuren voor deze techniek.' );
			}
			$w = max( 1, min( (float) $pos['w'], (float) ( $p['w'] ?? 0 ) ) );
			$h = max( 1, min( (float) $pos['h'], (float) ( $p['h'] ?? 0 ) ) );

			$logo = '';
			if ( 'logo' === $mode && ! empty( $p['logo'] ) && preg_match( '#^\d{6}/[a-f0-9]{32}\.[a-z]{2,4}$#', $p['logo'] ) ) {
				$logo = file_exists( MDO_Settings::upload_dir()['base'] . 'logos/' . $p['logo'] ) ? $p['logo'] : '';
			}
			$sels[] = array(
				'face'     => $face['label'],
				'position' => $pos['id'],
				'tech'     => $tech['id'],
				'techname' => $cfg['techniques'][ $tech['id'] ]['name'],
				'colours'  => $colours,
				'full'     => 0 === (int) $tech['max'],
				'w'        => round( $w, 1 ),
				'h'        => round( $h, 1 ),
				'mode'     => $mode,
				'rot'      => in_array( (int) ( $p['rot'] ?? 0 ), array( 0, 90, 180, 270 ), true ) ? (int) $p['rot'] : 0,
				'logo'     => $logo,
				'logoname' => $logo ? sanitize_file_name( (string) ( $p['logoname'] ?? '' ) ) : '',
				'text'     => 'text' === $mode ? mb_substr( sanitize_text_field( (string) ( $p['text'] ?? '' ) ), 0, 60 ) : '',
				'font'     => isset( self::FONTS[ $p['font'] ?? '' ] ) ? $p['font'] : 'poppins',
				'ink'      => preg_match( '/^#[0-9a-fA-F]{6}$/', (string) ( $p['ink'] ?? '' ) ) ? strtoupper( $p['ink'] ) : '',
				'pms'      => mb_substr( preg_replace( '/[^\p{L}\p{N} ,.\-\/]/u', '', (string) ( $p['pms'] ?? '' ) ), 0, 60 ),
			);
		}
		return array( 'cfg' => $cfg, 'color' => $color, 'prints' => $sels, 'note' => mb_substr( sanitize_textarea_field( (string) ( $data['note'] ?? '' ) ), 0, 500 ) );
	}

	public static function validate( $ok, $product_id, $qty ) {
		if ( ! isset( $_POST['mdo_config'] ) ) {
			return $ok;
		}
		if ( ! isset( $_POST['mdo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mdo_nonce'] ) ), 'mdo_add' ) ) {
			wc_add_notice( 'Je sessie is verlopen. Ververs de pagina en probeer het opnieuw.', 'error' );
			return false;
		}
		$r = self::parse( $product_id, absint( $_POST['variation_id'] ?? 0 ), wp_unslash( $_POST['mdo_config'] ) );
		if ( is_wp_error( $r ) ) {
			wc_add_notice( $r->get_error_message(), 'error' );
			return false;
		}
		return $ok;
	}

	public static function item_data( $data, $product_id, $variation_id ) {
		if ( ! isset( $_POST['mdo_config'] ) ) {
			return $data;
		}
		$r = self::parse( $product_id, $variation_id, wp_unslash( $_POST['mdo_config'] ) );
		if ( is_wp_error( $r ) ) {
			return $data;
		}
		$data['mdo'] = array( 'prints' => $r['prints'], 'note' => $r['note'] );
		$data['mdo_key'] = md5( wp_json_encode( $data['mdo'] ) . microtime() );
		return $data;
	}

	public static function set_prices( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		$cfg_cache = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( empty( $item['mdo'] ) ) {
				continue;
			}
			$pid = $item['product_id'];
			if ( ! isset( $cfg_cache[ $pid ] ) ) {
				$p                 = wc_get_product( $pid );
				$cfg_cache[ $pid ] = MDO_Frontend::configurable( $p ) ? MDO_Frontend::config( $p ) : null;
			}
			$cfg = $cfg_cache[ $pid ];
			if ( ! $cfg ) {
				continue;
			}
			$color = null;
			foreach ( $cfg['colors'] as $c ) {
				if ( (int) $c['id'] === (int) $item['variation_id'] ) {
					$color = $c;
				}
			}
			if ( ! $color ) {
				continue;
			}
			$q = MDO_Pricing::quote( $cfg, $color, $item['quantity'], $item['mdo']['prints'] );
			if ( is_wp_error( $q ) ) {
				continue;
			}
			// Instelkosten zitten verdeeld in de stukprijs, zodat ze meeschalen met het aantal.
			$item['data']->set_price( round( $q['per_pc'], 4 ) );
			$cart->cart_contents[ $key ]['mdo_quote'] = array( 'setup' => $q['setup'], 'unit' => $q['unit'] );
		}
	}

	private static function lines( array $mdo ) {
		$out = array();
		foreach ( $mdo['prints'] as $p ) {
			$font = isset( self::FONTS[ $p['font'] ] ) ? self::FONTS[ $p['font'] ] : $p['font'];
			$pms  = ! empty( $p['pms'] ) ? 'PMS ' . $p['pms'] : '';
			if ( 'text' === $p['mode'] ) {
				$kleur = trim( ( $p['ink'] ? 'kleur ' . $p['ink'] : '' ) . ( $pms ? ( $p['ink'] ? ', ' : '' ) . $pms : '' ) );
				$what  = '“' . $p['text'] . '” (' . $font . ( $kleur ? ', ' . $kleur : '' ) . ')';
			} else {
				$what = ( $p['logo'] ? 'logo: ' . ( $p['logoname'] ? $p['logoname'] : 'bestand' ) : 'logo volgt later' ) . ( $pms ? ' (' . $pms . ')' : '' );
			}
			$cols  = $p['full'] ? 'full colour' : ( $p['colours'] . ( 1 === $p['colours'] ? ' kleur' : ' kleuren' ) );
			$out[] = array(
				'key'   => $p['face'],
				'value' => $p['techname'] . ', ' . $cols . ', ' . $p['w'] . ' × ' . $p['h'] . ' mm' . ( ! empty( $p['rot'] ) ? ', ' . $p['rot'] . '° gedraaid' : '' ) . ', ' . $what,
			);
		}
		if ( ! $mdo['prints'] ) {
			$out[] = array( 'key' => 'Bedrukking', 'value' => 'Blanco' );
		}
		if ( ! empty( $mdo['note'] ) ) {
			$out[] = array( 'key' => 'Opmerking', 'value' => $mdo['note'] );
		}
		return $out;
	}

	public static function show( $data, $item ) {
		if ( empty( $item['mdo'] ) ) {
			return $data;
		}
		foreach ( self::lines( $item['mdo'] ) as $l ) {
			$data[] = array( 'key' => $l['key'], 'value' => esc_html( $l['value'] ) );
		}
		if ( ! empty( $item['mdo_quote']['setup'] ) ) {
			$data[] = array( 'key' => 'Instelkosten', 'value' => wp_strip_all_tags( wc_price( $item['mdo_quote']['setup'] ) ) . ' (verwerkt in de stukprijs)' );
		}
		return $data;
	}

	public static function order_meta( $item, $cart_key, $values, $order ) {
		if ( empty( $values['mdo'] ) ) {
			return;
		}
		foreach ( self::lines( $values['mdo'] ) as $l ) {
			$item->add_meta_data( $l['key'], $l['value'] );
		}
		if ( ! empty( $values['mdo_quote']['setup'] ) ) {
			$item->add_meta_data( 'Instelkosten', wp_strip_all_tags( wc_price( $values['mdo_quote']['setup'] ) ) );
		}
		$base = MDO_Settings::upload_dir()['url'] . 'logos/';
		foreach ( $values['mdo']['prints'] as $p ) {
			if ( $p['logo'] ) {
				$item->add_meta_data( '_mdo_logo_' . sanitize_key( $p['position'] ), $base . $p['logo'] );
			}
		}
		$item->add_meta_data( '_mdo_config', $values['mdo'] );
	}

	/** Logo-upload vanuit de configurator. Bestanden krijgen een willekeurige naam. */
	public static function upload() {
		check_ajax_referer( 'mdo_logo', 'nonce' );
		if ( empty( $_FILES['file'] ) || ! empty( $_FILES['file']['error'] ) ) {
			wp_send_json_error( 'Upload mislukt.' );
		}
		$f    = $_FILES['file'];
		$name = sanitize_file_name( wp_unslash( $f['name'] ) );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::LOGO_EXT, true ) ) {
			wp_send_json_error( 'Dit bestandstype kan niet. Gebruik PDF, EPS, AI, SVG, PNG of JPG.' );
		}
		if ( (int) $f['size'] > self::MAX_LOGO ) {
			wp_send_json_error( 'Bestand is groter dan 20 MB.' );
		}
		$sub = gmdate( 'Ym' );
		$dir = MDO_Settings::upload_dir()['base'] . 'logos/' . $sub . '/';
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . 'index.html' ) ) {
			file_put_contents( $dir . 'index.html', '' );
		}
		$token = $sub . '/' . bin2hex( random_bytes( 16 ) ) . '.' . $ext;
		if ( ! move_uploaded_file( $f['tmp_name'], MDO_Settings::upload_dir()['base'] . 'logos/' . $token ) ) {
			wp_send_json_error( 'Upload mislukt.' );
		}
		$preview = in_array( $ext, array( 'png', 'jpg', 'jpeg' ), true ) ? MDO_Settings::upload_dir()['url'] . 'logos/' . $token : '';
		wp_send_json_success( array( 'token' => $token, 'name' => $name, 'preview' => $preview ) );
	}
}
