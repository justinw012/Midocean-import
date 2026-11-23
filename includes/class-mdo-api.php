<?php
defined( 'ABSPATH' ) || exit;

class MDO_API {
	const BASE = 'https://api.midocean.com';

	const ENDPOINTS = array(
		'products'       => '/gateway/products/2.0',
		'pricelist'      => '/gateway/pricelist/2.0',
		'stock'          => '/gateway/stock/2.0',
		'printdata'      => '/gateway/printdata/1.0',
		'printpricelist' => '/gateway/printpricelist/2.0',
	);

	/**
	 * Downloadt een feed naar een bestand in de cachemap. Geeft het pad terug of WP_Error.
	 * Grote feeds worden direct naar schijf gestreamd, niet in het geheugen geladen.
	 */
	public static function download( $feed ) {
		$key = MDO_Settings::get( 'api_key' );
		if ( ! $key ) {
			return new WP_Error( 'mdo_no_key', 'Geen API-sleutel ingesteld.' );
		}
		if ( ! isset( self::ENDPOINTS[ $feed ] ) ) {
			return new WP_Error( 'mdo_feed', 'Onbekende feed.' );
		}
		$url = self::BASE . self::ENDPOINTS[ $feed ];
		if ( in_array( $feed, array( 'products', 'printdata' ), true ) ) {
			$url = add_query_arg( 'language', MDO_Settings::get( 'language' ), $url );
		}
		$file = MDO_Settings::cache_dir() . $feed . '.json';
		$tmp  = $file . '.part';

		$response = wp_remote_get( $url, array(
			'timeout'  => 600,
			'stream'   => true,
			'filename' => $tmp,
			'headers'  => array(
				'x-Gateway-APIKey' => $key,
				'Accept'           => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp );
			return new WP_Error( 'mdo_http', 'Verbinding met Midocean mislukt (' . $feed . ').' );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			@unlink( $tmp );
			return new WP_Error( 'mdo_http', 'Midocean gaf status ' . $code . ' voor ' . $feed . '.' );
		}
		@unlink( $file );
		rename( $tmp, $file );
		MDO_Log::info( 'Feed ' . $feed . ' gedownload (' . size_format( filesize( $file ) ) . ').' );
		return $file;
	}

	/** Controleert de sleutel met de kleinste feed. */
	public static function test() {
		$file = self::download( 'printpricelist' );
		return is_wp_error( $file ) ? $file : true;
	}
}
