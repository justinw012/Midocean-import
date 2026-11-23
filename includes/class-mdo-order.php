<?php
/**
 * Bestelscherm: blok "Midocean" met per regel de gegevens die Midocean nodig heeft
 * (artikelnummer, aantal, positie- en techniekcodes, formaat, kleuren, logo).
 * Automatisch doorsturen volgt zodra de Order API-specificatie van Midocean verwerkt is.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Order {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
	}

	public static function add_box() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'mdo-order', 'Midocean', array( __CLASS__, 'render' ), $screen, 'normal', 'high' );
		}
	}

	/** Alle Midocean-regels van een bestelling, als leesbare tekst en als gestructureerde data. */
	public static function lines( $order ) {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			$cfg = $item->get_meta( '_mdo_config' );
			if ( ! is_array( $cfg ) ) {
				continue;
			}
			$product = $item->get_product();
			$prints  = array();
			foreach ( (array) $cfg['prints'] as $p ) {
				$prints[] = array(
					'face'     => $p['face'],
					'position' => $p['position'],
					'tech'     => $p['tech'],
					'techname' => $p['techname'],
					'colours'  => $p['full'] ? 'full colour' : (int) $p['colours'],
					'size'     => $p['w'] . ' × ' . $p['h'] . ' mm' . ( ! empty( $p['rot'] ) ? ' (' . $p['rot'] . '° gedraaid)' : '' ),
					'mode'     => $p['mode'],
					'text'     => $p['text'],
					'font'     => isset( MDO_Cart::FONTS[ $p['font'] ] ) ? MDO_Cart::FONTS[ $p['font'] ] : $p['font'],
					'ink'      => $p['ink'] ?? '',
					'pms'      => $p['pms'] ?? '',
					'logo'     => $item->get_meta( '_mdo_logo_' . sanitize_key( $p['position'] ) ),
					'logoname' => $p['logoname'] ?? '',
				);
			}
			$out[] = array(
				'name'   => $item->get_name(),
				'sku'    => $product ? $product->get_sku() : '',
				'qty'    => $item->get_quantity(),
				'prints' => $prints,
				'note'   => $cfg['note'] ?? '',
			);
		}
		return $out;
	}

	public static function as_text( array $lines ) {
		$t = array();
		foreach ( $lines as $l ) {
			$t[] = $l['sku'] . ' × ' . $l['qty'] . ' (' . $l['name'] . ')';
			if ( ! $l['prints'] ) {
				$t[] = '  Blanco';
			}
			foreach ( $l['prints'] as $p ) {
				$t[] = '  ' . $p['face'] . ' – positie ' . $p['position'] . ', techniek ' . $p['tech'] . ' (' . $p['techname'] . '), ' . $p['colours'] . ( is_int( $p['colours'] ) ? ' kleur(en)' : '' ) . ', ' . $p['size'];
				if ( 'text' === $p['mode'] ) {
					$t[] = '    Tekst: "' . $p['text'] . '", lettertype ' . $p['font'] . ( $p['ink'] ? ', kleur ' . $p['ink'] : '' ) . ( $p['pms'] ? ', PMS ' . $p['pms'] : '' );
				} else {
					$t[] = '    Logo: ' . ( $p['logo'] ? $p['logo'] : 'nog niet aangeleverd' ) . ( $p['pms'] ? ', PMS ' . $p['pms'] : '' );
				}
			}
			if ( $l['note'] ) {
				$t[] = '  Opmerking klant: ' . $l['note'];
			}
			$t[] = '';
		}
		return trim( implode( "\n", $t ) );
	}

	public static function render( $post_or_order ) {
		$order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$lines = self::lines( $order );
		if ( ! $lines ) {
			echo '<p>Geen Midocean-producten met bedrukking in deze bestelling.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Artikel</th><th>Aantal</th><th>Bedrukking (Midocean-codes)</th><th>Ontwerp</th></tr></thead><tbody>';
		foreach ( $lines as $l ) {
			echo '<tr><td><strong>' . esc_html( $l['sku'] ) . '</strong><br>' . esc_html( $l['name'] ) . '</td><td>' . (int) $l['qty'] . '</td><td>';
			if ( ! $l['prints'] ) {
				echo 'Blanco';
			}
			foreach ( $l['prints'] as $p ) {
				echo '<div style="margin-bottom:6px"><strong>' . esc_html( $p['face'] ) . '</strong>: positie <code>' . esc_html( $p['position'] ) . '</code>, techniek <code>' . esc_html( $p['tech'] ) . '</code> (' . esc_html( $p['techname'] ) . '), ' . esc_html( (string) $p['colours'] ) . ( is_int( $p['colours'] ) ? ' kleur(en)' : '' ) . ', ' . esc_html( $p['size'] ) . '</div>';
			}
			echo '</td><td>';
			foreach ( $l['prints'] as $p ) {
				if ( 'text' === $p['mode'] ) {
					echo '<div style="margin-bottom:6px">Tekst “' . esc_html( $p['text'] ) . '”, ' . esc_html( $p['font'] ) . ( $p['ink'] ? ', <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . esc_attr( $p['ink'] ) . ';border:1px solid #ccc"></span> ' . esc_html( $p['ink'] ) : '' ) . ( $p['pms'] ? ', PMS ' . esc_html( $p['pms'] ) : '' ) . '</div>';
				} elseif ( $p['logo'] ) {
					echo '<div style="margin-bottom:6px"><a href="' . esc_url( $p['logo'] ) . '" target="_blank" rel="noopener">Logo downloaden</a>' . ( $p['pms'] ? ', PMS ' . esc_html( $p['pms'] ) : '' ) . '</div>';
				} else {
					echo '<div style="margin-bottom:6px;color:#B54708">Logo nog niet aangeleverd</div>';
				}
			}
			if ( $l['note'] ) {
				echo '<div><em>Opmerking: ' . esc_html( $l['note'] ) . '</em></div>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p style="margin-top:10px"><label for="mdo-copy"><strong>Om over te nemen in Midocean</strong></label></p>';
		echo '<textarea id="mdo-copy" readonly rows="6" style="width:100%;font-family:monospace;font-size:12px">' . esc_textarea( self::as_text( $lines ) ) . '</textarea>';
		echo '<p><button type="button" class="button" onclick="var t=document.getElementById(\'mdo-copy\');t.select();navigator.clipboard&&navigator.clipboard.writeText(t.value);this.textContent=\'Gekopieerd\';">Kopiëren</button> ';
		echo '<button type="button" class="button button-primary" disabled title="Volgt zodra de Order API van Midocean is ingericht">Doorsturen naar Midocean</button></p>';
	}
}
