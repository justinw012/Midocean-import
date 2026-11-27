<?php
/**
 * Footer voor Zigcy Lite: logo + korte tekst, shopcategorieën, klantenservice-links en contactgegevens.
 * Vervangt de (lege) footerwidgets van het thema en de vermelding "WordPress Theme: Zigcy Lite".
 * Toont alleen wat bestaat of is ingevuld: geen verzonnen gegevens.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Footer {

	public static function init() {
		if ( is_admin() || ! MDO_Settings::get( 'footer_on' ) ) {
			return;
		}
		add_action( 'wp', function () {
			remove_action( 'zigcy_lite_footer', 'zigcy_lite_footer_widgets', 0 );
		} );
		add_action( 'zigcy_lite_footer', array( __CLASS__, 'render' ), 5 );
		add_filter( 'theme_mod_zigcy_lite_footer_credit_enable', function () {
			return 'off';
		} );
		add_filter( 'body_class', function ( $c ) {
			$c[] = 'mdo-footer-on';
			return $c;
		} );
		add_action( 'wp_enqueue_scripts', function () {
			wp_enqueue_style( 'mdo-brand', MDO_URL . 'assets/brand.css', array(), MDO_VERSION );
		}, 999 );
	}

	private static function page_link( $paths ) {
		foreach ( (array) $paths as $path ) {
			$p = get_page_by_path( $path );
			if ( $p && 'publish' === $p->post_status ) {
				return array( get_the_title( $p ), get_permalink( $p ) );
			}
		}
		return null;
	}

	public static function render() {
		$s = MDO_Settings::all();

		// Kolom: shop (hoofdcategorieën met producten).
		$cats  = array();
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => true, 'orderby' => 'name' ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			if ( ! in_array( $t->slug, array( 'uncategorized', 'geen-categorie' ), true ) ) {
				$cats[] = array( html_entity_decode( $t->name ), get_term_link( $t ) );
			}
		}

		// Kolom: klantenservice (alleen pagina's die bestaan).
		$service = array();
		foreach ( array(
			array( 'verzending', 'verzenden', 'verzending-en-levering', 'levering' ),
			array( 'over', 'over-ons' ),
			array( 'contact' ),
			array( 'faq', 'veelgestelde-vragen' ),
		) as $paths ) {
			$l = self::page_link( $paths );
			if ( $l ) {
				$service[] = $l;
			}
		}
		if ( function_exists( 'wc_terms_and_conditions_page_id' ) && wc_terms_and_conditions_page_id() ) {
			$service[] = array( 'Algemene voorwaarden', get_permalink( wc_terms_and_conditions_page_id() ) );
		}
		if ( get_privacy_policy_url() ) {
			$service[] = array( 'Privacybeleid', get_privacy_policy_url() );
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$service[] = array( 'Mijn account', wc_get_page_permalink( 'myaccount' ) );
			$service[] = array( 'Winkelwagen', wc_get_cart_url() );
		}

		// Kolom: contact (alleen ingevulde velden).
		$contact = array();
		if ( $s['footer_email'] ) {
			$contact[] = '<a href="mailto:' . esc_attr( antispambot( $s['footer_email'] ) ) . '">' . esc_html( antispambot( $s['footer_email'] ) ) . '</a>';
		}
		if ( $s['footer_phone'] ) {
			$contact[] = '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $s['footer_phone'] ) ) . '">' . esc_html( $s['footer_phone'] ) . '</a>';
		}
		if ( $s['footer_address'] ) {
			$contact[] = nl2br( esc_html( $s['footer_address'] ) );
		}
		if ( $s['footer_kvk'] ) {
			$contact[] = 'KvK ' . esc_html( $s['footer_kvk'] );
		}
		if ( $s['footer_btw'] ) {
			$contact[] = 'Btw ' . esc_html( $s['footer_btw'] );
		}
		$contact_page = self::page_link( array( 'contact' ) );
		?>
		<div class="mdo-footer">
			<div class="mdo-ft-brand">
				<?php if ( has_custom_logo() ) : ?><div class="mdo-ft-logo"><?php echo get_custom_logo(); // phpcs:ignore ?></div><?php else : ?><p class="mdo-ft-name"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p><?php endif; ?>
				<?php if ( $s['footer_text'] ) : ?><p><?php echo esc_html( $s['footer_text'] ); ?></p><?php endif; ?>
			</div>
			<?php if ( $cats ) : ?>
			<nav class="mdo-ft-col" aria-label="Shop">
				<h2>Shop</h2>
				<ul><?php foreach ( $cats as $c ) : ?><li><a href="<?php echo esc_url( $c[1] ); ?>"><?php echo esc_html( $c[0] ); ?></a></li><?php endforeach; ?></ul>
			</nav>
			<?php endif; ?>
			<?php if ( $service ) : ?>
			<nav class="mdo-ft-col" aria-label="Klantenservice">
				<h2>Klantenservice</h2>
				<ul><?php foreach ( $service as $c ) : ?><li><a href="<?php echo esc_url( $c[1] ); ?>"><?php echo esc_html( $c[0] ); ?></a></li><?php endforeach; ?></ul>
			</nav>
			<?php endif; ?>
			<?php if ( $contact || $contact_page ) : ?>
			<div class="mdo-ft-col">
				<h2>Contact</h2>
				<ul>
					<?php foreach ( $contact as $c ) : ?><li><?php echo $c; // phpcs:ignore -- hierboven ge-escaped ?></li><?php endforeach; ?>
					<?php if ( ! $contact && $contact_page ) : ?><li><a href="<?php echo esc_url( $contact_page[1] ); ?>">Neem contact op</a></li><?php endif; ?>
				</ul>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
