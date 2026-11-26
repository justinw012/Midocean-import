<?php
/**
 * Klant kiest zelf: prijzen incl. of excl. btw.
 *
 * De keuze staat in een cookie (mdo_btw) en verandert alleen hoe WooCommerce en de plugin prijzen
 * tónen (woocommerce_tax_display_shop / _cart). Wat er afgerekend wordt verandert niet.
 * Werkt ook zonder JavaScript: de knoppen zijn gewone links (?mdo_btw=excl).
 */

defined( 'ABSPATH' ) || exit;

class MDO_Tax {

	const COOKIE = 'mdo_btw';

	public static function init() {
		add_shortcode( 'promofun_btw', array( __CLASS__, 'html' ) );
		if ( ! MDO_Settings::get( 'tax_toggle' ) ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'set' ), 1 );
		foreach ( array( 'woocommerce_tax_display_shop', 'woocommerce_tax_display_cart' ) as $opt ) {
			add_filter( 'option_' . $opt, array( __CLASS__, 'display' ) );
		}
		add_action( 'wp_head', array( __CLASS__, 'css' ) );
		add_action( 'wp_footer', array( __CLASS__, 'js' ), 99 );
	}

	/**
	 * Klikken zonder herladen waar dat kan (configurator, filters luisteren naar 'mdo-btw');
	 * anders de pagina opnieuw laden. De link zelf werkt ook zonder JavaScript.
	 */
	public static function js() {
		?>
		<script>
		(function () {
			document.addEventListener('click', function (e) {
				var a = e.target.closest && e.target.closest('.mdo-btw a');
				if (!a) return;
				var m = /[?&]mdo_btw=(incl|excl)/.exec(a.getAttribute('href') || '');
				if (!m) return;
				e.preventDefault();
				document.cookie = 'mdo_btw=' + m[1] + ';path=/;max-age=31536000;samesite=lax' + (location.protocol === 'https:' ? ';secure' : '');
				var ev = new CustomEvent('mdo-btw', { detail: m[1], cancelable: true });
				if (document.dispatchEvent(ev)) { location.reload(); return; }
				[].forEach.call(document.querySelectorAll('.mdo-btw a'), function (x) {
					var on = (x.getAttribute('href') || '').indexOf('mdo_btw=' + m[1]) >= 0;
					x.classList.toggle('is-on', on);
					if (on) x.setAttribute('aria-current', 'true'); else x.removeAttribute('aria-current');
				});
			});
		})();
		</script>
		<?php
	}

	/** 'incl' of 'excl' gekozen door de bezoeker, of '' als hij niets koos. */
	public static function choice() {
		$c = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		return in_array( $c, array( 'incl', 'excl' ), true ) ? $c : '';
	}

	public static function display( $value ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $value; // beheer: altijd de instelling van de winkel
		}
		$c = self::choice();
		return $c ? $c : $value;
	}

	/** ?mdo_btw=excl: keuze bewaren (1 jaar) en terug naar dezelfde pagina zonder die parameter. */
	public static function set() {
		if ( ! isset( $_GET['mdo_btw'] ) ) {
			return;
		}
		$c = sanitize_key( wp_unslash( $_GET['mdo_btw'] ) );
		if ( in_array( $c, array( 'incl', 'excl' ), true ) ) {
			setcookie( self::COOKIE, $c, array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			) );
			$_COOKIE[ self::COOKIE ] = $c;
		}
		nocache_headers();
		wp_safe_redirect( remove_query_arg( 'mdo_btw' ) );
		exit;
	}

	/** Schakelaar: [promofun_btw] (ook gebruikt in de shop, de configurator en de homepage). */
	public static function html() {
		if ( ! MDO_Settings::get( 'tax_toggle' ) || ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() ) {
			return '';
		}
		$cur = get_option( 'woocommerce_tax_display_shop' ); // via het filter: de keuze van de bezoeker
		$out = '<span class="mdo-btw" role="group" aria-label="Prijzen tonen">';
		foreach ( array( 'incl' => 'incl. btw', 'excl' => 'excl. btw' ) as $k => $label ) {
			$on   = $cur === $k;
			$out .= '<a href="' . esc_url( add_query_arg( 'mdo_btw', $k ) ) . '" rel="nofollow"' . ( $on ? ' class="is-on" aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		return $out . '</span>';
	}

	public static function css() {
		echo '<style id="mdo-btw-css">.mdo-btw{display:inline-flex;border:1.5px solid #D6D3CC;border-radius:999px;padding:2px;background:#fff;font-size:13px;line-height:1;vertical-align:middle;white-space:nowrap}.mdo-btw a{padding:6px 11px;border-radius:999px;color:#16181D!important;text-decoration:none!important;font-weight:500}.mdo-btw a.is-on{background:#16181D;color:#fff!important}.mdo-btw a:not(.is-on):hover{background:#F3F1EC}</style>';
	}
}
