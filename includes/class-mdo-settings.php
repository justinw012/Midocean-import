<?php
defined( 'ABSPATH' ) || exit;

class MDO_Settings {
	const OPTION = 'mdo_settings';

	public static function defaults() {
		return array(
			'api_key'          => '',
			'language'         => 'nl',
			'categories'       => array(), // paden "Niveau1 > Niveau2 > Niveau3"
			'margin_product'   => 2.0,
			'margin_print'     => 1.3,
			'margin_setup'     => 1.2,
			'setup_per_colour' => 1,
			'new_status'       => 'draft',
			'overwrite_texts'  => 0,
			'configurator'     => 1,
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
