<?php
/**
 * Filters op shop-, categorie- en zoekpagina's.
 *
 * De klant kiest eerst een aantal; daarna ziet hij per product de prijs per stuk bij dat aantal
 * (incl. de voordeligste bedrukking in 1 kleur), of er voorraad is en wanneer het klaar kan zijn.
 *
 * Per product wordt een kleine samenvatting bewaard (meta _mdo_idx), opnieuw gemaakt zodra het
 * product, de opslag of de drukprijzen veranderen. Voorraad en leveringen worden bij elke
 * aanvraag vers uit de database gelezen.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Filter {

	const META = '_mdo_idx';

	/** Kleurgroepen: sleutel => array( label, kleur, zoekwoorden ). Volgorde = volgorde in de filters. */
	const GROUPS = array(
		'zwart'       => array( 'Zwart', '#1E1E1E', 'zwart|black|antraciet|anthracite' ),
		'wit'         => array( 'Wit', '#FFFFFF', 'wit|white|ivoor|ivory|creme|cream' ),
		'grijs'       => array( 'Grijs', '#8B9099', 'grijs|grey|gray|titanium|melange' ),
		'zilver'      => array( 'Zilver', '#C3C7CC', 'zilver|silver|chroom|chrome|metaal|metal|aluminium|staal|steel' ),
		'blauw'       => array( 'Blauw', '#2F6FD3', 'blauw|blue|navy|marine|royal|kobalt|cobalt|turquoise|turquoise|aqua|petrol|denim|atoll|cyan' ),
		'groen'       => array( 'Groen', '#2E9E62', 'groen|green|lime|kaki|khaki|army|olijf|olive|mint|emerald|salie|sage' ),
		'rood'        => array( 'Rood', '#D53A35', 'rood|red|bordeaux|burgundy|wijnrood|tango' ),
		'roze'        => array( 'Roze', '#E87BAE', 'roze|pink|fuchsia|magenta|rose' ),
		'paars'       => array( 'Paars', '#7E57C2', 'paars|purple|violet|lila|lilac|orchid' ),
		'geel'        => array( 'Geel', '#F2C94C', 'geel|yellow|lemon|mosterd|mustard' ),
		'oranje'      => array( 'Oranje', '#F2994A', 'oranje|orange|apricot|abrikoos|koraal|coral' ),
		'bruin'       => array( 'Bruin', '#8D6E63', 'bruin|brown|chocolate|choco|earth|hout|wood|bamboe|bamboo|kurk|cork|walnoot' ),
		'beige'       => array( 'Beige', '#D9C9A8', 'beige|naturel|natural|sand|zand|ecru|taupe|kraft|jute|linnen|linen' ),
		'goud'        => array( 'Goud', '#C9A54B', 'goud|gold|brons|bronze|koper|copper' ),
		'transparant' => array( 'Transparant', '', 'transparant|transparent|helder|clear|frosted|mat transparant' ),
		'multi'       => array( 'Meerkleurig', '', 'multi|assorti|mix|regenboog|rainbow' ),
	);

	public static function init() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		add_action( 'wp_ajax_mdo_filter', array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_mdo_filter', array( __CLASS__, 'ajax' ) );
		if ( ! MDO_Settings::get( 'filter_on' ) ) {
			return;
		}
		add_action( 'wp', array( __CLASS__, 'setup' ) );
	}

	/** Welke lijst toont deze pagina? null = geen productoverzicht. */
	public static function context() {
		if ( ! function_exists( 'is_shop' ) ) {
			return null;
		}
		if ( is_product_category() ) {
			return array( 'cat' => (int) get_queried_object_id(), 'tag' => 0, 's' => '' );
		}
		if ( is_product_tag() ) {
			return array( 'cat' => 0, 'tag' => (int) get_queried_object_id(), 's' => '' );
		}
		if ( is_tax() ) {
			$o = get_queried_object();
			if ( $o && ! empty( $o->taxonomy ) && is_object_in_taxonomy( 'product', $o->taxonomy ) ) {
				return array( 'cat' => 0, 'tag' => 0, 's' => '', 'tax' => $o->taxonomy, 'term' => (int) $o->term_id );
			}
		}
		if ( is_search() && 'product' === get_query_var( 'post_type' ) ) {
			return array( 'cat' => 0, 'tag' => 0, 's' => (string) get_search_query( false ) );
		}
		if ( is_shop() ) {
			return array( 'cat' => 0, 'tag' => 0, 's' => '' );
		}
		return null;
	}

	public static function setup() {
		$ctx = self::context();
		if ( ! $ctx ) {
			return;
		}
		// Gegevens via dezelfde pagina-URL ophalen: wie de pagina mag zien (wachtwoord, beveiliging
		// van de host), mag dan ook de gegevens zien. admin-ajax blijft als reserve.
		if ( isset( $_GET['mdo_filter_json'] ) ) {
			self::send( $ctx );
		}
		add_action( 'wp_enqueue_scripts', function () use ( $ctx ) {
			self::assets( $ctx );
		} );
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'root' ), 1 );
		add_action( 'woocommerce_no_products_found', array( __CLASS__, 'root' ), 1 );
		// Meteen bij het laden de oude lijst en filters verbergen (niet pas als onze filters klaar zijn),
		// anders zijn die even zichtbaar. Lukt het laden niet, dan zet het script ze terug.
		add_action( 'wp_head', function () {
			echo '<noscript><style>body.mdo-filter-on ul.products,body.mdo-filter-on #secondary,body.mdo-filter-on .widget-area,body.mdo-filter-on .woocommerce-pagination{display:block!important}.mdo-filter{display:none}</style></noscript>';
		} );
		add_filter( 'body_class', function ( $c ) {
			$c[] = 'mdo-filter-page';
			$c[] = 'mdo-filter-on';
			if ( MDO_Settings::get( 'filter_sidebar' ) ) {
				$c[] = 'mdo-filter-nosidebar';
			}
			return $c;
		} );
	}

	/** Alle categorieën onder $cat (0 = alles), als platte lijst met diepte, in boomvolgorde. */
	private static function tree( $cat ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$kids = array();
		foreach ( $terms as $t ) {
			if ( 'uncategorized' === $t->slug || 'geen-categorie' === $t->slug ) {
				continue;
			}
			$kids[ (int) $t->parent ][] = $t;
		}
		$out  = array();
		$walk = function ( $parent, $depth ) use ( &$walk, &$out, $kids ) {
			foreach ( $kids[ $parent ] ?? array() as $t ) {
				$out[] = array( 'id' => $t->term_id, 'name' => html_entity_decode( $t->name ), 'url' => get_term_link( $t ), 'd' => $depth );
				$walk( (int) $t->term_id, $depth + 1 );
			}
		};
		$walk( (int) $cat, 0 );
		return $out;
	}

	private static function subcats( $cat ) {
		$out   = array();
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => (int) $cat, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return $out;
		}
		foreach ( $terms as $t ) {
			if ( 'uncategorized' === $t->slug || 'geen-categorie' === $t->slug ) {
				continue;
			}
			$out[] = array( 'id' => $t->term_id, 'name' => html_entity_decode( $t->name ), 'url' => get_term_link( $t ) );
		}
		return $out;
	}

	public static function assets( $ctx ) {
		wp_enqueue_style( 'mdo-filter', MDO_URL . 'assets/filter.css', array(), MDO_VERSION );
		wp_enqueue_script( 'mdo-filter', MDO_URL . 'assets/filter.js', array(), MDO_VERSION, true );
		wp_localize_script( 'mdo-filter', 'MDO_FILTER', array(
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'cat'      => $ctx['cat'],
			'tag'      => $ctx['tag'],
			's'        => $ctx['s'],
			'tax'      => $ctx['tax'] ?? '',
			'term'     => $ctx['term'] ?? 0,
			'subs'     => $ctx['cat'] || ( ! $ctx['tag'] && '' === $ctx['s'] && empty( $ctx['tax'] ) ) ? self::subcats( $ctx['cat'] ) : array(),
			'tree'     => ( $ctx['tag'] || '' !== $ctx['s'] || ! empty( $ctx['tax'] ) ) ? array() : self::tree( $ctx['cat'] ),
			'tiers'    => MDO_Frontend::TIERS,
			'taxLabel' => MDO_Frontend::tax_label(),
			'currency' => html_entity_decode( get_woocommerce_currency_symbol() ),
			'dec'      => wc_get_price_decimal_separator(),
			'newDays'  => (int) MDO_Settings::get( 'badge_new_days' ),
			'groups'   => array_map( function ( $g ) {
				return array( $g[0], $g[1] );
			}, self::GROUPS ),
		) );
	}

	public static function root() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		echo '<div id="mdo-filter" class="mdo-filter" aria-live="polite"><p class="mdo-f-loading">Producten laden…</p></div>';
	}

	/** Sleutel die verandert als iets in de berekening verandert (opslag, levertijden, drukprijzen). */
	public static function key() {
		static $k = null;
		if ( null === $k ) {
			$s = MDO_Settings::all();
			$k = substr( md5( wp_json_encode( array(
				MDO_VERSION,
				$s['margin_product'], $s['margin_print'], $s['margin_setup'], $s['setup_per_colour'],
				$s['lead_proof'], $s['lead_transport'], $s['lead_margin'], $s['lead_table'],
				md5( maybe_serialize( get_option( 'mdo_printprices', array() ) ) ),
				get_option( 'mdo_technique_names', array() ), get_option( 'mdo_technique_labels', array() ),
				get_option( 'woocommerce_tax_display_shop' ), get_option( 'woocommerce_calc_taxes' ),
			) ) ), 0, 12 );
		}
		return $k;
	}

	public static function group_of( $group, $name ) {
		foreach ( array( (string) $group, (string) $name ) as $txt ) {
			if ( '' === trim( $txt ) ) {
				continue;
			}
			foreach ( self::GROUPS as $key => $g ) {
				if ( preg_match( '/\b(' . $g[2] . ')/i', $txt ) ) {
					return $key;
				}
			}
		}
		return 'multi';
	}

	/** Productiedagen volgens de levertijdtabel (zelfde regel als in de configurator). */
	public static function prod_days( array $lead, $tech_name, $qty ) {
		$n   = strtolower( (string) $tech_name );
		$row = null;
		foreach ( $lead as $r ) {
			foreach ( $r[0] as $k ) {
				if ( $k && '*' !== $k && false !== strpos( $n, $k ) ) {
					$row = $r[1];
					break 2;
				}
			}
		}
		if ( ! $row ) {
			foreach ( $lead as $r ) {
				if ( in_array( '*', $r[0], true ) ) {
					$row = $r[1];
					break;
				}
			}
		}
		if ( ! $row ) {
			return 0;
		}
		$idx = (int) ceil( $qty / 250 ) - 1;
		if ( $idx < count( $row ) ) {
			return (int) $row[ max( 0, $idx ) ];
		}
		return (int) $row[ count( $row ) - 1 ] + (int) ceil( ( $qty - 3000 ) / 500 );
	}

	/** Samenvatting van één product (zonder voorraad). */
	public static function build( $product ) {
		$id   = $product->get_id();
		$img  = wp_get_attachment_image_src( $product->get_image_id(), 'woocommerce_thumbnail' );
		$row  = array(
			'id'   => $id,
			'n'    => html_entity_decode( wp_strip_all_tags( $product->get_name() ) ),
			'u'    => get_permalink( $id ),
			'img'  => $img ? $img[0] : wc_placeholder_img_src( 'woocommerce_thumbnail' ),
			'cats' => array_map( 'intval', $product->get_category_ids() ),
			'sku'  => (string) $product->get_meta( '_mdo_master_code' ),
		);
		$master = $row['sku'];
		$print  = $product->get_meta( MDO_Printdata::META );
		if ( ! $master || ! $product->is_type( 'variable' ) ) {
			// Ander product (niet van Midocean): gewone prijs, geen aantal-berekening.
			$row['x']  = 1;
			$row['ph'] = wp_strip_all_tags( html_entity_decode( $product->get_price_html() ) );
			return $row;
		}
		$s     = MDO_Settings::all();
		$tiers = MDO_Frontend::TIERS;

		// Kleuren (bij kleding: alle maten van een kleur samen).
		$colors = array();
		$sizes  = array();
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || 'publish' !== $v->get_status() ) {
				continue;
			}
			$scales = $v->get_meta( '_mdo_scales' );
			if ( ! $scales ) {
				continue;
			}
			$code  = MDO_Frontend::color_code( $v, $master );
			$cname = MDO_Frontend::colour_name( $v );
			$k     = $code . '|' . $cname;
			if ( ! isset( $colors[ $k ] ) ) {
				$im           = wp_get_attachment_image_src( $v->get_image_id(), 'woocommerce_thumbnail' );
				$colors[ $k ] = array(
					'k' => $code,
					'n' => $cname,
					'g' => self::group_of( $v->get_meta( '_mdo_color_group' ), $cname ),
					'i' => ( $im && $v->get_image_id() !== $product->get_image_id() ) ? $im[0] : '',
					'v' => array(),
					'p' => array(),
				);
			}
			$colors[ $k ]['v'][] = $vid;
			$sell = array();
			foreach ( $scales as $sc ) {
				$sell[] = array( (int) $sc[0], (float) $sc[1] * (float) $s['margin_product'] );
			}
			foreach ( $tiers as $i => $q ) {
				$pp = (float) MDO_Pricing::scale_at( $sell, $q )[1];
				if ( ! isset( $colors[ $k ]['p'][ $i ] ) || $pp < $colors[ $k ]['p'][ $i ] ) {
					$colors[ $k ]['p'][ $i ] = $pp; // goedkoopste maat
				}
			}
			$sz = (string) $v->get_meta( '_mdo_size' );
			if ( '' !== $sz ) {
				$sizes[ $sz ] = true;
			}
		}
		if ( ! $colors ) {
			$row['x']  = 1;
			$row['ph'] = wp_strip_all_tags( html_entity_decode( $product->get_price_html() ) );
			return $row;
		}

		// Goedkoopste bedrukking in 1 kleur per aantal, over alle posities en technieken.
		$faces = ( is_array( $print ) && ! empty( $print['positions'] ) ) ? MDO_Printdata::faces( $print['positions'] ) : array();
		$techs = $faces ? MDO_Frontend::techniques_for( $faces ) : array();
		$pp    = get_option( 'mdo_printprices', array() );
		$manip = ( ! empty( $print['manipulation'] ) && isset( $pp['manipulations'][ $print['manipulation'] ] ) ) ? (float) $pp['manipulations'][ $print['manipulation'] ] * (float) $s['margin_print'] : 0.0;
		$lead  = MDO_Frontend::lead_table();
		$names = array_merge( (array) get_option( 'mdo_technique_names', array() ), array_filter( (array) get_option( 'mdo_technique_labels', array() ) ) );
		$print_min = array_fill( 0, count( $tiers ), null );
		$prod_min  = array_fill( 0, count( $tiers ), null );
		$t         = array( 'fc' => 0, 'eng' => 0, 'scr' => 0, 'emb' => 0, 'wrap' => 0 );
		foreach ( $faces as $f ) {
			foreach ( $f['positions'] as $pos ) {
				if ( preg_match( '/360|WRAP|AROUND/i', (string) $pos['id'] ) ) {
					$t['wrap'] = 1;
				}
				$area = max( 0.01, (float) $pos['w'] * 0.85 * (float) $pos['h'] * 0.85 / 100 );
				foreach ( (array) $pos['techs'] as $pt ) {
					if ( empty( $techs[ $pt['id'] ] ) ) {
						continue;
					}
					$tech = $techs[ $pt['id'] ];
					$nm   = strtolower( (string) ( $names[ $pt['id'] ] ?? $tech['name'] ) . ' ' . $pt['id'] );
					$fx   = (bool) preg_match( '/laser|graveer|gravure|engrav|blind|deboss|emboss/', $nm );
					if ( $fx ) {
						$t['eng'] = 1;
					}
					if ( ! $fx && 0 === (int) ( $pt['max'] ?? 0 ) ) {
						$t['fc'] = 1;
					}
					if ( preg_match( '/zeef|tampon|screen|pad ?print/', $nm ) ) {
						$t['scr'] = 1;
					}
					if ( preg_match( '/borduur|borduren|embroid/', $nm ) ) {
						$t['emb'] = 1;
					}
					foreach ( $tiers as $i => $q ) {
						list( $u, $su ) = MDO_Pricing::print_cost( $tech, 1, $area, $q, (bool) $s['setup_per_colour'] );
						$c = $u + $su / $q;
						if ( null === $print_min[ $i ] || $c < $print_min[ $i ][0] ) {
							$print_min[ $i ] = array( $c, (string) $tech['name'] );
						}
					}
				}
			}
		}
		$pr = array();
		$ld = array();
		$ex = (int) $s['lead_proof'] + (int) $s['lead_transport'] + (int) $s['lead_margin'];
		foreach ( $tiers as $i => $q ) {
			$min = null;
			foreach ( $colors as $c ) {
				if ( null === $min || $c['p'][ $i ] < $min ) {
					$min = $c['p'][ $i ];
				}
			}
			$prod_min[ $i ] = $min;
			$pr[ $i ]       = round( $min + ( $print_min[ $i ] ? $print_min[ $i ][0] + $manip : 0 ), 4 );
			$ld[ $i ]       = $ex + ( $print_min[ $i ] ? self::prod_days( $lead, $print_min[ $i ][1], $q ) : 0 );
		}
		// Per kleur alleen de afwijking van de goedkoopste kleur bewaren (meestal 0).
		foreach ( $colors as &$c ) {
			$d = array();
			foreach ( $tiers as $i => $q ) {
				$d[ $i ] = round( $c['p'][ $i ] - $prod_min[ $i ], 4 );
			}
			$c['p'] = array_filter( $d ) ? $d : 0;
		}
		unset( $c );

		$spec = (array) $product->get_meta( '_mdo_specs' );
		$mat  = trim( (string) ( $spec['material'] ?? '' ) );
		$sz   = array_keys( $sizes );
		usort( $sz, array( 'MDO_Importer', 'size_cmp' ) );

		$row += array(
			'c'    => array_values( $colors ),
			'pr'   => $pr,
			'pp'   => (bool) $print_min[0],
			'tx'   => MDO_Frontend::tax_factor( $product ),
			'ld'   => $ld,
			't'    => $t,
			'mat'  => $mat,
			'rec'  => preg_match( '/recycl|gerecycl|rpet|\bgrs\b/i', $row['n'] . ' ' . $mat . ' ' . $product->get_short_description() ) ? 1 : 0,
			'gen'  => (string) ( $spec['gender'] ?? '' ),
			'slv'  => (string) ( $spec['sleeve'] ?? '' ),
			'sz'   => $sz,
			'rel'  => (string) $product->get_meta( '_mdo_release' ),
			'last' => (string) $product->get_meta( '_mdo_discontinued' ) ? 1 : 0,
			'hov'  => MDO_Settings::get( 'shop_hover' ) ? MDO_Shop::mark_data( $product ) : null,
			'mo'   => (int) $product->get_menu_order(),
		);
		return $row;
	}

	/** Opgeslagen samenvatting, of opnieuw maken als die verouderd is. */
	public static function row( $id, &$built ) {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			return null;
		}
		$mod  = $product->get_date_modified();
		$mod  = $mod ? $mod->getTimestamp() : 0;
		$have = get_post_meta( $id, self::META, true );
		if ( is_array( $have ) && ( $have['_k'] ?? '' ) === self::key() && (int) ( $have['_m'] ?? -1 ) === $mod ) {
			return $have;
		}
		$built++;
		$row       = self::build( $product );
		$row['_k'] = self::key();
		$row['_m'] = $mod;
		update_post_meta( $id, self::META, $row ); // geen $product->save(): dat zou de wijzigdatum aanpassen
		return $row;
	}

	private static function product_ids( $cat, $tag, $s, $tax = '', $term = 0 ) {
		$args = array(
			'post_type'        => 'product',
			'post_status'      => 'publish',
			'fields'           => 'ids',
			'posts_per_page'   => -1,
			'no_found_rows'    => true,
			'orderby'          => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			'suppress_filters' => false,
			'tax_query'        => array(
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => array( '' !== $s ? 'exclude-from-search' : 'exclude-from-catalog' ),
					'operator' => 'NOT IN',
				),
			),
		);
		if ( $cat ) {
			$args['tax_query'][] = array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array( $cat ), 'include_children' => true );
		}
		if ( $tag ) {
			$args['tax_query'][] = array( 'taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => array( $tag ) );
		}
		if ( $tax && $term ) {
			$args['tax_query'][] = array( 'taxonomy' => $tax, 'field' => 'term_id', 'terms' => array( $term ) );
		}
		if ( '' !== $s ) {
			$args['s']       = $s;
			$args['orderby'] = 'relevance';
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	/** Voorraad en verwachte leveringen per variant, in één keer uit de database. */
	private static function stock_for( array $vids ) {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_unique( $vids ), 800 ) as $chunk ) {
			$in   = implode( ',', array_map( 'intval', $chunk ) );
			$rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key IN ('_stock','_manage_stock','_mdo_arrivals')" ); // phpcs:ignore
			foreach ( $rows as $r ) {
				$out[ (int) $r->post_id ][ $r->meta_key ] = $r->meta_value;
			}
		}
		return $out;
	}

	public static function ajax() {
		$tax = sanitize_key( wp_unslash( $_GET['tax'] ?? '' ) );
		self::send( array(
			'cat'  => absint( $_GET['cat'] ?? 0 ),
			'tag'  => absint( $_GET['tag'] ?? 0 ),
			's'    => mb_substr( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ), 0, 80 ),
			'tax'  => ( $tax && taxonomy_exists( $tax ) && is_object_in_taxonomy( 'product', $tax ) ) ? $tax : '',
			'term' => absint( $_GET['term'] ?? 0 ),
		) );
	}

	public static function send( array $ctx ) {
		$ids   = self::product_ids( (int) $ctx['cat'], (int) $ctx['tag'], mb_substr( (string) $ctx['s'], 0, 80 ), (string) ( $ctx['tax'] ?? '' ), (int) ( $ctx['term'] ?? 0 ) );
		$rows  = array();
		$built = 0;
		$t0    = microtime( true );
		$more  = false;
		foreach ( $ids as $id ) {
			// Niet te lang in één aanvraag: de rest volgt bij de volgende aanvraag.
			if ( $built && microtime( true ) - $t0 > 6 ) {
				$more = true;
				break;
			}
			$r = self::row( $id, $built );
			if ( $r ) {
				unset( $r['_k'], $r['_m'] );
				$rows[] = $r;
			}
		}

		// Voorraad per kleur: som van de maten; null = geen voorraadbeheer.
		$vids = array();
		foreach ( $rows as $r ) {
			foreach ( (array) ( $r['c'] ?? array() ) as $c ) {
				$vids = array_merge( $vids, $c['v'] );
			}
		}
		$st = $vids ? self::stock_for( $vids ) : array();
		foreach ( $rows as &$r ) {
			if ( empty( $r['c'] ) ) {
				continue;
			}
			foreach ( $r['c'] as &$c ) {
				$sum = 0;
				$arr = array();
				foreach ( $c['v'] as $vid ) {
					$m = $st[ $vid ] ?? array();
					if ( 'yes' !== ( $m['_manage_stock'] ?? '' ) ) {
						$sum = null;
					} elseif ( null !== $sum ) {
						$sum += max( 0, (int) ( $m['_stock'] ?? 0 ) );
					}
					foreach ( (array) maybe_unserialize( $m['_mdo_arrivals'] ?? '' ) as $a ) {
						if ( is_array( $a ) && isset( $a[0], $a[1] ) ) {
							$arr[ (string) $a[0] ] = ( $arr[ (string) $a[0] ] ?? 0 ) + (int) $a[1];
						}
					}
				}
				ksort( $arr );
				$c['s'] = $sum;
				$c['a'] = array_map( null, array_keys( $arr ), array_values( $arr ) );
				unset( $c['v'] );
			}
			unset( $c );
		}
		unset( $r );

		nocache_headers();
		wp_send_json( array( 'rows' => $rows, 'more' => $more, 'total' => count( $ids ) ) );
	}
}
