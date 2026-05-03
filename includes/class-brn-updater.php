<?php
/**
 * BRN_Updater — checks for plugin updates from GitHub Releases and integrates
 * with the WordPress plugin-update system so core can auto-install the update.
 *
 * @package brn-lead-count
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'BRN_Updater' ) ) {

	class BRN_Updater {

		/** GitHub owner/repo, e.g. "brncoil/brn-lead-count". */
		const GITHUB_REPO = 'brncoil/brn-lead-count';

		/** Plugin basename as WordPress uses it: folder/main-file.php. */
		const PLUGIN_BASENAME = 'brn-lead-count/brn-lead-count.php';

		/** WordPress plugin folder slug (directory name). */
		const PLUGIN_SLUG = 'brn-lead-count';

		/** How long to keep cached release data before re-fetching (seconds). */
		const CACHE_TTL = DAY_IN_SECONDS;

		/** Option key for the cached GitHub release payload. */
		const OPT_CACHE = 'brn_updater_cache';

		/** Option key for the cache-expiry Unix timestamp. */
		const OPT_EXPIRY = 'brn_updater_cache_expiry';

		/** Option key for last-error string (empty string = no error). */
		const OPT_LAST_ERROR = 'brn_updater_last_error';

		/** Option key for last-checked Unix timestamp. */
		const OPT_LAST_CHECKED = 'brn_updater_last_checked';

		// ------------------------------------------------------------------ //

		public function __construct() {
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_transient' ) );
			add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 20, 3 );
			add_filter( 'auto_update_plugin', array( $this, 'enable_auto_update' ), 10, 2 );
		}

		// ------------------------------------------------------------------ //
		// Public API
		// ------------------------------------------------------------------ //

		/**
		 * Returns update data from cache (or live if stale).
		 * Returns an array with keys: version, download_url, changelog, name.
		 * Returns false on failure.
		 *
		 * @param bool $force_refresh Skip cache and re-fetch.
		 * @return array|false
		 */
		public function get_update_info( $force_refresh = false ) {
			if ( ! $force_refresh ) {
				$expiry = (int) get_option( self::OPT_EXPIRY, 0 );
				$cached = get_option( self::OPT_CACHE );
				if ( $cached && time() < $expiry ) {
					return $cached;
				}
			}

			return $this->fetch_and_cache();
		}

		/**
		 * Deletes the cache so the next get_update_info() call re-fetches.
		 *
		 * @return void
		 */
		public function bust_cache() {
			delete_option( self::OPT_CACHE );
			delete_option( self::OPT_EXPIRY );
		}

		// ------------------------------------------------------------------ //
		// WordPress update hooks
		// ------------------------------------------------------------------ //

		/**
		 * Injects our update data into the WordPress plugin-update transient.
		 *
		 * @param object|false $transient
		 * @return object|false
		 */
		public function filter_update_transient( $transient ) {
			if ( empty( $transient ) ) {
				return $transient;
			}

			$info = $this->get_update_info();
			if ( ! $info ) {
				return $transient;
			}

			$current_version = $this->get_installed_version();

			if ( version_compare( $current_version, $info['version'], '<' ) ) {
				$transient->response[ self::PLUGIN_BASENAME ] = $this->build_update_object( $info );
			} else {
				$transient->no_update[ self::PLUGIN_BASENAME ] = $this->build_update_object( $info );
			}

			return $transient;
		}

		/**
		 * Supplies plugin info for the "View details" modal in the Plugins screen.
		 *
		 * @param object|false $result
		 * @param string       $action
		 * @param object       $args
		 * @return object|false
		 */
		public function filter_plugins_api( $result, $action, $args ) {
			if ( 'plugin_information' !== $action ) {
				return $result;
			}

			if ( ! isset( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
				return $result;
			}

			$info = $this->get_update_info();
			if ( ! $info ) {
				return $result;
			}

			return $this->build_update_object( $info );
		}

		/**
		 * Enables automatic (unattended) updates for this plugin only.
		 *
		 * @param bool|null $update
		 * @param object    $item
		 * @return bool|null
		 */
		public function enable_auto_update( $update, $item ) {
			if ( isset( $item->plugin ) && self::PLUGIN_BASENAME === $item->plugin ) {
				return true;
			}
			return $update;
		}

		// ------------------------------------------------------------------ //
		// Internal helpers
		// ------------------------------------------------------------------ //

		/**
		 * Fetches the latest GitHub release, caches it, and returns normalized data.
		 *
		 * @return array|false
		 */
		private function fetch_and_cache() {
			$url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 15,
					'headers' => array(
						'Accept'     => 'application/vnd.github+json',
						'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
					),
				)
			);

			update_option( self::OPT_LAST_CHECKED, time(), false );

			if ( is_wp_error( $response ) ) {
				$this->record_error( $response->get_error_message() );
				return false;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== (int) $code ) {
				$this->record_error( 'GitHub API returned HTTP ' . $code );
				return false;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( json_last_error() !== JSON_ERROR_NONE || empty( $body['tag_name'] ) ) {
				$this->record_error( 'Could not parse GitHub API response.' );
				return false;
			}

			// Find the installable ZIP asset attached to the release.
			$zip_url = '';
			if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
				foreach ( $body['assets'] as $asset ) {
					if ( isset( $asset['browser_download_url'] ) && substr( $asset['browser_download_url'], -4 ) === '.zip' ) {
						$zip_url = $asset['browser_download_url'];
						break;
					}
				}
			}

			// Fall back to the GitHub-generated source ZIP if no dedicated asset found.
			if ( empty( $zip_url ) ) {
				$zip_url = 'https://github.com/' . self::GITHUB_REPO . '/archive/refs/tags/' . rawurlencode( $body['tag_name'] ) . '.zip';
			}

			$info = array(
				'name'         => 'BRN Lead Count',
				'version'      => ltrim( $body['tag_name'], 'vV' ),
				'download_url' => $zip_url,
				'changelog'    => ! empty( $body['body'] ) ? wp_kses_post( $body['body'] ) : '',
				'published_at' => isset( $body['published_at'] ) ? $body['published_at'] : '',
			);

			update_option( self::OPT_CACHE, $info, false );
			update_option( self::OPT_EXPIRY, time() + self::CACHE_TTL, false );
			update_option( self::OPT_LAST_ERROR, '', false );

			return $info;
		}

		/**
		 * Builds the stdClass object that WordPress expects in update transients and plugins_api.
		 *
		 * @param array $info
		 * @return \stdClass
		 */
		private function build_update_object( array $info ) {
			global $wp_version;

			$obj               = new stdClass();
			$obj->id           = self::PLUGIN_BASENAME;
			$obj->slug         = self::PLUGIN_SLUG;
			$obj->plugin       = self::PLUGIN_BASENAME;
			$obj->name         = $info['name'];
			$obj->new_version  = $info['version'];
			$obj->version      = $info['version'];
			$obj->requires     = '5.6';
			$obj->tested       = $wp_version;
			$obj->download_link = $info['download_url'];
			$obj->package      = $info['download_url'];
			$obj->trunk        = $info['download_url'];
			$obj->sections     = array(
				'description' => '<p>Counts and logs lead actions (phone clicks, WhatsApp clicks, and form submissions).</p>',
				'changelog'   => ! empty( $info['changelog'] )
					? '<pre>' . esc_html( $info['changelog'] ) . '</pre>'
					: '<p>See GitHub releases for full changelog.</p>',
			);

			return $obj;
		}

		/**
		 * Returns the currently installed plugin version from the plugin header.
		 *
		 * @return string
		 */
		private function get_installed_version() {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . self::PLUGIN_BASENAME, false, false );

			return isset( $data['Version'] ) ? $data['Version'] : '0.0.0';
		}

		/**
		 * Saves an error message and clears the cache expiry so the next request retries.
		 *
		 * @param string $message
		 * @return void
		 */
		private function record_error( $message ) {
			update_option( self::OPT_LAST_ERROR, sanitize_text_field( $message ), false );
			// On error: keep cached data (if any) but reset expiry so retry happens next time.
			delete_option( self::OPT_EXPIRY );
		}
	}

}
