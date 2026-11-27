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
		add_filter( 'woocommerce_add_cart_item', array( __CLASS__, 'drop_size_attr' ), 10, 1 );
		add_filter( 'woocommerce_product_backorders_allowed', array( __CLASS__, 'allow_backorder' ), 10, 3 );
		add_filter( 'woocommerce_product_is_in_stock', array( __CLASS__, 'in_stock' ), 10, 2 );
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'stock_notice' ) );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'stock_notice' ), 5 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'stock_order_note' ) );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'drop_size_attr' ), 10, 1 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'fixed_qty' ), 10, 3 );
		add_action( 'wp_ajax_mdo_logo', array( __CLASS__, 'upload' ) );
		add_action( 'wp_ajax_nopriv_mdo_logo', array( __CLASS__, 'upload' ) );
		// Weergave in winkelwagen, afrekenen en bestelling.
		add_filter( 'woocommerce_cart_item_thumbnail', array( __CLASS__, 'thumb' ), 10, 3 );
		add_filter( 'woocommerce_store_api_cart_item_images', array( __CLASS__, 'block_images' ), 10, 3 );
		add_filter( 'woocommerce_admin_order_item_thumbnail', array( __CLASS__, 'order_thumb' ), 10, 3 );
		add_filter( 'woocommerce_order_item_thumbnail', array( __CLASS__, 'mail_thumb' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'item_price' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'item_subtotal' ), 10, 3 );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'replace' ), 10, 1 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'replace_redirect' ), 20 );
		add_filter( 'wc_add_to_cart_message_html', array( __CLASS__, 'replace_message' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets() {
		if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			wp_enqueue_style( 'mdo-cart', MDO_URL . 'assets/cart.css', array(), MDO_VERSION );
			wp_enqueue_script( 'mdo-cart', MDO_URL . 'assets/cart.js', array(), MDO_VERSION, true );
		}
	}

	/** Waar wordt de regel getoond: 'rest' (blok-winkelwagen), 'checkout', 'cart' (klassieke winkelwagen) of 'other' (minicart). */
	private static function context() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'checkout';
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'cart';
		}
		return 'other';
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
				'al'       => in_array( $p['al'] ?? 'c', array( 'l', 'c', 'r' ), true ) ? $p['al'] : 'c',
				'va'       => in_array( $p['va'] ?? 'm', array( 't', 'm', 'b' ), true ) ? $p['va'] : 'm',
				'logo'     => $logo,
				'logoname' => $logo ? sanitize_file_name( (string) ( $p['logoname'] ?? '' ) ) : '',
				'text'     => 'text' === $mode ? mb_substr( sanitize_text_field( (string) ( $p['text'] ?? '' ) ), 0, 60 ) : '',
				'font'     => isset( self::FONTS[ $p['font'] ?? '' ] ) ? $p['font'] : 'poppins',
				'ink'      => preg_match( '/^#[0-9a-fA-F]{6}$/', (string) ( $p['ink'] ?? '' ) ) ? strtoupper( $p['ink'] ) : '',
				'pms'      => mb_substr( preg_replace( '/[^\p{L}\p{N} ,.\-\/]/u', '', (string) ( $p['pms'] ?? '' ) ), 0, 60 ),
			);
		}
		// Kleding: aantallen per maat, alleen maten die bij deze kleur bestaan.
		$sizes = array();
		if ( ! empty( $color['sizes'] ) ) {
			foreach ( $color['sizes'] as $sz ) {
				$q = (int) ( $data['sizes'][ $sz['size'] ] ?? 0 );
				if ( $q > 0 ) {
					$sizes[] = array( 'size' => $sz['size'], 'sku' => $sz['sku'], 'qty' => min( 100000, $q ) );
				}
			}
			if ( ! $sizes ) {
				return new WP_Error( 'mdo', 'Vul bij minstens één maat een aantal in.' );
			}
		}
		return array( 'cfg' => $cfg, 'color' => $color, 'prints' => $sels, 'sizes' => $sizes, 'note' => mb_substr( sanitize_textarea_field( (string) ( $data['note'] ?? '' ) ), 0, 500 ) );
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
		if ( $r['sizes'] ) {
			$data['mdo']['sizes'] = $r['sizes'];
		}
		if ( ! empty( $_POST['mdo_thumb'] ) ) {
			$thumb = self::make_thumb( $r['cfg'], $r['color'], wp_unslash( $_POST['mdo_thumb'] ) ); // phpcs:ignore
			if ( $thumb ) {
				$data['mdo_thumb'] = $thumb;
			}
		}
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
			$sizes = array();
			if ( ! empty( $item['mdo']['sizes'] ) ) {
				$total = 0;
				foreach ( $item['mdo']['sizes'] as $sz ) {
					$sizes[ $sz['size'] ] = (int) $sz['qty'];
					$total               += (int) $sz['qty'];
				}
				// Aantal hoort bij de maten; zet het terug als het ergens anders veranderd is.
				if ( (int) $item['quantity'] !== $total ) {
					$cart->cart_contents[ $key ]['quantity'] = $total;
					$item['quantity']                        = $total;
				}
			}
			$q = MDO_Pricing::quote( $cfg, $color, $item['quantity'], $item['mdo']['prints'], $sizes );
			if ( is_wp_error( $q ) ) {
				continue;
			}
			// Instelkosten zitten verdeeld in de stukprijs, zodat ze meeschalen met het aantal.
			$item['data']->set_price( round( MDO_Frontend::to_store_price( $q['per_pc'], $item['data'] ), 4 ) );
			$quote = array( 'setup' => $q['setup'], 'unit' => $q['unit'] );
			// Volgende staffel: hoeveel goedkoper per stuk (niet bij kleding, daar volgt het aantal uit de maten).
			if ( ! $sizes ) {
				foreach ( (array) $cfg['tiers'] as $t ) {
					if ( (int) $t > (int) $item['quantity'] ) {
						$qn = MDO_Pricing::quote( $cfg, $color, (int) $t, $item['mdo']['prints'], array() );
						if ( ! is_wp_error( $qn ) && $qn['per_pc'] < $q['per_pc'] - 0.005 ) {
							$quote['next'] = array( (int) $t, $qn['per_pc'] );
						}
						break;
					}
				}
			}
			$cart->cart_contents[ $key ]['mdo_quote'] = $quote;
		}
	}

	private static function sizes_text( array $sizes ) {
		return implode( ', ', array_map( function ( $s ) {
			return $s['qty'] . '× ' . $s['size'];
		}, $sizes ) );
	}

	private static function lines( array $mdo ) {
		$out = array();
		if ( ! empty( $mdo['sizes'] ) ) {
			$out[] = array( 'key' => 'Maten', 'value' => self::sizes_text( $mdo['sizes'] ) );
		}
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
				'value' => $p['techname'] . ', ' . $cols . ', ' . $p['w'] . ' × ' . $p['h'] . ' mm' . ( ! empty( $p['rot'] ) ? ', ' . $p['rot'] . '° gedraaid' : '' ) . ( self::align_text( $p ) ? ', ' . self::align_text( $p ) : '' ) . ', ' . $what,
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

	/** Korte samenvatting van de bedrukking: "Voorkant: Laser gravure, Achterkant: Zeefdruk". */
	private static function short( array $mdo ) {
		if ( empty( $mdo['prints'] ) ) {
			return 'Blanco';
		}
		return implode( ', ', array_map( function ( $p ) {
			return $p['face'] . ': ' . $p['techname'];
		}, $mdo['prints'] ) );
	}

	/** Bedrag in de prijsweergave van de winkel (incl./excl. btw zoals ingesteld). */
	private static function money( $excl, $product ) {
		$store = MDO_Frontend::to_store_price( (float) $excl, $product );
		return wc_price( wc_get_price_to_display( $product, array( 'price' => $store, 'qty' => 1 ) ) );
	}

	/**
	 * Gegevens onder de productnaam. Winkelwagen: korte samenvatting met uitklapbare details en "Wijzigen".
	 * Afrekenen en minicart: alleen de korte samenvatting.
	 */
	public static function show( $data, $item ) {
		if ( empty( $item['mdo'] ) ) {
			return $data;
		}
		$ctx = self::context();
		if ( ! empty( $item['mdo']['sizes'] ) ) {
			$data[] = array( 'key' => 'Maten', 'value' => esc_html( self::sizes_text( $item['mdo']['sizes'] ) ) );
		}
		$data[] = array( 'key' => 'Bedrukking', 'value' => esc_html( self::short( $item['mdo'] ) ) );
		if ( self::shortage( $item ) > 0 ) {
			$data[] = array( 'key' => 'Levering', 'value' => 'Nu niet op voorraad – we laten je de leverdatum weten' );
		}
		if ( 'rest' === $ctx && ! empty( $item['mdo_quote']['setup'] ) && ! empty( $item['data'] ) ) {
			$data[] = array( 'key' => 'Instelkosten', 'value' => wp_strip_all_tags( self::money( $item['mdo_quote']['setup'], $item['data'] ) ) . ' (zit in de stukprijs)' );
		}
		if ( 'cart' === $ctx ) {
			$rows = '';
			foreach ( self::lines( $item['mdo'] ) as $l ) {
				if ( 'Maten' === $l['key'] ) {
					continue;
				}
				$rows .= '<li><strong>' . esc_html( $l['key'] ) . ':</strong> ' . esc_html( $l['value'] ) . '</li>';
			}
			$edit = ! empty( $item['key'] ) ? add_query_arg( 'mdo_edit', $item['key'], get_permalink( $item['product_id'] ) ) : '';
			$data[] = array(
				'key'     => 'Ontwerp',
				'value'   => '',
				'display' => '<span class="mdo-ci"><details class="mdo-ci-det"><summary>Bekijk details</summary><ul>' . $rows . '</ul></details>' .
					( $edit ? '<a class="mdo-ci-edit" href="' . esc_url( $edit ) . '">Wijzigen</a>' : '' ) . '</span>',
			);
		}
		return $data;
	}

	// ---------- prijzen in de winkelwagen ----------

	/** Kolom "Prijs": stukprijs met "p/st". */
	public static function item_price( $html, $item, $key ) {
		if ( empty( $item['mdo'] ) ) {
			return $html;
		}
		return $html . ' <span class="mdo-ci-pc">p/st</span>';
	}

	/** Kolom "Subtotaal": totaal groot, daaronder aantal × stukprijs en wat erin zit. */
	public static function item_subtotal( $html, $item, $key ) {
		if ( empty( $item['mdo'] ) || 'checkout' === self::context() || empty( $item['data'] ) ) {
			return $html;
		}
		$pc   = WC()->cart ? WC()->cart->get_product_price( $item['data'] ) : '';
		$line = (int) $item['quantity'] . ' st. × ' . $pc;
		$inc  = empty( $item['mdo']['prints'] ) ? '' : 'incl. bedrukking';
		if ( ! empty( $item['mdo_quote']['setup'] ) ) {
			$inc .= ', waarvan ' . self::money( $item['mdo_quote']['setup'], $item['data'] ) . ' eenmalige instelkosten';
		}
		return '<strong class="mdo-ci-total">' . $html . '</strong><span class="mdo-ci-sub">' . $line . ( $inc ? '<br>' . $inc : '' ) . '</span>';
	}

	// ---------- plaatje van het ontwerp ----------

	private static function thumb_url( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '#^thumbs/\d{6}/[a-f0-9]{32}\.jpg$#', $token ) ) {
			return '';
		}
		return file_exists( MDO_Settings::upload_dir()['base'] . $token ) ? MDO_Settings::upload_dir()['url'] . $token : '';
	}

	private static function thumb_img( $url, $alt ) {
		return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail mdo-design-thumb" width="300" height="300" style="max-width:100%;height:auto" loading="lazy">';
	}

	public static function thumb( $html, $item, $key ) {
		$url = self::thumb_url( $item['mdo_thumb'] ?? '' );
		return $url ? self::thumb_img( $url, 'Jouw ontwerp' ) : $html;
	}

	/** Blok-winkelwagen en -afrekenen (Store API). */
	public static function block_images( $images, $item, $key ) {
		$url = self::thumb_url( $item['mdo_thumb'] ?? '' );
		if ( ! $url ) {
			return $images;
		}
		return array(
			(object) array(
				'id'        => 0,
				'src'       => $url,
				'thumbnail' => $url,
				'srcset'    => '',
				'sizes'     => '',
				'name'      => 'Jouw ontwerp',
				'alt'       => 'Jouw ontwerp',
			),
		);
	}

	public static function order_thumb( $html, $item_id, $item ) {
		$url = is_object( $item ) ? (string) $item->get_meta( '_mdo_thumb' ) : '';
		return $url ? '<img src="' . esc_url( $url ) . '" alt="Ontwerp" width="150" height="150">' : $html;
	}

	public static function mail_thumb( $html, $item ) {
		$url = is_object( $item ) ? (string) $item->get_meta( '_mdo_thumb' ) : '';
		return $url ? '<div style="margin-bottom:5px"><img src="' . esc_url( $url ) . '" alt="Ontwerp" width="64" height="64" style="vertical-align:middle;margin-right:10px"></div>' : $html;
	}

	/**
	 * Zet de opdruklaag (PNG uit de browser, al op de maat van het drukvlak) op de productfoto.
	 * De browser kan dit zelf niet: de foto's van Midocean mag hij niet als afbeelding opslaan.
	 */
	public static function make_thumb( $cfg, $color, $raw ) {
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagecreatetruecolor' ) ) {
			return '';
		}
		$t   = json_decode( (string) $raw, true );
		$art = is_array( $t ) ? (string) ( $t['art'] ?? '' ) : '';
		if ( 0 !== strpos( $art, 'data:image/png;base64,' ) || strlen( $art ) > 4000000 ) {
			return '';
		}
		$pos = null;
		foreach ( $cfg['faces'] as $f ) {
			foreach ( $f['positions'] as $p ) {
				if ( $p['id'] === ( $t['pos'] ?? '' ) ) {
					$pos = $p;
				}
			}
		}
		if ( ! $pos || empty( $pos['box'] ) || count( $pos['box'] ) < 4 ) {
			return '';
		}
		$bytes = self::image_bytes( self::pos_img( $pos, $color ) );
		$photo = $bytes ? @imagecreatefromstring( $bytes ) : false; // phpcs:ignore
		$layer = @imagecreatefromstring( (string) base64_decode( substr( $art, 22 ), true ) ); // phpcs:ignore
		if ( ! $photo || ! $layer ) {
			return '';
		}
		$S   = 400;
		$out = imagecreatetruecolor( $S, $S );
		$hex = ltrim( (string) MDO_Settings::get( 'img_bg' ), '#' );
		$hex = preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ? $hex : 'FFFFFF';
		imagefill( $out, 0, 0, imagecolorallocate( $out, hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) ) );
		$W = imagesx( $photo );
		$H = imagesy( $photo );
		$k = min( ( $S * 0.9 ) / $W, ( $S * 0.9 ) / $H );
		$dw = max( 1, (int) round( $W * $k ) );
		$dh = max( 1, (int) round( $H * $k ) );
		$dx = (int) round( ( $S - $dw ) / 2 );
		$dy = (int) round( ( $S - $dh ) / 2 );
		// Witte achtergrond van de foto wordt de beige winkelkleur (vermenigvuldigen, zoals in de shop).
		self::paste( $out, $photo, $dx, $dy, $dw, $dh, true );
		$b  = array_map( 'floatval', $pos['box'] );
		$bx = (int) round( $dx + $b[0] * $k );
		$by = (int) round( $dy + $b[1] * $k );
		$bw = max( 1, (int) round( ( $b[2] - $b[0] ) * $k ) );
		$bh = max( 1, (int) round( ( $b[3] - $b[1] ) * $k ) );
		self::paste( $out, $layer, $bx, $by, $bw, $bh, ! empty( $t['blend'] ) );
		$sub = gmdate( 'Ym' );
		$dir = MDO_Settings::upload_dir()['base'] . 'thumbs/' . $sub . '/';
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . 'index.html' ) ) {
			file_put_contents( $dir . 'index.html', '' ); // phpcs:ignore
		}
		$token = 'thumbs/' . $sub . '/' . bin2hex( random_bytes( 16 ) ) . '.jpg';
		$ok    = imagejpeg( $out, MDO_Settings::upload_dir()['base'] . $token, 86 );
		imagedestroy( $out );
		imagedestroy( $photo );
		imagedestroy( $layer );
		return $ok ? $token : '';
	}

	/** Afbeelding geschaald op een plek zetten; met $multiply als "vermenigvuldigen" (glans en structuur blijven zichtbaar). */
	private static function paste( $dst, $src, $x, $y, $w, $h, $multiply ) {
		$tmp = imagecreatetruecolor( $w, $h );
		imagealphablending( $tmp, false );
		imagesavealpha( $tmp, true );
		imagefill( $tmp, 0, 0, imagecolorallocatealpha( $tmp, 255, 255, 255, 127 ) );
		imagecopyresampled( $tmp, $src, 0, 0, 0, 0, $w, $h, imagesx( $src ), imagesy( $src ) );
		if ( ! $multiply ) {
			imagealphablending( $dst, true );
			imagecopy( $dst, $tmp, $x, $y, 0, 0, $w, $h );
			imagedestroy( $tmp );
			return;
		}
		// Vermenigvuldigen met de doorzichtigheid erbij (GD's eigen effect gaat mis bij doorzichtige pixels).
		$dw = imagesx( $dst );
		$dh = imagesy( $dst );
		for ( $j = 0; $j < $h; $j++ ) {
			$yy = $y + $j;
			if ( $yy < 0 || $yy >= $dh ) {
				continue;
			}
			for ( $i = 0; $i < $w; $i++ ) {
				$xx = $x + $i;
				if ( $xx < 0 || $xx >= $dw ) {
					continue;
				}
				$c = imagecolorat( $tmp, $i, $j );
				$a = 1 - ( ( $c >> 24 ) & 127 ) / 127;
				if ( $a <= 0 ) {
					continue;
				}
				$d = imagecolorat( $dst, $xx, $yy );
				$r = ( $d >> 16 ) & 255;
				$g = ( $d >> 8 ) & 255;
				$b = $d & 255;
				$r = (int) round( $r + ( $r * ( ( $c >> 16 ) & 255 ) / 255 - $r ) * $a );
				$g = (int) round( $g + ( $g * ( ( $c >> 8 ) & 255 ) / 255 - $g ) * $a );
				$b = (int) round( $b + ( $b * ( $c & 255 ) / 255 - $b ) * $a );
				imagesetpixel( $dst, $xx, $yy, ( $r << 16 ) | ( $g << 8 ) | $b );
			}
		}
		imagedestroy( $tmp );
	}

	/** Dezelfde foto als het voorbeeld in de configurator (zie posImg in configurator.js). */
	private static function pos_img( $pos, $color ) {
		$imgs = (array) ( $pos['images'] ?? array() );
		if ( ! empty( $imgs[ $color['code'] ] ) ) {
			return $imgs[ $color['code'] ];
		}
		foreach ( $imgs as $k => $u ) {
			if ( '' !== (string) $k && preg_match( '/-' . preg_quote( (string) $k, '/' ) . '(-|$)/i', (string) $color['sku'] ) ) {
				return $u;
			}
		}
		return $imgs ? reset( $imgs ) : (string) $color['image'];
	}

	/** Foto ophalen: eigen uploads van schijf, Midocean via de cache van de mockup-maker. */
	private static function image_bytes( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}
		$up = wp_upload_dir();
		if ( 0 === strpos( $url, $up['baseurl'] ) ) {
			$path = realpath( $up['basedir'] . substr( $url, strlen( $up['baseurl'] ) ) );
			return ( $path && 0 === strpos( $path, realpath( $up['basedir'] ) ) && is_file( $path ) ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! $host || ! preg_match( '/(^|\.)midocean\.com$/', $host ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return '';
		}
		$file = MDO_Settings::cache_dir( 'img' ) . md5( $url );
		if ( ! file_exists( $file ) ) {
			$r = wp_safe_remote_get( $url, array( 'timeout' => 8 ) );
			if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) || 0 !== strpos( (string) wp_remote_retrieve_header( $r, 'content-type' ), 'image/' ) ) {
				return '';
			}
			file_put_contents( $file, wp_remote_retrieve_body( $r ) ); // phpcs:ignore
			file_put_contents( $file . '.type', (string) wp_remote_retrieve_header( $r, 'content-type' ) ); // phpcs:ignore
		}
		return (string) file_get_contents( $file ); // phpcs:ignore
	}

	// ---------- ontwerp wijzigen vanuit de winkelwagen ----------

	/** Gegevens om de configurator met een bestaande winkelwagenregel te vullen (?mdo_edit=sleutel). */
	public static function edit_data( $product_id ) {
		$key = isset( $_GET['mdo_edit'] ) ? sanitize_key( wp_unslash( $_GET['mdo_edit'] ) ) : ''; // phpcs:ignore
		if ( ! $key || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}
		$item = WC()->cart->get_cart_item( $key );
		if ( ! $item || empty( $item['mdo'] ) || (int) $item['product_id'] !== (int) $product_id ) {
			return null;
		}
		return array(
			'key'       => $key,
			'variation' => (int) $item['variation_id'],
			'qty'       => (int) $item['quantity'],
			'mdo'       => $item['mdo'],
			'logoBase'  => MDO_Settings::upload_dir()['url'] . 'logos/',
		);
	}

	/** Na "Wijziging opslaan": de oude regel weghalen. */
	public static function replace( $new_key ) {
		if ( empty( $_POST['mdo_replace'] ) || ! WC()->cart ) { // phpcs:ignore
			return;
		}
		$old = sanitize_key( wp_unslash( $_POST['mdo_replace'] ) ); // phpcs:ignore
		if ( $old && $old !== $new_key && WC()->cart->get_cart_item( $old ) ) {
			WC()->cart->remove_cart_item( $old );
		}
	}

	public static function replace_redirect( $url ) {
		return empty( $_POST['mdo_replace'] ) ? $url : wc_get_cart_url(); // phpcs:ignore
	}

	public static function replace_message( $msg ) {
		return empty( $_POST['mdo_replace'] ) ? $msg : 'Je ontwerp is bijgewerkt.'; // phpcs:ignore
	}

	public static function order_meta( $item, $cart_key, $values, $order ) {
		$short = self::shortage( $values );
		if ( $short > 0 ) {
			$item->add_meta_data( 'Levering', 'Nu niet op voorraad – we laten je de leverdatum weten' );
			$item->add_meta_data( '_mdo_short', $short );
		}
		if ( empty( $values['mdo'] ) ) {
			return;
		}
		foreach ( self::lines( $values['mdo'] ) as $l ) {
			$item->add_meta_data( $l['key'], $l['value'] );
		}
		if ( ! empty( $values['mdo_quote']['setup'] ) ) {
			$item->add_meta_data( 'Instelkosten', wp_strip_all_tags( wc_price( $values['mdo_quote']['setup'] ) ) . ' (excl. btw, zit in de stukprijs)' );
		}
		$thumb = self::thumb_url( $values['mdo_thumb'] ?? '' );
		if ( $thumb ) {
			$item->add_meta_data( '_mdo_thumb', $thumb );
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

	/** "uitgelijnd links boven", of leeg bij midden. */
	public static function align_text( $p ) {
		$parts = array();
		$h     = array( 'l' => 'links', 'r' => 'rechts' );
		$v     = array( 't' => 'boven', 'b' => 'onder' );
		if ( isset( $h[ $p['al'] ?? '' ] ) ) {
			$parts[] = $h[ $p['al'] ];
		}
		if ( isset( $v[ $p['va'] ?? '' ] ) ) {
			$parts[] = $v[ $p['va'] ];
		}
		return $parts ? 'uitgelijnd ' . implode( ' ', $parts ) : '';
	}

	/** Kleding: de maat van de eerste variant niet tonen; de maten staan in de regel "Maten". */
	public static function drop_size_attr( $item ) {
		if ( ! empty( $item['mdo']['sizes'] ) && ! empty( $item['variation'] ) ) {
			foreach ( array_keys( $item['variation'] ) as $k ) {
				if ( false !== stripos( $k, 'maat' ) || false !== stripos( $k, 'size' ) ) {
					unset( $item['variation'][ $k ] );
				}
			}
		}
		return $item;
	}

	/** Kleding: aantal in de winkelwagen niet los aanpasbaar (het hoort bij de maten). */
	public static function fixed_qty( $html, $key, $item ) {
		if ( ! empty( $item['mdo']['sizes'] ) ) {
			return '<span class="mdo-fixed-qty">' . (int) $item['quantity'] . '</span><input type="hidden" name="cart[' . esc_attr( $key ) . '][qty]" value="' . (int) $item['quantity'] . '">';
		}
		// Tip: bij de volgende staffel per stuk goedkoper (klik zet het aantal en werkt de winkelwagen bij).
		if ( ! empty( $item['mdo_quote']['next'] ) && ! empty( $item['data'] ) && 'cart' === self::context() ) {
			$n     = $item['mdo_quote']['next'];
			$html .= '<a href="#" class="mdo-ci-tip" data-mdo-qty="' . (int) $n[0] . '" data-mdo-key="' . esc_attr( $key ) . '">Bij ' . esc_html( number_format_i18n( (int) $n[0] ) ) . ' st. ' . wp_strip_all_tags( self::money( $n[1], $item['data'] ) ) . ' p/st</a>';
		}
		return $html;
	}

	/**
	 * Nabestellen toestaan als Midocean al een levering gepland heeft, en bij kleding
	 * (daar controleert de configurator de voorraad per maat; WooCommerce ziet alleen één maat).
	 */
	// ---------- niet op voorraad: toch bestellen en betalen, leverdatum volgt ----------

	private static function is_mdo( $product ) {
		if ( ! $product || ! is_object( $product ) ) {
			return false;
		}
		if ( $product->is_type( 'variation' ) ) {
			return '' !== (string) $product->get_meta( '_mdo_color_code' ) || (bool) get_post_meta( $product->get_parent_id(), '_mdo_master_code', true );
		}
		return (bool) $product->get_meta( '_mdo_master_code' );
	}

	public static function allow_backorder( $allowed, $product_id, $product = null ) {
		return $allowed || self::is_mdo( $product );
	}

	public static function in_stock( $in, $product ) {
		return $in || self::is_mdo( $product );
	}

	/** Tekort voor deze regel (0 = genoeg voorraad). */
	private static function shortage( $item ) {
		$p = $item['data'] ?? null;
		if ( ! $p || ! is_object( $p ) || ! $p->managing_stock() || null === $p->get_stock_quantity() ) {
			return 0;
		}
		if ( ! empty( $item['mdo']['sizes'] ) ) {
			return 0; // maten: voorraad per maat wordt in de configurator al getoond
		}
		return max( 0, (int) $item['quantity'] - max( 0, (int) $p->get_stock_quantity() ) );
	}

	public static function stock_notice() {
		if ( ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::shortage( $item ) > 0 ) {
				echo '<div class="woocommerce-info mdo-stock-info">Een of meer producten zijn nu niet (genoeg) op voorraad. Je kunt gewoon bestellen en betalen; na je bestelling laten we je weten wanneer het geleverd wordt.</div>';
				return;
			}
		}
	}

	public static function stock_order_note( $order ) {
		$lines = array();
		foreach ( $order->get_items() as $it ) {
			$short = (int) $it->get_meta( '_mdo_short' );
			if ( $short > 0 ) {
				$lines[] = $it->get_name() . ': ' . $short . ' st. tekort';
			}
		}
		if ( $lines ) {
			$order->add_order_note( 'Niet op voorraad – leverdatum doorgeven aan klant. ' . implode( '; ', $lines ) . '.' );
		}
	}
}
