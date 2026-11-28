<?php
/**
 * Winkel- en categoriepagina's: bij aanwijzen (desktop) of vasthouden (mobiel) het product
 * tonen met het PromoFun-logo erop gedrukt, op het echte drukvlak van Midocean.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Shop {

	/** Korte omschrijving weglaten als die alleen de productnaam herhaalt (bijv. "PP lunchbox 800ml"). */
	public static function short_desc( $html ) {
		global $product;
		if ( ! is_singular( 'product' ) || ! $product || ! is_object( $product ) ) {
			return $html;
		}
		$t = trim( wp_strip_all_tags( (string) $html ) );
		if ( '' !== $t && false !== mb_stripos( wp_strip_all_tags( $product->get_name() ), $t ) ) {
			return '';
		}
		return $html;
	}

	public static function init() {
		if ( ! is_admin() ) {
			add_filter( 'woocommerce_short_description', array( __CLASS__, 'short_desc' ), 20 );
			add_action( 'woocommerce_before_shop_loop_item_title', array( __CLASS__, 'badges' ), 15 );
			// Productpagina: labels boven de titel.
			add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'badges_single' ), 4 );
			// Productpagina: korte feiten onder de naam (voorraad, klaar rond, kleuren, bedrukking).
			add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'facts' ), 6 );
			// Productprijs in lijsten (gerelateerde producten, widgets, zoekresultaten van het thema):
			// bij Midocean-producten "Vanaf … p/st bij 250 st. incl. bedrukking" i.p.v. de kale productprijs.
			add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 20, 2 );
			add_action( 'wp_head', array( __CLASS__, 'badge_css' ) );
		}
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		// Huisstijl (kleurverloop uit het logo) in de hele site, als laatste geladen.
		add_action( 'wp_enqueue_scripts', function () {
			if ( MDO_Settings::get( 'brand_grad' ) ) {
				wp_enqueue_style( 'mdo-brand', MDO_URL . 'assets/brand.css', array(), MDO_VERSION );
			}
			if ( MDO_Settings::get( 'compact_crumbs' ) && function_exists( 'is_product' ) && ( is_product() || is_product_category() || is_product_tag() || is_shop() || ( is_search() && 'product' === get_query_var( 'post_type' ) ) ) ) {
				wp_enqueue_style( 'mdo-brand', MDO_URL . 'assets/brand.css', array(), MDO_VERSION );
				wp_enqueue_script( 'mdo-crumbs', MDO_URL . 'assets/crumbs.js', array(), MDO_VERSION, true );
			}
		}, 999 );
		if ( MDO_Settings::get( 'shop_hover' ) ) {
			add_action( 'woocommerce_before_shop_loop_item_title', array( __CLASS__, 'mark' ), 20 );
		}
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
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		$logo = MDO_Settings::get( 'shop_hover' ) ? self::logo_url() : '';
		$bg   = (string) MDO_Settings::get( 'img_bg' );
		if ( ! $logo && ! $bg ) {
			return;
		}
		wp_enqueue_style( 'mdo-shop', MDO_URL . 'assets/shop.css', array(), MDO_VERSION );
		if ( $bg ) {
			wp_add_inline_style( 'mdo-shop', '.mdo-imgbg{background:' . $bg . '}' );
		}
		wp_enqueue_script( 'mdo-shop', MDO_URL . 'assets/shop.js', array(), MDO_VERSION, true );
		wp_localize_script( 'mdo-shop', 'MDO_SHOP', array(
			'bg'       => $bg ? 1 : 0,
			'bgColor'  => $bg,
			'logo'     => $logo,
			'icon'     => self::icon_url(),
			'trimLine' => (int) MDO_Settings::get( 'hover_trim' ),
		) );
	}

	/** Gegevens voor het bedrukte voorbeeld: foto van de drukpositie + drukvlak. */
	public static function mark() {
		global $product;
		if ( ! $product || ! self::logo_url() ) {
			return;
		}
		$data = self::mark_data( $product );
		if ( $data ) {
			echo '<span class="mdo-hov" hidden data-mdo-hov="' . esc_attr( wp_json_encode( $data ) ) . '"></span>';
		}
	}

	/** Zelfde gegevens als array (ook gebruikt door de filters). */
	public static function mark_data( $product ) {
		if ( ! $product->get_meta( '_mdo_master_code' ) ) {
			return null;
		}
		$print = $product->get_meta( MDO_Printdata::META );
		if ( ! is_array( $print ) || empty( $print['positions'] ) ) {
			return null;
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
				'w'     => (float) $pos['w'],
				'dia'   => MDO_Frontend::diameter( $product ),
			);
			return $data;
		}
		return null;
	}

	/**
	 * Welke versie van het logo past bij deze drukpositie?
	 * - kleur mogelijk (een techniek zonder kleurlimiet, bijv. digitaal/transfer): logo in kleur ('')
	 * - alleen gravering/blinddruk: grijs, zoals gegraveerd materiaal
	 * - alleen beperkt aantal kleuren (zeefdruk/tampondruk): logo in één kleur, zwart of wit op donker
	 */
	public static function ink_for( array $pos, $colour_name ) {
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

	/** Labels "Nieuw" en "Op = op" op de productfoto in de winkel. */
	public static function price_html( $html, $product ) {
		static $busy = false;
		if ( $busy || is_admin() || ! $product || ! $product->get_meta( '_mdo_master_code' ) || ! class_exists( 'MDO_Filter' ) ) {
			return $html;
		}
		// Op de productpagina zelf toont de configurator de prijs (en verbergt de themaprijs).
		if ( function_exists( 'is_product' ) && is_product() && get_queried_object_id() === $product->get_id() ) {
			return $html;
		}
		$busy  = true;
		$built = 0;
		$row   = MDO_Filter::row( $product->get_id(), $built );
		$busy  = false;
		if ( ! $row || ! empty( $row['x'] ) || empty( $row['pr'] ) ) {
			return $html;
		}
		$q  = 250;
		$i  = array_search( $q, MDO_Frontend::TIERS, true );
		$tx = 'incl' === get_option( 'woocommerce_tax_display_shop' ) ? (float) ( $row['tx'] ?? 1 ) : 1.0;
		$pp = (float) $row['pr'][ $i ] * $tx;
		$tl = MDO_Frontend::tax_label();
		return '<span class="mdo-from-price">Vanaf <strong>' . wc_price( $pp ) . '</strong> <small>p/st bij ' . $q . ' st.' . ( ! empty( $row['pp'] ) ? ' incl. bedrukking' : '' ) . ( $tl ? ', ' . esc_html( $tl ) : '' ) . '</small></span>';
	}

	/** Datum na $n werkdagen (za/zo overgeslagen). */
	private static function workdays( DateTimeImmutable $d, $n ) {
		while ( (int) $d->format( 'N' ) >= 6 ) {
			$d = $d->modify( '+1 day' );
		}
		while ( $n > 0 ) {
			$d = $d->modify( '+1 day' );
			if ( (int) $d->format( 'N' ) < 6 ) {
				$n--;
			}
		}
		return $d;
	}

	public static function facts() {
		global $product;
		if ( ! $product || ! $product->get_meta( '_mdo_master_code' ) || ! class_exists( 'MDO_Filter' ) ) {
			return;
		}
		$built = 0;
		$row   = MDO_Filter::row( $product->get_id(), $built );
		if ( ! $row || ! empty( $row['x'] ) || empty( $row['c'] ) ) {
			return;
		}
		$q     = 250;
		$i     = array_search( $q, MDO_Frontend::TIERS, true );
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$facts = array();

		// Voorraad (som van alle kleuren/maten per kleur) en verwachte leveringen.
		$best = null;
		$now  = false;
		foreach ( $row['c'] as $c ) {
			$stock = 0;
			$arr   = array();
			$known = true;
			foreach ( $c['v'] as $vid ) {
				if ( 'yes' !== get_post_meta( $vid, '_manage_stock', true ) ) {
					$known = false;
					break;
				}
				$stock += max( 0, (int) get_post_meta( $vid, '_stock', true ) );
				foreach ( (array) get_post_meta( $vid, '_mdo_arrivals', true ) as $a ) {
					if ( is_array( $a ) && isset( $a[0], $a[1] ) ) {
						$arr[ (string) $a[0] ] = ( $arr[ (string) $a[0] ] ?? 0 ) + (int) $a[1];
					}
				}
			}
			$start = null;
			if ( ! $known || $stock >= $q ) {
				$start = $today;
				$now   = true;
			} else {
				ksort( $arr );
				$have = $stock;
				foreach ( $arr as $date => $n ) {
					$have += $n;
					if ( $have >= $q ) {
						$start = max( $today, new DateTimeImmutable( $date, $tz ) );
						break;
					}
				}
			}
			if ( $start && ( ! $best || $start < $best ) ) {
				$best = $start;
			}
		}
		$days  = array( 'zo', 'ma', 'di', 'wo', 'do', 'vr', 'za' );
		$month = array( '', 'jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec' );
		if ( $now ) {
			$facts[] = array( 'ok', 'Op voorraad' );
		} elseif ( $best ) {
			$facts[] = array( 'wait', 'Nieuwe voorraad onderweg' );
		}
		if ( $best && isset( $row['ld'][ $i ] ) ) {
			$d       = self::workdays( $best, (int) $row['ld'][ $i ] );
			$facts[] = array( 'date', 'Klaar rond ' . $days[ (int) $d->format( 'w' ) ] . ' ' . $d->format( 'j' ) . ' ' . $month[ (int) $d->format( 'n' ) ] . ' bij ' . $q . ' st.' );
		}
		$groups = array_unique( wp_list_pluck( $row['c'], 'g' ) );
		$nc     = count( $row['c'] );
		if ( $nc > 1 ) {
			$facts[] = array( 'col', $nc . ' kleuren' );
		}
		$t = (array) ( $row['t'] ?? array() );
		if ( ! empty( $t['fc'] ) ) {
			$facts[] = array( 'print', 'Full colour mogelijk' );
		} elseif ( ! empty( $t['eng'] ) ) {
			$facts[] = array( 'print', 'Gravure' );
		}
		if ( ! empty( $t['wrap'] ) ) {
			$facts[] = array( 'print', 'Rondom bedrukken' );
		}
		if ( ! empty( $row['rec'] ) ) {
			$facts[] = array( 'eco', 'Gerecycled materiaal' );
		}
		if ( ! $facts ) {
			return;
		}
		echo '<ul class="mdo-facts">';
		foreach ( $facts as $f ) {
			echo '<li class="mdo-fact mdo-fact-' . esc_attr( $f[0] ) . '">' . esc_html( $f[1] ) . '</li>';
		}
		echo '</ul>';
		unset( $groups );
	}

	public static function badges_single() {
		ob_start();
		self::badges();
		$html = ob_get_clean();
		if ( $html ) {
			echo str_replace( 'class="mdo-badges"', 'class="mdo-badges mdo-badges-single"', $html ); // phpcs:ignore
		}
	}

	public static function badges() {
		global $product;
		if ( ! $product || ! $product->get_meta( '_mdo_master_code' ) ) {
			return;
		}
		$out  = array();
		$days = (int) MDO_Settings::get( 'badge_new_days' );
		$rel  = (string) $product->get_meta( '_mdo_release' );
		if ( $days && $rel && strtotime( $rel ) > time() - $days * DAY_IN_SECONDS && strtotime( $rel ) <= time() + 30 * DAY_IN_SECONDS ) {
			$out[] = '<span class="mdo-badge mdo-badge-new">Nieuw</span>';
		}
		if ( (string) $product->get_meta( '_mdo_discontinued' ) ) {
			$out[] = '<span class="mdo-badge mdo-badge-last">Op = op</span>';
		}
		if ( $out ) {
			echo '<span class="mdo-badges">' . implode( '', $out ) . '</span>'; // phpcs:ignore
		}
	}

	public static function badge_css() {
		if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_front_page() ) ) {
			echo '<style>li.product{position:relative}.mdo-badges{position:absolute;top:8px;left:8px;z-index:3;display:flex;flex-direction:column;gap:4px;pointer-events:none}.mdo-badge{display:inline-block;padding:3px 9px;border-radius:999px;font-size:12px;font-weight:700;line-height:1.3;color:#fff;background:#5B2EE5;box-shadow:0 1px 3px rgba(0,0,0,.15)}.mdo-badge-last{background:#B42318}.mdo-badges-single{position:static;flex-direction:row;margin:0 0 8px}.mdo-facts{list-style:none;margin:6px 0 14px;padding:0;display:flex;flex-wrap:wrap;gap:6px}.mdo-fact{margin:0;padding:5px 11px;border-radius:999px;font-size:13px;font-weight:500;line-height:1.3;background:#F3EDFD;color:#3E1A8F}.mdo-fact-ok{background:#E3F4EA;color:#14532D}.mdo-fact-wait{background:#FFF1D6;color:#7A4A00}.mdo-fact-date{background:#E6F3FD;color:#0B4A75}.mdo-fact-col{background:#FDE7F5;color:#8A1C64}.mdo-fact-eco{background:#E3F4EA;color:#14532D}.mdo-badges-single .mdo-badge{font-size:13px;padding:4px 12px}</style>';
		}
	}
}
