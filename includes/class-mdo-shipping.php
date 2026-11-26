<?php
/**
 * Verzendkosten in WooCommerce zetten vanuit PromoFun → Instellingen:
 * één verzendzone "Nederland en België" met een vast bedrag per bestelling,
 * eventueel gratis vanaf een orderbedrag.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Shipping {

	const OPTION = 'mdo_shipping';

	public static function init() {
		add_action( 'admin_post_mdo_shipping', array( __CLASS__, 'handle' ) );
	}

	public static function get() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), array(
			'cost'      => '12.30',
			'free_from' => '',
			'zone_id'   => 0,
		) );
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Geen toegang.' );
		}
		check_admin_referer( 'mdo_shipping' );
		$o              = self::get();
		$o['cost']      = wc_format_decimal( wp_unslash( $_POST['cost'] ?? '0' ), 2 );
		$o['free_from'] = '' === trim( (string) ( $_POST['free_from'] ?? '' ) ) ? '' : wc_format_decimal( wp_unslash( $_POST['free_from'] ), 2 );
		$o['zone_id']   = self::apply( $o );
		update_option( self::OPTION, $o, false );
		MDO_Log::info( 'Verzendkosten ingesteld: ' . $o['cost'] . ( $o['free_from'] ? ', gratis vanaf ' . $o['free_from'] : '' ) . '.' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . MDO_Admin::SLUG . '&mdo_ship=1#verzending' ) );
		exit;
	}

	/** Zone aanmaken of bijwerken; geeft het zone-id terug. */
	private static function apply( array $o ) {
		$zone = null;
		if ( $o['zone_id'] ) {
			$z = WC_Shipping_Zones::get_zone( (int) $o['zone_id'] );
			if ( $z && $z->get_id() ) {
				$zone = $z;
			}
		}
		if ( ! $zone ) {
			$zone = new WC_Shipping_Zone();
		}
		$zone->set_zone_name( 'Nederland en België' );
		$zone->set_zone_order( 0 );
		$zone->clear_locations();
		$zone->add_location( 'NL', 'country' );
		$zone->add_location( 'BE', 'country' );
		$zone->save();

		$flat = null;
		$free = null;
		foreach ( $zone->get_shipping_methods() as $m ) {
			if ( 'flat_rate' === $m->id && ! $flat ) {
				$flat = $m;
			} elseif ( 'free_shipping' === $m->id && ! $free ) {
				$free = $m;
			}
		}
		$flat_id = $flat ? $flat->instance_id : $zone->add_shipping_method( 'flat_rate' );
		update_option( 'woocommerce_flat_rate_' . $flat_id . '_settings', array(
			'title'      => 'Verzending',
			'tax_status' => 'taxable',
			'cost'       => (string) $o['cost'],
			'type'       => 'order',
		) );
		if ( '' !== $o['free_from'] && (float) $o['free_from'] > 0 ) {
			$free_id = $free ? $free->instance_id : $zone->add_shipping_method( 'free_shipping' );
			update_option( 'woocommerce_free_shipping_' . $free_id . '_settings', array(
				'title'            => 'Gratis verzending',
				'requires'         => 'min_amount',
				'min_amount'       => (string) $o['free_from'],
				'ignore_discounts' => 'no',
			) );
		} elseif ( $free ) {
			$zone->delete_shipping_method( $free->instance_id );
		}
		WC_Cache_Helper::get_transient_version( 'shipping', true );
		return $zone->get_id();
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! class_exists( 'WC_Shipping_Zone' ) ) {
			return;
		}
		$o = self::get();
		?>
		<h2 id="verzending">Verzendkosten</h2>
		<?php if ( isset( $_GET['mdo_ship'] ) ) : // phpcs:ignore ?>
			<div class="notice notice-success inline"><p>Verzendkosten staan in WooCommerce (zone "Nederland en België").</p></div>
		<?php endif; ?>
		<p class="description" style="max-width:960px">Midocean rekent per bestelling een vast bedrag (bij jou € 12,30 bij 10, 100 en 1000 stuks). Hier zet je wat de klant betaalt. Het bedrag is <strong>exclusief btw</strong>; WooCommerce telt de btw er net als bij producten bij op. De knop maakt of werkt de verzendzone "Nederland en België" bij in WooCommerce → Instellingen → Verzending.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mdo_shipping">
			<?php wp_nonce_field( 'mdo_shipping' ); ?>
			<table class="form-table" role="presentation">
				<tr><th>Verzendkosten per bestelling</th><td>€ <input type="text" name="cost" value="<?php echo esc_attr( $o['cost'] ); ?>" style="width:90px"> excl. btw</td></tr>
				<tr><th>Gratis verzending vanaf</th><td>€ <input type="text" name="free_from" value="<?php echo esc_attr( $o['free_from'] ); ?>" style="width:90px" placeholder="leeg = nooit"> orderbedrag</td></tr>
			</table>
			<p><button class="button button-primary">Toepassen in WooCommerce</button></p>
		</form>
		<?php
	}
}
