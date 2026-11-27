<?php
/**
 * Homepage-blok: [promofun_home]
 *
 * Opbouw: voordelenbalk, kop met een product waar ons logo op gedrukt staat, bezoekers (Koko),
 * "Wat zoek je?" met de hoofdcategorieën, hoe het werkt, populaire producten.
 * Kleuren en letters sluiten aan op het thema (Poppins, paars) en het logo (verloop blauw → roze).
 */

defined( 'ABSPATH' ) || exit;

class MDO_Home {

	public static function init() {
		add_shortcode( 'promofun_home', array( __CLASS__, 'render' ) );
	}

	private static function assets() {
		wp_enqueue_style( 'mdo-home', MDO_URL . 'assets/home.css', array(), MDO_VERSION );
		wp_enqueue_script( 'mdo-home', MDO_URL . 'assets/home.js', array(), MDO_VERSION, true );
		// Voorbeeld met logo en fotoachtergrond (zelfde script als in de winkel).
		MDO_Shop::assets();
	}

	/** Prijs per stuk bij 250 stuks (incl. goedkoopste bedrukking in 1 kleur, zoals de winkel hem toont). */
	private static function price_250( $id ) {
		$built = 0;
		$row   = MDO_Filter::row( $id, $built );
		if ( ! $row || ! empty( $row['x'] ) || empty( $row['pr'] ) ) {
			return null;
		}
		$i = array_search( 250, MDO_Frontend::TIERS, true );
		$tx = 'incl' === get_option( 'woocommerce_tax_display_shop' ) ? (float) ( $row['tx'] ?? 1 ) : 1.0;
		return array( (float) $row['pr'][ $i ] * $tx, ! empty( $row['pp'] ), $row );
	}

	/** Welke producten tonen we als "Populair"? Eigen keuze → meest bekeken (Koko) → meest verkocht → nieuwste. */
	private static function popular_ids( $n = 8 ) {
		$own = array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', (string) MDO_Settings::get( 'home_products' ) ) ) );
		$ids = $own;
		if ( count( $ids ) < $n && class_exists( 'MDO_Stats' ) && MDO_Stats::koko_active() ) {
			$ids = array_merge( $ids, MDO_Stats::top_products( $n * 2 ) );
		}
		if ( count( $ids ) < $n ) {
			$ids = array_merge( $ids, wc_get_products( array( 'status' => 'publish', 'visibility' => 'catalog', 'limit' => $n * 2, 'orderby' => 'popularity', 'return' => 'ids' ) ) );
		}
		$out = array();
		foreach ( array_unique( $ids ) as $id ) {
			$p = wc_get_product( $id );
			if ( $p && 'publish' === $p->get_status() && $p->is_visible() ) {
				$out[] = (int) $id;
			}
			if ( count( $out ) >= $n ) {
				break;
			}
		}
		return $out;
	}

	/** Hoofdcategorieën met producten, met een foto: de categorie-afbeelding of een product eruit. */
	public static function categories( $n = 8 ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC' ) );
		$out   = array();
		if ( is_wp_error( $terms ) ) {
			return $out;
		}
		foreach ( $terms as $t ) {
			if ( in_array( $t->slug, array( 'uncategorized', 'geen-categorie' ), true ) ) {
				continue;
			}
			$img   = '';
			$thumb = (int) get_term_meta( $t->term_id, 'thumbnail_id', true );
			if ( $thumb ) {
				$src = wp_get_attachment_image_src( $thumb, 'woocommerce_thumbnail' );
				$img = $src ? $src[0] : '';
			}
			if ( ! $img ) {
				$ids = wc_get_products( array( 'status' => 'publish', 'visibility' => 'catalog', 'limit' => 1, 'category' => array( $t->slug ), 'orderby' => 'popularity', 'return' => 'ids' ) );
				if ( $ids ) {
					$src = wp_get_attachment_image_src( get_post_thumbnail_id( $ids[0] ), 'woocommerce_thumbnail' );
					$img = $src ? $src[0] : '';
				}
			}
			if ( ! $img ) {
				continue; // zonder zichtbaar product geen tegel
			}
			$out[] = array( html_entity_decode( $t->name ), get_term_link( $t ), $img );
			if ( count( $out ) >= $n ) {
				break;
			}
		}
		return $out;
	}

	public static function card( $id ) {
		$p = wc_get_product( $id );
		if ( ! $p ) {
			return '';
		}
		$pr   = self::price_250( $id );
		$src  = wp_get_attachment_image_src( $p->get_image_id(), 'woocommerce_thumbnail' );
		$img  = $src ? $src[0] : wc_placeholder_img_src( 'woocommerce_thumbnail' );
		$hov  = ( $pr && ! empty( $pr[2]['hov'] ) ) ? $pr[2]['hov'] : null;
		$dots = '';
		if ( $pr && ! empty( $pr[2]['c'] ) ) {
			$seen = array();
			foreach ( $pr[2]['c'] as $c ) {
				if ( isset( $seen[ $c['g'] ] ) || count( $seen ) >= 8 ) {
					continue;
				}
				$seen[ $c['g'] ] = 1;
				$g               = MDO_Filter::GROUPS[ $c['g'] ] ?? array( '', '' );
				$dots           .= '<span class="mdo-dot' . ( 'transparant' === $c['g'] ? ' is-clear' : ( 'multi' === $c['g'] ? ' is-multi' : '' ) ) . '" title="' . esc_attr( $g[0] ) . '"' . ( $g[1] ? ' style="background:' . esc_attr( $g[1] ) . '"' : '' ) . '></span>';
			}
		}
		$price = $pr
			? '<span class="mdo-card-p">Vanaf <b>' . wp_strip_all_tags( wc_price( $pr[0] ) ) . '</b> <span>p/st bij 250 st.' . ( $pr[1] ? ' incl. bedrukking' : '' ) . '</span></span>'
			: '<span class="mdo-card-p">' . wp_strip_all_tags( html_entity_decode( $p->get_price_html() ) ) . '</span>';
		ob_start();
		?>
		<li class="product mdo-card">
			<a class="mdo-card-a" href="<?php echo esc_url( add_query_arg( 'aantal', 250, get_permalink( $id ) ) ); ?>">
				<span class="mdo-card-img"><img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( html_entity_decode( $p->get_name() ) ); ?>" loading="lazy" decoding="async"></span>
				<span class="mdo-card-n"><?php echo esc_html( html_entity_decode( $p->get_name() ) ); ?></span>
				<?php if ( $dots ) : ?><span class="mdo-dots"><?php echo $dots; // phpcs:ignore ?></span><?php endif; ?>
				<?php echo $price; // phpcs:ignore ?>
			</a>
			<?php if ( $hov && MDO_Settings::get( 'shop_hover' ) ) : ?><span class="mdo-hov" hidden data-mdo-hov="<?php echo esc_attr( wp_json_encode( $hov ) ); ?>"></span><?php endif; ?>
		</li>
		<?php
		return ob_get_clean();
	}

	/**
	 * Producten die bovenaan rouleren, elk met ons logo er vast op gedrukt (getekend in de browser).
	 * Eigen keuze (product-ID's) → anders een tas, de populaire producten en uit elke hoofdcategorie één,
	 * met zo veel mogelijk verschillende categorieën.
	 */
	private static function hero_products( array $popular, $max = 5 ) {
		if ( ! MDO_Shop::logo_url() ) {
			return array();
		}
		$want = array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', (string) MDO_Settings::get( 'home_hero' ) ) ) );
		$cand = $want;
		if ( ! $want ) {
			$bags = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 's' => 'tas', 'fields' => 'ids', 'posts_per_page' => 30, 'no_found_rows' => true ) );
			$bags = array_values( array_filter( (array) $bags, function ( $id ) {
				return (bool) preg_match( '/\b(tas|tasje|shopper|draagtas|boodschappentas|strandtas)\b|tas\b/i', html_entity_decode( get_the_title( $id ) ) );
			} ) );
			$cand = array_merge( array_slice( $bags, 0, 1 ), $popular );
			$tops = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 10 ) );
			foreach ( is_wp_error( $tops ) ? array() : $tops as $t ) {
				$cand = array_merge( $cand, wc_get_products( array( 'status' => 'publish', 'visibility' => 'catalog', 'limit' => 2, 'category' => array( $t->slug ), 'orderby' => 'popularity', 'return' => 'ids' ) ) );
			}
		}
		$out  = array();
		$used = array();
		foreach ( array( true, false ) as $spread ) { // eerst één per categorie, dan aanvullen
			foreach ( array_unique( $cand ) as $id ) {
				if ( count( $out ) >= $max ) {
					break 2;
				}
				if ( isset( $out[ $id ] ) ) {
					continue;
				}
				$p = wc_get_product( $id );
				if ( ! $p || 'publish' !== $p->get_status() ) {
					continue;
				}
				$top = 0;
				foreach ( $p->get_category_ids() as $cid ) {
					$anc = get_ancestors( $cid, 'product_cat' );
					$top = $anc ? (int) end( $anc ) : (int) $cid;
					break;
				}
				if ( $spread && ! $want && isset( $used[ $top ] ) ) {
					continue;
				}
				$hov = MDO_Shop::mark_data( $p );
				// Geen rondom-opdruk: die foto's tonen meerdere aanzichten naast elkaar.
				if ( ! $hov || preg_match( '/360|WRAP|AROUND|ROUND/i', (string) $hov['id'] ) ) {
					continue;
				}
				$out[ $id ]   = array( $p, $hov );
				$used[ $top ] = 1;
			}
		}
		return array_values( $out );
	}

	public static function render() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return '';
		}
		self::assets();
		$s       = MDO_Settings::all();
		$popular = self::popular_ids( 8 );
		$try     = self::hero_products( $popular, 6 );
		$hero    = array_slice( $try, 0, 5 );
		$cats    = self::categories( 8 );
		$lines   = array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', (string) $s['home_title'] ) ) ) );
		$tax     = function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() && 'incl' === get_option( 'woocommerce_tax_display_shop' );
		$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		// Bezoekersaantallen alleen als dat bij Instellingen aan staat (standaard uit).
		$visits   = ( $s['home_stats'] && class_exists( 'MDO_Stats' ) && MDO_Stats::koko_active() ) ? MDO_Stats::visitors() : array( null, null );
		$count    = (int) wp_count_posts( 'product' )->publish;

		ob_start();
		?>
		<div class="mdo-home">
			<ul class="mdo-h-usp">
				<li><?php echo $tax ? 'Prijs incl. bedrukking en btw direct zichtbaar' : 'Prijs incl. bedrukking direct zichtbaar'; ?></li>
				<li>Levertijd per product berekend</li>
				<?php $btw = MDO_Tax::html(); if ( $btw ) : ?><li class="mdo-h-btw">Prijzen <?php echo $btw; // phpcs:ignore ?></li><?php endif; ?>
			</ul>

			<section class="mdo-h-hero">
				<div class="mdo-h-copy">
					<p class="mdo-h-kicker">Promotieartikelen met je eigen logo</p>
					<h1 class="mdo-h-title"><?php foreach ( $lines as $i => $l ) : ?><span class="<?php echo count( $lines ) - 1 === $i ? 'mdo-grad' : ''; ?>"><?php echo esc_html( $l ); ?></span><?php endforeach; ?></h1>
					<?php if ( $s['home_text'] ) : ?><p class="mdo-h-text"><?php echo esc_html( $s['home_text'] ); ?></p><?php endif; ?>
					<p class="mdo-h-btns">
						<a class="mdo-h-btn" href="<?php echo esc_url( $shop_url ); ?>">Bekijk producten</a>
						<a class="mdo-h-btn is-ghost" href="#mdo-h-how">Hoe het werkt</a>
					</p>
					<?php if ( null !== $visits[0] || $count ) : ?>
					<dl class="mdo-h-stats">
						<?php if ( null !== $visits[0] ) : ?><div><dt><?php echo esc_html( number_format_i18n( $visits[0] ) ); ?></dt><dd>bezoekers vandaag</dd></div><?php endif; ?>
						<?php if ( null !== $visits[1] ) : ?><div><dt><?php echo esc_html( number_format_i18n( $visits[1] ) ); ?></dt><dd>bezoekers deze week</dd></div><?php endif; ?>
						<?php if ( $count ) : ?><div><dt><?php echo esc_html( number_format_i18n( $count ) ); ?></dt><dd>producten in de shop</dd></div><?php endif; ?>
					</dl>
					<?php endif; ?>
				</div>
				<?php if ( $hero ) : ?>
				<div class="mdo-h-show" data-mdo-rotate>
					<?php foreach ( $hero as $i => $h ) : list( $hp, $hov ) = $h; $nm = html_entity_decode( $hp->get_name() ); ?>
					<a class="mdo-h-slide product<?php echo 0 === $i ? ' is-on' : ''; ?>" href="<?php echo esc_url( get_permalink( $hp->get_id() ) ); ?>"<?php echo 0 === $i ? '' : ' aria-hidden="true" tabindex="-1"'; ?>>
						<img src="<?php echo esc_url( $hov['img'] ); ?>" alt="<?php echo esc_attr( $nm ); ?> met PromoFun-logo"<?php echo 0 === $i ? '' : ' loading="lazy"'; ?>>
						<span class="mdo-hov" hidden data-mdo-always="1" data-mdo-hov="<?php echo esc_attr( wp_json_encode( $hov ) ); ?>"></span>
						<span class="mdo-h-note"><b>Zo ziet jouw logo eruit</b> op <?php echo esc_html( $nm ); ?></span>
					</a>
					<?php endforeach; ?>
					<?php if ( count( $hero ) > 1 ) : ?>
					<div class="mdo-h-dots">
						<?php foreach ( $hero as $i => $h ) : ?><button type="button" aria-label="Voorbeeld <?php echo (int) $i + 1; ?>"<?php echo 0 === $i ? ' aria-current="true"' : ''; ?>></button><?php endforeach; ?>
					</div>
					<?php endif; ?>
				</div>
				<?php endif; ?>
			</section>

			<?php if ( count( $try ) >= 2 ) : ?>
			<section class="mdo-h-sec mdo-h-band mdo-h-try" id="mdo-h-try">
				<div class="mdo-h-try-head">
					<h2>Probeer je eigen logo</h2>
					<p>Kies je logo en zie het meteen op onze producten. Daarna zie je het ook bij elk product in de shop, en staat het klaar als je gaat bestellen: met één klik gebruik je het voor je bestelling.</p>
					<p class="mdo-h-try-note">Je logo blijft in je eigen browser; wij ontvangen het pas als je het bij een product kiest voor je bestelling.</p>
					<p class="mdo-h-try-act">
						<label class="mdo-h-btn mdo-h-up"><input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="mdo-h-file"> Kies je logo</label>
						<button type="button" class="mdo-h-reset" hidden>Terug naar het PromoFun-logo</button>
					</p>
					<p class="mdo-h-try-msg" aria-live="polite"></p>
				</div>
				<ul class="mdo-h-try-grid">
					<?php foreach ( $try as $h ) : list( $tp, $thov ) = $h; $tn = html_entity_decode( $tp->get_name() ); ?>
					<li class="product mdo-h-tryitem">
						<a href="<?php echo esc_url( get_permalink( $tp->get_id() ) ); ?>">
							<span class="mdo-h-tryimg"><img src="<?php echo esc_url( $thov['img'] ); ?>" alt="<?php echo esc_attr( $tn ); ?>" loading="lazy"></span>
							<span class="mdo-h-tryname"><?php echo esc_html( $tn ); ?></span>
						</a>
						<span class="mdo-hov" hidden data-mdo-always="1" data-mdo-hov="<?php echo esc_attr( wp_json_encode( $thov ) ); ?>"></span>
					</li>
					<?php endforeach; ?>
				</ul>
			</section>
			<?php endif; ?>

			<?php if ( $cats ) : ?>
			<section class="mdo-h-sec mdo-h-band mdo-h-band-blue">
				<h2>Wat zoek je?</h2>
				<div class="mdo-h-cats">
					<?php foreach ( $cats as $c ) : ?>
					<a class="mdo-h-cat" href="<?php echo esc_url( $c[1] ); ?>"><span class="mdo-imgbg"><img src="<?php echo esc_url( $c[2] ); ?>" alt="" loading="lazy"></span><b><?php echo esc_html( $c[0] ); ?></b></a>
					<?php endforeach; ?>
				</div>
			</section>
			<?php endif; ?>

			<section class="mdo-h-sec mdo-h-band mdo-h-band-purple" id="mdo-h-how">
				<h2>Zo werkt het</h2>
				<ol class="mdo-h-steps">
					<li><span>1</span><h3>Kies een product</h3><p>Kies kleur, aantal en eventueel maten.</p></li>
					<li><span>2</span><h3>Upload je logo</h3><p>Je ziet direct een voorbeeld op het product, op de plek die jij kiest.</p></li>
					<li><span>3</span><h3>Bestel met vaste prijs</h3><p>Totaal incl. bedrukking<?php echo $tax ? ' en btw' : ''; ?>, met de verwachte leverdatum erbij.</p></li>
				</ol>
			</section>

			<?php if ( $popular ) : ?>
			<section class="mdo-h-sec">
				<div class="mdo-h-head"><h2>Populair</h2><a href="<?php echo esc_url( $shop_url ); ?>">Alle producten</a></div>
				<ul class="products mdo-grid mdo-h-grid">
					<?php foreach ( $popular as $id ) { echo self::card( $id ); } // phpcs:ignore ?>
				</ul>
			</section>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
