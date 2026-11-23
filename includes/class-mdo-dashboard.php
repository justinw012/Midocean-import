<?php
/**
 * PromoFun-startpagina: bestellingen afhandelen, winkelstatus, prijsfactoren en categorieën
 * op één pagina. Winkelbeheerders (geen beheerder) krijgen een eenvoudig menu en komen
 * na het inloggen direct hier.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Dashboard {
	const SLUG = 'promofun';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'simplify_menu' ), 999 );
		add_action( 'admin_post_mdo_quick', array( __CLASS__, 'quick' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 99, 3 );
		add_filter( 'woocommerce_prevent_admin_access', array( __CLASS__, 'allow_admin' ), 99 );
	}

	private static function is_simple_user() {
		return current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' );
	}

	private static function orders_url( $args = array() ) {
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$base = $hpos ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
		return add_query_arg( $args, $base );
	}

	public static function menu() {
		add_menu_page( 'PromoFun', 'PromoFun', 'manage_woocommerce', self::SLUG, array( __CLASS__, 'page' ), 'dashicons-store', 2 );
		add_submenu_page( self::SLUG, 'PromoFun', 'Overzicht', 'manage_woocommerce', self::SLUG, array( __CLASS__, 'page' ) );
	}

	/** Winkelbeheerders zien alleen PromoFun, Bestellingen en Producten. */
	public static function simplify_menu() {
		if ( ! self::is_simple_user() ) {
			return;
		}
		global $menu;
		$keep = array( self::SLUG, 'edit.php?post_type=product', 'profile.php' );
		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && ! in_array( $item[2], $keep, true ) ) {
				remove_menu_page( $item[2] );
			}
		}
		add_menu_page( 'Bestellingen', 'Bestellingen', 'manage_woocommerce', self::orders_url(), '', 'dashicons-cart', 3 );
	}

	public static function login_redirect( $to, $requested, $user ) {
		if ( $user instanceof WP_User && $user->has_cap( 'manage_woocommerce' ) && ! $user->has_cap( 'manage_options' ) ) {
			return admin_url( 'admin.php?page=' . self::SLUG );
		}
		return $to;
	}

	public static function allow_admin( $prevent ) {
		return current_user_can( 'manage_woocommerce' ) ? false : $prevent;
	}

	private static function back( $msg, $anchor = '' ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'mdo_msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) . $anchor );
		exit;
	}

	/** Kleine acties en deel-opslaan vanaf deze pagina (raakt alleen de genoemde instellingen). */
	public static function quick() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'mdo_quick' ) ) {
			wp_die( 'Geen toegang.' );
		}
		$in = wp_unslash( $_POST );
		switch ( sanitize_key( $in['do'] ?? '' ) ) {
			case 'handled':
				$order = wc_get_order( absint( $in['order'] ?? 0 ) );
				if ( $order ) {
					$order->update_meta_data( '_mdo_handled', current_time( 'mysql' ) );
					$order->add_order_note( 'Bedrukking afgehandeld (doorgegeven aan Midocean).' );
					$order->save();
				}
				self::back( 'Bestelling gemarkeerd als afgehandeld.', '#todo' );
				break;
			case 'unhandle':
				$order = wc_get_order( absint( $in['order'] ?? 0 ) );
				if ( $order ) {
					$order->delete_meta_data( '_mdo_handled' );
					$order->save();
				}
				self::back( 'Bestelling staat weer bij Te doen.', '#todo' );
				break;
			case 'prices':
				MDO_Settings::update( array(
					'margin_product' => max( 0, (float) str_replace( ',', '.', $in['margin_product'] ?? 2 ) ),
					'margin_print'   => max( 0, (float) str_replace( ',', '.', $in['margin_print'] ?? 1.3 ) ),
					'margin_setup'   => max( 0, (float) str_replace( ',', '.', $in['margin_setup'] ?? 1.2 ) ),
				) );
				self::back( 'Prijsfactoren opgeslagen. Drukprijzen gelden direct; productprijzen na "Nu alles bijwerken".', '#prijzen' );
				break;
			case 'categories':
				MDO_Settings::update( array(
					'categories'        => array_values( array_map( 'sanitize_text_field', (array) ( $in['categories'] ?? array() ) ) ),
					'hidden_categories' => array_values( array_map( 'sanitize_text_field', (array) ( $in['hidden_categories'] ?? array() ) ) ),
				) );
				self::back( 'Categorieën opgeslagen. Klik op "Nu alles bijwerken" om het door te voeren.', '#categorieen' );
				break;
			case 'sync':
				MDO_Sync::start_full();
				self::back( 'Bijwerken gestart. Dit loopt op de achtergrond en kan even duren.', '#status' );
				break;
			case 'stock':
				MDO_Sync::start_stock();
				self::back( 'Voorraad wordt bijgewerkt.', '#status' );
				break;
		}
		self::back( '' );
	}

	/** Bestellingen met bedrukking die nog niet afgehandeld zijn. */
	private static function todo_orders( $handled = false ) {
		$orders = wc_get_orders( array(
			'status' => array( 'processing', 'on-hold' ),
			'limit'  => 50,
			'orderby'=> 'date',
			'order'  => 'DESC',
		) );
		$out = array();
		foreach ( $orders as $o ) {
			if ( ! MDO_Order::lines( $o ) ) {
				continue;
			}
			$is_handled = (bool) $o->get_meta( '_mdo_handled' );
			if ( $is_handled === $handled ) {
				$out[] = $o;
			}
		}
		return $out;
	}

	private static function form( $do, $fields = '', $label = '', $class = 'button', $anchor = '' ) {
		$h  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 6px 6px 0">';
		$h .= wp_nonce_field( 'mdo_quick', '_wpnonce', true, false );
		$h .= '<input type="hidden" name="action" value="mdo_quick"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">' . $fields;
		if ( $label ) {
			$h .= '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		}
		return $h . '</form>';
	}

	private static function status_line() {
		$st    = MDO_Sync::state();
		$log   = MDO_Log::all();
		$error = null;
		foreach ( array_slice( $log, 0, 30 ) as $row ) {
			if ( 'fout' === $row['level'] ) {
				$error = $row;
				break;
			}
		}
		$running = ! empty( $st['running'] ) || MDO_Admin::pending( 'mdo_import_product' ) || MDO_Admin::pending( 'mdo_import_images' );
		if ( $running ) {
			return array( '#FFF4E5', '#B54708', 'Bezig met bijwerken…', 'Producten, prijzen en foto\'s worden op de achtergrond bijgewerkt. Je kunt gewoon doorwerken.' );
		}
		if ( $error && ( empty( $st['updated'] ) || strtotime( $error['time'] ) >= strtotime( $st['updated'] ) - 60 ) ) {
			return array( '#FDE8E8', '#B42318', 'Er ging iets mis bij het bijwerken', $error['time'] . ': ' . $error['msg'] );
		}
		if ( ! empty( $st['updated'] ) ) {
			return array( '#E3F4E8', '#1E6B3A', 'Alles is bijgewerkt', 'Laatst bijgewerkt: ' . $st['updated'] . '. Elke nacht om 03:00 automatisch, de voorraad elk uur.' );
		}
		return array( '#F0F0F1', '#3C434A', 'Nog niet bijgewerkt', 'Klik op "Nu alles bijwerken" om producten en prijzen op te halen.' );
	}

	public static function page() {
		$s   = MDO_Settings::all();
		$msg = isset( $_GET['mdo_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['mdo_msg'] ) ) : '';
		$todo = self::todo_orders( false );
		$done = array_slice( self::todo_orders( true ), 0, 10 );
		list( $sbg, $sfg, $stitle, $stext ) = self::status_line();
		$card = 'background:#fff;border:1px solid #DCDCDE;border-radius:12px;padding:18px 20px;margin:0 0 18px;max-width:1100px';
		?>
		<div class="wrap" style="font-size:14px">
			<h1 style="margin-bottom:14px">PromoFun</h1>
			<?php if ( $msg ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div><?php endif; ?>

			<div id="todo" style="<?php echo esc_attr( $card ); ?>">
				<h2 style="margin-top:0">Te doen <span style="display:inline-block;min-width:24px;padding:2px 8px;border-radius:999px;background:<?php echo $todo ? '#DF3550' : '#8C8F94'; ?>;color:#fff;font-size:13px;text-align:center"><?php echo count( $todo ); ?></span></h2>
				<?php if ( ! $todo ) : ?>
					<p>Geen nieuwe bestellingen met bedrukking. 🎉</p>
				<?php endif; ?>
				<?php foreach ( $todo as $o ) :
					$lines   = MDO_Order::lines( $o );
					$missing = false;
					foreach ( $lines as $l ) {
						foreach ( $l['prints'] as $p ) {
							if ( 'logo' === $p['mode'] && ! $p['logo'] ) {
								$missing = true;
							}
						}
					}
					$text = MDO_Order::as_text( $lines );
					?>
					<div style="border:1px solid #E3E3E3;border-radius:10px;padding:14px 16px;margin:0 0 12px">
						<div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;align-items:center">
							<div>
								<strong style="font-size:15px">Bestelling #<?php echo esc_html( $o->get_order_number() ); ?></strong>
								· <?php echo esc_html( $o->get_formatted_billing_full_name() ); ?>
								· <?php echo esc_html( wc_format_datetime( $o->get_date_created(), 'j M Y, H:i' ) ); ?>
								· <?php echo wp_kses_post( $o->get_formatted_order_total() ); ?>
							</div>
							<?php if ( $missing ) : ?>
								<span style="padding:3px 10px;border-radius:999px;background:#FFF4E5;color:#B54708;font-weight:600">Logo ontbreekt nog</span>
							<?php else : ?>
								<span style="padding:3px 10px;border-radius:999px;background:#E3F4E8;color:#1E6B3A;font-weight:600">Klaar om te bestellen bij Midocean</span>
							<?php endif; ?>
						</div>
						<ul style="margin:10px 0 10px 18px;list-style:disc">
							<?php foreach ( $lines as $l ) : ?>
								<li><strong><?php echo (int) $l['qty']; ?>×</strong> <?php echo esc_html( $l['name'] ); ?> <span style="color:#646970">(<?php echo esc_html( $l['sku'] ); ?>)</span>
									<?php foreach ( $l['prints'] as $p ) : ?>
										<br><span style="color:#3C434A"><?php echo esc_html( $p['face'] . ': ' . $p['techname'] . ', ' . $p['size'] ); ?>
										<?php echo 'text' === $p['mode'] ? esc_html( ' – tekst “' . $p['text'] . '”' ) : ( $p['logo'] ? ' – <a href="' . esc_url( $p['logo'] ) . '" target="_blank" rel="noopener">logo downloaden</a>' : ' – <span style="color:#B54708">logo ontbreekt</span>' ); ?></span>
									<?php endforeach; ?>
								</li>
							<?php endforeach; ?>
						</ul>
						<textarea id="mdo-t-<?php echo (int) $o->get_id(); ?>" readonly style="position:absolute;left:-9999px"><?php echo esc_textarea( $text ); ?></textarea>
						<a class="button" href="<?php echo esc_url( $o->get_edit_order_url() ); ?>">Bestelling openen</a>
						<button type="button" class="button" onclick="var t=document.getElementById('mdo-t-<?php echo (int) $o->get_id(); ?>');t.select();(navigator.clipboard?navigator.clipboard.writeText(t.value):document.execCommand('copy'));this.textContent='Gekopieerd ✓';">Kopiëren voor Midocean</button>
						<?php echo self::form( 'handled', '<input type="hidden" name="order" value="' . (int) $o->get_id() . '">', 'Afgehandeld', 'button button-primary' ); // phpcs:ignore ?>
					</div>
				<?php endforeach; ?>
				<?php if ( $done ) : ?>
					<details style="margin-top:8px"><summary style="cursor:pointer">Recent afgehandeld (<?php echo count( $done ); ?>)</summary>
						<ul style="margin:10px 0 0 18px;list-style:disc">
						<?php foreach ( $done as $o ) : ?>
							<li><a href="<?php echo esc_url( $o->get_edit_order_url() ); ?>">#<?php echo esc_html( $o->get_order_number() ); ?></a> · <?php echo esc_html( $o->get_formatted_billing_full_name() ); ?> · afgehandeld <?php echo esc_html( $o->get_meta( '_mdo_handled' ) ); ?>
							<?php echo self::form( 'unhandle', '<input type="hidden" name="order" value="' . (int) $o->get_id() . '">', 'Terugzetten' ); // phpcs:ignore ?></li>
						<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>
				<p style="margin-bottom:0"><a href="<?php echo esc_url( self::orders_url() ); ?>">Alle bestellingen bekijken →</a></p>
			</div>

			<?php MDO_Stats::render( $card ); ?>

			<div id="status" style="<?php echo esc_attr( $card ); ?>">
				<h2 style="margin-top:0">Winkel</h2>
				<div style="padding:12px 14px;border-radius:10px;background:<?php echo esc_attr( $sbg ); ?>;color:<?php echo esc_attr( $sfg ); ?>;margin-bottom:12px">
					<strong><?php echo esc_html( $stitle ); ?></strong><br><span style="color:#3C434A"><?php echo esc_html( $stext ); ?></span>
				</div>
				<?php echo self::form( 'sync', '', 'Nu alles bijwerken', 'button button-primary' ); // phpcs:ignore ?>
				<?php echo self::form( 'stock', '', 'Alleen voorraad bijwerken' ); // phpcs:ignore ?>
				<a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener" style="margin:0 6px 6px 0">Winkel bekijken</a>
			</div>

			<div id="prijzen" style="<?php echo esc_attr( $card ); ?>">
				<h2 style="margin-top:0">Prijzen</h2>
				<p style="margin-top:0">De inkoopprijs van Midocean wordt vermenigvuldigd met deze factoren. Voorbeeld: factor 2 maakt van € 1,00 inkoop € 2,00 verkoop (exclusief btw).</p>
				<?php
				$fields  = '<table class="form-table" role="presentation" style="margin:0">';
				$fields .= '<tr><th><label for="mp">Product</label></th><td><input id="mp" type="number" step="0.01" min="0" name="margin_product" value="' . esc_attr( $s['margin_product'] ) . '" style="width:90px"> <span class="description">× inkoopprijs van het artikel</span></td></tr>';
				$fields .= '<tr><th><label for="mr">Bedrukking</label></th><td><input id="mr" type="number" step="0.01" min="0" name="margin_print" value="' . esc_attr( $s['margin_print'] ) . '" style="width:90px"> <span class="description">× drukprijs per stuk</span></td></tr>';
				$fields .= '<tr><th><label for="ms">Instelkosten</label></th><td><input id="ms" type="number" step="0.01" min="0" name="margin_setup" value="' . esc_attr( $s['margin_setup'] ) . '" style="width:90px"> <span class="description">× eenmalige instelkosten</span></td></tr>';
				$fields .= '</table>';
				echo self::form( 'prices', $fields, 'Prijzen opslaan', 'button button-primary' ); // phpcs:ignore
				?>
			</div>

			<div id="categorieen" style="<?php echo esc_attr( $card ); ?>">
				<h2 style="margin-top:0">Categorieën</h2>
				<?php $tree = MDO_Admin::category_tree(); ?>
				<?php if ( ! $tree ) : ?>
					<p>Nog geen categorieën bekend.</p>
				<?php else : ?>
					<p style="margin-top:0"><strong>In winkel</strong> = producten uit deze categorie staan in de winkel. <strong>Verbergen</strong> = wel bijgewerkt, maar niet te zien voor klanten. Na opslaan op "Nu alles bijwerken" klikken.</p>
					<?php
					$rows = '<div style="max-height:420px;overflow:auto;border:1px solid #DCDCDE;border-radius:8px"><table class="widefat striped" style="border:0"><thead><tr><th>Categorie</th><th style="width:90px;text-align:center">In winkel</th><th style="width:90px;text-align:center">Verbergen</th></tr></thead><tbody>';
					foreach ( $tree as $path => $n ) {
						$depth = substr_count( $path, '>' );
						$name  = trim( substr( strrchr( ' > ' . $path, '>' ), 1 ) );
						$imp   = MDO_Sync::in_list( $path, $s['categories'] );
						$own_i = in_array( $path, (array) $s['categories'], true );
						$own_h = in_array( $path, (array) $s['hidden_categories'], true );
						$rows .= '<tr><td style="padding-left:' . ( 10 + 20 * $depth ) . 'px">' . esc_html( $name ) . ' <span style="color:#787C82">(' . (int) $n . ')</span></td>';
						$rows .= '<td style="text-align:center"><input type="checkbox" name="categories[]" value="' . esc_attr( $path ) . '"' . checked( $own_i, true, false ) . '>' . ( ( $imp && ! $own_i ) ? ' <span style="color:#1E6B3A" title="Via bovenliggende categorie">✓</span>' : '' ) . '</td>';
						$rows .= '<td style="text-align:center"><input type="checkbox" name="hidden_categories[]" value="' . esc_attr( $path ) . '"' . checked( $own_h, true, false ) . '></td></tr>';
					}
					$rows .= '</tbody></table></div><p></p>';
					echo self::form( 'categories', $rows, 'Categorieën opslaan', 'button button-primary' ); // phpcs:ignore
					?>
				<?php endif; ?>
			</div>

			<p style="max-width:1100px;color:#646970">Meer instellingen (productnamen, vertalingen, uitleg technieken, voorraad, logboek): <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . MDO_Admin::SLUG ) ); ?>">PromoFun → Instellingen</a></p>
		</div>
		<?php
	}
}
