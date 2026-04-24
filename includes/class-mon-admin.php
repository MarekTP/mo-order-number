<?php
/**
 * Admin stránka: WooCommerce → Číslování objednávek.
 *
 * @package MO_Order_Number
 */

defined( 'ABSPATH' ) || exit;

class MON_Admin {

	const PAGE_SLUG = 'mo-order-number';

	/**
	 * Definice předvoleb formátu.
	 * Klíč → [ format (sprintf vzorec), period (year|month|global) ]
	 */
	private static function presets(): array {
		return [
			'year'   => [
				'label'  => __( 'YYYY + pořadí v roce  (např. 2026000042)', 'mo-order-number' ),
				'format' => '%1$04d%3$06d',
				'period' => 'year',
			],
			'month'  => [
				'label'  => __( 'YYYYMM + pořadí v měsíci  (např. 2026040042)', 'mo-order-number' ),
				'format' => '%1$04d%2$02d%3$04d',
				'period' => 'month',
			],
			'global' => [
				'label'  => __( 'Globální pořadové číslo  (např. 0000000042)', 'mo-order-number' ),
				'format' => '%3$010d',
				'period' => 'global',
			],
		];
	}

	// -----------------------------------------------------------------------
	// Init
	// -----------------------------------------------------------------------

	public static function init(): void {
		add_action( 'admin_menu',                   [ self::class, 'add_menu' ] );
		add_action( 'admin_post_mon_save_settings', [ self::class, 'save_settings' ] );
		add_action( 'admin_post_mon_reset_counter', [ self::class, 'handle_reset' ] );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Číslování objednávek', 'mo-order-number' ),
			__( 'Číslování objednávek', 'mo-order-number' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	// -----------------------------------------------------------------------
	// Render stránky
	// -----------------------------------------------------------------------

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'mo-order-number' ) );
		}

		$settings = MON_Core::get_settings();
		$now      = new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) );
		$year     = (int) $now->format( 'Y' );
		$month    = (int) $now->format( 'm' );

		// Stav všech čítačů
		$counters = [
			'year'   => [
				'key'   => MON_Core::period_key( 'year',   $year, $month ),
				'label' => sprintf(
					/* translators: %d: rok */
					__( 'Roční %d', 'mo-order-number' ), $year
				),
			],
			'month'  => [
				'key'   => MON_Core::period_key( 'month',  $year, $month ),
				'label' => sprintf(
					/* translators: 1: rok, 2: měsíc */
					__( 'Měsíční %1$d/%2$02d', 'mo-order-number' ), $year, $month
				),
			],
			'global' => [
				'key'   => MON_Core::period_key( 'global', $year, $month ),
				'label' => __( 'Globální', 'mo-order-number' ),
			],
		];

		foreach ( $counters as &$c ) {
			$c['value'] = MON_Core::get_counter( $c['key'] );
		}
		unset( $c );

		// Příklad čísla s aktuálním nastavením
		$preview_seq = max( $counters[ $settings['period'] ]['value'], 42 );
		$preview     = MON_Core::generate_number( $settings['format'], $year, $month, $preview_seq );

		// Jednorázové admin hlášení
		$notice = get_transient( 'mon_admin_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'mon_admin_notice_' . get_current_user_id() );
		}

		// JSON dat pro JS (presets + texty)
		$js_presets = [];
		foreach ( self::presets() as $key => $p ) {
			$js_presets[ $key ] = [ 'format' => $p['format'], 'period' => $p['period'] ];
		}

		?>
		<div class="wrap woocommerce">
			<h1><?php esc_html_e( 'Číslování objednávek', 'mo-order-number' ); ?></h1>

			<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
				<p><?php echo esc_html( $notice['message'] ); ?></p>
			</div>
			<?php endif; ?>

			<?php /* ── Nastavení formátu ──────────────────────────────────── */ ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="mon_save_settings">
				<?php wp_nonce_field( 'mon_save_settings', 'mon_nonce' ); ?>

				<h2><?php esc_html_e( 'Formát čísla objednávky', 'mo-order-number' ); ?></h2>
				<p class="description" style="margin-bottom:1em">
					<?php esc_html_e( 'Stávající objednávky si ponechají původní čísla. Nový formát se uplatní od příští vytvořené objednávky.', 'mo-order-number' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="mon-preset"><?php esc_html_e( 'Předvolba', 'mo-order-number' ); ?></label>
						</th>
						<td>
							<select id="mon-preset" name="mon_preset">
								<option value=""><?php esc_html_e( '— vlastní —', 'mo-order-number' ); ?></option>
								<?php foreach ( self::presets() as $key => $p ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( self::active_preset( $settings ), $key ); ?>>
									<?php echo esc_html( $p['label'] ); ?>
								</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mon-format"><?php esc_html_e( 'Vzorec (sprintf)', 'mo-order-number' ); ?></label>
						</th>
						<td>
							<input type="text" id="mon-format" name="mon_format"
								   value="<?php echo esc_attr( $settings['format'] ); ?>"
								   class="regular-text" autocomplete="off">
							<p class="description">
								<?php esc_html_e( 'Poziční argumenty: %1$s = rok (YYYY), %2$s = měsíc (MM), %3$s = pořadové číslo.', 'mo-order-number' ); ?>
								<?php esc_html_e( 'Příklady: %1$04d%3$06d · %1$04d%2$02d%3$04d · %3$010d', 'mo-order-number' ); ?>
							</p>
							<p style="margin-top:.5em">
								<strong><?php esc_html_e( 'Náhled:', 'mo-order-number' ); ?></strong>
								<code id="mon-preview"><?php echo esc_html( $preview ); ?></code>
								<span id="mon-preview-error" style="color:#d63638;display:none">
									<?php esc_html_e( '⚠ Neplatný vzorec', 'mo-order-number' ); ?>
								</span>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mon-period"><?php esc_html_e( 'Perioda čítače', 'mo-order-number' ); ?></label>
						</th>
						<td>
							<select id="mon-period" name="mon_period">
								<option value="year"   <?php selected( $settings['period'], 'year' ); ?>>
									<?php esc_html_e( 'Roční – reset každý rok', 'mo-order-number' ); ?>
								</option>
								<option value="month"  <?php selected( $settings['period'], 'month' ); ?>>
									<?php esc_html_e( 'Měsíční – reset každý měsíc', 'mo-order-number' ); ?>
								</option>
								<option value="global" <?php selected( $settings['period'], 'global' ); ?>>
									<?php esc_html_e( 'Globální – nikdy se neresetuje', 'mo-order-number' ); ?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Určuje, kdy se pořadové číslo vrátí na 1. Musí odpovídat použitému vzorci.', 'mo-order-number' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Uložit změny', 'mo-order-number' ) ); ?>
			</form>

			<hr>

			<?php /* ── Stav čítačů ─────────────────────────────────────────── */ ?>
			<h2><?php esc_html_e( 'Aktuální stav čítačů', 'mo-order-number' ); ?></h2>
			<p class="description" style="margin-bottom:.75em">
				<?php esc_html_e( 'Reset nastaví čítač na 0. Příští objednávka dostane pořadové číslo 1.', 'mo-order-number' ); ?>
			</p>

			<table class="widefat striped" style="max-width:520px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Čítač', 'mo-order-number' ); ?></th>
						<th style="text-align:right"><?php esc_html_e( 'Hodnota', 'mo-order-number' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $counters as $c ) : ?>
					<tr>
						<td><?php echo esc_html( $c['label'] ); ?></td>
						<td style="text-align:right"><code><?php echo esc_html( $c['value'] ); ?></code></td>
						<td style="text-align:right">
							<?php self::render_reset_button( $c['key'] ); ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

		</div><!-- .wrap -->

		<script>
		(function($) {
			'use strict';

			var presets  = <?php echo wp_json_encode( $js_presets ); ?>;
			var yearVal  = <?php echo (int) $year; ?>;
			var monthVal = <?php echo (int) $month; ?>;

			// Předvolba → vyplnit vzorec + periodu
			$('#mon-preset').on('change', function() {
				var key = $(this).val();
				if ( key && presets[key] ) {
					$('#mon-format').val( presets[key].format );
					$('#mon-period').val( presets[key].period );
				}
				refreshPreview();
			});

			// Live náhled čísla (simulace PHP sprintf v JS)
			$('#mon-format').on('input', function() {
				clearTimeout( window._monTimer );
				window._monTimer = setTimeout( refreshPreview, 300 );
			});

			function refreshPreview() {
				var fmt = $('#mon-format').val();
				try {
					var num = sprintfMon( fmt, yearVal, monthVal, 42 );
					$('#mon-preview').text( num );
					$('#mon-preview-error').hide();
				} catch(e) {
					$('#mon-preview').text('');
					$('#mon-preview-error').show();
				}
			}

			/**
			 * Minimální simulace PHP sprintf pro poziční argumenty %N$Xd.
			 * Podporuje %1$04d, %2$02d, %3$06d, %3$010d atd.
			 */
			function sprintfMon( fmt, year, month, seq ) {
				var args = [ null, year, month, seq ]; // index 1-based
				return fmt.replace( /%(\d+)\$0?(\d*)d/g, function( match, pos, width ) {
					var val = args[ parseInt(pos,10) ];
					if ( val === undefined ) throw new Error('bad arg');
					var s   = String( Math.abs(val) );
					var w   = parseInt( width, 10 ) || 0;
					while ( s.length < w ) s = '0' + s;
					return s;
				});
			}

		}(jQuery));
		</script>
		<?php
	}

	// -----------------------------------------------------------------------
	// Tlačítko resetu
	// -----------------------------------------------------------------------

	private static function render_reset_button( string $period_key ): void {
		$confirm_msg = esc_js( __( 'Opravdu resetovat tento čítač na 0? Příští objednávka dostane pořadové číslo 1. Tuto akci nelze vrátit.', 'mo-order-number' ) );
		$url = wp_nonce_url(
			add_query_arg(
				[ 'action' => 'mon_reset_counter', 'period_key' => $period_key ],
				admin_url( 'admin-post.php' )
			),
			'mon_reset_' . $period_key
		);
		printf(
			'<a href="%s" class="button button-small button-link-delete" onclick="return confirm(\'%s\');">%s</a>',
			esc_url( $url ),
			$confirm_msg,
			esc_html__( 'Resetovat', 'mo-order-number' )
		);
	}

	// -----------------------------------------------------------------------
	// Uložení nastavení
	// -----------------------------------------------------------------------

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' )
			|| ! check_admin_referer( 'mon_save_settings', 'mon_nonce' )
		) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'mo-order-number' ) );
		}

		$allowed_periods = [ 'year', 'month', 'global' ];
		$period = in_array( $_POST['mon_period'] ?? '', $allowed_periods, true )
			? sanitize_key( $_POST['mon_period'] )
			: 'year';

		// Sanitace vzorce: povoleny jsou jen znaky relevantní pro sprintf formát.
		$format = sanitize_text_field( wp_unslash( $_POST['mon_format'] ?? '' ) );
		$format = preg_replace( '/[^%0-9$+\-. a-zA-Z]/', '', $format );
		if ( empty( $format ) ) {
			$format = '%1$04d%3$06d';
		}

		// Ověření vzorce pokusem o spuštění s testovacími hodnotami.
		try {
			$test = sprintf( $format, 2026, 4, 1 );
			if ( empty( $test ) ) {
				throw new \RuntimeException( 'Prázdný výsledek.' );
			}
		} catch ( \Throwable $e ) {
			self::set_notice( 'error', __( 'Neplatný vzorec. Nastavení nebylo uloženo.', 'mo-order-number' ) );
			self::redirect_back();
		}

		update_option( MON_Core::OPTION_SETTINGS, compact( 'format', 'period' ) );
		self::set_notice( 'success', __( 'Nastavení bylo uloženo.', 'mo-order-number' ) );
		self::redirect_back();
	}

	// -----------------------------------------------------------------------
	// Reset čítače
	// -----------------------------------------------------------------------

	public static function handle_reset(): void {
		$period_key = sanitize_key( $_GET['period_key'] ?? '' );

		if ( ! $period_key
			|| ! current_user_can( 'manage_woocommerce' )
			|| ! check_admin_referer( 'mon_reset_' . $period_key )
		) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'mo-order-number' ) );
		}

		MON_Core::reset_counter( $period_key );

		self::set_notice(
			'success',
			sprintf(
				/* translators: %s: identifikátor čítače (např. y2026, m202604, global) */
				__( 'Čítač „%s" byl resetován na 0.', 'mo-order-number' ),
				$period_key
			)
		);
		self::redirect_back();
	}

	// -----------------------------------------------------------------------
	// Privátní pomocné metody
	// -----------------------------------------------------------------------

	/**
	 * Detekuje, zda aktuální nastavení odpovídá některé předvolbě.
	 * Vrátí klíč předvolby nebo prázdný řetězec.
	 */
	private static function active_preset( array $settings ): string {
		foreach ( self::presets() as $key => $p ) {
			if ( $p['format'] === $settings['format'] && $p['period'] === $settings['period'] ) {
				return $key;
			}
		}
		return '';
	}

	private static function set_notice( string $type, string $message ): void {
		set_transient(
			'mon_admin_notice_' . get_current_user_id(),
			compact( 'type', 'message' ),
			60
		);
	}

	private static function redirect_back(): never {
		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}
}
