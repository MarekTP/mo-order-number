<?php
/**
 * Lehký GitHub updater: sleduje nejnovější release a nabízí aktualizaci
 * přes standardní WordPress mechanismus (Pluginy → aktualizace).
 *
 * Předpoklady na GitHub repozitáři:
 *  - Release je označen tagem ve tvaru `v1.2.3` nebo `1.2.3`.
 *  - Release obsahuje asset `mo-order-number.zip` s hotovým pluginem,
 *    nebo je akceptován GitHub zipball (složka bude přejmenována).
 *
 * @package MO_Order_Number
 */

defined( 'ABSPATH' ) || exit;

class MON_Updater {

	private string $plugin_file;
	private string $plugin_slug;   // relativní cesta: mo-order-number/mo-order-number.php
	private string $current_version;
	private string $github_user;
	private string $github_repo;
	private string $cache_key;

	public function __construct(
		string $plugin_file,
		string $github_user,
		string $github_repo,
		string $current_version
	) {
		$this->plugin_file      = $plugin_file;
		$this->plugin_slug      = plugin_basename( $plugin_file );
		$this->current_version  = $current_version;
		$this->github_user      = $github_user;
		$this->github_repo      = $github_repo;
		$this->cache_key        = 'mon_gh_release_' . md5( $github_user . '/' . $github_repo );
	}

	public function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
		add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 20, 3 );
		add_filter( 'upgrader_post_install',                 [ $this, 'post_install' ], 10, 3 );
	}

	// -----------------------------------------------------------------------
	// GitHub API
	// -----------------------------------------------------------------------

	/**
	 * Vrátí objekt posledního release z GitHub API (s 12h cache v transientu).
	 * Při selhání dotazu uloží `false` s 1h platností, aby se request neopakoval.
	 */
	private function get_release(): ?object {
		$cached = get_transient( $this->cache_key );
		if ( false !== $cached ) {
			return $cached ?: null;
		}

		$response = wp_remote_get(
			"https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest",
			[
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
				],
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $this->cache_key, false, HOUR_IN_SECONDS );
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ) );
		set_transient( $this->cache_key, $release, 12 * HOUR_IN_SECONDS );
		return $release;
	}

	// -----------------------------------------------------------------------
	// WordPress update mechanismus
	// -----------------------------------------------------------------------

	/**
	 * Přidá plugin do fronty aktualizací pokud je na GitHubu novější verze.
	 */
	public function check_update( object $transient ): object {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_release();
		if ( ! $release || empty( $release->tag_name ) ) {
			return $transient;
		}

		$remote_version = ltrim( $release->tag_name, 'v' );
		if ( ! version_compare( $remote_version, $this->current_version, '>' ) ) {
			return $transient;
		}

		$zip_url = $this->resolve_zip_url( $release );
		if ( ! $zip_url ) {
			return $transient;
		}

		$transient->response[ $this->plugin_slug ] = (object) [
			'slug'        => $this->github_repo,
			'plugin'      => $this->plugin_slug,
			'new_version' => $remote_version,
			'url'         => "https://github.com/{$this->github_user}/{$this->github_repo}",
			'package'     => $zip_url,
			'icons'       => [],
			'banners'     => [],
			'tested'      => '',
			'requires'    => '',
		];

		return $transient;
	}

	/**
	 * Poskytne informace o pluginu v dialogu „Zobrazit podrobnosti" ve WP admin.
	 */
	public function plugin_info( mixed $result, string $action, object $args ): mixed {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== $this->github_repo ) {
			return $result;
		}

		$release = $this->get_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'MO Order Number',
			'slug'          => $this->github_repo,
			'version'       => ltrim( $release->tag_name, 'v' ),
			'author'        => '<a href="https://olsavsky.cz">Marek Olšavský</a>',
			'homepage'      => "https://github.com/{$this->github_user}/{$this->github_repo}",
			'download_link' => $this->resolve_zip_url( $release ) ?? '',
			'requires'      => '6.4',
			'requires_php'  => '8.0',
			'sections'      => [
				'changelog' => '<pre>' . esc_html( $release->body ?? '' ) . '</pre>',
			],
		];
	}

	/**
	 * Po instalaci přejmenuje rozbalený adresář na správný název pluginu.
	 * GitHub zipball obsahuje složku ve tvaru `user-repo-abc1234/`, nikoliv `mo-order-number/`.
	 */
	public function post_install( bool $response, array $hook_extra, array $result ): array {
		// Spouštíme jen pro náš plugin.
		if ( ( $hook_extra['plugin'] ?? '' ) !== $this->plugin_slug ) {
			return $result;
		}

		global $wp_filesystem;
		$plugin_dir = plugin_dir_path( $this->plugin_file );
		$wp_filesystem->move( $result['destination'], $plugin_dir, true );
		$result['destination'] = $plugin_dir;

		if ( is_plugin_active( $this->plugin_slug ) ) {
			activate_plugin( $this->plugin_slug );
		}

		return $result;
	}

	// -----------------------------------------------------------------------
	// Pomocné metody
	// -----------------------------------------------------------------------

	/**
	 * Najde URL ke stahovatelnému ZIPu.
	 * Přednost má release asset (*.zip), fallback je GitHub zipball.
	 */
	private function resolve_zip_url( object $release ): ?string {
		foreach ( ( $release->assets ?? [] ) as $asset ) {
			if ( str_ends_with( $asset->name ?? '', '.zip' ) ) {
				return $asset->browser_download_url ?? null;
			}
		}
		return $release->zipball_url ?? null;
	}
}
