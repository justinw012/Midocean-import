<?php
/**
 * Winkel- en categoriepagina's: bij aanwijzen (desktop) of vasthouden (mobiel) het product
 * tonen met het PromoFun-logo erop gedrukt, op het echte drukvlak van Midocean.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Shop {

	public static function init() {
		if ( is_admin() || ! MDO_Settings::get( 'shop_hover' ) ) {
			return;
		}
		add_action( 'woocommerce_before_shop_loop_item_title', array( __CLASS__, 'mark' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function logo_url() {
		$id = (int) MDO_Settings::get( 'hover_logo' );
		if ( ! $id ) {
			$id = (int) get_theme_mod( 'custom_logo' );
		}
		if ( ! $id ) {
			$id = (int) get_option( 'site_logo' );
		}
		$src = $id ? wp_get_attachment_image_src( $id, 'large' ) : false;
		return $src ? $src[0] : '';
	}

	public static function icon_url() {
		$u = get_site_icon_url( 96 );
		return $u ? $u : '';
	}

	public static function assets() {
		if ( ! self::logo_url() ) {
			return;
		}
		wp_enqueue_style( 'mdo-shop', MDO_URL . 'assets/shop.css', array(), MDO_VERSION );
		wp_enqueue_script( 'mdo-shop', MDO_URL . 'assets/shop.js', array(), MDO_VERSION, true );
		wp_localize_script( 'mdo-shop', 'MDO_SHOP', array(
			'logo'     => self::logo_url(),
			'icon'     => self::icon_url(),
			'trimLine' => (int) MDO_Settings::get( 'hover_trim' ),
		) );
	}

	/** Gegevens voor het bedrukte voorbeeld: foto van de drukpositie + drukvlak. */
	public static function mark() {
		global $product;
		if ( ! $product || ! $product->get_meta( '_mdo_master_code' ) || ! self::logo_url() ) {
			return;
		}
		$print = $product->get_meta( MDO_Printdata::META );
		if ( ! is_array( $print ) || empty( $print['positions'] ) ) {
			return;
		}
		// Kleurcode van de standaardvariant (of de eerste), zodat het voorbeeld dezelfde kleur heeft.
		$code   = '';
		$cname  = '';
		$master = (string) $product->get_meta( '_mdo_master_code' );
		if ( $product->is_type( 'variable' ) ) {
			$children = $product->get_children();
			$default  = $product->get_default_attributes();
			foreach ( $children as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v ) {
					continue;
				}
				if ( ! $code || ( $default && ! array_diff_assoc( $default, array_map( 'strval', $v->get_attributes() ) ) ) ) {
					$code  = MDO_Frontend::color_code( $v, $master );
					$cname = MDO_Frontend::colour_name( $v );
					if ( $default && ! array_diff_assoc( $default, array_map( 'strval', $v->get_attributes() ) ) ) {
						break;
					}
				}
			}
		}
		// Eerste drukpositie (in de volgorde van de configurator) met een foto en drukvlak.
		// Rondom/360 liever niet: die foto's tonen vaak meerdere aanzichten naast elkaar
		// met één drukvlak eroverheen, waardoor het logo over alle aanzichten loopt.
		$cands = array();
		foreach ( MDO_Printdata::faces( $print['positions'] ) as $f ) {
			foreach ( $f['positions'] as $pos ) {
				if ( empty( $pos['box'] ) || empty( $pos['images'] ) ) {
					continue;
				}
				$wrap    = (bool) preg_match( '/360|WRAP|AROUND|ROUND ?SCREEN|ROUNDPART/i', (string) $pos['id'] );
				$cands[] = array( $wrap ? 1 : 0, count( $cands ), $pos );
			}
		}
		usort( $cands, function ( $a, $b ) {
			return $a[0] - $b[0] ? $a[0] - $b[0] : $a[1] - $b[1];
		} );
		foreach ( $cands as $c ) {
			$pos = $c[2];
			$img = ( $code && ! empty( $pos['images'][ $code ] ) ) ? $pos['images'][ $code ] : reset( $pos['images'] );
			if ( ! $img ) {
				continue;
			}
			$data = array(
				'img'   => $img,
				'box'   => array_map( 'floatval', $pos['box'] ),
				'id'    => (string) $pos['id'],
				'shape' => isset( $pos['type'] ) ? (string) $pos['type'] : '',
				'ink'   => self::ink_for( $pos, $cname ),
			);
			echo '<span class="mdo-hov" hidden data-mdo-hov="' . esc_attr( wp_json_encode( $data ) ) . '"></span>';
			return;
		}
	}

	/**
	 * Welke versie van het logo past bij deze drukpositie?
	 * - kleur mogelijk (een techniek zonder kleurlimiet, bijv. digitaal/transfer): logo in kleur ('')
	 * - alleen gravering/blinddruk: grijs, zoals gegraveerd materiaal
	 * - alleen beperkt aantal kleuren (zeefdruk/tampondruk): logo in één kleur, zwart of wit op donker
	 */
	private static function ink_for( array $pos, $colour_name ) {
		$names  = array_merge( (array) get_option( 'mdo_technique_names', array() ), array_filter( (array) get_option( 'mdo_technique_labels', array() ) ) );
		$dark   = (bool) preg_match( '/zwart|black|navy|marine|donker|dark|bordeaux|petrol|royal|bruin|paars|antraciet|kobalt/i', (string) $colour_name );
		$effect = true;
		foreach ( (array) $pos['techs'] as $t ) {
			$n = strtolower( (string) ( $names[ $t['id'] ] ?? $t['id'] ) );
			$fx = (bool) preg_match( '/laser|graveer|gravure|engrav|blind|deboss|emboss|reli/', $n );
			if ( ! $fx && 0 === (int) ( $t['max'] ?? 0 ) ) {
				return '';
			}
			if ( ! $fx ) {
				$effect = false;
			}
		}
		if ( $effect && $pos['techs'] ) {
			return $dark ? '#C9CDD2' : '#5A5E64';
		}
		return $dark ? '#FFFFFF' : '#1E1E1E';
	}
}
