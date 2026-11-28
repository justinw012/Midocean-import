<?php
/**
 * Kleine verbeteringen aan het thema Zigcy Lite (onder de instelling "Thema-aanpassingen"):
 * Nederlandse teksten in de kop, nette categorielijst, mobiel menu met categorieën,
 * "Home" in plaats van "Thuis", gerelateerde producten als PromoFun-kaarten.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Theme {

	const WORDS = array(
		'All Categories'            => 'Alle categorieën',
		'Browse Categories'         => 'Categorieën',
		'Login/Signup'              => 'Inloggen',
		'Logout'                    => 'Uitloggen',
		'Search'                    => 'Zoeken',
		'search entire store here'  => 'Zoek een product…',
		'There are %1$s products'   => '%1$s producten',
		'There is %1$s product'     => '%1$s product',
	);

	public static function init() {
		if ( is_admin() || ! MDO_Settings::get( 'compact_crumbs' ) ) {
			return;
		}
		add_filter( 'gettext_zigcy-lite', function ( $t, $text ) {
			return self::WORDS[ $text ] ?? $t;
		}, 10, 2 );
		// Categorielijst in de kop: alleen hoofdcategorieën met producten, alfabetisch (i.p.v. alle subcategorieën door elkaar).
		if ( ! function_exists( 'zigcy_lite_add_browse_categories_nav_menu_items' ) ) {
			function zigcy_lite_add_browse_categories_nav_menu_items() { // phpcs:ignore
				MDO_Theme::browse();
			}
		}
		// Categoriekeuze in de zoekbalk: alleen hoofdcategorieën (zoeken in een hoofdcategorie neemt de subcategorieën mee),
		// anders staan namen als "Sets" en "Zaklampen" er meerdere keren in.
		if ( ! function_exists( 'zigcy_lite_product_search' ) ) {
			function zigcy_lite_product_search() { // phpcs:ignore
				return MDO_Theme::search_form();
			}
		}
		add_filter( 'wp_nav_menu_objects', function ( $items ) {
			foreach ( $items as $it ) {
				if ( 'Thuis' === trim( wp_strip_all_tags( $it->title ) ) ) {
					$it->title = 'Home';
				}
			}
			return $items;
		} );
		add_action( 'wp_footer', array( __CLASS__, 'mobile_menu' ), 5 );
		add_action( 'wp', array( __CLASS__, 'related_setup' ), 20 );
	}

	public static function search_form() {
		$cur  = ( is_search() && isset( $_GET['term'] ) ) ? sanitize_title( wp_unslash( $_GET['term'] ) ) : ''; // phpcs:ignore
		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => true, 'orderby' => 'name', 'order' => 'ASC' ) );
		$opts = '<option value="">Alle categorieën</option>';
		foreach ( is_array( $cats ) ? $cats : array() as $c ) {
			if ( 'uncategorized' === $c->slug || 'geen-categorie' === $c->slug ) {
				continue;
			}
			$opts .= '<option value="' . esc_attr( $c->slug ) . '"' . selected( $cur, $c->slug, false ) . '>' . esc_html( $c->name ) . '</option>';
		}
		return '<form role="search" method="get" id="searchform" action="' . esc_url( home_url( '/' ) ) . '">' .
			'<div class="search-wrap"><div class="sm_search_wrap"><select class="sm_search_product false" name="term" aria-label="Categorie">' . $opts . '</select></div>' .
			'<div class="sm_search_form"><input type="text" value="' . esc_attr( get_search_query() ) . '" name="s" id="s" placeholder="Zoek een product…" autocomplete="off"/>' .
			'<button type="submit" id="searchsubmit" aria-label="Zoeken"><i class="fa fa-search"></i></button>' .
			'<input type="hidden" name="post_type" value="product" /><input type="hidden" name="taxonomy" value="product_cat" /></div>' .
			'<div class="search-content"></div></div></form>';
	}

	public static function browse() {
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			return;
		}
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => true, 'orderby' => 'name' ) );
		?>
		<div class="browse-category-wrap">
			<button class="btn-transparent-toggle sml-cat-text-wrap"><i class="lnr lnr-menu"></i><span>Categorieën</span></button>
			<div class="categorylist"><ul>
				<?php foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) : if ( in_array( $t->slug, array( 'uncategorized', 'geen-categorie' ), true ) ) { continue; } ?>
				<li><a href="<?php echo esc_url( get_term_link( $t ) ); ?>"><?php echo esc_html( html_entity_decode( $t->name ) ); ?></a></li>
				<?php endforeach; ?>
				<li class="mdo-cat-all"><a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">Alle producten</a></li>
			</ul></div>
		</div>
		<?php
	}

	/** Mobiel menu: hoofdcategorieën met een foto + de btw-schakelaar, onder het gewone menu. */
	public static function mobile_menu() {
		if ( ! class_exists( 'MDO_Home' ) || ! function_exists( 'wc_get_page_permalink' ) ) {
			return;
		}
		$cats = MDO_Home::categories( 12 );
		$btw  = class_exists( 'MDO_Tax' ) ? MDO_Tax::html() : '';
		if ( ! $cats && ! $btw ) {
			return;
		}
		?>
		<template id="mdo-mobmenu">
			<div class="mdo-mobmenu">
				<?php if ( $cats ) : ?>
				<p class="mdo-mm-title">Categorieën</p>
				<ul class="mdo-mm-cats">
					<?php foreach ( $cats as $c ) : ?>
					<li><a href="<?php echo esc_url( $c[1] ); ?>"><span class="mdo-mm-img"><img src="<?php echo esc_url( $c[2] ); ?>" alt="" loading="lazy"></span><span><?php echo esc_html( $c[0] ); ?></span></a></li>
					<?php endforeach; ?>
				</ul>
				<p class="mdo-mm-all"><a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">Alle producten ›</a></p>
				<?php endif; ?>
				<?php if ( $btw ) : ?><p class="mdo-mm-btw">Prijzen <?php echo $btw; // phpcs:ignore ?></p><?php endif; ?>
			</div>
		</template>
		<script>
		(function () {
			var t = document.getElementById('mdo-mobmenu'), side = document.querySelector('.mob-side-nav-wrapp');
			if (!t || !side || side.querySelector('.mdo-mobmenu')) return;
			var at = side.querySelector('.menu-wrapp-outer');
			var node = t.content.cloneNode(true);
			if (at && at.parentNode) at.parentNode.insertBefore(node, at.nextSibling); else side.appendChild(node);
		})();
		</script>
		<?php
	}

	/** Gerelateerde producten: zelfde kaarten als op de homepage (logo-voorbeeld, kleuren, prijs bij 250 st.). */
	public static function related_setup() {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! class_exists( 'MDO_Home' ) ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product || ! $product->get_meta( '_mdo_master_code' ) ) {
			return;
		}
		remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
		add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'related' ), 20 );
		add_action( 'wp_enqueue_scripts', function () {
			wp_enqueue_style( 'mdo-home', MDO_URL . 'assets/home.css', array(), MDO_VERSION );
		} );
	}

	public static function related() {
		$id  = get_queried_object_id();
		$ids = wc_get_related_products( $id, 4 );
		if ( ! $ids ) {
			return;
		}
		echo '<section class="mdo-home mdo-related"><div class="mdo-h-head"><h2>Ook leuk met jouw logo</h2></div><ul class="products mdo-grid mdo-h-grid">';
		foreach ( $ids as $rid ) {
			echo MDO_Home::card( $rid ); // phpcs:ignore
		}
		echo '</ul></section>';
	}
}
