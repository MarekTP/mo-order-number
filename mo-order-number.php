<?php
/**
 * Plugin Name: MO Order Number
 * Description: Lidské číslování objednávek WooCommerce ve formátu YYYYnnnnnn (rok + pořadové číslo v roce).
 * Version:     1.0.0
 * Author:      Marek Olšavský
 * Author URI:  https://olsavsky.cz
 * Text Domain: mo-order-number
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

class MO_Order_Number {

    const META_KEY    = '_mo_order_number';
    const OPTION_BASE = 'mo_order_counter_';

    public static function init(): void {
        // Přiřadit číslo objednávky při jejím vytvoření
        add_action( 'woocommerce_checkout_order_created',    [ self::class, 'assign_order_number' ] );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ self::class, 'assign_order_number' ] );

        // Zobrazovat vlastní číslo všude, kde WooCommerce zobrazuje číslo objednávky
        add_filter( 'woocommerce_order_number', [ self::class, 'get_order_number' ], 10, 2 );

        // Prohledávat objednávky i podle vlastního čísla (vyhledávání v adminu)
        add_filter( 'woocommerce_order_search_fields', [ self::class, 'add_search_field' ] );

        // Admin: přidat sloupec s vlastním číslem (volitelné, záleží na preferencích)
        // add_filter( 'manage_woocommerce_page_wc-orders_columns', ... );
    }

    /**
     * Vygeneruje a uloží číslo objednávky do meta pole, pokud ještě nebylo přiřazeno.
     *
     * @param \WC_Order $order
     */
    public static function assign_order_number( \WC_Order $order ): void {
        if ( $order->get_meta( self::META_KEY, true ) ) {
            return; // Již přiřazeno (např. double-fire)
        }

        $year    = (int) date( 'Y' );
        $seq     = self::next_sequence( $year );
        $number  = sprintf( '%04d%06d', $year, $seq );

        $order->update_meta_data( self::META_KEY, $number );
        $order->save_meta_data();
    }

    /**
     * Vrátí vlastní číslo objednávky pro WooCommerce filter.
     *
     * @param string    $order_number  Výchozí číslo (= post ID nebo HPOS ID)
     * @param \WC_Order $order
     * @return string
     */
    public static function get_order_number( string $order_number, \WC_Order $order ): string {
        $custom = $order->get_meta( self::META_KEY, true );
        return $custom ?: $order_number;
    }

    /**
     * Umožní vyhledávání v adminu podle vlastního čísla objednávky.
     *
     * @param string[] $fields
     * @return string[]
     */
    public static function add_search_field( array $fields ): array {
        $fields[] = self::META_KEY;
        return $fields;
    }

    // ------------------------------------------------------------------
    // Interní: atomické čítadlo v wp_options
    // ------------------------------------------------------------------

    /**
     * Atomicky inkrementuje a vrátí pořadové číslo pro daný rok.
     * Používá transakci přes MySQL SELECT ... FOR UPDATE (přes $wpdb).
     *
     * @param int $year
     * @return int
     */
    private static function next_sequence( int $year ): int {
        global $wpdb;

        $option_name = self::OPTION_BASE . $year;

        // Pokusíme se o atomický update; pokud ještě neexistuje, vložíme nový.
        $wpdb->query( 'START TRANSACTION' );

        $current = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options}
                 WHERE option_name = %s
                 FOR UPDATE",
                $option_name
            )
        );

        $next = $current + 1;

        if ( $current === 0 ) {
            // Záznam neexistuje — vložit; pokud souběžně vznikne, UNIQUE key to zachytí.
            $inserted = $wpdb->insert(
                $wpdb->options,
                [
                    'option_name'  => $option_name,
                    'option_value' => $next,
                    'autoload'     => 'no',
                ],
                [ '%s', '%d', '%s' ]
            );

            if ( ! $inserted ) {
                // Jiný request ho vložil mezitím — přečíst a inkrementovat
                $current = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT option_value FROM {$wpdb->options}
                         WHERE option_name = %s FOR UPDATE",
                        $option_name
                    )
                );
                $next = $current + 1;
                $wpdb->update(
                    $wpdb->options,
                    [ 'option_value' => $next ],
                    [ 'option_name'  => $option_name ],
                    [ '%d' ],
                    [ '%s' ]
                );
            }
        } else {
            $wpdb->update(
                $wpdb->options,
                [ 'option_value' => $next ],
                [ 'option_name'  => $option_name ],
                [ '%d' ],
                [ '%s' ]
            );
        }

        $wpdb->query( 'COMMIT' );

        return $next;
    }
}

add_action( 'plugins_loaded', [ 'MO_Order_Number', 'init' ] );
