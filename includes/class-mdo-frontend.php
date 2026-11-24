<?php
/**
 * Vervangt op Midocean-producten het standaard bestelblok door de configurator.
 * De rest van de productpagina (thema, galerij, tabbladen) blijft ongewijzigd.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Frontend {
	const TIERS = array( 50, 100, 250, 500, 1000, 2500 );

	public static function init() {
		add_action( 'woocommerce_before_single_product', array( __CLASS__, 'maybe_replace' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'woocommerce_product_tabs', array( __CLASS__, 'specs_tab' ), 20 );
	}

	public static function configurable( $product ) {
		return $product
			&& MDO_Settings::get( 'configurator' )
			&& $product->is_type( 'variable' )
			&& $product->get_meta( '_mdo_master_code' )
			&& $product->get_meta( MDO_Printdata::META )
			&& get_option( 'mdo_printprices' );
	}

	public static function body_class( $classes ) {
		if ( is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( self::configurable( $product ) ) {
				$classes[] = 'mdo-configured';
			}
		}
		return $classes;
	}

	public static function maybe_replace() {
		global $product;
		if ( ! self::configurable( $product ) ) {
			return;
		}
		// Het standaard bestelblok weghalen, ook als het thema het op een andere plek of
		// prioriteit heeft gezet (Zigcy Lite toonde het anders onder de configurator).
		global $wp_filter;
		foreach ( $wp_filter as $hook => $obj ) {
			if ( 0 !== strpos( $hook, 'woocommerce_' ) || ! is_object( $obj ) || empty( $obj->callbacks ) ) {
				continue;
			}
			foreach ( $obj->callbacks as $prio => $cbs ) {
				foreach ( $cbs as $cb ) {
					if ( 'woocommerce_template_single_add_to_cart' === $cb['function'] ) {
						remove_action( $hook, 'woocommerce_template_single_add_to_cart', $prio );
					}
				}
			}
		}
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render' ), 30 );

		// Fotogalerij vervangen door een galerij die per kleur alle foto's toont.
		// (De standaardgalerij en Variation Gallery luisteren naar het oude bestelformulier.)
		remove_all_actions( 'woocommerce_before_single_product_summary', 20 );
		add_action( 'woocommerce_before_single_product_summary', array( __CLASS__, 'render_gallery' ), 20 );
		wp_enqueue_style( 'mdo-configurator', MDO_URL . 'assets/configurator.css', array(), MDO_VERSION );
		wp_enqueue_script( 'mdo-configurator', MDO_URL . 'assets/configurator.js', array(), MDO_VERSION, true );
	}

	/** Verkoopprijzen (al met opslag) van de technieken die dit product gebruikt. */
	private static function techniques_for( array $faces ) {
		$pp    = get_option( 'mdo_printprices', array() );
		$names = array_merge( get_option( 'mdo_technique_names', array() ), array_filter( (array) get_option( 'mdo_technique_labels', array() ) ) );
		$infos = array_filter( (array) get_option( 'mdo_technique_info', array() ) );
		$s     = MDO_Settings::all();
		$out   = array();
		foreach ( $faces as $f ) {
			foreach ( $f['positions'] as $pos ) {
				foreach ( $pos['techs'] as $t ) {
					$id = $t['id'];
					if ( isset( $out[ $id ] ) || empty( $pp['techniques'][ $id ] ) ) {
						continue;
					}
					$tech   = $pp['techniques'][ $id ];
					$ranges = array();
					foreach ( $tech['ranges'] as $r ) {
						$scales = array();
						foreach ( $r[2] as $sc ) {
							$scales[] = array( $sc[0], round( $sc[1] * $s['margin_print'], 4 ), round( $sc[2] * $s['margin_print'], 4 ) );
						}
						$ranges[] = array( $r[0], $r[1] >= 1e9 ? null : $r[1], $scales );
					}
					$out[ $id ] = array(
						'name'   => ! empty( $names[ $id ] ) ? $names[ $id ] : ( $tech['name'] ? $tech['name'] : $id ),
						'info'   => (string) ( $infos[ $id ] ?? '' ),
						'type'   => $tech['type'],
						'next'   => (bool) $tech['next'],
						'setup'  => round( $tech['setup'] * $s['margin_setup'], 2 ),
						'ranges' => $ranges,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Kleurcode van een variant, zoals in de printdata (bijv. "03").
	 * Uit de opgeslagen code, anders uit het artikelnummer: AR1804-03 → 03, S01194-BK-L → BK.
	 */
	public static function color_code( $v, $master ) {
		$code = (string) $v->get_meta( '_mdo_color_code' );
		if ( '' !== $code ) {
			return $code;
		}
		$sku = (string) $v->get_sku();
		if ( $master && 0 === stripos( $sku, $master . '-' ) ) {
			$rest = substr( $sku, strlen( $master ) + 1 );
			$part = explode( '-', $rest );
			return (string) $part[0];
		}
		return '';
	}

	public static function config( $product ) {
		$s      = MDO_Settings::all();
		$print  = $product->get_meta( MDO_Printdata::META );
		$faces  = MDO_Printdata::faces( $print['positions'] );
		$pp     = get_option( 'mdo_printprices', array() );
		$manip  = ( $print['manipulation'] && isset( $pp['manipulations'][ $print['manipulation'] ] ) ) ? $pp['manipulations'][ $print['manipulation'] ] : 0;
		$colors = array();
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || ! $v->is_purchasable() ) {
				continue;
			}
			$scales = $v->get_meta( '_mdo_scales' );
			if ( ! $scales ) {
				continue;
			}
			$sell = array();
			foreach ( $scales as $sc ) {
				$sell[] = array( (int) $sc[0], round( $sc[1] * $s['margin_product'], 4 ) );
			}
			$img      = wp_get_attachment_image_src( $v->get_image_id(), 'woocommerce_single' );
			$thumb    = wp_get_attachment_image_src( $v->get_image_id(), 'woocommerce_gallery_thumbnail' );
			$colors[] = array(
				'id'      => $vid,
				'sku'     => $v->get_sku(),
				'name'    => wp_strip_all_tags( wc_get_formatted_variation( $v, true, false, false ) ),
				'code'    => self::color_code( $v, $product->get_meta( '_mdo_master_code' ) ),
				'attrs'   => $v->get_variation_attributes(),
				'image'   => $img ? $img[0] : '',
				'thumb'   => $thumb ? $thumb[0] : '',
				'gallery' => self::gallery_for( $v, $product ),
				'stock'   => $v->managing_stock() ? (int) $v->get_stock_quantity() : null,
				'backorders' => $v->backorders_allowed(),
				'scales'  => $sell,
			);
		}
		$out_faces = array();
		foreach ( $faces as $f ) {
			$positions = array();
			foreach ( $f['positions'] as $pos ) {
				$positions[] = array(
					'id'     => $pos['id'],
					'w'      => $pos['w'],
					'h'      => $pos['h'],
					'box'    => $pos['box'],
					'shape'  => isset( $pos['type'] ) ? (string) $pos['type'] : '',
					'techs'  => $pos['techs'],
					'images' => $pos['images'],
				);
			}
			$out_faces[] = array( 'key' => $f['key'], 'label' => $f['label'], 'positions' => $positions );
		}
		return array(
			'productId'      => $product->get_id(),
			'colors'         => $colors,
			'faces'          => $out_faces,
			'techniques'     => self::techniques_for( $faces ),
			'manip'          => round( $manip * $s['margin_print'], 4 ),
			'setupPerColour' => (bool) $s['setup_per_colour'],
			'tiers'          => self::TIERS,
			'currency'       => html_entity_decode( get_woocommerce_currency_symbol() ),
			'decimalSep'     => wc_get_price_decimal_separator(),
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'mdo_logo' ),
		);
	}

	/** Alle foto's van een kleur: hoofdfoto + extra foto's (Variation Gallery), anders die van het product. */
	public static function gallery_for( $v, $product ) {
		$ids = array();
		if ( $v->get_image_id() ) {
			$ids[] = (int) $v->get_image_id();
		}
		$extra = get_post_meta( $v->get_id(), 'woo_variation_gallery_images', true );
		if ( is_array( $extra ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $extra ) );
		}
		if ( count( $ids ) < 2 ) {
			if ( ! $ids && $product->get_image_id() ) {
				$ids[] = (int) $product->get_image_id();
			}
			$ids = array_merge( $ids, array_map( 'intval', $product->get_gallery_image_ids() ) );
		}
		$out = array();
		foreach ( array_values( array_unique( array_filter( $ids ) ) ) as $id ) {
			$large = wp_get_attachment_image_src( $id, 'woocommerce_single' );
			$full  = wp_get_attachment_image_src( $id, 'full' );
			$thumb = wp_get_attachment_image_src( $id, 'woocommerce_gallery_thumbnail' );
			if ( $large ) {
				$out[] = array(
					'large' => $large[0],
					'full'  => $full ? $full[0] : $large[0],
					'thumb' => $thumb ? $thumb[0] : $large[0],
					'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				);
			}
		}
		return $out;
	}

	public static function render_gallery() {
		global $product;
		$img = wp_get_attachment_image_src( $product->get_image_id(), 'woocommerce_single' );
		echo '<div class="images mdo-gallery" id="mdo-gallery">';
		if ( $img ) {
			echo '<div class="mdo-gallery-main"><img src="' . esc_url( $img[0] ) . '" alt="' . esc_attr( $product->get_name() ) . '"></div>';
		}
		echo '</div>';
	}

	public static function render() {
		global $product;
		$cfg = self::config( $product );
		if ( ! $cfg['colors'] ) {
			woocommerce_template_single_add_to_cart();
			return;
		}
		?>
		<form class="mdo-form cart" method="post" enctype="multipart/form-data" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>">
			<div id="mdo-configurator" class="mdo" data-config="<?php echo esc_attr( wp_json_encode( $cfg ) ); ?>">
				<p class="mdo-loading">Configurator laden…</p>
			</div>
			<input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>">
			<input type="hidden" name="product_id" value="<?php echo esc_attr( $product->get_id() ); ?>">
			<input type="hidden" name="variation_id" value="">
			<input type="hidden" name="quantity" value="100">
			<input type="hidden" name="mdo_config" value="">
			<?php wp_nonce_field( 'mdo_add', 'mdo_nonce' ); ?>
			<noscript><p>Zet JavaScript aan om dit product samen te stellen.</p></noscript>
		</form>
		<?php
	}

	/** Tabblad "Specificaties & downloads": materiaal, afmetingen, gewicht en de pdf's van Midocean. */
	public static function specs_tab( $tabs ) {
		global $product;
		if ( ! $product || ! $product->get_meta( '_mdo_master_code' ) ) {
			return $tabs;
		}
		if ( self::specs_rows( $product ) || $product->get_meta( '_mdo_documents' ) ) {
			$tabs['mdo_specs'] = array(
				'title'    => 'Specificaties &amp; downloads',
				'priority' => 15,
				'callback' => array( __CLASS__, 'specs_html' ),
			);
		}
		return $tabs;
	}

	private static function specs_rows( $product ) {
		$s    = (array) $product->get_meta( '_mdo_specs' );
		$rows = array();
		if ( ! empty( $s['material'] ) ) {
			$rows['Materiaal'] = $s['material'];
		}
		if ( ! empty( $s['dimensions'] ) ) {
			$rows['Afmetingen'] = $s['dimensions'];
		}
		if ( $product->get_weight() ) {
			$rows['Gewicht'] = wc_format_weight( $product->get_weight() );
		}
		if ( ! empty( $s['origin'] ) ) {
			$c = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
			$rows['Land van herkomst'] = $c[ strtoupper( $s['origin'] ) ] ?? $s['origin'];
		}
		return $rows;
	}

	/** Leesbare naam voor een documentsoort van Midocean. */
	public static function doc_label( $subtype ) {
		$t = strtolower( (string) $subtype );
		// Soorten zoals Midocean ze levert, specifiek eerst.
		$map = array(
			'declaration_of_conformity'     => 'Conformiteitsverklaring (CE)',
			'declaration_of_sustainability' => 'Duurzaamheidsverklaring',
			'test_reach'                    => 'Testrapport REACH (chemische stoffen)',
			'test_fca'                      => 'Testrapport voedselcontact',
			'test_performance'              => 'Testrapport prestaties',
			'size_chart'                    => 'Maattabel',
			'manual'                        => 'Handleiding',
			'conform'                       => 'Conformiteitsverklaring',
			'sustainab'                     => 'Duurzaamheidsverklaring',
			'reach'                         => 'Testrapport REACH',
			'fca'                           => 'Testrapport voedselcontact',
			'test'                          => 'Testrapport',
			'instruction'                   => 'Handleiding',
			'safety'                        => 'Veiligheidsinformatie',
			'certificat'                    => 'Certificaat',
		);
		foreach ( $map as $k => $label ) {
			if ( false !== strpos( $t, $k ) ) {
				return $label;
			}
		}
		return ucfirst( trim( str_replace( array( '_', '-' ), ' ', $t ) ) ) ?: 'Document';
	}

	public static function specs_html() {
		global $product;
		$rows = self::specs_rows( $product );
		$docs = (array) $product->get_meta( '_mdo_documents' );
		echo '<div class="mdo-specs">';
		if ( $rows ) {
			echo '<table class="woocommerce-product-attributes shop_attributes"><tbody>';
			foreach ( $rows as $k => $v ) {
				echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( $docs ) {
			echo '<h3 style="font-size:16px;margin:18px 0 8px">Downloads</h3><ul class="mdo-docs">';
			foreach ( $docs as $sub => $url ) {
				echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener nofollow">' . esc_html( self::doc_label( $sub ) ) . '</a> <span style="color:#646970">(pdf)</span></li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}
}
