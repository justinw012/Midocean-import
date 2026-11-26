<?php
/**
 * Bezoekers (uit Koko Analytics) en verkoop (uit WooCommerce) op de PromoFun-pagina.
 * Koko telt zonder cookies en zonder persoonsgegevens; hier worden alleen totalen getoond.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Stats {

	public static function koko_active() {
		return class_exists( '\KokoAnalytics\Stats' );
	}

	private static function koko() {
		static $k = null;
		if ( null === $k ) {
			$k = self::koko_active() ? new \KokoAnalytics\Stats() : false;
		}
		return $k;
	}

	private static function day( $offset ) {
		return ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( ( $offset >= 0 ? '+' : '' ) . (int) $offset . ' days' )->format( 'Y-m-d' );
	}

	/** Bezoekers en weergaven tussen twee datums (Y-m-d, inclusief). */
	private static function visits( $from, $to ) {
		$k = self::koko();
		if ( ! $k ) {
			return null;
		}
		try {
			$r = $k->get_totals( $from, $to );
			return array( (int) $r->visitors, (int) $r->pageviews );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** Betaalde bestellingen en omzet excl. btw sinds een datum. */
	private static function sales( $from, $to ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( 0, 0.0 );
		}
		$orders = wc_get_orders( array(
			'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
			'date_created' => strtotime( $from . ' 00:00:00 ' . wp_timezone_string() ) . '...' . strtotime( $to . ' 23:59:59 ' . wp_timezone_string() ),
			'limit'        => -1,
			'type'         => 'shop_order',
		) );
		$sum = 0.0;
		foreach ( $orders as $o ) {
			$sum += (float) $o->get_total() - (float) $o->get_total_tax();
		}
		return array( count( $orders ), $sum );
	}

	/** Voor de homepage: bezoekers vandaag en de laatste 7 dagen (null zonder Koko). */
	public static function visitors() {
		$c = get_transient( 'mdo_home_visitors' );
		if ( is_array( $c ) ) {
			return $c;
		}
		$t = self::visits( self::day( 0 ), self::day( 0 ) );
		$w = self::visits( self::day( -6 ), self::day( 0 ) );
		$c = array( $t ? $t[0] : null, $w ? $w[0] : null );
		set_transient( 'mdo_home_visitors', $c, 10 * MINUTE_IN_SECONDS );
		return $c;
	}

	/** Meest bekeken producten van de laatste 30 dagen (ids), uit Koko. */
	public static function top_products( $n = 8 ) {
		$d = self::data();
		return array_slice( array_map( function ( $r ) {
			return (int) $r[2];
		}, $d['products'] ), 0, $n );
	}

	private static function data() {
		$cached = get_transient( 'mdo_stats' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$periods = array(
			'today' => array( 'Vandaag', self::day( 0 ), self::day( 0 ), self::day( -1 ), self::day( -1 ) ),
			'week'  => array( 'Laatste 7 dagen', self::day( -6 ), self::day( 0 ), self::day( -13 ), self::day( -7 ) ),
			'month' => array( 'Laatste 30 dagen', self::day( -29 ), self::day( 0 ), self::day( -59 ), self::day( -30 ) ),
		);
		$d = array( 'periods' => array(), 'chart' => array(), 'products' => array(), 'sources' => array() );
		foreach ( $periods as $key => $p ) {
			$d['periods'][ $key ] = array(
				'label'  => $p[0],
				'visits' => self::visits( $p[1], $p[2] ),
				'prev'   => self::visits( $p[3], $p[4] ),
				'sales'  => self::sales( $p[1], $p[2] ),
			);
		}
		$k = self::koko();
		if ( $k ) {
			try {
				foreach ( $k->get_stats( self::day( -13 ), self::day( 0 ) ) as $row ) {
					$d['chart'][] = array( (string) $row->date, (int) $row->visitors );
				}
				foreach ( $k->get_posts( self::day( -29 ), self::day( 0 ), 0, 60 ) as $row ) {
					if ( $row->post_id > 0 && 'product' === get_post_type( $row->post_id ) ) {
						$d['products'][] = array( get_the_title( $row->post_id ), (int) $row->visitors, (int) $row->post_id );
						if ( count( $d['products'] ) >= 8 ) {
							break;
						}
					}
				}
				if ( method_exists( $k, 'get_referrers' ) ) {
					foreach ( $k->get_referrers( self::day( -29 ), self::day( 0 ), 0, 6 ) as $row ) {
						$host             = wp_parse_url( (string) $row->url, PHP_URL_HOST );
						$d['sources'][] = array( $host ? preg_replace( '/^www\./', '', $host ) : (string) $row->url, (int) $row->visitors );
					}
				}
			} catch ( \Throwable $e ) {
				// Koko-versie met andere opbouw: alleen de totalen tonen.
			}
		}
		set_transient( 'mdo_stats', $d, 10 * MINUTE_IN_SECONDS );
		return $d;
	}

	private static function eur( $v ) {
		return '€ ' . number_format( (float) $v, 2, ',', '.' );
	}

	private static function trend( $now, $prev ) {
		if ( null === $prev || $prev <= 0 ) {
			return '';
		}
		$pct = round( ( $now - $prev ) / $prev * 100 );
		if ( 0 == $pct ) {
			return '<span style="color:#646970">gelijk</span>';
		}
		$up = $pct > 0;
		return '<span style="color:' . ( $up ? '#1E6B3A' : '#B42318' ) . '">' . ( $up ? '▲ +' : '▼ ' ) . (int) $pct . '%</span>';
	}

	public static function render( $card ) {
		$d    = self::data();
		$koko = self::koko_active();
		$tile = 'flex:1 1 220px;border:1px solid #E3E3E3;border-radius:10px;padding:12px 14px';
		?>
		<div id="bezoekers" style="<?php echo esc_attr( $card ); ?>">
			<h2 style="margin-top:0">Bezoekers en verkoop</h2>
			<?php if ( ! $koko ) : ?>
				<p style="background:#FFF4E5;padding:10px 12px;border-radius:8px;margin-top:0">Bezoekerscijfers verschijnen hier zodra de plugin <strong>Koko Analytics</strong> actief is. De verkoopcijfers hieronder komen uit WooCommerce.</p>
			<?php endif; ?>
			<div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px">
				<?php foreach ( $d['periods'] as $p ) :
					list( $orders, $sum ) = $p['sales'];
					$v = $p['visits'];
					?>
					<div style="<?php echo esc_attr( $tile ); ?>">
						<div style="color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.04em"><?php echo esc_html( $p['label'] ); ?></div>
						<?php if ( $v ) : ?>
							<div style="font-size:24px;font-weight:700;line-height:1.3"><?php echo (int) $v[0]; ?> <span style="font-size:14px;font-weight:400">bezoekers</span></div>
							<div style="font-size:13px;color:#3C434A"><?php echo (int) $v[1]; ?> pagina's bekeken · <?php echo self::trend( $v[0], $p['prev'] ? $p['prev'][0] : null ); // phpcs:ignore ?></div>
						<?php endif; ?>
						<div style="font-size:13px;margin-top:6px"><strong><?php echo (int) $orders; ?></strong> bestelling<?php echo 1 === $orders ? '' : 'en'; ?> · <strong><?php echo esc_html( self::eur( $sum ) ); ?></strong> <span style="color:#646970">excl. btw</span></div>
						<?php if ( $v && $v[0] > 0 && $orders > 0 ) : ?>
							<div style="font-size:12px;color:#646970">Conversie: <?php echo esc_html( number_format( $orders / $v[0] * 100, 1, ',', '' ) ); ?>% van de bezoekers bestelde</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( $d['chart'] ) :
				$max = max( 1, max( array_column( $d['chart'], 1 ) ) );
				$days = array( 'zo', 'ma', 'di', 'wo', 'do', 'vr', 'za' );
				?>
				<h3 style="margin:6px 0 8px;font-size:14px">Bezoekers per dag (14 dagen)</h3>
				<div style="display:flex;align-items:flex-end;gap:4px;height:120px;max-width:700px;border-bottom:1px solid #DCDCDE;padding-bottom:2px">
					<?php foreach ( $d['chart'] as $c ) : ?>
						<div title="<?php echo esc_attr( $c[0] . ': ' . $c[1] . ' bezoekers' ); ?>" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%">
							<span style="font-size:10px;color:#646970"><?php echo $c[1] ? (int) $c[1] : ''; ?></span>
							<div style="width:100%;background:#DF3550;border-radius:3px 3px 0 0;height:<?php echo esc_attr( max( 1, round( $c[1] / $max * 100 ) ) ); ?>px"></div>
						</div>
					<?php endforeach; ?>
				</div>
				<div style="display:flex;gap:4px;max-width:700px;font-size:10px;color:#646970;margin-bottom:14px">
					<?php foreach ( $d['chart'] as $c ) : ?>
						<span style="flex:1;text-align:center"><?php echo esc_html( $days[ (int) gmdate( 'w', strtotime( $c[0] ) ) ] ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $d['products'] || $d['sources'] ) : ?>
				<div style="display:flex;flex-wrap:wrap;gap:20px">
					<?php if ( $d['products'] ) : ?>
						<div style="flex:1 1 320px">
							<h3 style="margin:0 0 6px;font-size:14px">Meest bekeken producten (30 dagen)</h3>
							<table class="widefat striped" style="max-width:520px"><tbody>
								<?php foreach ( $d['products'] as $p ) : ?>
									<tr><td><a href="<?php echo esc_url( get_permalink( $p[2] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p[0] ); ?></a></td><td style="text-align:right;white-space:nowrap"><?php echo (int) $p[1]; ?> bezoekers</td></tr>
								<?php endforeach; ?>
							</tbody></table>
						</div>
					<?php endif; ?>
					<?php if ( $d['sources'] ) : ?>
						<div style="flex:1 1 240px">
							<h3 style="margin:0 0 6px;font-size:14px">Waar komen bezoekers vandaan (30 dagen)</h3>
							<table class="widefat striped" style="max-width:380px"><tbody>
								<?php foreach ( $d['sources'] as $s ) : ?>
									<tr><td><?php echo esc_html( $s[0] ); ?></td><td style="text-align:right;white-space:nowrap"><?php echo (int) $s[1]; ?></td></tr>
								<?php endforeach; ?>
							</tbody></table>
							<p style="font-size:12px;color:#646970;margin:4px 0 0">Bezoekers die je adres intypen of via een bladwijzer komen, staan hier niet bij.</p>
						</div>
					<?php endif; ?>
				</div>
			<?php elseif ( $koko ) : ?>
				<p style="color:#646970">Nog weinig gegevens. Koko telt vanaf het moment dat de plugin aanstaat; na een dag of twee verschijnen hier de populairste producten en bronnen.</p>
			<?php endif; ?>

			<p style="margin:12px 0 0;font-size:12px;color:#646970">Cijfers worden elke 10 minuten ververst. Bezoekers worden zonder cookies en zonder persoonsgegevens geteld.
				<?php if ( $koko && current_user_can( 'view_koko_analytics' ) ) : ?>
					<a href="<?php echo esc_url( admin_url( 'index.php?page=koko-analytics' ) ); ?>">Alle statistieken in Koko →</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}
