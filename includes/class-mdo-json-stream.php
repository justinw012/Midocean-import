<?php
/**
 * Leest de elementen van één JSON-array uit een groot bestand, één voor één,
 * zonder het hele bestand in het geheugen te laden. Hervatbaar via een byte-offset,
 * zodat het werk over meerdere korte achtergrondtaken verdeeld kan worden.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Json_Stream {
	const CHUNK = 1048576;

	/**
	 * Zoekt het begin van de array. Zonder $key: de array op het hoogste niveau.
	 * Met $key: de eerste array met die sleutel. Geeft de offset na '[' terug, of false.
	 */
	public static function find_array_start( $file, $key = null ) {
		$fh = fopen( $file, 'rb' );
		if ( ! $fh ) {
			return false;
		}
		$head = fread( $fh, 8 * self::CHUNK );
		fclose( $fh );
		if ( null === $key ) {
			$trim = ltrim( $head );
			if ( '' === $trim || '[' !== $trim[0] ) {
				return false;
			}
			return strlen( $head ) - strlen( $trim ) + 1;
		}
		if ( preg_match( '/"' . preg_quote( $key, '/' ) . '"\s*:\s*\[/', $head, $m, PREG_OFFSET_CAPTURE ) ) {
			return $m[0][1] + strlen( $m[0][0] );
		}
		return false;
	}

	/**
	 * Roept $callback( array $element ) aan voor elk element vanaf $offset,
	 * tot de array klaar is of $deadline (unix-tijd) verstreken is.
	 *
	 * @return array{offset:int,done:bool,count:int}
	 */
	public static function each( $file, $offset, $deadline, callable $callback ) {
		$fh = fopen( $file, 'rb' );
		if ( ! $fh ) {
			return array( 'offset' => $offset, 'done' => true, 'count' => 0 );
		}
		fseek( $fh, $offset );
		$count    = 0;
		$buf      = '';
		$buf_pos  = $offset; // bestandspositie van $buf[0]
		$i        = 0;
		$in_el    = false;
		$el_start = 0;
		$depth    = 0;
		$in_str   = false;

		while ( true ) {
			if ( $i >= strlen( $buf ) ) {
				// Buffer aanvullen; deel van een lopend element bewaren.
				if ( $in_el ) {
					$keep     = substr( $buf, $el_start );
					$buf_pos += $el_start;
					$i       -= $el_start;
					$el_start = 0;
					$buf      = $keep;
				} else {
					$buf_pos += strlen( $buf );
					$buf      = '';
					$i        = 0;
				}
				$chunk = fread( $fh, self::CHUNK );
				if ( false === $chunk || '' === $chunk ) {
					fclose( $fh );
					return array( 'offset' => $buf_pos + ( $in_el ? $el_start : $i ), 'done' => true, 'count' => $count );
				}
				$buf .= $chunk;
			}

			if ( ! $in_el ) {
				$c = $buf[ $i ];
				if ( ']' === $c ) {
					fclose( $fh );
					return array( 'offset' => $buf_pos + $i + 1, 'done' => true, 'count' => $count );
				}
				if ( '{' === $c || '[' === $c ) {
					$in_el    = true;
					$el_start = $i;
					$depth    = 1;
					$in_str   = false;
				}
				$i++;
				continue;
			}

			if ( $in_str ) {
				$skip = strcspn( $buf, '"\\', $i );
				$i   += $skip;
				if ( $i >= strlen( $buf ) ) {
					continue;
				}
				if ( '\\' === $buf[ $i ] ) {
					if ( $i + 1 >= strlen( $buf ) ) {
						// Escape valt op de grens: volgende chunk eerst laden.
						$more = fread( $fh, self::CHUNK );
						if ( false === $more || '' === $more ) {
							fclose( $fh );
							return array( 'offset' => $buf_pos + $el_start, 'done' => true, 'count' => $count );
						}
						$buf .= $more;
					}
					$i += 2;
				} else {
					$in_str = false;
					$i++;
				}
				continue;
			}

			$skip = strcspn( $buf, '{}[]"', $i );
			$i   += $skip;
			if ( $i >= strlen( $buf ) ) {
				continue;
			}
			$c = $buf[ $i ];
			$i++;
			if ( '"' === $c ) {
				$in_str = true;
			} elseif ( '{' === $c || '[' === $c ) {
				$depth++;
			} else {
				$depth--;
				if ( 0 === $depth ) {
					$json  = substr( $buf, $el_start, $i - $el_start );
					$in_el = false;
					$data  = json_decode( $json, true );
					if ( is_array( $data ) ) {
						$callback( $data );
					}
					$count++;
					if ( time() >= $deadline ) {
						fclose( $fh );
						return array( 'offset' => $buf_pos + $i, 'done' => false, 'count' => $count );
					}
				}
			}
		}
	}
}
