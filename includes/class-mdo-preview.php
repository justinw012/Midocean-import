<?php
/**
 * Tijdelijke kijklink: laat iemand de winkel bekijken zonder het sitewachtwoord,
 * terwijl de site voor alle anderen beveiligd blijft. Alleen lezen (GET), max. 1 uur, in te trekken.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Preview {

	const OPTION = 'mdo_preview';
	const COOKIE = 'mdo_preview';
	const PARAM  = 'mdo_preview';

	public static function init() {
		add_filter( 'password_protected_is_active', array( __CLASS__, 'bypass' ), 99 );
		add_action( 'init', array( __CLASS__, 'remember' ) );
		add_action( 'admin_post_mdo_preview', array( __CLASS__, 'handle' ) );
	}

	private static function current() {
		$p = get_option( self::OPTION );
		if ( ! is_array( $p ) || empty( $p['hash'] ) || (int) $p['expires'] < time() ) {
			return null;
		}
		return $p;
	}

	private static function valid( $token ) {
		$p = self::current();
		return $p && is_string( $token ) && '' !== $token && hash_equals( $p['hash'], hash( 'sha256', $token ) );
	}

	private static function given() {
		if ( isset( $_GET[ self::PARAM ] ) ) {
			return sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) );
		}
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		}
		return '';
	}

	private static function is_get() {
		return 'GET' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' );
	}

	public static function bypass( $active ) {
		if ( $active && self::is_get() && self::valid( self::given() ) ) {
			if ( ! headers_sent() ) {
				header( 'X-Robots-Tag: noindex, nofollow' );
			}
			return false;
		}
		return $active;
	}

	/** Token uit de link onthouden in een cookie, zodat doorklikken binnen de site ook werkt. */
	public static function remember() {
		if ( isset( $_GET[ self::PARAM ] ) && self::is_get() ) {
			$t = self::given();
			$p = self::current();
			if ( $p && self::valid( $t ) && ! headers_sent() ) {
				setcookie( self::COOKIE, $t, array( 'expires' => (int) $p['expires'], 'path' => COOKIEPATH ? COOKIEPATH : '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
			}
		}
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Geen toegang.' );
		}
		check_admin_referer( 'mdo_preview' );
		$back = admin_url( 'admin.php?page=' . MDO_Admin::SLUG . '#kijklink' );
		if ( isset( $_POST['revoke'] ) ) {
			delete_option( self::OPTION );
			delete_transient( 'mdo_preview_url' );
			MDO_Log::info( 'Kijklink ingetrokken.' );
		} else {
			$token = wp_generate_password( 32, false, false );
			$exp   = time() + HOUR_IN_SECONDS;
			update_option( self::OPTION, array( 'hash' => hash( 'sha256', $token ), 'expires' => $exp ), false );
			// De link zelf alleen kort bewaren, om hem één keer te tonen.
			set_transient( 'mdo_preview_url', add_query_arg( self::PARAM, $token, home_url( '/' ) ), 5 * MINUTE_IN_SECONDS );
			MDO_Log::info( 'Kijklink aangemaakt (1 uur geldig).' );
		}
		wp_safe_redirect( $back );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$p   = self::current();
		$url = get_transient( 'mdo_preview_url' );
		?>
		<h2 id="kijklink">Kijklink</h2>
		<p class="description" style="max-width:960px">Een link waarmee iemand de winkel 1 uur kan <strong>bekijken</strong> zonder het sitewachtwoord, bijvoorbeeld om het ontwerp te beoordelen. Inloggen of iets wijzigen kan er niet mee. Voor iedereen anders blijft de site beveiligd.</p>
		<?php if ( $p ) : ?>
			<p>Er is een kijklink actief tot <strong><?php echo esc_html( wp_date( 'H:i', (int) $p['expires'] ) ); ?></strong>.</p>
			<?php if ( $url ) : ?>
				<p><input type="text" readonly value="<?php echo esc_attr( $url ); ?>" class="large-text code" onclick="this.select()"></p>
				<p class="description">Kopieer de link nu; hij wordt na een paar minuten niet meer getoond.</p>
			<?php endif; ?>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px">
			<input type="hidden" name="action" value="mdo_preview">
			<?php wp_nonce_field( 'mdo_preview' ); ?>
			<button class="button"><?php echo $p ? 'Nieuwe kijklink (1 uur)' : 'Kijklink maken (1 uur)'; ?></button>
			<?php if ( $p ) : ?>
				<button class="button" name="revoke" value="1">Nu intrekken</button>
			<?php endif; ?>
		</form>
		<?php
	}
}
