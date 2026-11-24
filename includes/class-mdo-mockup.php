<?php
/**
 * Mockup maken: kies een product, zet een logo of tekst erop en download een realistische afbeelding.
 * Alles gebeurt in de browser; alleen de productfoto's van Midocean gaan via de eigen server
 * (anders mag de browser de afbeelding niet als PNG opslaan).
 */

defined( 'ABSPATH' ) || exit;

class MDO_Mockup {

	const SLUG = 'promofun-mockup';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 65 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_mdo_mock_search', array( __CLASS__, 'search' ) );
		add_action( 'wp_ajax_mdo_mock_product', array( __CLASS__, 'product' ) );
		add_action( 'wp_ajax_mdo_mock_img', array( __CLASS__, 'img' ) );
	}

	public static function menu() {
		add_submenu_page( MDO_Dashboard::SLUG, 'Mockup maken', 'Mockup maken', 'manage_woocommerce', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'mdo-configurator', MDO_URL . 'assets/configurator.css', array(), MDO_VERSION );
		wp_enqueue_style( 'mdo-mockup', MDO_URL . 'assets/mockup.css', array(), MDO_VERSION );
		wp_enqueue_script( 'mdo-mockup', MDO_URL . 'assets/mockup.js', array(), MDO_VERSION, true );
		wp_localize_script( 'mdo-mockup', 'MDO_MOCK', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'mdo_mock' ),
		) );
	}

	private static function check() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'mdo_mock', 'nonce', false ) ) {
			wp_send_json_error( 'Geen toegang.', 403 );
		}
	}

	/** Producten met Midocean-gegevens zoeken op naam of artikelnummer. */
	public static function search() {
		self::check();
		$q   = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$ids = array();
		if ( strlen( $q ) >= 2 ) {
			$ids = get_posts( array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				's'              => $q,
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'meta_query'     => array( array( 'key' => '_mdo_master_code', 'compare' => 'EXISTS' ) ),
			) );
			$by_code = get_posts( array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'meta_query'     => array( array( 'key' => '_mdo_master_code', 'value' => $q, 'compare' => 'LIKE' ) ),
			) );
			$ids = array_slice( array_values( array_unique( array_merge( $by_code, $ids ) ) ), 0, 20 );
		}
		$out = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$t     = wp_get_attachment_image_src( $p->get_image_id(), 'thumbnail' );
			$out[] = array(
				'id'    => $id,
				'name'  => $p->get_name(),
				'code'  => (string) $p->get_meta( '_mdo_master_code' ),
				'thumb' => $t ? $t[0] : '',
			);
		}
		wp_send_json_success( $out );
	}

	/** Kleuren met foto's en drukposities (met drukvlak op de foto) van één product. */
	public static function product() {
		self::check();
		$product = wc_get_product( absint( $_GET['id'] ?? 0 ) );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			wp_send_json_error( 'Product niet gevonden.' );
		}
		$master = (string) $product->get_meta( '_mdo_master_code' );
		$print  = $product->get_meta( MDO_Printdata::META );
		$colors = array();
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v ) {
				continue;
			}
			$photos = array();
			foreach ( MDO_Frontend::gallery_for( $v, $product ) as $g ) {
				$photos[] = self::proxied( $g['full'] );
			}
			$colors[] = array(
				'name'   => wp_strip_all_tags( wc_get_formatted_variation( $v, true, false, false ) ),
				'code'   => MDO_Frontend::color_code( $v, $master ),
				'sku'    => $v->get_sku(),
				'photos' => $photos,
			);
		}
		$positions = array();
		if ( is_array( $print ) && ! empty( $print['positions'] ) ) {
			foreach ( $print['positions'] as $pos ) {
				$imgs = array();
				foreach ( (array) $pos['images'] as $code => $url ) {
					$imgs[ $code ] = self::proxied( $url );
				}
				$positions[] = array(
					'id'     => $pos['id'],
					'label'  => MDO_Printdata::label( $pos['id'] ),
					'w'      => $pos['w'],
					'h'      => $pos['h'],
					'box'    => $pos['box'],
					'shape'  => isset( $pos['type'] ) ? (string) $pos['type'] : '',
					'images' => $imgs,
				);
			}
		}
		wp_send_json_success( array(
			'name'      => $product->get_name(),
			'diameter'  => MDO_Frontend::diameter( $product ),
			'colors'    => $colors,
			'positions' => $positions,
		) );
	}

	/** Externe foto's via de eigen server laten lopen (zelfde domein = te downloaden als PNG). */
	private static function proxied( $url ) {
		$url  = (string) $url;
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host || wp_parse_url( home_url(), PHP_URL_HOST ) === $host ) {
			return $url;
		}
		return add_query_arg( array(
			'action' => 'mdo_mock_img',
			'nonce'  => wp_create_nonce( 'mdo_mock' ),
			'u'      => rawurlencode( $url ),
		), admin_url( 'admin-ajax.php' ) );
	}

	public static function img() {
		self::check();
		$url  = esc_url_raw( rawurldecode( wp_unslash( $_GET['u'] ?? '' ) ) );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		// Alleen foto's van Midocean.
		if ( ! $host || ! preg_match( '/(^|\.)midocean\.com$/', $host ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			status_header( 400 );
			exit;
		}
		$file = MDO_Settings::cache_dir( 'img' ) . md5( $url );
		if ( ! file_exists( $file ) ) {
			$r = wp_safe_remote_get( $url, array( 'timeout' => 20 ) );
			$ct = (string) wp_remote_retrieve_header( $r, 'content-type' );
			if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) || 0 !== strpos( $ct, 'image/' ) ) {
				status_header( 502 );
				exit;
			}
			file_put_contents( $file, wp_remote_retrieve_body( $r ) ); // phpcs:ignore
			file_put_contents( $file . '.type', $ct ); // phpcs:ignore
		}
		$ct = file_exists( $file . '.type' ) ? trim( (string) file_get_contents( $file . '.type' ) ) : 'image/jpeg';
		nocache_headers();
		header( 'Content-Type: ' . ( 0 === strpos( $ct, 'image/' ) ? $ct : 'image/jpeg' ) );
		header( 'Cache-Control: private, max-age=86400' );
		readfile( $file ); // phpcs:ignore
		exit;
	}

	public static function page() {
		?>
		<div class="wrap mdo-mock">
			<h1>Mockup maken</h1>
			<p class="description">Kies een product, zet er een logo of tekst op en download de afbeelding, bijvoorbeeld voor een offerte of social media. Je logo blijft op je eigen computer; er wordt niets geüpload.</p>
			<div class="mdo-mock-grid">
				<div class="mdo-mock-side">
					<label class="mdo-mock-lbl" for="mdo-mock-q">1. Product</label>
					<input type="search" id="mdo-mock-q" class="regular-text" placeholder="Zoek op naam of artikelnummer (bijv. MO9439)" autocomplete="off">
					<div id="mdo-mock-results" class="mdo-mock-results"></div>
					<div id="mdo-mock-form" hidden>
						<p id="mdo-mock-name" class="mdo-mock-name"></p>
						<label class="mdo-mock-lbl" for="mdo-mock-color">2. Kleur</label>
						<select id="mdo-mock-color"></select>
						<label class="mdo-mock-lbl">3. Foto</label>
						<div id="mdo-mock-photos" class="mdo-mock-photos"></div>
						<label class="mdo-mock-lbl">4. Opdruk</label>
						<div class="mdo-mock-tabs"><button type="button" class="button is-on" data-mode="logo">Logo</button><button type="button" class="button" data-mode="text">Tekst</button></div>
						<div data-show="logo"><input type="file" id="mdo-mock-file" accept="image/png,image/jpeg,image/svg+xml,image/webp"><p class="description">PNG, JPG of SVG. Tip: een PNG met transparante achtergrond werkt het mooist.</p></div>
						<div data-show="text" hidden>
							<input type="text" id="mdo-mock-text" class="regular-text" value="Jouw tekst">
							<select id="mdo-mock-font"></select>
							<input type="color" id="mdo-mock-ink" value="#1e1e1e" title="Tekstkleur">
						</div>
						<label class="mdo-mock-lbl" for="mdo-mock-size">Grootte</label>
						<input type="range" id="mdo-mock-size" min="3" max="100" value="35">
						<label class="mdo-mock-lbl">Draaien</label>
						<div class="mdo-mock-rot"><button type="button" class="button is-on" data-rot="0">0°</button><button type="button" class="button" data-rot="90">90°</button><button type="button" class="button" data-rot="180">180°</button><button type="button" class="button" data-rot="270">270°</button></div>
						<label class="mdo-mock-lbl" for="mdo-mock-dia">Diameter (mm) – voor ronde producten</label>
						<input type="number" id="mdo-mock-dia" min="0" step="1" placeholder="bijv. 80" style="width:120px">
						<label class="mdo-mock-lbl" for="mdo-mock-curve">Rondom (kromming)</label>
						<input type="range" id="mdo-mock-curve" min="0" max="85" value="0">
						<p class="description" id="mdo-mock-curve-hint">0 = plat. Vul de diameter in, dan wordt de kromming exact berekend.</p>
						<label class="mdo-mock-lbl" for="mdo-mock-arc">Boog (bovenaanzicht)</label>
						<input type="range" id="mdo-mock-arc" min="-10" max="10" value="3">
						<label class="mdo-mock-lbl" for="mdo-mock-opacity">Dekking</label>
						<input type="range" id="mdo-mock-opacity" min="30" max="100" value="95">
						<label><input type="checkbox" id="mdo-mock-blend" checked> Mengen met de foto (glans en structuur blijven zichtbaar)</label><br>
						<label><input type="checkbox" id="mdo-mock-effect"> Gegraveerd effect</label><br>
						<label><input type="checkbox" id="mdo-mock-box" checked> Drukvlak tonen (niet in de download)</label>
						<p class="description">Sleep op de foto om de opdruk te verplaatsen. Sleep een hoek van het drukvlak om het vlak aan te passen.</p>
						<p><button type="button" class="button button-primary" id="mdo-mock-dl">Download PNG</button> <button type="button" class="button" id="mdo-mock-reset">Opdruk centreren</button></p>
					</div>
				</div>
				<div class="mdo-mock-stage"><canvas id="mdo-mock-canvas" width="800" height="800"></canvas><p id="mdo-mock-msg" class="description">Kies eerst een product.</p></div>
			</div>
		</div>
		<?php
	}
}
