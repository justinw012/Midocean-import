<?php
defined( 'ABSPATH' ) || exit;

class MDO_Settings {
	const OPTION = 'mdo_settings';

	public static function defaults() {
		return array(
			'api_key'          => '',
			'language'         => 'nl',
			'categories'       => array(), // paden "Niveau1 > Niveau2 > Niveau3"
			'hidden_categories'=> array(), // geïmporteerd, maar niet zichtbaar in de winkel
			'margin_product'   => 2.0,
			'margin_print'     => 1.3,
			'margin_setup'     => 1.2,
			'setup_per_colour' => 1,
			'new_status'       => 'draft',
			'overwrite_texts'  => 0,
			'name_format'      => 'short_name', // short_name | short | name | name_short
			'rename_existing'  => 0,
			'update_slug'      => 1,
			'backorders'       => 'no', // no | notify
			'configurator'     => 1,
			'shop_hover'       => 1,
			'lead_proof'       => 1,
			'lead_transport'   => 2,
			'lead_margin'      => 1,
			'lead_table'       => self::LEAD_DEFAULT,
			'badge_new_days'   => 90,
			'hover_logo'       => 0,
			'hover_trim'       => 1,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}

	public static function upload_dir() {
		$up = wp_upload_dir();
		return array(
			'base' => trailingslashit( $up['basedir'] ) . 'midocean-import/',
			'url'  => trailingslashit( $up['baseurl'] ) . 'midocean-import/',
		);
	}

	/**
	 * Productiedagen (werkdagen) volgens Midocean, per 250 stuks oplopend tot 3000.
	 * Bron: midocean.com, pagina "Challenging all lead times" (stand november 2026). Aan te passen bij Instellingen.
	 */
	const LEAD_DEFAULT = "tampondruk metaal|tampondruk glas|keramiek = 2,3,4,4,5,5,6,6,7,7,7,7\ntampondruk = 1,2,3,3,4,4,5,5,6,6,6,6\nzeefdruk textiel = 1,1,3,3,4,4,5,5,6,6,6,6\nzeefdruk|rondzeefdruk = 2,3,4,4,5,5,6,6,7,7,7,7\nlaser|graveer|gravure = 1,2,3,3,4,4,5,5,6,6,7,7\ndoming = 5,6,7,7,8,8,9,9,10,10,11,11\ndigita|label|inlay = 1,2,3,3,4,4,5,5,6,6,7,7\n* = 2,3,4,4,5,5,6,6,7,7,7,7";

	public static function cache_dir( $sub = '' ) {
		$dir = self::upload_dir()['base'] . 'cache/' . ( $sub ? trailingslashit( $sub ) : '' );
		wp_mkdir_p( $dir );
		return $dir;
	}

	public static function ensure_dirs() {
		$base = self::upload_dir()['base'];
		foreach ( array( '', 'cache/', 'logos/' ) as $sub ) {
			wp_mkdir_p( $base . $sub );
			if ( ! file_exists( $base . $sub . 'index.html' ) ) {
				file_put_contents( $base . $sub . 'index.html', '' );
			}
		}
		// Cachebestanden zijn niet bedoeld voor bezoekers.
		if ( ! file_exists( $base . 'cache/.htaccess' ) ) {
			file_put_contents( $base . 'cache/.htaccess', "Require all denied\nDeny from all\n" );
		}
	}
}
