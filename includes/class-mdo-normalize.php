<?php
/**
 * Zet Midocean-feeds om naar compacte, voorspelbare arrays.
 * Ruim opgezet: accepteert meerdere schrijfwijzen van veldnamen en
 * decimalen met komma of punt.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Normalize {

	public static function num( $v ) {
		if ( is_int( $v ) || is_float( $v ) ) {
			return (float) $v;
		}
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return 0.0;
		}
		if ( false !== strpos( $v, ',' ) && false !== strpos( $v, '.' ) ) {
			$v = str_replace( '.', '', $v );
		}
		return (float) str_replace( ',', '.', $v );
	}

	private static function pick( array $a, array $keys, $default = null ) {
		foreach ( $keys as $k ) {
			if ( isset( $a[ $k ] ) && '' !== $a[ $k ] ) {
				return $a[ $k ];
			}
		}
		return $default;
	}

	private static function list_of( $v ) {
		if ( ! is_array( $v ) ) {
			return array();
		}
		// Eén object in plaats van een lijst.
		if ( ! empty( $v ) && array_keys( $v ) !== range( 0, count( $v ) - 1 ) ) {
			return array( $v );
		}
		return $v;
	}

	/** Afbeeldingen uit digital_assets, in een vaste volgorde. */
	private static function images( $assets ) {
		$order = array( '', 'item_picture_front', 'item_picture_back', 'item_picture_side', 'item_picture_top', 'item_picture_open', 'item_detail_picture', 'item_picture_printed', 'item_ambiant_picture', 'item_picture_box' );
		$out   = array();
		foreach ( self::list_of( $assets ) as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$type = strtolower( (string) self::pick( $a, array( 'type' ), 'image' ) );
			$url  = self::pick( $a, array( 'url', 'url_highress' ) );
			if ( 'image' !== $type || ! $url ) {
				continue;
			}
			$sub   = strtolower( (string) self::pick( $a, array( 'subtype' ), '' ) );
			$rank  = array_search( $sub, $order, true );
			$out[] = array( 'url' => $url, 'subtype' => $sub, 'rank' => false === $rank ? 50 : $rank );
		}
		usort( $out, function ( $x, $y ) {
			return $x['rank'] - $y['rank'];
		} );
		return array_values( array_unique( wp_list_pluck( $out, 'url' ) ) );
	}

	private static function documents( $assets ) {
		$out = array();
		foreach ( self::list_of( $assets ) as $a ) {
			if ( is_array( $a ) && 'document' === strtolower( (string) self::pick( $a, array( 'type' ), '' ) ) ) {
				$url = self::pick( $a, array( 'url' ) );
				if ( $url ) {
					$out[ (string) self::pick( $a, array( 'subtype' ), 'document' ) ] = $url;
				}
			}
		}
		return $out;
	}

	public static function category_path( array $v ) {
		$parts = array();
		foreach ( array( 'category_level1', 'category_level2', 'category_level3' ) as $k ) {
			if ( ! empty( $v[ $k ] ) ) {
				$parts[] = trim( $v[ $k ] );
			}
		}
		return implode( ' > ', $parts );
	}

	public static function product( array $p ) {
		$variants = array();
		foreach ( self::list_of( self::pick( $p, array( 'variants' ), array() ) ) as $v ) {
			if ( ! is_array( $v ) || empty( $v['sku'] ) ) {
				continue;
			}
			$variants[] = array(
				'sku'        => (string) $v['sku'],
				'color_code' => (string) self::pick( $v, array( 'color_code' ), '' ),
				'color_name' => (string) self::pick( $v, array( 'color_description', 'color_name', 'color' ), '' ),
				'color_group'=> (string) self::pick( $v, array( 'color_group' ), '' ),
				'category'   => self::category_path( $v ),
				'plc'        => (string) self::pick( $v, array( 'plc_status_description' ), '' ),
				'gtin'       => (string) self::pick( $v, array( 'gtin' ), '' ),
				'images'     => self::images( self::pick( $v, array( 'digital_assets' ), array() ) ),
			);
		}
		return array(
			'master_code' => (string) self::pick( $p, array( 'master_code' ), '' ),
			'name'        => (string) self::pick( $p, array( 'product_name', 'name' ), '' ),
			'short'       => (string) self::pick( $p, array( 'short_description' ), '' ),
			'long'        => (string) self::pick( $p, array( 'long_description', 'description' ), '' ),
			'material'    => (string) self::pick( $p, array( 'material' ), '' ),
			'dimensions'  => (string) self::pick( $p, array( 'dimensions' ), '' ),
			'origin'      => (string) self::pick( $p, array( 'country_of_origin' ), '' ),
			'weight'      => self::num( self::pick( $p, array( 'net_weight', 'gross_weight' ), 0 ) ),
			'length'      => self::num( self::pick( $p, array( 'length' ), 0 ) ),
			'width'       => self::num( self::pick( $p, array( 'width' ), 0 ) ),
			'height'      => self::num( self::pick( $p, array( 'height' ), 0 ) ),
			'size_unit'   => strtolower( (string) self::pick( $p, array( 'length_unit' ), 'cm' ) ),
			'images'      => self::images( self::pick( $p, array( 'digital_assets' ), array() ) ),
			'documents'   => self::documents( self::pick( $p, array( 'digital_assets' ), array() ) ),
			'variants'    => $variants,
		);
	}

	/** Eén element uit de prijslijst: array( sku, scales[[qty, prijs], ...] ). */
	public static function price( array $e ) {
		$sku    = (string) self::pick( $e, array( 'sku' ), '' );
		$scales = array();
		foreach ( self::list_of( self::pick( $e, array( 'scale', 'scales' ), array() ) ) as $s ) {
			if ( is_array( $s ) ) {
				$scales[] = array(
					(int) self::num( self::pick( $s, array( 'minimum_quantity', 'quantity', 'qty' ), 1 ) ),
					self::num( self::pick( $s, array( 'price' ), 0 ) ),
				);
			}
		}
		if ( ! $scales ) {
			$scales[] = array( 1, self::num( self::pick( $e, array( 'price' ), 0 ) ) );
		}
		usort( $scales, function ( $a, $b ) {
			return $a[0] - $b[0];
		} );
		return array( $sku, $scales );
	}

	/** Eén element uit de voorraadfeed: array( sku, aantal ). */
	public static function stock( array $e ) {
		return array(
			(string) self::pick( $e, array( 'sku' ), '' ),
			(int) self::num( self::pick( $e, array( 'qty', 'quantity', 'stock', 'qty_available', 'available', 'free_stock' ), 0 ) ),
		);
	}

	/** De hele printprijslijst (klein bestand). */
	public static function printpricelist( array $d ) {
		$out = array( 'manipulations' => array(), 'techniques' => array() );
		foreach ( self::list_of( self::pick( $d, array( 'print_manipulations', 'manipulations' ), array() ) ) as $m ) {
			if ( is_array( $m ) && isset( $m['code'] ) ) {
				$out['manipulations'][ (string) $m['code'] ] = self::num( self::pick( $m, array( 'price' ), 0 ) );
			}
		}
		foreach ( self::list_of( self::pick( $d, array( 'print_techniques', 'techniques' ), array() ) ) as $t ) {
			if ( ! is_array( $t ) || empty( $t['id'] ) ) {
				continue;
			}
			$ranges = array();
			foreach ( self::list_of( self::pick( $t, array( 'var_costs', 'ranges', 'var_cost' ), array() ) ) as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$scales = array();
				foreach ( self::list_of( self::pick( $r, array( 'scales', 'scale' ), array() ) ) as $s ) {
					if ( is_array( $s ) ) {
						$scales[] = array(
							(int) self::num( self::pick( $s, array( 'minimum_quantity', 'quantity' ), 1 ) ),
							self::num( self::pick( $s, array( 'price' ), 0 ) ),
							self::num( self::pick( $s, array( 'next_price', 'next_colour_price' ), 0 ) ),
						);
					}
				}
				usort( $scales, function ( $a, $b ) {
					return $a[0] - $b[0];
				} );
				$to       = self::pick( $r, array( 'area_to' ), '' );
				$ranges[] = array(
					self::num( self::pick( $r, array( 'area_from' ), 0 ) ),
					( '' === $to || null === $to ) ? 1e9 : self::num( $to ),
					$scales,
				);
			}
			$next = self::pick( $t, array( 'next_colour_cost_indicator' ), false );
			$out['techniques'][ (string) $t['id'] ] = array(
				'type'   => (string) self::pick( $t, array( 'pricing_type' ), 'NumberOfPositions' ),
				'setup'  => self::num( self::pick( $t, array( 'setup', 'setup_cost' ), 0 ) ),
				'repeat' => self::num( self::pick( $t, array( 'setup_repeat', 'setup_repeat_cost' ), 0 ) ),
				'next'   => ( true === $next || 'true' === strtolower( (string) $next ) || '1' === (string) $next ),
				'ranges' => $ranges,
				'name'   => (string) self::pick( $t, array( 'description' ), '' ),
			);
		}
		return $out;
	}

	/** Naam van een techniek in de gekozen taal; name kan object of lijst zijn. */
	public static function translated( $name, $lang ) {
		if ( is_string( $name ) ) {
			return $name;
		}
		if ( ! is_array( $name ) ) {
			return '';
		}
		foreach ( self::list_of( $name ) as $n ) {
			if ( is_array( $n ) ) {
				if ( isset( $n[ $lang ] ) ) {
					return (string) $n[ $lang ];
				}
				if ( isset( $n['en'] ) ) {
					$fallback = (string) $n['en'];
				}
			}
		}
		return isset( $fallback ) ? $fallback : '';
	}

	/** Eén product uit de printdata. */
	public static function printproduct( array $p ) {
		$positions = array();
		foreach ( self::list_of( self::pick( $p, array( 'printing_positions' ), array() ) ) as $pos ) {
			if ( ! is_array( $pos ) || empty( $pos['position_id'] ) ) {
				continue;
			}
			$techs = array();
			foreach ( self::list_of( self::pick( $pos, array( 'printing_techniques' ), array() ) ) as $t ) {
				if ( is_array( $t ) && ! empty( $t['id'] ) ) {
					$def     = self::pick( $t, array( 'default' ), false );
					$techs[] = array(
						'id'      => (string) $t['id'],
						'max'     => (int) self::num( self::pick( $t, array( 'max_colours' ), 0 ) ),
						'default' => ( true === $def || 'true' === strtolower( (string) $def ) ),
					);
				}
			}
			$xs = array();
			$ys = array();
			foreach ( self::list_of( self::pick( $pos, array( 'points' ), array() ) ) as $pt ) {
				if ( is_array( $pt ) ) {
					$xs[] = self::num( self::pick( $pt, array( 'distance_from_left' ), 0 ) );
					$ys[] = self::num( self::pick( $pt, array( 'distance_from_top' ), 0 ) );
				}
			}
			$images = array();
			foreach ( self::list_of( self::pick( $pos, array( 'images' ), array() ) ) as $im ) {
				if ( is_array( $im ) ) {
					$c = (string) self::pick( $im, array( 'variant_color' ), '' );
					$u = self::pick( $im, array( 'print_position_image_blank', 'print_position_image_with_area' ) );
					if ( $u ) {
						$images[ $c ] = $u;
					}
				}
			}
			$positions[] = array(
				'id'     => (string) $pos['position_id'],
				'w'      => self::num( self::pick( $pos, array( 'max_print_size_width' ), 0 ) ),
				'h'      => self::num( self::pick( $pos, array( 'max_print_size_height' ), 0 ) ),
				'type'   => (string) self::pick( $pos, array( 'print_position_type' ), '' ),
				'techs'  => $techs,
				'box'    => $xs ? array( min( $xs ), min( $ys ), max( $xs ), max( $ys ) ) : null,
				'images' => $images,
			);
		}
		return array(
			'master_code'  => (string) self::pick( $p, array( 'master_code' ), '' ),
			'manipulation' => (string) self::pick( $p, array( 'print_manipulation' ), '' ),
			'positions'    => $positions,
		);
	}
}
