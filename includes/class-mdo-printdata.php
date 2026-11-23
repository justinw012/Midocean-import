<?php
/**
 * Printdata per product: drukposities groeperen tot "vlakken" die de klant kiest
 * (bijv. FRONT, FRONT PD en FRONT PAD worden samen "Voorkant"), plus namen in het Nederlands.
 */

defined( 'ABSPATH' ) || exit;

class MDO_Printdata {

	const META = '_mdo_printdata';

	private static $words = array(
		'FRONT'       => 'voorkant',
		'BACK'        => 'achterkant',
		'SIDE'        => 'zijkant',
		'TOP'         => 'bovenkant',
		'BOTTOM'      => 'onderkant',
		'LID'         => 'deksel',
		'CHEST'       => 'borst',
		'ARM'         => 'mouw',
		'RIGHT'       => 'rechts',
		'LEFT'        => 'links',
		'BARREL'      => 'huls',
		'CLIP'        => 'clip',
		'POCKET'      => 'zak',
		'BOX'         => 'doos',
		'POUCH'       => 'etui',
		'STRAP'       => 'band',
		'SHOULDER'    => 'schouder',
		'INSIDE'      => 'binnenkant',
		'UPPER'       => 'boven',
		'LOWER'       => 'onder',
		'HANDLE'      => 'handvat',
		'ROUNDSCREEN' => 'rondom',
		'360'         => 'rondom (360°)',
		'COMPASS'     => 'kompas',
		'CAP'         => 'dop',
		'BODY'        => 'romp',
		'NECK'        => 'nek',
		'SLEEVE'      => 'mouw',
		'SCREEN'      => '',
		'BELOW'       => 'onder',
		'THE'         => '',
		'HOOD'        => 'capuchon',
		'BAG'         => 'tas',
		'FLAP'        => 'klep',
		'PANEL'       => 'paneel',
		'BRIM'        => 'klep',
		'BASE'        => 'voet',
		'RIBBON'      => 'lint',
		'MIDDLE'      => 'midden',
		'OF'          => '',
		'BAND'        => 'band',
		'BOTTLE'      => 'fles',
		'CENTER'      => 'midden',
		'CENTRE'      => 'midden',
		// Uitgebreid op basis van alle drukposities van Midocean.
		'PART' => 'deel',
		'PEN' => 'pen',
		'CUP' => 'beker',
		'GLASS' => 'glas',
		'TOWEL' => 'handdoek',
		'PLATE' => 'plaatje',
		'SEGMENT' => 'segment',
		'SEG' => 'segment',
		'RACKET' => 'racket',
		'MUG' => 'mok',
		'INLAY' => 'inleg',
		'METAL' => 'metaal',
		'STAND' => 'standaard',
		'GLOVE' => 'handschoen',
		'KNIFE' => 'mes',
		'NOTEBOOK' => 'notitieboek',
		'POT' => 'pot',
		'COASTER' => 'onderzetter',
		'CORNER' => 'hoek',
		'OPENER' => 'opener',
		'TRANSPARENT' => 'transparant',
		'PLUG' => 'stekker',
		'STRIP' => 'strook',
		'HEAD' => 'kop',
		'BOARD' => 'plank',
		'LABEL' => 'label',
		'SMALL' => 'klein',
		'CASE' => 'hoes',
		'HOLDER' => 'houder',
		'FORK' => 'vork',
		'PIECE' => 'stuk',
		'BUTTON' => 'knop',
		'BUTTONS' => 'knoppen',
		'FAN' => 'waaier',
		'CHARGER' => 'oplader',
		'PILLOW' => 'kussen',
		'PUZZLE' => 'puzzel',
		'STAR' => 'ster',
		'TREE' => 'boom',
		'BALL' => 'bal',
		'OUTSIDE' => 'buitenkant',
		'PENCIL' => 'potlood',
		'SHAKER' => 'shaker',
		'CARTON' => 'doos',
		'STEEL' => 'staal',
		'TUBE' => 'koker',
		'CHEESE' => 'kaas',
		'PATCH' => 'embleem',
		'BELL' => 'bel',
		'CARD' => 'kaart',
		'CARDS' => 'kaarten',
		'MIRROR' => 'spiegel',
		'STRIPE' => 'streep',
		'EAR' => 'oor',
		'TOOL' => 'gereedschap',
		'LIGHT' => 'lamp',
		'CLOSURE' => 'sluiting',
		'EGG' => 'ei',
		'SPOON' => 'lepel',
		'CUBE' => 'kubus',
		'SEAT' => 'zitting',
		'FOLDER' => 'map',
		'TSHIRT' => 'T-shirt',
		'T-SHIRT' => 'T-shirt',
		'SLATE' => 'leisteen',
		'LEATHER' => 'leer',
		'POTHOLDER' => 'pannenlap',
		'COVER' => 'omslag',
		'DISPLAY' => 'scherm',
		'TORCH' => 'zaklamp',
		'CORK' => 'kurk',
		'ROOF' => 'dak',
		'WOOD' => 'hout',
		'KEYRING' => 'sleutelhanger',
		'BAMBOO' => 'bamboe',
		'BUCKLE' => 'gesp',
		'TRAY' => 'dienblad',
		'INFUSER' => 'theezeef',
		'BIG' => 'groot',
		'STICK' => 'stok',
		'PLANK' => 'plank',
		'CARDBOARD' => 'karton',
		'ROLLER' => 'roller',
		'HAMMOCK' => 'hangmat',
		'STRAW' => 'rietje',
		'SOLAR' => 'zonnepaneel',
		'PAPER' => 'papier',
		'HAT' => 'hoed',
		'FRISBEE' => 'frisbee',
		'SPATULA' => 'spatel',
		'COIN' => 'munt',
		'FILTER' => 'filter',
		'BREAST' => 'borst',
		'RING' => 'ring',
		'CLOTH' => 'doek',
		'LAMP' => 'lamp',
		'SPADE' => 'schep',
		'EARBUD' => 'oordopje',
		'ORGANIZER' => 'organizer',
		'MOUSEPAD' => 'muismat',
		'WING' => 'vleugel',
		'LUNCHBOX' => 'lunchbox',
		'BLANKET' => 'deken',
		'FIRESTARTER' => 'vuurstarter',
		'LEG' => 'poot',
		'CONTAINER' => 'bak',
		'MOUSE' => 'muis',
		'USB' => 'USB',
		'MAT' => 'mat',
		'UMBRELLA' => 'paraplu',
		'LEAF' => 'blad',
		'MARKER' => 'marker',
		'TUMBLER' => 'beker',
		'POLE' => 'paal',
		'REST' => 'steun',
		'TUB' => 'kuipje',
		'RACK' => 'rek',
		'AXE' => 'bijl',
		'WHISK' => 'garde',
		'HOODIE' => 'hoodie',
		'BUCKET' => 'emmer',
		'TEA' => 'thee',
		'DOOR' => 'deur',
		'RULER' => 'liniaal',
		'RULLER' => 'liniaal',
		'INNER' => 'binnenkant',
		'SLIPPER' => 'slipper',
		'SLIPPERS' => 'slippers',
		'SHOVEL' => 'schep',
		'TOOTHBRUSH' => 'tandenborstel',
		'SOCK' => 'sok',
		'ENVELOPE' => 'envelop',
		'TONGS' => 'tang',
		'PAGE' => 'pagina',
		'KEYBOARD' => 'toetsenbord',
		'HAND' => 'hand',
		'SHARPENER' => 'puntenslijper',
		'CHAIR' => 'stoel',
		'WALLET' => 'portemonnee',
		'KEY' => 'sleutel',
		'WRIST' => 'pols',
		'TABLE' => 'tafel',
		'GRIP' => 'greep',
		'TAG' => 'label',
		'MAGNET' => 'magneet',
		'MASK' => 'masker',
		'MICROPHONE' => 'microfoon',
		'HEART' => 'hart',
		'KITE' => 'vlieger',
		'CANDLE' => 'kaars',
		'BEANIE' => 'muts',
		'SCARF' => 'sjaal',
		'CARAFE' => 'karaf',
		'WHEEL' => 'wiel',
		'BACKREST' => 'rugleuning',
		'CIRCLE' => 'cirkel',
		'BALLPEN' => 'balpen',
		'BEAR' => 'beer',
		'SCREWDRIVER' => 'schroevendraaier',
		'POWERBANK' => 'powerbank',
		'SURFACE' => 'oppervlak',
		'COLLAR' => 'kraag',
		'POCKETS' => 'zakken',
		'TOKEN' => 'munt',
		'INSERT' => 'inleg',
		'LIDTOP' => 'bovenkant deksel',
		'CUTTER' => 'snijder',
		'MEASURING' => 'meet',
		'HOURGLASS' => 'zandloper',
		'SLEEPING' => 'slaap',
		'TOWER' => 'toren',
		'WHITE' => 'wit',
		'BLACK' => 'zwart',
		'RED' => 'rood',
		'GREEN' => 'groen',
		'SILVER' => 'zilver',
		'ORANGE' => 'oranje',
		'YELLOW' => 'geel',
		'PVC' => 'pvc',
		'COLOURED' => 'gekleurd',
		'COLORED' => 'gekleurd',
		'COLOURDED' => 'gekleurd',
		'TEXTURED' => 'gestructureerd',
		'SMOOTH' => 'glad',
		'FLAT' => 'vlak',
		'CONVEX' => 'bol',
		'REFLECTIVE' => 'reflecterend',
		'EMBROIDERY' => '',
		'TRANSFER' => '',
		'DEBOSSING' => '',
		'DIGITAL' => '',
		'SUBLIMATION' => '',
		'DOMING' => '',
		'INKLESS' => '',
		'PRINT' => '',
		'(PAD)' => '',
		'(PD)' => '',
		'(B)' => '',
		'DO/DL' => '',
		'ABOVE' => 'boven',
		'UNDER' => 'onder',
		'UP' => 'boven',
		'DOWN' => 'onder',
		'OPPOSITE' => 'tegenover',
		'NEXT' => 'naast',
		'TO' => '',
		'ON' => 'op',
		'IN' => 'in',
		'AT' => 'bij',
		'WITH' => 'met',
		'ROUND' => 'rond',
		'FULL' => 'volledig',
		'SINGLE' => 'enkel',
		'OUTER' => 'buitenkant',
		'HIGH' => 'hoog',
		'LOW' => 'laag',
		'END' => 'uiteinde',
		'ADDITIONAL' => 'extra',
		'FIRST' => 'eerste',
		'OTHER' => 'ander',
		'ROUNDPART' => 'ronde deel',
		'LEVEL' => 'niveau',
		'LINE' => 'lijn',
		'CM' => 'cm',
		'INCH' => 'inch',
	);

	/**
	 * Woorden waarbij de vertaling een keuze is (meerdere betekenissen mogelijk).
	 * Alle andere woorden uit $words zijn letterlijk en daarmee "zeker".
	 */
	private static $choices = array(
		'PLATE' => 'Plaatje; kan ook: bord (servies).',
		'FAN' => 'Waaier; kan ook: ventilator.',
		'STAND' => 'Standaard; kan ook: voet.',
		'CASE' => 'Hoes; kan ook: etui of koffer.',
		'HEAD' => 'Kop; kan ook: hoofd.',
		'BOARD' => 'Plank; kan ook: bord (bijv. snijplank).',
		'PATCH' => 'Embleem; kan ook: badge of patch.',
		'LIGHT' => 'Lamp; kan ook: licht.',
		'COVER' => 'Omslag (boek); kan ook: hoes of deksel.',
		'TUMBLER' => 'Beker; kan ook: drinkbeker of tumbler.',
		'CONTAINER' => 'Bak; kan ook: houder of pot.',
		'NEXT' => 'Naast: "next to" wordt "naast"; controleer de woordvolgorde.',
		'ON' => 'Op: controleer de woordvolgorde.',
		'REST' => 'Steun; kan ook: houder.',
		'INLAY' => 'Inleg; kan ook: inlegplaatje.',
		'INSERT' => 'Inleg; kan ook: inzetstuk.',
		'SHAKER' => 'Shaker; kan ook: cocktailshaker.',
		'TUB' => 'Kuipje; kan ook: bakje.',
		'ARM'         => 'Arm of mouw; gekozen: mouw (bij kleding).',
		'CHEST'       => 'Borst (kleding); bij tassen kan "voorvak" beter zijn.',
		'BARREL'      => 'Huls (pen); kan ook: schacht.',
		'POCKET'      => 'Zak; bij tassen kan "vak" beter zijn.',
		'BOX'         => 'Doos; kan ook: doosje, verpakking of blik.',
		'POUCH'       => 'Etui; kan ook: hoesje of zakje.',
		'STRAP'       => 'Band; kan ook: riem of hengsel.',
		'SHOULDER'    => 'Schouder; bij tassen: schouderband.',
		'UPPER'       => 'Boven; "bovenste deel" kan duidelijker zijn.',
		'LOWER'       => 'Onder; "onderste deel" kan duidelijker zijn.',
		'ROUNDSCREEN' => 'Rondom bedrukt (zeefdruk rondom).',
		'360'         => 'Rondom (360°).',
		'CAP'         => 'Dop (fles, pen); bij kleding betekent het "pet".',
		'BODY'        => 'Romp; kan ook: behuizing of flesbody.',
		'NECK'        => 'Nek (kleding); bij flessen: hals.',
		'HOOD'        => 'Capuchon.',
		'FLAP'        => 'Klep; kan ook: flap.',
		'PANEL'       => 'Paneel; kan ook: vlak.',
		'BRIM'        => 'Klep van een pet; kan ook: rand.',
		'BASE'        => 'Voet; kan ook: onderkant of sokkel.',
		'BAND'        => 'Band; kan ook: ring of strook.',
		'RIBBON'      => 'Lint; kan ook: band (bijv. de band om een lunchbox).',
		'HANDLE'      => 'Handvat; kan ook: hengsel of greep.',
		'SLEEVE'      => 'Mouw; bij hoezen betekent het "hoes".',
		'MIDDLE'      => 'Midden.',
		'CENTER'      => 'Midden.',
		'CENTRE'      => 'Midden.',
		'BELOW'       => 'Onder.',
	);

	// Tokens die alleen de techniek aanduiden, niet de plek.
	private static $tech_tokens = array( 'PD', 'PAD', 'TD1', 'TD', 'LASER', 'DL', 'RD', 'DO', 'T1', 'S', 'P' );

	const LABELS_OPTION = 'mdo_position_labels';
	const SEEN_OPTION   = 'mdo_positions_seen';
	const WORDS_OPTION  = 'mdo_position_words';

	private static $own_words = null;

	/** Eigen woordvertalingen uit het beheerscherm (bijv. CORK => kurk); gelden in elke drukpositie. */
	public static function own_words() {
		if ( null === self::$own_words ) {
			self::$own_words = (array) get_option( self::WORDS_OPTION, array() );
		}
		return self::$own_words;
	}

	public static function reset_words() {
		self::$own_words = null;
	}

	/** Vertaling van één woord: eigen woordenlijst eerst, dan de ingebouwde. Null = onbekend. */
	public static function word( $t ) {
		$own = self::own_words();
		if ( isset( $own[ $t ] ) ) {
			return '-' === $own[ $t ] ? '' : $own[ $t ];
		}
		return isset( self::$words[ $t ] ) ? self::$words[ $t ] : null;
	}

	/** Woorden in een positie-id; "SIDE1" wordt "SIDE 1", haakjes om techniekcodes vallen weg. */
	public static function tokens( $position_id ) {
		$id = strtoupper( trim( (string) $position_id ) );
		$id = preg_replace( '/\(([A-Z0-9\/]+)\)/', '$1', $id );
		$id = preg_replace( '/([A-Z]{2,})(\d+)\b/', '$1 $2', $id );
		return array_values( array_filter( preg_split( '/\s+/', $id ), 'strlen' ) );
	}

	/** Woorden die nog niet vertaald zijn, met hoe vaak ze voorkomen en een voorbeeld. */
	public static function unknown_words( array $position_ids ) {
		$out = array();
		foreach ( $position_ids as $id ) {
			foreach ( self::tokens( $id ) as $t ) {
				if ( in_array( $t, self::$tech_tokens, true ) || 'HANDED' === $t || ctype_digit( $t ) || null !== self::word( $t ) ) {
					continue;
				}
				if ( ! isset( $out[ $t ] ) ) {
					$out[ $t ] = array( 'count' => 0, 'example' => $id );
				}
				$out[ $t ]['count']++;
			}
		}
		uasort( $out, function ( $a, $b ) {
			return $b['count'] - $a['count'];
		} );
		return $out;
	}

	/** Naam voor de klant: eigen vertaling uit het beheerscherm, anders de automatische. */
	public static function label( $position_id ) {
		$own = get_option( self::LABELS_OPTION, array() );
		if ( ! empty( $own[ $position_id ] ) ) {
			return $own[ $position_id ];
		}
		return self::auto_label( $position_id );
	}

	/** True als de automatische vertaling een woord niet kent (dan blijft het Engels). */
	public static function has_unknown( $position_id ) {
		foreach ( self::tokens( $position_id ) as $t ) {
			if ( '' === $t || in_array( $t, self::$tech_tokens, true ) || 'HANDED' === $t || ctype_digit( $t ) ) {
				continue;
			}
			if ( null === self::word( $t ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hoe zeker is de automatische vertaling?
	 * zeker   = letterlijke vertaling van bekende woorden
	 * keuze   = een woord met meerdere betekenissen, of de woordvolgorde is automatisch gekozen
	 * onbekend = een woord staat niet in de woordenlijst en blijft Engels
	 *
	 * @return array{status:string,notes:string[]}
	 */
	public static function explain( $position_id ) {
		$status  = 'zeker';
		$notes   = array();
		$places  = 0;
		$dropped = array();
		foreach ( self::tokens( $position_id ) as $t ) {
			if ( '' === $t || ctype_digit( $t ) && '360' !== $t || 'HANDED' === $t ) {
				continue;
			}
			if ( in_array( $t, self::$tech_tokens, true ) ) {
				$dropped[] = $t;
				continue;
			}
			if ( null === self::word( $t ) ) {
				$status  = 'onbekend';
				$notes[] = $t . ': staat niet in de woordenlijst, blijft Engels.';
				continue;
			}
			if ( '' === self::word( $t ) ) {
				continue;
			}
			$places++;
			if ( isset( self::$choices[ $t ] ) && ! isset( self::own_words()[ $t ] ) ) {
				if ( 'onbekend' !== $status ) {
					$status = 'keuze';
				}
				$notes[] = $t . ' → ' . self::word( $t ) . ': ' . self::$choices[ $t ];
			}
		}
		if ( $places > 1 ) {
			if ( 'zeker' === $status ) {
				$status = 'keuze';
			}
			$notes[] = 'Woordvolgorde automatisch gekozen; controleer of het goed loopt.';
		}
		if ( $dropped ) {
			$notes[] = implode( ', ', $dropped ) . ' weggelaten: dat is een techniekaanduiding, geen plek.';
		}
		return array( 'status' => $status, 'notes' => $notes );
	}

	public static function auto_label( $position_id ) {
		$tokens = self::tokens( $position_id );
		$out    = array();
		foreach ( $tokens as $t ) {
			if ( in_array( $t, self::$tech_tokens, true ) ) {
				continue;
			}
			if ( 'HANDED' === $t ) {
				$prev  = array_pop( $out );
				$out[] = ( 'links' === $prev ) ? 'linkshandig' : 'rechtshandig';
				continue;
			}
			if ( ctype_digit( $t ) && '360' !== $t ) {
				$out[] = $t;
				continue;
			}
			$w = null !== self::word( $t ) ? self::word( $t ) : strtolower( $t );
			if ( '' !== $w ) {
				$out[] = $w;
			}
		}
		$label = trim( implode( ' ', $out ) );
		return '' === $label ? $position_id : ucfirst( $label );
	}

	private static function overlap( $a, $b ) {
		if ( ! $a || ! $b ) {
			return false;
		}
		return $a[0] < $b[2] && $b[0] < $a[2] && $a[1] < $b[3] && $b[1] < $a[3];
	}

	/**
	 * Groepeert posities waarvan het drukvlak overlapt: dat zijn alternatieven
	 * voor dezelfde plek. Geeft een lijst vlakken terug, elk met zijn posities.
	 */
	public static function faces( array $positions ) {
		$n      = count( $positions );
		$parent = range( 0, max( 0, $n - 1 ) );
		$find   = function ( $i ) use ( &$parent, &$find ) {
			return $parent[ $i ] === $i ? $i : ( $parent[ $i ] = $find( $parent[ $i ] ) );
		};
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				if ( self::overlap( $positions[ $i ]['box'], $positions[ $j ]['box'] ) && self::same_view( $positions[ $i ], $positions[ $j ] ) ) {
					$parent[ $find( $j ) ] = $find( $i );
				}
			}
		}
		$groups = array();
		foreach ( $positions as $i => $p ) {
			$groups[ $find( $i ) ][] = $p;
		}
		$faces = array();
		foreach ( $groups as $g ) {
			usort( $g, function ( $a, $b ) {
				return strlen( $a['id'] ) - strlen( $b['id'] );
			} );
			$label = self::label( $g[0]['id'] );
			$own   = get_option( self::LABELS_OPTION, array() );
			if ( count( $g ) > 1 && empty( $own[ $g[0]['id'] ] ) ) {
				// Gemeenschappelijke plaatsaanduiding: TOP COMPASS + LASER COMPASS → "Kompas".
				$common = self::place_tokens( $g[0]['id'] );
				foreach ( $g as $pos ) {
					$common = array_values( array_intersect( $common, self::place_tokens( $pos['id'] ) ) );
				}
				if ( $common ) {
					$label = self::auto_label( implode( ' ', $common ) );
				}
			}
			$faces[] = array(
				'key'       => sanitize_title( $g[0]['id'] ),
				'label'     => $label,
				'positions' => $g,
			);
		}
		return $faces;
	}

	/**
	 * Twee posities horen alleen bij hetzelfde vlak als ze een plaatsaanduiding delen
	 * (FRONT en FRONT PD, TOP BOX en PD BOX) en elkaar niet tegenspreken
	 * (LEFT tegenover RIGHT, SIDE 1 tegenover SIDE 2).
	 */
	private static function same_view( $a, $b ) {
		$ta = self::place_tokens( $a['id'] );
		$tb = self::place_tokens( $b['id'] );
		if ( ! array_intersect( $ta, $tb ) ) {
			return false;
		}
		// Alleen samenvoegen als de ene plaatsaanduiding volledig in de andere zit
		// (FRONT en FRONT PD, COMPASS en TOP COMPASS), niet bij LID LEFT en LID CENTER.
		if ( array_diff( $ta, $tb ) && array_diff( $tb, $ta ) ) {
			return false;
		}
		$pairs = array( array( 'LEFT', 'RIGHT' ), array( 'FRONT', 'BACK' ), array( 'TOP', 'BOTTOM' ), array( 'UPPER', 'LOWER' ) );
		foreach ( $pairs as $p ) {
			if ( ( in_array( $p[0], $ta, true ) && in_array( $p[1], $tb, true ) ) || ( in_array( $p[1], $ta, true ) && in_array( $p[0], $tb, true ) ) ) {
				return false;
			}
		}
		$da = array_filter( $ta, 'ctype_digit' );
		$db = array_filter( $tb, 'ctype_digit' );
		return ! ( $da && $db && $da !== $db );
	}

	private static function place_tokens( $id ) {
		$out = array();
		foreach ( preg_split( '/\s+/', strtoupper( $id ) ) as $t ) {
			if ( '' !== $t && ! in_array( $t, self::$tech_tokens, true ) ) {
				$out[] = $t;
			}
		}
		return array_values( $out );
	}
}
