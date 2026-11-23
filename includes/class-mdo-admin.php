<?php
defined( 'ABSPATH' ) || exit;

class MDO_Admin {
	const SLUG = 'midocean-import';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_mdo_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_mdo_action', array( __CLASS__, 'action' ) );
		add_action( 'admin_post_mdo_labels', array( __CLASS__, 'save_labels' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Midocean', 'Midocean', 'manage_woocommerce', self::SLUG, array( __CLASS__, 'page' ) );
	}

	private static function back( $msg = '' ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'mdo_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'mdo_save' ) ) {
			wp_die( 'Geen toegang.' );
		}
		$in  = wp_unslash( $_POST );
		$new = array(
			'language'         => in_array( $in['language'] ?? 'nl', array( 'nl', 'en', 'de', 'fr' ), true ) ? $in['language'] : 'nl',
			'margin_product'   => max( 0, (float) str_replace( ',', '.', $in['margin_product'] ?? 2 ) ),
			'margin_print'     => max( 0, (float) str_replace( ',', '.', $in['margin_print'] ?? 1.3 ) ),
			'margin_setup'     => max( 0, (float) str_replace( ',', '.', $in['margin_setup'] ?? 1.2 ) ),
			'setup_per_colour' => empty( $in['setup_per_colour'] ) ? 0 : 1,
			'new_status'       => ( 'publish' === ( $in['new_status'] ?? '' ) ) ? 'publish' : 'draft',
			'overwrite_texts'  => empty( $in['overwrite_texts'] ) ? 0 : 1,
			'configurator'     => empty( $in['configurator'] ) ? 0 : 1,
			'categories'       => array_values( array_map( 'sanitize_text_field', (array) ( $in['categories'] ?? array() ) ) ),
		);
		$key = trim( (string) ( $in['api_key'] ?? '' ) );
		if ( '' !== $key ) {
			$new['api_key'] = sanitize_text_field( $key );
		}
		MDO_Settings::update( $new );
		MDO_Settings::ensure_dirs();
		self::back( 'Instellingen opgeslagen.' );
	}

	public static function save_labels() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'mdo_labels' ) ) {
			wp_die( 'Geen toegang.' );
		}
		$pos  = array();
		$tech = array();
		foreach ( (array) wp_unslash( $_POST['pos'] ?? array() ) as $id => $label ) {
			$label = sanitize_text_field( $label );
			if ( '' !== $label ) {
				$pos[ sanitize_text_field( $id ) ] = $label;
			}
		}
		foreach ( (array) wp_unslash( $_POST['tech'] ?? array() ) as $id => $label ) {
			$label = sanitize_text_field( $label );
			if ( '' !== $label ) {
				$tech[ sanitize_text_field( $id ) ] = $label;
			}
		}
		update_option( MDO_Printdata::LABELS_OPTION, $pos, false );
		update_option( 'mdo_technique_labels', $tech, false );
		self::back( 'Vertalingen opgeslagen.' );
	}

	/** Posities en technieken die in de gesynchroniseerde producten voorkomen. */
	private static function seen() {
		$seen = get_option( MDO_Printdata::SEEN_OPTION, array() );
		$pos  = array_flip( (array) ( $seen['positions'] ?? array() ) );
		$tech = array_flip( (array) ( $seen['techniques'] ?? array() ) );
		foreach ( (array) glob( MDO_Settings::cache_dir( 'print' ) . '*.json' ) as $f ) {
			$p = json_decode( (string) file_get_contents( $f ), true );
			foreach ( (array) ( $p['positions'] ?? array() ) as $ps ) {
				$pos[ $ps['id'] ] = 1;
				foreach ( (array) $ps['techs'] as $t ) {
					$tech[ $t['id'] ] = 1;
				}
			}
		}
		$pos  = array_keys( $pos );
		$tech = array_keys( $tech );
		usort( $pos, function ( $a, $b ) {
			$ua = MDO_Printdata::has_unknown( $a );
			$ub = MDO_Printdata::has_unknown( $b );
			return $ua === $ub ? strcmp( $a, $b ) : ( $ua ? -1 : 1 );
		} );
		sort( $tech );
		return array( $pos, $tech );
	}

	public static function action() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'mdo_action' ) ) {
			wp_die( 'Geen toegang.' );
		}
		MDO_Settings::ensure_dirs();
		switch ( sanitize_key( $_POST['do'] ?? '' ) ) {
			case 'test':
				$r = MDO_API::test();
				self::back( is_wp_error( $r ) ? $r->get_error_message() : 'Verbinding met Midocean werkt.' );
				break;
			case 'categories':
				MDO_Sync::start_categories();
				self::back( 'Categorieën worden opgehaald op de achtergrond. Ververs deze pagina over een paar minuten.' );
				break;
			case 'sync':
				MDO_Sync::start_full();
				self::back( 'Synchronisatie gestart op de achtergrond.' );
				break;
			case 'stock':
				MDO_Sync::start_stock();
				self::back( 'Voorraadupdate gestart.' );
				break;
			case 'stop':
				MDO_Sync::stop();
				self::back( 'Gestopt.' );
				break;
			case 'clearlog':
				MDO_Log::clear();
				self::back( 'Logboek gewist.' );
				break;
		}
		self::back();
	}

	/** Alle categoriepaden én hun bovenliggende niveaus, met aantallen producten. */
	private static function category_tree() {
		$cats = get_option( 'mdo_categories', array() );
		$tree = array();
		foreach ( (array) $cats as $path => $n ) {
			$parts = array_map( 'trim', explode( '>', $path ) );
			for ( $i = 1; $i <= count( $parts ); $i++ ) {
				$k          = implode( ' > ', array_slice( $parts, 0, $i ) );
				$tree[ $k ] = ( $tree[ $k ] ?? 0 ) + $n;
			}
		}
		ksort( $tree );
		return $tree;
	}

	private static function pending( $hook ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}
		return count( as_get_scheduled_actions( array( 'hook' => $hook, 'status' => 'pending', 'group' => MDO_Sync::GROUP, 'per_page' => -1 ), 'ids' ) );
	}

	public static function page() {
		$s     = MDO_Settings::all();
		$state = MDO_Sync::state();
		$tree  = self::category_tree();
		$msg   = isset( $_GET['mdo_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['mdo_msg'] ) ) : '';
		$act   = function ( $do, $label, $class = 'button' ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 8px 8px 0">';
			wp_nonce_field( 'mdo_action' );
			echo '<input type="hidden" name="action" value="mdo_action"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
			echo '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
		};
		?>
		<div class="wrap">
			<h1>Midocean Import &amp; Configurator</h1>
			<?php if ( $msg ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>

			<h2>Status</h2>
			<table class="widefat striped" style="max-width:720px">
				<tr><td>Fase</td><td><?php echo esc_html( $state['stage'] ?? 'nog niet gestart' ); ?><?php echo ! empty( $state['running'] ) ? ' (bezig)' : ''; ?></td></tr>
				<tr><td>Laatst bijgewerkt</td><td><?php echo esc_html( $state['updated'] ?? '-' ); ?></td></tr>
				<tr><td>Producten nog te importeren</td><td><?php echo (int) self::pending( 'mdo_import_product' ); ?></td></tr>
				<tr><td>Afbeeldingen nog te verwerken</td><td><?php echo (int) self::pending( 'mdo_import_images' ); ?></td></tr>
				<?php if ( ! empty( $state['error'] ) && 'fout' === ( $state['stage'] ?? '' ) ) : ?>
					<tr><td>Laatste fout</td><td><?php echo esc_html( $state['error'] ); ?></td></tr>
				<?php endif; ?>
			</table>
			<p style="margin-top:12px">
				<?php
				$act( 'test', 'Verbinding testen' );
				$act( 'categories', 'Categorieën ophalen' );
				$act( 'sync', 'Nu synchroniseren', 'button button-primary' );
				$act( 'stock', 'Alleen voorraad bijwerken' );
				$act( 'stop', 'Stoppen' );
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mdo_save' ); ?>
				<input type="hidden" name="action" value="mdo_save">

				<h2>Verbinding</h2>
				<table class="form-table">
					<tr><th>API-sleutel</th><td>
						<input type="password" name="api_key" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo $s['api_key'] ? 'Opgeslagen – laat leeg om te behouden' : 'Plak hier je Midocean API-sleutel'; ?>">
					</td></tr>
					<tr><th>Taal productteksten</th><td>
						<select name="language">
							<?php foreach ( array( 'nl' => 'Nederlands', 'en' => 'Engels', 'de' => 'Duits', 'fr' => 'Frans' ) as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['language'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				</table>

				<h2>Categorieën</h2>
				<?php if ( ! $tree ) : ?>
					<p>Nog geen categorieën bekend. Sla eerst je API-sleutel op en klik op <strong>Categorieën ophalen</strong>.</p>
				<?php else : ?>
					<p>Vink een hoofd- of subcategorie aan; alles daaronder wordt meegenomen.</p>
					<div style="max-height:420px;overflow:auto;border:1px solid #ccd0d4;background:#fff;padding:8px 12px;max-width:720px">
						<?php foreach ( $tree as $path => $n ) :
							$depth = substr_count( $path, '>' );
							$name  = trim( substr( strrchr( ' > ' . $path, '>' ), 1 ) );
							?>
							<label style="display:block;margin-left:<?php echo 20 * $depth; ?>px;line-height:1.9">
								<input type="checkbox" name="categories[]" value="<?php echo esc_attr( $path ); ?>" <?php checked( in_array( $path, (array) $s['categories'], true ) ); ?>>
								<?php echo esc_html( $name ); ?> <span style="color:#777">(<?php echo (int) $n; ?>)</span>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<h2>Prijzen</h2>
				<table class="form-table">
					<tr><th>Opslag product</th><td><input type="number" step="0.01" min="0" name="margin_product" value="<?php echo esc_attr( $s['margin_product'] ); ?>"> × inkoopprijs (per staffel)</td></tr>
					<tr><th>Opslag drukkosten</th><td><input type="number" step="0.01" min="0" name="margin_print" value="<?php echo esc_attr( $s['margin_print'] ); ?>"> × drukprijs en handling per stuk</td></tr>
					<tr><th>Opslag instelkosten</th><td><input type="number" step="0.01" min="0" name="margin_setup" value="<?php echo esc_attr( $s['margin_setup'] ); ?>"> × instelkosten</td></tr>
					<tr><th>Instelkosten per kleur</th><td><label><input type="checkbox" name="setup_per_colour" value="1" <?php checked( $s['setup_per_colour'] ); ?>> Bij zeefdruk, tampondruk en transfer de instelkosten per kleur rekenen</label></td></tr>
				</table>
				<p class="description">Prijzen worden ingesteld zoals WooCommerce ze verwacht (WooCommerce → Instellingen → Btw: prijzen inclusief of exclusief btw). Midocean-prijzen zijn exclusief btw.</p>

				<h2>Import</h2>
				<table class="form-table">
					<tr><th>Nieuwe producten</th><td>
						<select name="new_status">
							<option value="draft" <?php selected( $s['new_status'], 'draft' ); ?>>Als concept (eerst zelf controleren)</option>
							<option value="publish" <?php selected( $s['new_status'], 'publish' ); ?>>Direct publiceren</option>
						</select>
					</td></tr>
					<tr><th>Teksten bestaande producten</th><td><label><input type="checkbox" name="overwrite_texts" value="1" <?php checked( $s['overwrite_texts'] ); ?>> Naam en omschrijving overschrijven met Midocean</label></td></tr>
					<tr><th>Configurator</th><td><label><input type="checkbox" name="configurator" value="1" <?php checked( $s['configurator'] ); ?>> Configurator tonen op Midocean-producten</label></td></tr>
				</table>
				<?php submit_button( 'Instellingen opslaan' ); ?>
			</form>

			<h2>Vertalingen</h2>
			<?php
			list( $seen_pos, $seen_tech ) = self::seen();
			$own_pos   = get_option( MDO_Printdata::LABELS_OPTION, array() );
			$own_tech  = get_option( 'mdo_technique_labels', array() );
			$tech_nl   = get_option( 'mdo_technique_names', array() );
			?>
			<?php if ( ! $seen_pos ) : ?>
				<p>Na de eerste synchronisatie verschijnen hier de drukposities en technieken van je producten.</p>
			<?php else : ?>
				<p>Midocean levert drukposities alleen in het Engels. Laat een veld leeg om de automatische vertaling te gebruiken. <strong>Oranje</strong> = de automatische vertaling kent een woord niet.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'mdo_labels' ); ?>
					<input type="hidden" name="action" value="mdo_labels">
					<h3>Drukposities (<?php echo count( $seen_pos ); ?>)</h3>
					<div style="max-height:420px;overflow:auto;max-width:960px">
					<table class="widefat striped">
						<thead><tr><th>Midocean</th><th>Automatisch</th><th>Jouw vertaling</th></tr></thead>
						<tbody>
						<?php foreach ( $seen_pos as $id ) :
							$unknown = MDO_Printdata::has_unknown( $id );
							?>
							<tr<?php echo $unknown ? ' style="background:#FFF4E5"' : ''; ?>>
								<td><code><?php echo esc_html( $id ); ?></code></td>
								<td><?php echo esc_html( MDO_Printdata::auto_label( $id ) ); ?><?php echo $unknown ? ' <strong style="color:#B54708">(nog Engels)</strong>' : ''; ?></td>
								<td><input type="text" class="regular-text" name="pos[<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( $own_pos[ $id ] ?? '' ); ?>"></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<h3>Technieken (<?php echo count( $seen_tech ); ?>)</h3>
					<div style="max-height:420px;overflow:auto;max-width:960px">
					<table class="widefat striped">
						<thead><tr><th>Code</th><th>Naam van Midocean</th><th>Jouw naam</th></tr></thead>
						<tbody>
						<?php foreach ( $seen_tech as $id ) : ?>
							<tr>
								<td><code><?php echo esc_html( $id ); ?></code></td>
								<td><?php echo esc_html( $tech_nl[ $id ] ?? '–' ); ?></td>
								<td><input type="text" class="regular-text" name="tech[<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( $own_tech[ $id ] ?? '' ); ?>"></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<?php submit_button( 'Vertalingen opslaan' ); ?>
				</form>
			<?php endif; ?>

			<h2>Logboek</h2>
			<p class="description">Bevat alleen aantallen en artikelnummers, geen sleutels of klantgegevens.</p>
			<table class="widefat striped" style="max-width:960px">
				<thead><tr><th style="width:160px">Tijd</th><th style="width:60px">Soort</th><th>Melding</th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( MDO_Log::all(), 0, 100 ) as $row ) : ?>
					<tr><td><?php echo esc_html( $row['time'] ); ?></td><td><?php echo esc_html( $row['level'] ); ?></td><td><?php echo esc_html( $row['msg'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><?php $act( 'clearlog', 'Logboek wissen' ); ?></p>
		</div>
		<?php
	}
}
