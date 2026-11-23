<?php
/**
 * Logboek. Bewaart alleen meldingen met aantallen en artikelnummers.
 * Nooit API-sleutels, klantgegevens, prijzen of bestandsinhoud.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Log {
	const OPTION = 'mdo_log';
	const MAX    = 200;

	public static function info( $message ) {
		self::add( 'info', $message );
	}

	public static function error( $message ) {
		self::add( 'fout', $message );
	}

	private static function add( $level, $message ) {
		$message = self::scrub( (string) $message );
		$log     = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, array(
			'time'  => current_time( 'mysql' ),
			'level' => $level,
			'msg'   => $message,
		) );
		update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
	}

	/**
	 * Haalt alles weg dat op een sleutel, token, e-mailadres of URL-parameter lijkt.
	 */
	public static function scrub( $text ) {
		$key = MDO_Settings::get( 'api_key' );
		if ( $key ) {
			$text = str_replace( $key, '[sleutel]', $text );
		}
		$text = preg_replace( '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[e-mail]', $text );
		$text = preg_replace( '/([?&][^=\s]+=)[^&\s]+/', '$1[…]', $text );
		$text = preg_replace( '/\b[A-Fa-f0-9]{24,}\b/', '[id]', $text );
		return mb_substr( $text, 0, 300 );
	}

	public static function all() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
