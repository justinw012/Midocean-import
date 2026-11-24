<?php
/**
 * Diagnose: laat zien welke gegevens de Midocean-feeds bevatten en wat de server kan.
 * Waarden worden eerst geanonimiseerd (cijfers → 9, letters → x/X), zodat alleen de opbouw
 * en het formaat zichtbaar zijn. De API-sleutel komt er nooit in.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Diag {

	const SLUG = 'promofun-diagnose';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 75 );
	}

	public static function menu() {
		add_submenu_page( MDO_Dashboard::SLUG, 'Diagnose', 'Diagnose', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	/** Waarde vervangen door alleen het formaat: "2026-12-01" → "9999-99-99", "MO2789-03" → "XX9999-99". */
	public static function shape( $v ) {
		if ( is_bool( $v ) ) {
			return 'bool';
		}
		if ( null === $v ) {
			return 'null';
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return 'getal';
		}
		$s = (string) $v;
		if ( preg_match( '#^https?://([^/]+)#i', $s, $m ) ) {
			$ext = strtolower( (string) pathinfo( (string) wp_parse_url( $s, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			return 'url ' . $m[1] . ( $ext ? ' (.' . $ext . ')' : '' );
		}
		$len = mb_strlen( $s );
		if ( $len > 40 ) {
			return 'tekst (' . $len . ' tekens)';
		}
		$s = preg_replace( '/[0-9]/', '9', $s );
		$s = preg_replace( '/[a-z\x{00E0}-\x{00FF}]/u', 'x', $s );
		$s = preg_replace( '/[A-Z\x{00C0}-\x{00DE}]/u', 'X', $s );
		return '"' . $s . '"';
	}

	/** Alle sleutelpaden in een element verzamelen, met per pad een paar formaten. */
	private static function walk( $v, $path, array &$out, $depth = 0 ) {
		if ( $depth > 7 || count( $out ) > 400 ) {
			return;
		}
		if ( is_array( $v ) ) {
			$is_list = array_keys( $v ) === range( 0, count( $v ) - 1 );
			if ( $is_list ) {
				foreach ( array_slice( $v, 0, 5 ) as $x ) {
					self::walk( $x, $path . '[]', $out, $depth + 1 );
				}
				if ( ! $v ) {
					$out[ $path . '[]' ]['leeg'] = 1;
				}
				return;
			}
			foreach ( $v as $k => $x ) {
				self::walk( $x, ( '' === $path ? '' : $path . '.' ) . $k, $out, $depth + 1 );
			}
			return;
		}
		$sh = self::shape( $v );
		if ( ! isset( $out[ $path ] ) ) {
			$out[ $path ] = array();
		}
		if ( count( $out[ $path ] ) < 3 ) {
			$out[ $path ][ $sh ] = 1;
		}
	}

	/** Eerste elementen van een feed lezen (zonder het hele bestand te laden). */
	private static function sample_feed( $name ) {
		$file = MDO_Settings::cache_dir() . $name . '.json';
		if ( ! file_exists( $file ) ) {
			return array( 'missing' => true );
		}
		$head = (string) file_get_contents( $file, false, null, 0, 4096 ); // phpcs:ignore
		preg_match_all( '/"([A-Za-z0-9_]+)"\s*:\s*\[/', $head, $m );
		$keys  = array_values( array_unique( $m[1] ) );
		$res   = array( 'size' => filesize( $file ), 'time' => filemtime( $file ), 'arrays' => array() );
		$cands = array_merge( array( null ), $keys );
		foreach ( $cands as $key ) {
			$start = MDO_Json_Stream::find_array_start( $file, $key );
			if ( false === $start ) {
				continue;
			}
			$paths = array();
			$n     = 0;
			try {
				MDO_Json_Stream::each( $file, $start, time() + 8, function ( $el ) use ( &$paths, &$n ) {
					self::walk( $el, '', $paths );
					if ( ++$n >= 25 ) {
						throw new RuntimeException( 'genoeg' );
					}
				} );
			} catch ( RuntimeException $e ) {
				// genoeg voorbeelden
			}
			$res['arrays'][ null === $key ? '(hoofdlijst)' : $key ] = array( 'n' => $n, 'paths' => $paths );
			if ( null === $key ) {
				break;
			}
		}
		return $res;
	}

	private static function counts() {
		global $wpdb;
		$c = array();
		$c['Producten met Midocean-code']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_mdo_master_code'" ); // phpcs:ignore
		$c['Varianten']                      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product_variation'" ); // phpcs:ignore
		$c['Varianten met maat']             = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_mdo_size' AND meta_value <> ''" ); // phpcs:ignore
		$c['Producten met specificaties']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_mdo_specs'" ); // phpcs:ignore
		$c['Producten met documenten']       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_mdo_documents' AND meta_value NOT IN ('', 'a:0:{}')" ); // phpcs:ignore
		$c['Producten met printdata']        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", MDO_Printdata::META ) ); // phpcs:ignore
		return $c;
	}

	private static function server() {
		$s = array(
			'PHP'                => PHP_VERSION,
			'WordPress'          => get_bloginfo( 'version' ),
			'WooCommerce'        => defined( 'WC_VERSION' ) ? WC_VERSION : '-',
			'Plugin'             => MDO_VERSION,
			'Thema'              => wp_get_theme()->get( 'Name' ) . ' ' . wp_get_theme()->get( 'Version' ),
			'Geheugenlimiet'     => ini_get( 'memory_limit' ),
			'Max. uploadgrootte' => size_format( wp_max_upload_size() ),
			'Max. looptijd'      => ini_get( 'max_execution_time' ) . ' s',
			'GD'                 => extension_loaded( 'gd' ) ? 'ja' : 'nee',
			'Imagick'            => extension_loaded( 'imagick' ) ? 'ja' : 'nee',
		);
		if ( class_exists( 'Imagick' ) ) {
			try {
				$s['Imagick kan PDF/EPS/AI lezen'] = ( Imagick::queryFormats( 'PDF' ) ? 'PDF ' : '' ) . ( Imagick::queryFormats( 'EPS' ) ? 'EPS ' : '' ) . ( Imagick::queryFormats( 'AI' ) ? 'AI' : '' );
			} catch ( \Throwable $e ) {
				$s['Imagick kan PDF/EPS/AI lezen'] = 'onbekend';
			}
		}
		$s['WP-cron uit (DISABLE_WP_CRON)'] = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'ja' : 'nee';
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$s['Wachtende importstappen'] = count( as_get_scheduled_actions( array( 'group' => 'midocean-import', 'status' => 'pending', 'per_page' => 500 ), 'ids' ) );
			$s['Mislukte importstappen']  = count( as_get_scheduled_actions( array( 'group' => 'midocean-import', 'status' => 'failed', 'per_page' => 500 ), 'ids' ) );
		}
		$s['Koko Analytics'] = MDO_Stats::koko_active() ? 'actief' : 'niet actief';
		$s['Eigenschap pa_maat'] = taxonomy_exists( 'pa_maat' ) ? 'bestaat' : 'bestaat niet';
		$s['Sitelogo gevonden'] = MDO_Shop::logo_url() ? 'ja' : 'nee';
		return $s;
	}

	public static function report() {
		$t   = array();
		$t[] = '=== PromoFun diagnose ' . wp_date( 'Y-m-d H:i' ) . ' (waarden geanonimiseerd: 9 = cijfer, x/X = letter) ===';
		$t[] = '';
		$t[] = '--- Server en plugin ---';
		foreach ( self::server() as $k => $v ) {
			$t[] = $k . ': ' . $v;
		}
		$t[] = '';
		$t[] = '--- Aantallen in de winkel ---';
		foreach ( self::counts() as $k => $v ) {
			$t[] = $k . ': ' . $v;
		}
		foreach ( array( 'products', 'stock', 'pricelist', 'printdata', 'printpricelist' ) as $feed ) {
			$t[] = '';
			$t[] = '--- Feed ' . $feed . ' ---';
			$r = self::sample_feed( $feed );
			if ( ! empty( $r['missing'] ) ) {
				$t[] = '(nog niet gedownload – start eerst een synchronisatie)';
				continue;
			}
			$t[] = 'Bestand: ' . size_format( $r['size'] ) . ', opgehaald ' . wp_date( 'Y-m-d H:i', $r['time'] );
			foreach ( $r['arrays'] as $name => $a ) {
				$t[] = 'Lijst ' . $name . ' – ' . $a['n'] . ' voorbeelden bekeken:';
				foreach ( $a['paths'] as $path => $shapes ) {
					$t[] = '  ' . $path . ' = ' . implode( ' | ', array_keys( $shapes ) );
				}
			}
		}
		$t[] = '';
		$t[] = '=== einde ===';
		return MDO_Log::scrub( implode( "\n", $t ) );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$run = isset( $_POST['mdo_diag'] ) && check_admin_referer( 'mdo_diag' );
		?>
		<div class="wrap">
			<h1>Diagnose</h1>
			<p style="max-width:900px">Laat zien welke velden de Midocean-feeds bevatten (bijvoorbeeld of er levertijden of binnenkomstdata in zitten) en wat je server kan. Alle waarden worden eerst geanonimiseerd: je ziet alleen het formaat, zoals <code>"9999-99-99"</code> voor een datum. Je API-sleutel staat er niet in.</p>
			<form method="post">
				<?php wp_nonce_field( 'mdo_diag' ); ?>
				<p><button class="button button-primary" name="mdo_diag" value="1">Diagnose maken</button> <span class="description">Gebruikt de feeds van de laatste synchronisatie; duurt een paar seconden.</span></p>
			</form>
			<?php if ( $run ) : ?>
				<p><button type="button" class="button" onclick="var t=document.getElementById('mdo-diag');t.select();document.execCommand('copy');this.textContent='Gekopieerd ✓';">Kopiëren</button></p>
				<textarea id="mdo-diag" readonly rows="30" style="width:100%;max-width:1100px;font-family:monospace;font-size:12px"><?php echo esc_textarea( self::report() ); ?></textarea>
			<?php endif; ?>
		</div>
		<?php
	}
}
