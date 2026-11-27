<?php
/**
 * Zoeken met suggesties: tijdens het typen verschijnen categorieën (met filters, bijv. "Mokken · zwart"),
 * producten met foto en prijs, en "Alle resultaten". Werkt op elk zoekveld van de site (name="s").
 * Gegevens via ?mdo_suggest=… op de eigen site (zelfde toegang als de pagina's).
 */

defined( 'ABSPATH' ) || exit;

class MDO_Search {

	/** Woorden die een filter betekenen (shop-adres ?druk=… / ?gerecycled=1). */
	const TECH = array(
		'gravure' => 'eng', 'graveren' => 'eng', 'laser' => 'eng', 'gegraveerd' => 'eng',
		'full colour' => 'fc', 'fullcolour' => 'fc', 'full color' => 'fc', 'kleurendruk' => 'fc', 'foto' => 'fc',
		'zeefdruk' => 'scr', 'tampondruk' => 'scr', 'borduren' => 'emb', 'geborduurd' => 'emb', 'rondom' => 'wrap', '360' => 'wrap',
	);
	const TECH_LABEL = array( 'eng' => 'gravure', 'fc' => 'full colour', 'scr' => 'zeef-/tampondruk', 'emb' => 'borduren', 'wrap' => 'rondom bedrukt' );

	public static function init() {
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp', array( __CLASS__, 'maybe_json' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets() {
		wp_enqueue_script( 'mdo-search', MDO_URL . 'assets/search.js', array(), MDO_VERSION, true );
		wp_localize_script( 'mdo-search', 'MDO_SEARCH', array(
			'url'  => home_url( '/' ),
			'shop' => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ),
		) );
		wp_enqueue_style( 'mdo-brand', MDO_URL . 'assets/brand.css', array(), MDO_VERSION );
	}

	public static function maybe_json() {
		if ( ! isset( $_GET['mdo_suggest'] ) ) {
			return;
		}
		$q = mb_substr( trim( sanitize_text_field( wp_unslash( $_GET['mdo_suggest'] ) ) ), 0, 60 );
		nocache_headers();
		wp_send_json( self::suggest( $q ) );
	}

	private static function norm( $s ) {
		return mb_strtolower( remove_accents( $s ) );
	}

	public static function suggest( $q ) {
		$out = array( 'cats' => array(), 'products' => array(), 'q' => $q );
		if ( mb_strlen( $q ) < 2 ) {
			return $out;
		}
		$text  = ' ' . self::norm( $q ) . ' ';
		$extra = array();
		$label = array();

		// Filterwoorden eruit halen: kleur, bedrukking, gerecycled.
		foreach ( self::TECH as $w => $k ) {
			if ( preg_match( '/(?<![a-z])' . preg_quote( $w, '/' ) . '(?![a-z])/', $text ) ) {
				$extra['druk'] = $k;
				$label[]       = self::TECH_LABEL[ $k ];
				$text          = str_replace( $w, ' ', $text );
			}
		}
		if ( preg_match( '/(?<![a-z])(gerecycled|gerecyclede|recycled|duurzaam|duurzame)(?![a-z])/', $text, $m ) ) {
			$extra['gerecycled'] = '1';
			$label[]             = 'gerecycled';
			$text                = str_replace( $m[1], ' ', $text );
		}
		foreach ( MDO_Filter::GROUPS as $key => $g ) {
			// Ook bijvoeglijke vorm: zwarte, witte, blauwe, rode, groene …
			$forms = array( $key, $key . 'e', rtrim( $key, 'd' ) . 'de', mb_strtolower( $g[0] ) );
			if ( 'wit' === $key ) {
				$forms[] = 'witte';
			}
			if ( 'rood' === $key ) {
				$forms[] = 'rode';
			}
			foreach ( array_unique( $forms ) as $f ) {
				if ( preg_match( '/(?<![a-z])' . preg_quote( $f, '/' ) . '(?![a-z])/', $text ) ) {
					$extra['kleur'] = $key;
					$label[]        = mb_strtolower( $g[0] );
					$text           = preg_replace( '/(?<![a-z])' . preg_quote( $f, '/' ) . '(?![a-z])/', ' ', $text );
					break 2;
				}
			}
		}
		$rest = trim( preg_replace( '/\s+/', ' ', $text ) );

		// Categorieën die op de (overgebleven) woorden lijken; bij lege rest de hele winkel met filter.
		$cats = array();
		if ( '' !== $rest ) {
			$words = array_filter( explode( ' ', $rest ), function ( $w ) {
				return mb_strlen( $w ) >= 3;
			} );
			$seen  = array();
			foreach ( $words ?: array( $rest ) as $w ) {
				$stem  = preg_replace( '/(en|s)$/', '', $w ); // mokken → mok, flessen → fless
				$stem  = mb_strlen( $stem ) >= 3 ? $stem : $w;
				$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'name__like' => $stem, 'number' => 6, 'orderby' => 'count', 'order' => 'DESC' ) );
				foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
					if ( isset( $seen[ $t->term_id ] ) || in_array( $t->slug, array( 'uncategorized', 'geen-categorie' ), true ) ) {
						continue;
					}
					$seen[ $t->term_id ] = 1;
					$parent              = $t->parent ? get_term( $t->parent, 'product_cat' ) : null;
					$cats[]              = array(
						'name'   => html_entity_decode( $t->name ),
						'parent' => ( $parent && ! is_wp_error( $parent ) ) ? html_entity_decode( $parent->name ) : '',
						'count'  => (int) $t->count,
						'url'    => add_query_arg( $extra, get_term_link( $t ) ),
						'filter' => implode( ', ', $label ),
					);
				}
			}
		} elseif ( $extra && function_exists( 'wc_get_page_permalink' ) ) {
			$cats[] = array( 'name' => 'Alle producten', 'parent' => '', 'count' => 0, 'url' => add_query_arg( $extra, wc_get_page_permalink( 'shop' ) ), 'filter' => implode( ', ', $label ) );
		}
		$out['cats'] = array_slice( $cats, 0, 4 );

		// Producten (zoeken op de woorden zonder de filterwoorden; anders op alles).
		$ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			's'              => '' !== $rest ? $rest : $q,
			'posts_per_page' => 6,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( array( 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => array( 'exclude-from-search' ), 'operator' => 'NOT IN' ) ),
		) );
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$src   = wp_get_attachment_image_src( $p->get_image_id(), 'woocommerce_gallery_thumbnail' );
			$price = '';
			if ( $p->get_meta( '_mdo_master_code' ) && class_exists( 'MDO_Filter' ) ) {
				$built = 0;
				$row   = MDO_Filter::row( $id, $built );
				if ( $row && empty( $row['x'] ) && ! empty( $row['pr'] ) ) {
					$i     = array_search( 250, MDO_Frontend::TIERS, true );
					$tx    = 'incl' === get_option( 'woocommerce_tax_display_shop' ) ? (float) ( $row['tx'] ?? 1 ) : 1.0;
					$price = 'Vanaf ' . html_entity_decode( wp_strip_all_tags( wc_price( (float) $row['pr'][ $i ] * $tx ) ) ) . ' p/st';
				}
			}
			if ( '' === $price ) {
				$price = html_entity_decode( wp_strip_all_tags( $p->get_price_html() ) );
			}
			$out['products'][] = array(
				'name'  => html_entity_decode( wp_strip_all_tags( $p->get_name() ) ),
				'url'   => get_permalink( $id ),
				'img'   => $src ? $src[0] : '',
				'price' => $price,
			);
		}
		$out['all'] = add_query_arg( array( 's' => $q, 'post_type' => 'product' ), home_url( '/' ) );
		return $out;
	}
}
