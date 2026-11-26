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
		add_submenu_page( MDO_Dashboard::SLUG, 'Instellingen', 'Instellingen', 'manage_woocommerce', self::SLUG, array( __CLASS__, 'page' ) );
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
			'name_format'      => in_array( $in['name_format'] ?? '', array( 'short_name', 'short', 'name', 'name_short' ), true ) ? $in['name_format'] : 'short_name',
			'rename_existing'  => empty( $in['rename_existing'] ) ? 0 : 1,
			'update_slug'      => empty( $in['update_slug'] ) ? 0 : 1,
			'backorders'       => ( 'notify' === ( $in['backorders'] ?? '' ) ) ? 'notify' : 'no',
			'configurator'     => empty( $in['configurator'] ) ? 0 : 1,
			'shop_hover'       => empty( $in['shop_hover'] ) ? 0 : 1,
			'lead_proof'       => max( 0, min( 10, (int) ( $in['lead_proof'] ?? 1 ) ) ),
			'lead_transport'   => max( 0, min( 15, (int) ( $in['lead_transport'] ?? 2 ) ) ),
			'lead_margin'      => max( 0, min( 15, (int) ( $in['lead_margin'] ?? 1 ) ) ),
			'lead_table'       => trim( sanitize_textarea_field( wp_unslash( $in['lead_table'] ?? '' ) ) ) ?: MDO_Settings::LEAD_DEFAULT,
			'badge_new_days'   => max( 0, min( 365, (int) ( $in['badge_new_days'] ?? 90 ) ) ),
			'qty_note'         => sanitize_text_field( wp_unslash( $in['qty_note'] ?? '' ) ),
			'hover_logo'       => absint( $in['hover_logo'] ?? 0 ),
			'hover_trim'       => empty( $in['hover_trim'] ) ? 0 : 1,
			'filter_on'        => empty( $in['filter_on'] ) ? 0 : 1,
			'filter_sidebar'   => empty( $in['filter_sidebar'] ) ? 0 : 1,
			'img_bg'           => sanitize_hex_color( $in['img_bg'] ?? '' ) ?: '',
			'categories'       => array_values( array_map( 'sanitize_text_field', (array) ( $in['categories'] ?? array() ) ) ),
			'hidden_categories'=> array_values( array_map( 'sanitize_text_field', (array) ( $in['hidden_categories'] ?? array() ) ) ),
		);
		$key = trim( (string) ( $in['api_key'] ?? '' ) );
		if ( '' !== $key && current_user_can( 'manage_options' ) ) {
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
		$words = array();
		foreach ( (array) wp_unslash( $_POST['word'] ?? array() ) as $w => $nl ) {
			$w  = strtoupper( preg_replace( '/[^A-Za-z0-9()\/.\-]/', '', (string) $w ) );
			$nl = sanitize_text_field( $nl );
			if ( '' !== $w && '' !== $nl ) {
				$words[ $w ] = $nl;
			}
		}
		update_option( MDO_Printdata::WORDS_OPTION, $words, false );
		MDO_Printdata::reset_words();
		update_option( MDO_Printdata::LABELS_OPTION, $pos, false );
		update_option( 'mdo_technique_labels', $tech, false );
		$info = array();
		foreach ( (array) wp_unslash( $_POST['techinfo'] ?? array() ) as $id => $text ) {
			$text = sanitize_textarea_field( $text );
			if ( '' !== trim( $text ) ) {
				$info[ sanitize_text_field( $id ) ] = mb_substr( $text, 0, 600 );
			}
		}
		update_option( 'mdo_technique_info', $info, false );
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
		$rank = array( 'onbekend' => 0, 'keuze' => 1, 'zeker' => 2 );
		usort( $pos, function ( $a, $b ) use ( $rank ) {
			$ra = $rank[ MDO_Printdata::explain( $a )['status'] ];
			$rb = $rank[ MDO_Printdata::explain( $b )['status'] ];
			return $ra === $rb ? strcmp( $a, $b ) : $ra - $rb;
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
	public static function category_tree() {
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

	public static function pending( $hook ) {
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
			<h1>PromoFun – Instellingen</h1>
			<?php if ( function_exists( 'wc_tax_enabled' ) && ! wc_tax_enabled() ) : ?>
				<div class="notice notice-error inline"><p><strong>Btw staat uit in WooCommerce.</strong> De plugin rekent met inkoopprijzen exclusief btw; klanten betalen nu dus geen btw. Zet bij WooCommerce → Instellingen → Algemeen "Btw-tarieven en -berekeningen inschakelen" aan, en vul bij het tabblad Btw → Standaardtarieven NL 21% in. Kies bij Btw: prijzen ingevoerd <em>exclusief</em> btw, en prijzen tonen <em>inclusief</em> btw.</p></div>
			<?php elseif ( function_exists( 'wc_prices_include_tax' ) && 'incl' !== get_option( 'woocommerce_tax_display_shop' ) ) : ?>
				<div class="notice notice-warning inline"><p>Prijzen in de winkel worden <strong>exclusief btw</strong> getoond (WooCommerce → Instellingen → Btw → "Prijzen in de winkel weergeven"). Voor particulieren en de meeste klanten is "inclusief btw" gebruikelijk.</p></div>
			<?php endif; ?>
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
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<tr><th>API-sleutel</th><td>
						<input type="password" name="api_key" value="" autocomplete="new-password" class="regular-text" placeholder="<?php echo $s['api_key'] ? 'Opgeslagen – laat leeg om te behouden' : 'Plak hier je Midocean API-sleutel'; ?>">
					</td></tr>
					<?php else : ?>
					<tr><th>API-sleutel</th><td><?php echo $s['api_key'] ? 'Ingesteld door de beheerder.' : 'Nog niet ingesteld; vraag de beheerder.'; ?></td></tr>
					<?php endif; ?>
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
					<p><strong>Importeren:</strong> vink een hoofd- of subcategorie aan; alles daaronder wordt meegenomen.<br>
					<strong>Verbergen:</strong> wordt wel geïmporteerd en bijgewerkt, maar is niet te vinden in de winkel en de zoekfunctie. Wie de directe link heeft, kan het product nog wel openen. Werkt ook op een subcategorie binnen een geïmporteerde hoofdcategorie.</p>
					<div style="max-height:460px;overflow:auto;border:1px solid #ccd0d4;background:#fff;max-width:760px">
						<table class="widefat striped" style="border:0">
							<thead><tr><th>Categorie</th><th style="width:90px;text-align:center">Importeren</th><th style="width:90px;text-align:center">Verbergen</th></tr></thead>
							<tbody>
						<?php foreach ( $tree as $path => $n ) :
							$depth = substr_count( $path, '>' );
							$name  = trim( substr( strrchr( ' > ' . $path, '>' ), 1 ) );
							$imp   = MDO_Sync::in_list( $path, $s['categories'] );
							$hid   = MDO_Sync::in_list( $path, $s['hidden_categories'] );
							$own_i = in_array( $path, (array) $s['categories'], true );
							$own_h = in_array( $path, (array) $s['hidden_categories'], true );
							?>
							<tr>
								<td style="padding-left:<?php echo 10 + 20 * $depth; ?>px"><?php echo esc_html( $name ); ?> <span style="color:#777">(<?php echo (int) $n; ?>)</span><?php echo ( $hid && ! $own_h ) ? ' <span style="color:#B54708;font-size:12px">verborgen via bovenliggende</span>' : ''; ?></td>
								<td style="text-align:center"><input type="checkbox" name="categories[]" value="<?php echo esc_attr( $path ); ?>" <?php checked( $own_i ); ?> <?php echo ( $imp && ! $own_i ) ? 'title="Al meegenomen via bovenliggende categorie"' : ''; ?>><?php echo ( $imp && ! $own_i ) ? ' <span style="color:#1E6B3A">✓</span>' : ''; ?></td>
								<td style="text-align:center"><input type="checkbox" name="hidden_categories[]" value="<?php echo esc_attr( $path ); ?>" <?php checked( $own_h ); ?>></td>
							</tr>
						<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<p class="description">Wijzigingen bij Verbergen gelden vanaf de volgende synchronisatie.</p>
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
					<tr><th>Productnaam</th><td>
						<?php
						$ex = array( 'name' => 'ABE', 'short' => 'Matglazen fles 500 ml' );
						foreach ( array( 'short_name' => 'Omschrijving – modelnaam', 'short' => 'Alleen omschrijving', 'name' => 'Alleen modelnaam (zoals Midocean)', 'name_short' => 'Modelnaam – omschrijving' ) as $k => $l ) :
							?>
							<label style="display:block;line-height:2"><input type="radio" name="name_format" value="<?php echo esc_attr( $k ); ?>" <?php checked( $s['name_format'], $k ); ?>> <?php echo esc_html( $l ); ?> <span style="color:#777">– bijv. “<?php echo esc_html( MDO_Importer::title( $ex, $k ) ); ?>”</span></label>
						<?php endforeach; ?>
						<label style="display:block;line-height:2;margin-top:6px"><input type="checkbox" name="rename_existing" value="1" <?php checked( $s['rename_existing'] ); ?>> Ook bestaande producten hernoemen bij de volgende synchronisatie</label>
						<label style="display:block;line-height:2"><input type="checkbox" name="update_slug" value="1" <?php checked( $s['update_slug'] ); ?>> Webadres (URL) aanpassen aan de nieuwe naam <span style="color:#777">– niet doen als de shop al live staat en de oude links gedeeld zijn</span></label>
					</td></tr>
					<tr><th>Niet op voorraad</th><td>
						<select name="backorders">
							<option value="no" <?php selected( $s['backorders'], 'no' ); ?>>Niet bestelbaar</option>
							<option value="notify" <?php selected( $s['backorders'], 'notify' ); ?>>Toch bestelbaar (klant ziet: langere levertijd)</option>
						</select>
						<p class="description">Geldt vanaf de volgende synchronisatie.</p>
					</td></tr>
					<tr><th>Teksten bestaande producten</th><td><label><input type="checkbox" name="overwrite_texts" value="1" <?php checked( $s['overwrite_texts'] ); ?>> Naam en omschrijving overschrijven met Midocean</label></td></tr>
					<tr><th>Configurator</th><td><label><input type="checkbox" name="configurator" value="1" <?php checked( $s['configurator'] ); ?>> Configurator tonen op Midocean-producten</label></td></tr>
					<tr><th>Levertijd</th><td>
						<p style="margin-top:0">De klant ziet "Verwacht in huis: rond …". Berekening in werkdagen: drukproef + productie (tabel hieronder, per techniek en aantal) + verzending + marge. Is er te weinig voorraad, dan telt de plugin vanaf de verwachte binnenkomst bij Midocean.</p>
						<label>Drukproef <input type="number" name="lead_proof" min="0" max="10" value="<?php echo (int) $s['lead_proof']; ?>" style="width:60px"> dag(en)</label> &nbsp;
						<label>Verzending <input type="number" name="lead_transport" min="0" max="15" value="<?php echo (int) $s['lead_transport']; ?>" style="width:60px"> dag(en)</label> &nbsp;
						<label>Marge <input type="number" name="lead_margin" min="0" max="15" value="<?php echo (int) $s['lead_margin']; ?>" style="width:60px"> dag(en)</label>
						<p><label for="mdo-lead">Productiedagen per techniek (zoekwoorden in de techniek­naam = dagen bij t/m 250, 500, 750 … 3000 stuks; <code>*</code> = alle andere):</label><br>
						<textarea id="mdo-lead" name="lead_table" rows="9" class="large-text code"><?php echo esc_textarea( $s['lead_table'] ); ?></textarea></p>
						<p class="description">Standaard overgenomen van Midocean ("Challenging all lead times"). Boven 3000 stuks komt er per 500 stuks een dag bij. Leeg laten = standaardtabel.</p>
					</td></tr>
					<tr><th>Melding hoeveelheid</th><td><input type="text" name="qty_note" value="<?php echo esc_attr( $s['qty_note'] ); ?>" class="large-text"><p class="description">Staat bij de prijs in de configurator. Leeg = niet tonen. Midocean levert volgens hun voorwaarden 5–10% meer of minder.</p></td></tr>
					<tr><th>Labels in de winkel</th><td>
						<label>"Nieuw" tonen bij producten die de laatste <input type="number" name="badge_new_days" min="0" max="365" value="<?php echo (int) $s['badge_new_days']; ?>" style="width:70px"> dagen zijn uitgekomen (0 = uit).</label><br>
						<span class="description">"Op = op" verschijnt vanzelf als Midocean een einddatum voor alle kleuren van een product heeft.</span>
					</td></tr>
					<tr><th>Voorbeeld met logo in de winkel</th><td>
						<label><input type="checkbox" name="shop_hover" value="1" <?php checked( $s['shop_hover'] ); ?>> Product tonen met ons logo erop bij aanwijzen (computer) of vasthouden (telefoon)</label><br>
						<label><input type="checkbox" name="hover_trim" value="1" <?php checked( $s['hover_trim'] ); ?>> Alleen de grote regel van het logo gebruiken (zonder slogan)</label><br>
						<label>Ander logo (media-ID, leeg = sitelogo): <input type="number" name="hover_logo" min="0" value="<?php echo $s['hover_logo'] ? (int) $s['hover_logo'] : ''; ?>" style="width:110px"></label>
						<?php $lu = MDO_Shop::logo_url(); if ( $lu ) : ?><br><img src="<?php echo esc_url( $lu ); ?>" alt="" style="max-width:260px;max-height:70px;margin-top:6px;background:#eee;padding:4px"><?php endif; ?>
					</td></tr>
					<tr><th>Winkel en filters</th><td>
						<label><input type="checkbox" name="filter_on" value="1" <?php checked( $s['filter_on'] ); ?>> PromoFun-filters op shop-, categorie- en zoekpagina's (eerst aantal kiezen; prijs en levertijd bij dat aantal)</label><br>
						<label><input type="checkbox" name="filter_sidebar" value="1" <?php checked( $s['filter_sidebar'] ); ?>> Zijbalk van het thema daar verbergen (de oude filters)</label><br>
						<label>Achtergrond achter productfoto's: <input type="text" name="img_bg" value="<?php echo esc_attr( $s['img_bg'] ); ?>" placeholder="#F3F1EC" style="width:100px"></label> <span class="description">Leeg = geen. Witte productfoto's krijgen dan deze kleur.</span>
					</td></tr>
				</table>
				<?php submit_button( 'Instellingen opslaan' ); ?>
			</form>

			<h2>Vertalingen</h2>
			<?php
			list( $seen_pos, $seen_tech ) = self::seen();
			$own_pos   = get_option( MDO_Printdata::LABELS_OPTION, array() );
			$own_tech  = get_option( 'mdo_technique_labels', array() );
			$own_info  = get_option( 'mdo_technique_info', array() );
			$tech_nl   = get_option( 'mdo_technique_names', array() );
			?>
			<?php if ( ! $seen_pos ) : ?>
				<p>Na de eerste synchronisatie verschijnen hier de drukposities en technieken van je producten.</p>
			<?php else : ?>
				<?php
				$badge  = array(
					'onbekend' => array( '#FDE8E8', '#B42318', 'Onbekend' ),
					'keuze'    => array( '#FFF4E5', '#B54708', 'Gekozen' ),
					'zeker'    => array( '#E3F4E8', '#1E6B3A', 'Zeker' ),
					'eigen'    => array( '#E8EEFD', '#1D4ED8', 'Eigen vertaling' ),
				);
				$counts = array( 'onbekend' => 0, 'keuze' => 0, 'zeker' => 0, 'eigen' => 0 );
				foreach ( $seen_pos as $id ) {
					$counts[ ! empty( $own_pos[ $id ] ) ? 'eigen' : MDO_Printdata::explain( $id )['status'] ]++;
				}
				$pill = function ( $k, $suffix = '' ) use ( $badge ) {
					return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-weight:600;font-size:12px;background:' . $badge[ $k ][0] . ';color:' . $badge[ $k ][1] . '">' . esc_html( $badge[ $k ][2] . $suffix ) . '</span>';
				};
				?>
				<p>Midocean levert drukposities alleen in het Engels. Elke automatische vertaling heeft een label:</p>
				<ul style="list-style:none;margin:0 0 12px;padding:0;line-height:2">
					<li><?php echo $pill( 'onbekend' ); ?> Een woord staat niet in de woordenlijst en blijft Engels. Vul hier altijd zelf iets in.</li>
					<li><?php echo $pill( 'keuze' ); ?> Het woord heeft meerdere betekenissen, of de woordvolgorde is automatisch. De uitleg staat erbij.</li>
					<li><?php echo $pill( 'zeker' ); ?> Letterlijke vertaling van bekende woorden.</li>
					<li><?php echo $pill( 'eigen' ); ?> Je hebt zelf een vertaling ingevuld; die wordt gebruikt.</li>
				</ul>
				<p><strong>Overzicht:</strong>
					<?php echo $pill( 'onbekend', ': ' . $counts['onbekend'] ); ?>
					<?php echo $pill( 'keuze', ': ' . $counts['keuze'] ); ?>
					<?php echo $pill( 'zeker', ': ' . $counts['zeker'] ); ?>
					<?php echo $pill( 'eigen', ': ' . $counts['eigen'] ); ?>
				</p>
				<p>Laat een veld leeg om de automatische vertaling te gebruiken.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'mdo_labels' ); ?>
					<input type="hidden" name="action" value="mdo_labels">
					<?php
					$own_words = MDO_Printdata::own_words();
					$unknown   = MDO_Printdata::unknown_words( $seen_pos );
					?>
					<h3>Woorden (<?php echo count( $unknown ); ?> nog niet vertaald)</h3>
					<p style="max-width:960px">Vertaal hier één woord, dan geldt het voor álle drukposities waar het in staat. Bijvoorbeeld <code>CORK</code> = <code>kurk</code> maakt van <code>CORK FRONT</code> "Kurk voorkant". Een streepje (<code>-</code>) laat het woord weg. Een vertaling per drukpositie hieronder gaat altijd voor.</p>
					<div style="max-height:320px;overflow:auto;max-width:960px">
					<table class="widefat striped">
						<thead><tr><th>Woord</th><th>Komt voor in</th><th>Voorbeeld</th><th>Jouw vertaling</th></tr></thead>
						<tbody>
						<?php foreach ( $unknown as $w => $info ) : ?>
							<tr style="background:#FDE8E8">
								<td><code><?php echo esc_html( $w ); ?></code></td>
								<td><?php echo (int) $info['count']; ?> positie<?php echo 1 === $info['count'] ? '' : 's'; ?></td>
								<td><code><?php echo esc_html( $info['example'] ); ?></code></td>
								<td><input type="text" name="word[<?php echo esc_attr( $w ); ?>]" value=""></td>
							</tr>
						<?php endforeach; ?>
						<?php foreach ( $own_words as $w => $nl ) : ?>
							<tr style="background:#E8EEFD">
								<td><code><?php echo esc_html( $w ); ?></code></td>
								<td colspan="2">Eigen vertaling</td>
								<td><input type="text" name="word[<?php echo esc_attr( $w ); ?>]" value="<?php echo esc_attr( $nl ); ?>"></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( ! $unknown && ! $own_words ) : ?>
							<tr><td colspan="4">Alle woorden zijn bekend.</td></tr>
						<?php endif; ?>
						</tbody>
					</table>
					</div>
					<p class="description">Een eigen woord toevoegen dat al automatisch vertaald wordt? Vul het in bij een drukpositie hieronder, of vraag het mij.</p>
					<h3>Drukposities (<?php echo count( $seen_pos ); ?>)</h3>
					<div style="max-height:420px;overflow:auto;max-width:960px">
					<table class="widefat striped">
						<thead><tr><th>Midocean</th><th>Automatisch</th><th>Zekerheid</th><th>Toelichting</th><th>Jouw vertaling</th></tr></thead>
						<tbody>
						<?php foreach ( $seen_pos as $id ) :
							$ex  = MDO_Printdata::explain( $id );
							$own = ! empty( $own_pos[ $id ] );
							?>
							<tr style="background:<?php echo esc_attr( $badge[ $own ? 'eigen' : $ex['status'] ][0] ); ?>">
								<td><code><?php echo esc_html( $id ); ?></code></td>
								<td><?php echo esc_html( MDO_Printdata::auto_label( $id ) ); ?></td>
								<td><?php echo $pill( $own ? 'eigen' : $ex['status'] ); ?></td>
								<td style="font-size:12px;color:#444"><?php echo $ex['notes'] ? implode( '<br>', array_map( 'esc_html', $ex['notes'] ) ) : '–'; ?></td>
								<td><input type="text" class="regular-text" name="pos[<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( $own_pos[ $id ] ?? '' ); ?>" placeholder="<?php echo esc_attr( MDO_Printdata::auto_label( $id ) ); ?>"></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<h3>Technieken (<?php echo count( $seen_tech ); ?>)</h3>
					<div style="max-height:420px;overflow:auto;max-width:960px">
					<table class="widefat striped">
						<thead><tr><th>Code</th><th>Naam van Midocean (officieel Nederlands)</th><th>Jouw naam</th><th>Eigen uitleg bij de i-knop <span style="font-weight:400;color:#646970">(leeg = standaardtekst; regels met + of − worden voor- en nadelen)</span></th></tr></thead>
						<tbody>
						<?php foreach ( $seen_tech as $id ) : ?>
							<tr>
								<td><code><?php echo esc_html( $id ); ?></code></td>
								<td><?php echo esc_html( $tech_nl[ $id ] ?? '–' ); ?></td>
								<td><input type="text" class="regular-text" name="tech[<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( $own_tech[ $id ] ?? '' ); ?>"></td>
								<td><textarea name="techinfo[<?php echo esc_attr( $id ); ?>]" rows="3" style="width:100%"><?php echo esc_textarea( $own_info[ $id ] ?? '' ); ?></textarea></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
					<?php submit_button( 'Vertalingen opslaan' ); ?>
				</form>
			<?php endif; ?>

			<?php MDO_Shipping::render(); ?>

			<?php MDO_Preview::render(); ?>

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
