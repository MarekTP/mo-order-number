<?php
/**
 * Jádro: přiřazování a generování čísel objednávek.
 *
 * @package MO_Order_Number
 */

defined( 'ABSPATH' ) || exit;

class MON_Core {

	/** Klíč post meta / HPOS meta pro uložené číslo objednávky. */
	const META_KEY = '_mo_order_number';

	/** Prefix option_name pro čítače v wp_options. */
	const OPTION_COUNTER_PREFIX = 'mo_order_counter_';

	/** Klíč pro uložení nastavení pluginu. */
	const OPTION_SETTINGS = 'mo_order_number_settings';

	// -----------------------------------------------------------------------
	// Inicializace hooků
	// -----------------------------------------------------------------------

	public static function init(): void {
		// Pokrytí všech cest vytvoření objednávky – meta klíč zajistí idempotenci.
		add_action( 'woocommerce_checkout_order_created',              [ self::class, 'assign_order_number' ] );
		add_action( 'woocommerce_store_api_checkout_order_processed',  [ self::class, 'assign_order_number' ] );
		add_action( 'woocommerce_new_order',                           [ self::class, 'assign_by_id' ] );

		// Zobrazení vlastního čísla kdekoliv WC volá get_order_number().
		add_filter( 'woocommerce_order_number', [ self::class, 'filter_order_number' ], 10, 2 );

		// Fulltext vyhledávání v admin seznamu objednávek.
		add_filter( 'woocommerce_order_search_fields', [ self::class, 'add_search_field' ] );
	}

	// -----------------------------------------------------------------------
	// Přiřazení čísla
	// -----------------------------------------------------------------------

	/**
	 * Wrapper pro hook `woocommerce_new_order`, který předává jen ID (int).
	 */
	public static function assign_by_id( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			self::assign_order_number( $order );
		}
	}

	/**
	 * Vygeneruje a uloží číslo objednávky. Idempotentní – na již očíslované
	 * objednávce neprovede nic.
	 */
	public static function assign_order_number( \WC_Order $order ): void {
		if ( $order->get_meta( self::META_KEY, true ) ) {
			return;
		}

		$settings   = self::get_settings();
		$now        = new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) );
		$year       = (int) $now->format( 'Y' );
		$month      = (int) $now->format( 'm' );
		$period_key = self::period_key( $settings['format'], $year, $month, (bool) $settings['auto_reset'] );
		$seq        = self::next_sequence( $period_key );
		$number     = self::generate_number( $settings['format'], $year, $month, $seq );

		$order->update_meta_data( self::META_KEY, $number );
		$order->save_meta_data();
	}

	// -----------------------------------------------------------------------
	// Filtry WooCommerce
	// -----------------------------------------------------------------------

	public static function filter_order_number( string $number, \WC_Order $order ): string {
		$custom = $order->get_meta( self::META_KEY, true );
		return $custom ?: $number;
	}

	public static function add_search_field( array $fields ): array {
		$fields[] = self::META_KEY;
		return $fields;
	}

	// -----------------------------------------------------------------------
	// Pomocné metody (public – volají je i MON_Admin)
	// -----------------------------------------------------------------------

	/**
	 * Vrátí aktuální nastavení pluginu s výchozími hodnotami.
	 *
	 * @return array{ format: string, auto_reset: bool }
	 */
	public static function get_settings(): array {
		return wp_parse_args(
			(array) get_option( self::OPTION_SETTINGS, [] ),
			[
				'format'     => '%1$04d%3$06d',
				'auto_reset' => true,
			]
		);
	}

	/**
	 * Sestaví číslo objednávky ze sprintf vzorce.
	 *
	 * Argumenty vzorce:
	 *   %1$... = rok  (int, např. 2026)
	 *   %2$... = měsíc (int, 1–12)
	 *   %3$... = pořadové číslo (int)
	 *
	 * Výchozí formát jako záložní při chybě ve vzorci.
	 */
	public static function generate_number( string $format, int $year, int $month, int $seq ): string {
		try {
			return sprintf( $format, $year, $month, $seq );
		} catch ( \Throwable $e ) {
			return sprintf( '%04d%06d', $year, $seq );
		}
	}

	/**
	 * Vrátí klíč čítače odvozený z formátu a nastavení auto_reset.
	 *
	 * Logika detekce periody:
	 *   - auto_reset = false  → vždy 'global'
	 *   - formát obsahuje %2$ (měsíc) → měsíční čítač
	 *   - formát obsahuje %1$ (rok)   → roční čítač
	 *   - jinak                        → 'global'
	 *
	 * Příklady klíčů: 'y2026', 'm202604', 'global'
	 */
	public static function period_key( string $format, int $year, int $month, bool $auto_reset = true ): string {
		if ( ! $auto_reset ) {
			return 'global';
		}
		if ( str_contains( $format, '%2$' ) ) {
			return sprintf( 'm%04d%02d', $year, $month );
		}
		if ( str_contains( $format, '%1$' ) ) {
			return sprintf( 'y%04d', $year );
		}
		return 'global';
	}

	/**
	 * Přečte aktuální hodnotu čítače bez inkrementace.
	 */
	public static function get_counter( string $period_key ): int {
		return (int) get_option( self::OPTION_COUNTER_PREFIX . $period_key, 0 );
	}

	/**
	 * Resetuje čítač na 0 (smazáním option záznamu).
	 */
	public static function reset_counter( string $period_key ): void {
		delete_option( self::OPTION_COUNTER_PREFIX . $period_key );
	}

	// -----------------------------------------------------------------------
	// Atomická inkrementace (MySQL transakce + SELECT FOR UPDATE)
	// -----------------------------------------------------------------------

	/**
	 * Bezpečně inkrementuje čítač a vrátí novou hodnotu.
	 * Používá transakci, aby dva souběžné požadavky nedostaly stejné číslo.
	 */
	public static function next_sequence( string $period_key ): int {
		global $wpdb;

		$option_name = self::OPTION_COUNTER_PREFIX . $period_key;

		$wpdb->query( 'START TRANSACTION' );

		$current = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE",
				$option_name
			)
		);

		$next = $current + 1;

		if ( 0 === $current ) {
			// Záznam zatím neexistuje – pokusíme se vložit.
			$inserted = $wpdb->insert(
				$wpdb->options,
				[ 'option_name' => $option_name, 'option_value' => $next, 'autoload' => 'no' ],
				[ '%s', '%d', '%s' ]
			);

			if ( ! $inserted ) {
				// Souběžný request ho vložil těsně před námi – přečíst a inkrementovat.
				$current = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE",
						$option_name
					)
				);
				$next = $current + 1;
				$wpdb->update( $wpdb->options, [ 'option_value' => $next ], [ 'option_name' => $option_name ], [ '%d' ], [ '%s' ] );
			}
		} else {
			$wpdb->update( $wpdb->options, [ 'option_value' => $next ], [ 'option_name' => $option_name ], [ '%d' ], [ '%s' ] );
		}

		$wpdb->query( 'COMMIT' );

		return $next;
	}
}
