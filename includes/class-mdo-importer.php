<?php
/**
 * Maakt of werkt één WooCommerce-product bij vanuit de cache van de laatste sync.
 * Bestaande producten (bijv. eerder via WP All Import geïmporteerd) worden herkend
 * op het artikelnummer van de varianten.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Importer {

	private static $cache = array();

	private static function cache_json( $name ) {
		if ( ! isset( self::$cache[ $name ] ) ) {
			$f                    = MDO_Settings::cache_dir() . $name;
			self::$cache[ $name ] = file_exists( $f ) ? json_decode( file_get_contents( $f ), true ) : array();
		}
		return is_array( self::$cache[ $name ] ) ? self::$cache[ $name ] : array();
	}

	private static function find_parent( array $p ) {
		$q = get_posts( array(
			'post_type'   => 'product',
			'post_status' => 'any',
			'meta_key'    => '_mdo_master_code',
			'meta_value'  => $p['master_code'],
			'fields'      => 'ids',
			'numberposts' => 1,
		) );
		if ( $q ) {
			return (int) $q[0];
		}
		foreach ( $p['variants'] as $v ) {
			$id = wc_get_product_id_by_sku( $v['sku'] );
			if ( $id ) {
				$parent = wp_get_post_parent_id( $id );
				return $parent ? (int) $parent : (int) $id;
			}
		}
		$id = wc_get_product_id_by_sku( $p['master_code'] );
		return $id ? (int) $id : 0;
	}

	/** Bestaande kleur-eigenschap van het product hergebruiken, anders pa_kleur of een lokale "Kleur". */
	private static function colour_attribute( $product ) {
		if ( $product ) {
			foreach ( $product->get_attributes() as $key => $attr ) {
				if ( false !== stripos( $key, 'kleur' ) || false !== stripos( $key, 'color' ) || false !== stripos( $key, 'colour' ) ) {
					return array( 'key' => $key, 'name' => $attr->get_name(), 'taxonomy' => $attr->is_taxonomy() );
				}
			}
		}
		if ( taxonomy_exists( 'pa_kleur' ) ) {
			return array( 'key' => 'pa_kleur', 'name' => 'pa_kleur', 'taxonomy' => true );
		}
		return array( 'key' => 'kleur', 'name' => 'Kleur', 'taxonomy' => false );
	}

	private static function category_ids( $path ) {
		$ids    = array();
		$parent = 0;
		foreach ( array_filter( array_map( 'trim', explode( '>', $path ) ) ) as $name ) {
			$term = get_term_by( 'name', $name, 'product_cat' );
			if ( $term && (int) $term->parent !== $parent ) {
				$found = get_terms( array( 'taxonomy' => 'product_cat', 'name' => $name, 'parent' => $parent, 'hide_empty' => false ) );
				$term  = ( ! is_wp_error( $found ) && $found ) ? $found[0] : null;
			}
			if ( ! $term ) {
				$new = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
				if ( is_wp_error( $new ) ) {
					break;
				}
				$term = get_term( $new['term_id'], 'product_cat' );
			}
			$ids[]  = (int) $term->term_id;
			$parent = (int) $term->term_id;
		}
		return $ids;
	}

	private static function colour_label( array $v ) {
		$name = $v['color_name'] ? $v['color_name'] : ( $v['color_group'] ? $v['color_group'] : $v['color_code'] );
		return $name ? $name : $v['sku'];
	}

	/** Productnaam volgens de instelling, bijv. "Matglazen fles 500 ml – ABE". */
	public static function title( array $p, $format = null ) {
		$format = $format ? $format : MDO_Settings::get( 'name_format' );
		$short  = trim( rtrim( trim( wp_strip_all_tags( $p['short'] ) ), '.' ) );
		$name   = trim( $p['name'] );
		if ( '' === $short ) {
			return $name ? $name : ( $p['master_code'] ?? '' );
		}
		if ( '' === $name ) {
			return $short;
		}
		switch ( $format ) {
			case 'short':
				return $short;
			case 'name':
				return $name;
			case 'name_short':
				return $name . ' – ' . $short;
			default:
				return $short . ' – ' . $name;
		}
	}

	public static function import_product( $master ) {
		$file = MDO_Settings::cache_dir( 'products' ) . sanitize_file_name( $master ) . '.json';
		if ( ! file_exists( $file ) ) {
			return;
		}
		$p      = json_decode( file_get_contents( $file ), true );
		$prices = self::cache_json( 'prices.json' );
		$stock  = self::cache_json( 'stock-selected.json' );
		$arr    = self::cache_json( 'stock-arrivals.json' );
		$pf     = MDO_Settings::cache_dir( 'print' ) . sanitize_file_name( $master ) . '.json';
		$print  = file_exists( $pf ) ? json_decode( file_get_contents( $pf ), true ) : null;
		$s      = MDO_Settings::all();

		$id      = self::find_parent( $p );
		$is_new  = ! $id;
		$product = $id ? wc_get_product( $id ) : null;
		if ( $product && ! $product->is_type( 'variable' ) ) {
			MDO_Log::error( $master . ': bestaand product is geen variabel product; overgeslagen.' );
			return;
		}
		if ( ! $product ) {
			$product = new WC_Product_Variable();
			$product->set_status( $s['new_status'] );
			if ( ! wc_get_product_id_by_sku( $p['master_code'] ) ) {
				$product->set_sku( $p['master_code'] );
			}
		}
		$title = self::title( $p );
		if ( $is_new || $s['overwrite_texts'] || $s['rename_existing'] ) {
			if ( $product->get_name() !== $title ) {
				$product->set_name( $title );
				if ( $is_new || $s['update_slug'] ) {
					$product->set_slug( sanitize_title( $title ) );
				}
			}
		}
		if ( $is_new || $s['overwrite_texts'] ) {
			$product->set_description( wp_kses_post( wpautop( $p['long'] ) ) );
			$product->set_short_description( wp_kses_post( $p['short'] ) );
		}
		if ( $p['variants'] ) {
			$cats = self::category_ids( $p['variants'][0]['category'] );
			if ( $cats && ( $is_new || ! $product->get_category_ids() ) ) {
				$product->set_category_ids( $cats );
			}
		}
		if ( $p['weight'] ) {
			$product->set_weight( $p['weight'] );
		}
		if ( $p['length'] ) {
			$factor = ( 'mm' === $p['size_unit'] ) ? 0.1 : 1;
			$product->set_length( $p['length'] * $factor );
			$product->set_width( $p['width'] * $factor );
			$product->set_height( $p['height'] * $factor );
		}

		// Kleur-eigenschap met alle kleuren.
		$ca      = self::colour_attribute( $is_new ? null : $product );
		$options = array();
		foreach ( $p['variants'] as $v ) {
			$options[ $v['sku'] ] = self::colour_label( $v );
		}
		$attr = new WC_Product_Attribute();
		if ( $ca['taxonomy'] ) {
			$term_ids = array();
			foreach ( array_unique( $options ) as $label ) {
				$t = term_exists( $label, $ca['key'] );
				if ( ! $t ) {
					$t = wp_insert_term( $label, $ca['key'] );
				}
				if ( ! is_wp_error( $t ) ) {
					$term_ids[] = (int) ( is_array( $t ) ? $t['term_id'] : $t );
				}
			}
			$attr->set_id( wc_attribute_taxonomy_id_by_name( $ca['key'] ) );
			$attr->set_name( $ca['key'] );
			$attr->set_options( $term_ids );
		} else {
			$attr->set_name( $ca['name'] );
			$attr->set_options( array_values( array_unique( $options ) ) );
		}
		$attr->set_visible( true );
		$attr->set_variation( true );
		$attrs                 = $product->get_attributes();
		$attrs[ $ca['key'] ]   = $attr;
		// Kleding: tweede eigenschap "Maat".
		$sizes = array_values( array_unique( array_filter( wp_list_pluck( $p['variants'], 'size' ) ) ) );
		$sa    = null;
		if ( $sizes ) {
			usort( $sizes, array( 'MDO_Importer', 'size_cmp' ) );
			$sa    = taxonomy_exists( 'pa_maat' ) ? array( 'key' => 'pa_maat', 'taxonomy' => true ) : array( 'key' => 'maat', 'taxonomy' => false );
			$sattr = new WC_Product_Attribute();
			if ( $sa['taxonomy'] ) {
				$ids = array();
				foreach ( $sizes as $sz ) {
					$t = term_exists( $sz, 'pa_maat' );
					if ( ! $t ) {
						$t = wp_insert_term( $sz, 'pa_maat' );
					}
					if ( ! is_wp_error( $t ) ) {
						$tid   = (int) ( is_array( $t ) ? $t['term_id'] : $t );
						$ids[] = $tid;
						// Volgorde XS, S, M, L, XL, 3XL in plaats van alfabetisch (3XL vooraan).
						update_term_meta( $tid, 'order', self::size_rank( $sz ) );
					}
				}
				self::size_attribute_custom_order();
				$sattr->set_id( wc_attribute_taxonomy_id_by_name( 'pa_maat' ) );
				$sattr->set_name( 'pa_maat' );
				$sattr->set_options( $ids );
			} else {
				$sattr->set_name( 'Maat' );
				$sattr->set_options( $sizes );
			}
			$sattr->set_visible( true );
			$sattr->set_variation( true );
			$attrs[ $sa['key'] ] = $sattr;
		}
		$product->set_attributes( $attrs );
		// Verbergen in de winkel als de categorie dat vraagt; terugzetten als dat niet meer zo is.
		$hide = $p['variants'] && MDO_Sync::in_list( $p['variants'][0]['category'], $s['hidden_categories'] );
		if ( $hide ) {
			if ( 'hidden' !== $product->get_catalog_visibility() ) {
				$product->set_catalog_visibility( 'hidden' );
				$product->update_meta_data( '_mdo_hidden', 1 );
			}
		} elseif ( $product->get_meta( '_mdo_hidden' ) ) {
			$product->set_catalog_visibility( 'visible' );
			$product->delete_meta_data( '_mdo_hidden' );
		}
		$product->update_meta_data( '_mdo_master_code', $p['master_code'] );
		$product->update_meta_data( '_mdo_documents', $p['documents'] );
		$product->update_meta_data( '_mdo_specs', array(
			'material'   => $p['material'],
			'dimensions' => $p['dimensions'],
			'origin'     => $p['origin'] ?? '',
			'packaging'  => $p['packaging'] ?? '',
			'gender'     => $p['gender'] ?? '',
			'sleeve'     => $p['sleeve'] ?? '',
			'inner_qty'  => $p['inner_qty'] ?? 0,
			'outer_qty'  => $p['outer_qty'] ?? 0,
		) );
		// Nieuw / op = op: vroegste uitkomstdatum en of er varianten uit de collectie gaan.
		$rel  = array_filter( wp_list_pluck( $p['variants'], 'release' ) );
		$disc = array_filter( wp_list_pluck( $p['variants'], 'discontinued' ) );
		$product->update_meta_data( '_mdo_release', $rel ? min( $rel ) : '' );
		$product->update_meta_data( '_mdo_discontinued', $disc && count( $disc ) === count( $p['variants'] ) ? min( $disc ) : '' );
		if ( ! empty( $p['commercial'] ) ) {
			$product->update_meta_data( '_mdo_commercial', $p['commercial'] );
		}
		if ( $print ) {
			$product->update_meta_data( MDO_Printdata::META, $print );
		}
		$parent_id = $product->save();

		// Varianten.
		$attr_key = $ca['taxonomy'] ? $ca['key'] : sanitize_title( $ca['name'] );
		$count    = 0;
		foreach ( $p['variants'] as $v ) {
			$vid = wc_get_product_id_by_sku( $v['sku'] );
			$var = $vid ? wc_get_product( $vid ) : null;
			if ( $var && ! $var->is_type( 'variation' ) ) {
				continue;
			}
			if ( ! $var ) {
				$var = new WC_Product_Variation();
				$var->set_parent_id( $parent_id );
				$var->set_sku( $v['sku'] );
			}
			$label = $options[ $v['sku'] ];
			if ( $ca['taxonomy'] ) {
				$term  = get_term_by( 'name', $label, $ca['key'] );
				$value = $term ? $term->slug : sanitize_title( $label );
			} else {
				$value = $label;
			}
			$va = array( $attr_key => $value );
			if ( $sa && $v['size'] ) {
				if ( $sa['taxonomy'] ) {
					$st_term = get_term_by( 'name', $v['size'], 'pa_maat' );
					$va['pa_maat'] = $st_term ? $st_term->slug : sanitize_title( $v['size'] );
				} else {
					$va['maat'] = $v['size'];
				}
			}
			$var->set_attributes( $va );
			$var->update_meta_data( '_mdo_size', $v['size'] );

			$scales = isset( $prices[ $v['sku'] ] ) ? $prices[ $v['sku'] ] : null;
			if ( $scales ) {
				$var->set_regular_price( wc_format_decimal( MDO_Frontend::to_store_price( $scales[0][1] * (float) $s['margin_product'], $product ), 2 ) );
				$var->update_meta_data( '_mdo_scales', $scales );
			}
			if ( isset( $stock[ $v['sku'] ] ) ) {
				$var->set_manage_stock( true );
				$var->set_stock_quantity( (int) $stock[ $v['sku'] ] );
				$var->set_backorders( MDO_Settings::get( 'backorders' ) );
			}
			$var->update_meta_data( '_mdo_color_code', $v['color_code'] );
			$var->update_meta_data( '_mdo_pms', $v['pms'] ?? '' );
			$var->update_meta_data( '_mdo_arrivals', isset( $arr[ $v['sku'] ] ) ? $arr[ $v['sku'] ] : array() );
			if ( $v['gtin'] && method_exists( $var, 'set_global_unique_id' ) && ! $var->get_global_unique_id() ) {
				try {
					$var->set_global_unique_id( $v['gtin'] );
				} catch ( Exception $e ) { // dubbele EAN: overslaan
				}
			}
			$var->set_status( 'publish' );
			$var->save();
			$count++;
		}
		WC_Product_Variable::sync( $parent_id );

		MDO_Log::info( $master . ': ' . ( $is_new ? 'aangemaakt' : 'bijgewerkt' ) . ' met ' . $count . ( $sa ? ' varianten (kleur × maat).' : ' kleuren.' ) );
		as_enqueue_async_action( 'mdo_import_images', array( $parent_id ), MDO_Sync::GROUP );
	}

	/** Zoekt een eerder gedownloade afbeelding op bron-URL, anders downloaden. */
	private static function attachment_for( $url, $parent_id ) {
		$found = get_posts( array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'meta_key'    => '_mdo_source_url',
			'meta_value'  => $url,
			'fields'      => 'ids',
			'numberposts' => 1,
		) );
		if ( $found ) {
			return (int) $found[0];
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_sideload_image( $url, $parent_id, null, 'id' );
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_post_meta( $id, '_mdo_source_url', $url );
		return (int) $id;
	}

	public static function import_images( $parent_id ) {
		$product = wc_get_product( $parent_id );
		if ( ! $product ) {
			return;
		}
		$master = $product->get_meta( '_mdo_master_code' );
		$file   = MDO_Settings::cache_dir( 'products' ) . sanitize_file_name( $master ) . '.json';
		if ( ! file_exists( $file ) ) {
			return;
		}
		$p        = json_decode( file_get_contents( $file ), true );
		$deadline = time() + MDO_Sync::BUDGET;
		$hash     = md5( wp_json_encode( wp_list_pluck( $p['variants'], 'images' ) ) );
		if ( $product->get_meta( '_mdo_images_hash' ) === $hash ) {
			return;
		}

		$first_gallery = null;
		foreach ( $p['variants'] as $v ) {
			$vid = wc_get_product_id_by_sku( $v['sku'] );
			if ( ! $vid || ! $v['images'] ) {
				continue;
			}
			$ids = array();
			foreach ( $v['images'] as $url ) {
				if ( time() > $deadline ) {
					// Later verder; al gedownloade afbeeldingen worden hergebruikt.
					as_enqueue_async_action( 'mdo_import_images', array( $parent_id ), MDO_Sync::GROUP );
					return;
				}
				$a = self::attachment_for( $url, $parent_id );
				if ( $a ) {
					$ids[] = $a;
				}
			}
			if ( ! $ids ) {
				continue;
			}
			$var = wc_get_product( $vid );
			$var->set_image_id( $ids[0] );
			$var->save();
			// Additional Variation Images Gallery (woo-variation-gallery).
			update_post_meta( $vid, 'woo_variation_gallery_images', array_slice( $ids, 1 ) );
			if ( null === $first_gallery ) {
				$first_gallery = $ids;
			}
		}
		if ( $first_gallery ) {
			$product->set_image_id( $first_gallery[0] );
			$product->set_gallery_image_ids( array_slice( $first_gallery, 1 ) );
		}
		$product->update_meta_data( '_mdo_images_hash', $hash );
		$product->save();
		MDO_Log::info( $master . ': afbeeldingen bijgewerkt.' );
	}

	/** Volgorde van maten: XS, S, M, L, XL, XXL, 3XL ... en kindermaten op getal. */
	public static function size_rank( $s ) {
		$s     = strtoupper( trim( (string) $s ) );
		$order = array( 'XXXS', 'XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '2XL', 'XXXL', '3XL', '4XL', '5XL', '6XL' );
		$i     = array_search( $s, $order, true );
		if ( false !== $i ) {
			return 1000 + ( '2XL' === $s ? 7 : ( '3XL' === $s ? 9 : $i ) );
		}
		if ( preg_match( '/^(\d+)/', $s, $m ) ) {
			return (int) $m[1];
		}
		return 5000;
	}

	public static function size_cmp( $a, $b ) {
		$d = self::size_rank( $a ) - self::size_rank( $b );
		return $d ? $d : strcmp( (string) $a, (string) $b );
	}

	/** Eenmalig: bestaande maten (al geïmporteerd) meteen goed sorteren. */
	public static function fix_size_order() {
		if ( get_option( 'mdo_size_order_v1' ) || ! taxonomy_exists( 'pa_maat' ) ) {
			return;
		}
		$terms = get_terms( array( 'taxonomy' => 'pa_maat', 'hide_empty' => false ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				update_term_meta( $t->term_id, 'order', self::size_rank( $t->name ) );
			}
		}
		self::size_attribute_custom_order();
		update_option( 'mdo_size_order_v1', 1, false );
	}

	/** Eigenschap "Maat" laten sorteren op eigen volgorde (eenmalig instellen). */
	private static function size_attribute_custom_order() {
		static $done = false;
		if ( $done || ! function_exists( 'wc_get_attribute' ) ) {
			return;
		}
		$done = true;
		$id   = wc_attribute_taxonomy_id_by_name( 'pa_maat' );
		$attr = $id ? wc_get_attribute( $id ) : null;
		if ( $attr && 'menu_order' !== $attr->order_by ) {
			wc_update_attribute( $id, array(
				'name'         => $attr->name,
				'slug'         => preg_replace( '/^pa_/', '', $attr->slug ),
				'type'         => $attr->type,
				'order_by'     => 'menu_order',
				'has_archives' => $attr->has_archives,
			) );
		}
	}
}
