<?php
/**
 * Compacte kop: één smalle regel kruimelpad (+ kleine titel) in plaats van de grote grijze
 * titelbalk van het thema (Zigcy Lite: zigcy_lite_header_title_display in header.php).
 * Op een productpagina komt de productnaam weer naast de foto, zoals in de meeste webshops.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Crumbs {

	public static function init() {
		if ( is_admin() || ! MDO_Settings::get( 'compact_crumbs' ) ) {
			return;
		}
		// Het thema maakt zijn titelbalk alleen als deze functie nog niet bestaat: wij leveren hem eerder.
		if ( ! function_exists( 'zigcy_lite_header_title_display' ) ) {
			function zigcy_lite_header_title_display() { // phpcs:ignore
				MDO_Crumbs::render();
			}
		}
		add_action( 'wp', function () {
			if ( function_exists( 'is_product' ) && is_product() && false === has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title' ) ) {
				add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title', 5 );
			}
		}, 20 );
		add_filter( 'body_class', function ( $c ) {
			$c[] = 'mdo-crumbs-on';
			return $c;
		} );
		add_action( 'wp_enqueue_scripts', function () {
			wp_enqueue_style( 'mdo-brand', MDO_URL . 'assets/brand.css', array(), MDO_VERSION );
		}, 999 );
	}

	/** Kruimels als lijst van array( naam, url ). De laatste is de huidige pagina. */
	private static function items() {
		if ( function_exists( 'is_woocommerce' ) && is_woocommerce() && class_exists( 'WC_Breadcrumb' ) ) {
			$b = new WC_Breadcrumb();
			$b->add_crumb( 'Home', apply_filters( 'woocommerce_breadcrumb_home_url', home_url( '/' ) ) );
			return $b->generate();
		}
		$out = array( array( 'Home', home_url( '/' ) ) );
		if ( is_page() ) {
			foreach ( array_reverse( get_post_ancestors( get_queried_object_id() ) ) as $a ) {
				$out[] = array( get_the_title( $a ), get_permalink( $a ) );
			}
			$out[] = array( get_the_title(), '' );
		} elseif ( is_single() ) {
			$out[] = array( get_the_title(), '' );
		} elseif ( is_search() ) {
			$out[] = array( 'Zoeken', '' );
		} elseif ( is_archive() ) {
			$out[] = array( wp_strip_all_tags( get_the_archive_title() ), '' );
		} elseif ( is_404() ) {
			$out[] = array( 'Pagina niet gevonden', '' );
		}
		return $out;
	}

	private static function title() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			return ''; // staat naast de foto
		}
		if ( function_exists( 'is_woocommerce' ) && is_woocommerce() && ! is_search() ) {
			return woocommerce_page_title( false );
		}
		if ( is_search() ) {
			return sprintf( 'Zoekresultaten voor: %s', get_search_query() );
		}
		if ( is_404() ) {
			return 'Pagina niet gevonden';
		}
		if ( is_archive() ) {
			return wp_strip_all_tags( get_the_archive_title() );
		}
		if ( is_home() ) {
			return single_post_title( '', false );
		}
		return get_the_title();
	}

	public static function render() {
		if ( is_front_page() ) {
			return;
		}
		$items = self::items();
		$title = self::title();
		$n     = count( $items );
		$back  = null;
		for ( $i = $n - 2; $i >= 0; $i-- ) {
			if ( ! empty( $items[ $i ][1] ) ) {
				$back = $items[ $i ];
				break;
			}
		}
		echo '<div class="mdo-crumbs-wrap container">';
		if ( $n > 1 ) {
			echo '<nav class="mdo-crumbs" aria-label="Kruimelpad">';
			if ( $back ) {
				echo '<a class="mdo-crumbs-back" href="' . esc_url( $back[1] ) . '"><span aria-hidden="true">‹</span> ' . esc_html( wp_strip_all_tags( $back[0] ) ) . '</a>';
			}
			echo '<ol>';
			foreach ( $items as $i => $c ) {
				$name = wp_strip_all_tags( $c[0] );
				if ( $i === $n - 1 || empty( $c[1] ) ) {
					echo '<li aria-current="page">' . esc_html( $name ) . '</li>';
				} else {
					echo '<li><a href="' . esc_url( $c[1] ) . '">' . esc_html( $name ) . '</a></li>';
				}
			}
			echo '</ol></nav>';
		}
		if ( '' !== (string) $title ) {
			echo '<h1 class="mdo-crumbs-title page-title">' . esc_html( wp_strip_all_tags( $title ) ) . '</h1>';
		}
		echo '</div>';
	}
}
