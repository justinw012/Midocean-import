<?php
/**
 * Prijsberekening. Werkt met de verkoopprijzen uit MDO_Frontend::config()
 * (opslag al verwerkt). Exact dezelfde regels staan in assets/configurator.js;
 * de winkelwagen rekent altijd hier opnieuw, de browser toont alleen.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Pricing {

	public static function scale_at( array $scales, $qty ) {
		$hit = $scales ? $scales[0] : array( 1, 0, 0 );
		foreach ( $scales as $s ) {
			if ( $qty >= $s[0] ) {
				$hit = $s;
			}
		}
		return $hit;
	}

	public static function range_for( array $ranges, $area ) {
		foreach ( $ranges as $r ) {
			$to = ( null === $r[1] ) ? INF : $r[1];
			if ( $area >= $r[0] && $area < $to ) {
				return $r[2];
			}
		}
		return $ranges ? $ranges[ count( $ranges ) - 1 ][2] : array();
	}

	/** Eén bedrukte positie: array( per stuk, instelkosten ). */
	public static function print_cost( array $tech, $colours, $area_cm2, $qty, $setup_per_colour ) {
		$c      = max( 1, (int) $colours );
		$type   = $tech['type'];
		$area   = in_array( $type, array( 'AreaRange', 'ColourAreaRange' ), true );
		$scales = $area ? self::range_for( $tech['ranges'], $area_cm2 ) : ( $tech['ranges'] ? $tech['ranges'][0][2] : array() );
		$s      = self::scale_at( $scales, $qty );
		$unit   = isset( $s[1] ) ? (float) $s[1] : 0.0;
		$next   = isset( $s[2] ) ? (float) $s[2] : 0.0;
		$setup  = (float) $tech['setup'];

		if ( 'Area' === $type ) {
			$unit *= $area_cm2;
		}
		if ( in_array( $type, array( 'NumberOfColours', 'ColourAreaRange' ), true ) ) {
			$unit = $tech['next'] ? $unit + $next * ( $c - 1 ) : $unit * $c;
			if ( $setup_per_colour ) {
				$setup *= $c;
			}
		}
		return array( $unit, $setup );
	}

	/**
	 * @param array $cfg        MDO_Frontend::config()
	 * @param array $color      één element uit $cfg['colors']
	 * @param int   $qty
	 * @param array $selections elk: tech, colours, w, h (mm)
	 */
	public static function quote( array $cfg, array $color, $qty, array $selections ) {
		$qty   = max( 1, (int) $qty );
		$ps    = self::scale_at( $color['scales'], $qty );
		$unit  = (float) $ps[1];
		$setup = 0.0;
		$lines = array();
		foreach ( $selections as $sel ) {
			if ( empty( $cfg['techniques'][ $sel['tech'] ] ) ) {
				return new WP_Error( 'mdo_tech', 'Onbekende techniek.' );
			}
			list( $u, $su ) = self::print_cost( $cfg['techniques'][ $sel['tech'] ], $sel['colours'], $sel['w'] * $sel['h'] / 100, $qty, $cfg['setupPerColour'] );
			$unit          += $u;
			$setup         += $su;
			$lines[]        = array( 'unit' => $u, 'setup' => $su );
		}
		$manip = $selections ? (float) $cfg['manip'] : 0.0;
		$unit += $manip;
		$total = $unit * $qty + $setup;
		return array(
			'product' => (float) $ps[1],
			'unit'    => $unit,
			'setup'   => $setup,
			'manip'   => $manip,
			'lines'   => $lines,
			'total'   => $total,
			'per_pc'  => $total / $qty,
		);
	}
}
