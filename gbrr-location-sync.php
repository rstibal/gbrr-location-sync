<?php
/**
 * Plugin Name: GBRR Location Sync
 * Plugin URI:  https://robstibal.com
 * Description: Automatically syncs all saved locations in Reviews and Rating – Google Reviews (g-business-reviews-rating) daily via WP-Cron, and fixes the duplicate-default-location bug that occurs after any manual location cycle.
 * Version:     1.3.1
 * Author:      Rob Stibal
 * Author URI:  https://robstibal.com
 * License:     GPLv2 or later
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GBRR_SYNC_VERSION',     '1.3.1' );
define( 'GBRR_SYNC_OPTION_BASE', 'google_business_reviews_rating' );
define( 'GBRR_SYNC_CRON_HOOK',   'gbrr_location_sync_run' );
define( 'GBRR_SYNC_LOG_OPTION',  'gbrr_location_sync_log' );
define( 'GBRR_SYNC_DB_OPTION',   'gbrr_sync_db_version' );
define( 'GBRR_SYNC_NOTIFY_ENABLED_OPTION', 'gbrr_location_sync_notify_enabled' );
define( 'GBRR_SYNC_NOTIFY_EMAIL_OPTION',   'gbrr_location_sync_notify_email' );

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

add_action( 'init', array( 'GBRR_Location_Sync', 'get_instance' ) );
register_activation_hook( __FILE__,   array( 'GBRR_Location_Sync', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GBRR_Location_Sync', 'deactivate' ) );

// ---------------------------------------------------------------------------
// Main class
// ---------------------------------------------------------------------------

class GBRR_Location_Sync {

	/** @var GBRR_Location_Sync|null */
	private static $instance = null;

	// -----------------------------------------------------------------------
	// Singleton
	// -----------------------------------------------------------------------

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( GBRR_SYNC_CRON_HOOK,          array( $this, 'run_sync' ) );
		add_action( 'admin_menu',                  array( $this, 'admin_menu' ) );
		add_action( 'admin_notices',               array( $this, 'dependency_notice' ) );
		add_action( 'admin_post_gbrr_sync_now',    array( $this, 'handle_manual_sync' ) );
		add_action( 'admin_post_gbrr_conn_test',   array( $this, 'handle_conn_test' ) );
		add_action( 'admin_post_gbrr_save_notify', array( $this, 'handle_save_notify' ) );
		add_action( 'admin_init',                  array( $this, 'maybe_repair_reviews' ) );
	}

	// -----------------------------------------------------------------------
	// Activation / deactivation
	// -----------------------------------------------------------------------

	public static function activate() {
		if ( ! wp_next_scheduled( GBRR_SYNC_CRON_HOOK ) ) {
			$midnight = strtotime( 'tomorrow midnight UTC' );
			wp_schedule_event( $midnight, 'daily', GBRR_SYNC_CRON_HOOK );
		}
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( GBRR_SYNC_CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, GBRR_SYNC_CRON_HOOK );
		}
		wp_clear_scheduled_hook( GBRR_SYNC_CRON_HOOK );
	}

	// -----------------------------------------------------------------------
	// Core sync logic
	// -----------------------------------------------------------------------

	public function run_sync() {
		$api_key   = get_option( GBRR_SYNC_OPTION_BASE . '_api_key' );
		$places    = get_option( GBRR_SYNC_OPTION_BASE . '_places', array() );
		$active_id = get_option( GBRR_SYNC_OPTION_BASE . '_place_id' );

		if ( empty( $api_key ) || ! is_array( $places ) || empty( $places ) ) {
			$this->log( 'skip', 'No API key or no saved locations; aborting sync.' );
			return array();
		}

		$fields = get_option( GBRR_SYNC_OPTION_BASE . '_retrieval_fields', null );
		if ( ! is_array( $fields ) ) {
			$fields = array(
				'formatted_address',
				'icon',
				'id',
				'name',
				'rating',
				'reviews',
				'url',
				'user_ratings_total',
				'vicinity',
			);
		}

		$language      = get_option( GBRR_SYNC_OPTION_BASE . '_language', null );
		$translate_raw = get_option( GBRR_SYNC_OPTION_BASE . '_retrieval_translate', null );
		$translate     = (
			( is_bool( $translate_raw ) && $translate_raw )
			|| ( is_string( $translate_raw ) && preg_match( '/^(?:1|true)$/i', $translate_raw ) )
			|| ( is_numeric( $translate_raw ) && intval( $translate_raw ) >= 1 )
		);

		$reviews_all = get_option( GBRR_SYNC_OPTION_BASE . '_reviews', array() );
		if ( ! is_array( $reviews_all ) ) {
			$reviews_all = array();
		}

		$results = array();

		foreach ( $places as $idx => $place ) {
			$pid = isset( $place['place_id'] ) ? $place['place_id'] : null;
			if ( ! $pid ) {
				continue;
			}

			$response = $this->fetch_place( $pid, $api_key, $fields, $language, $translate );

			if ( is_wp_error( $response ) ) {
				$safe_error = $this->redact_key( $response->get_error_message() );
				$places[ $idx ]['status'] = false;
				$results[ $pid ] = array( 'ok' => false, 'error' => $safe_error );
				$this->log( 'error', sprintf( 'WP_Error for %s (%s): %s', $pid, $place['vicinity'] ?? '', $safe_error ) );
				continue;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $data ) || empty( $data ) || ! isset( $data['status'] ) ) {
				$places[ $idx ]['status'] = false;
				$results[ $pid ] = array( 'ok' => false, 'error' => 'Empty or malformed API response' );
				$this->log( 'error', sprintf( 'Malformed response for %s (%s)', $pid, $place['vicinity'] ?? '' ) );
				continue;
			}

			if ( 'OK' !== $data['status'] ) {
				$places[ $idx ]['status'] = false;
				$error_msg = isset( $data['error_message'] ) ? $data['error_message'] : $data['status'];
				$results[ $pid ] = array( 'ok' => false, 'error' => $error_msg );
				$this->log( 'error', sprintf( 'API status "%s" for %s (%s): %s', $data['status'], $pid, $place['vicinity'] ?? '', $error_msg ) );
				continue;
			}

			// Merge new reviews into the master reviews store
			if ( isset( $data['result']['reviews'] ) && is_array( $data['result']['reviews'] ) ) {
				$reviews_all = $this->merge_reviews( $reviews_all, $data['result']['reviews'], $pid );
			}

			// Update the place snapshot
			$r = $data['result'];
			$places[ $idx ]['time']              = time();
			$places[ $idx ]['status']            = isset( $r['name'] ) && $r['name'] !== null;
			$places[ $idx ]['name']              = $r['name']               ?? ( $places[ $idx ]['name']              ?? null );
			$places[ $idx ]['rating']            = $r['rating']             ?? null;
			$places[ $idx ]['rating_count']      = $r['user_ratings_total'] ?? null;
			$places[ $idx ]['formatted_address'] = $r['formatted_address']  ?? ( $places[ $idx ]['formatted_address'] ?? null );
			$places[ $idx ]['vicinity']          = $r['vicinity']           ?? ( $places[ $idx ]['vicinity']          ?? null );
			$places[ $idx ]['icon']              = $r['icon']               ?? ( $places[ $idx ]['icon']              ?? null );

			// Refresh _result for the active/default location
			if ( $pid === $active_id ) {
				update_option( GBRR_SYNC_OPTION_BASE . '_result',       $data, 'no' );
				update_option( GBRR_SYNC_OPTION_BASE . '_result_valid', $data, 'no' );
				wp_cache_delete( 'result',       GBRR_SYNC_OPTION_BASE );
				wp_cache_delete( 'result_valid', GBRR_SYNC_OPTION_BASE );
			}

			$results[ $pid ] = array(
				'ok'           => true,
				'name'         => $r['name']               ?? '',
				'rating'       => $r['rating']             ?? null,
				'rating_count' => $r['user_ratings_total'] ?? null,
				'vicinity'     => $place['vicinity']        ?? '',
			);

			$this->log( 'sync', sprintf(
				'OK: %s (%s) — %s / %s reviews',
				$pid,
				$place['vicinity'] ?? '',
				isset( $r['rating'] ) ? $r['rating'] . '*' : 'no rating',
				isset( $r['user_ratings_total'] ) ? number_format( $r['user_ratings_total'] ) : '0'
			) );
		}

		// Fix the default flag: exactly one entry should be true, matching active_id
		foreach ( $places as $idx => $place ) {
			$places[ $idx ]['default'] = ( ( $place['place_id'] ?? null ) === $active_id );
		}

		// Persist
		update_option( GBRR_SYNC_OPTION_BASE . '_places',  $places,      'yes' );
		update_option( GBRR_SYNC_OPTION_BASE . '_reviews', $reviews_all, 'no' );
		wp_cache_delete( 'reviews',          GBRR_SYNC_OPTION_BASE );
		wp_cache_delete( 'reviews_shuffled', GBRR_SYNC_OPTION_BASE );
		delete_transient( GBRR_SYNC_OPTION_BASE . '_reviews_shuffled' );

		$ok_count = count( array_filter( $results, function( $r ) { return $r['ok']; } ) );
		$this->log( 'complete', sprintf(
			'Sync complete. %d/%d locations updated successfully.',
			$ok_count,
			count( $results )
		) );

		$this->maybe_notify_errors( $results, $places );

		return $results;
	}

	// -----------------------------------------------------------------------
	// Error email notifications
	// -----------------------------------------------------------------------

	/**
	 * Emails the configured address(es) a summary of this run's failed
	 * locations, if notifications are enabled and at least one failed.
	 * One email per run — not per error — to avoid spamming on repeated
	 * failures of the same location.
	 */
	private function maybe_notify_errors( $results, $places ) {
		if ( ! get_option( GBRR_SYNC_NOTIFY_ENABLED_OPTION, false ) ) {
			return;
		}

		$failures = array_filter( $results, function( $r ) { return ! $r['ok']; } );
		if ( empty( $failures ) ) {
			return;
		}

		$to = $this->get_notify_emails();
		if ( empty( $to ) ) {
			return;
		}

		$names_by_pid = array();
		foreach ( $places as $place ) {
			if ( isset( $place['place_id'] ) ) {
				$names_by_pid[ $place['place_id'] ] = $place['name'] ?? $place['vicinity'] ?? $place['place_id'];
			}
		}

		$lines = array();
		foreach ( $failures as $pid => $failure ) {
			$lines[] = sprintf(
				'- %s (%s): %s',
				$names_by_pid[ $pid ] ?? $pid,
				$pid,
				$failure['error'] ?? 'Unknown error'
			);
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( '[%s] GBRR Location Sync: %d location(s) failed to sync', $site_name, count( $failures ) );
		$body      = sprintf(
			"GBRR Location Sync ran on %s and %d of %d location(s) failed:\n\n%s\n\nSee Settings \xE2\x86\x92 GBRR Sync on %s for the full log.",
			gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			count( $failures ),
			count( $results ),
			implode( "\n", $lines ),
			home_url()
		);

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Returns the configured notification email address(es) as an array,
	 * or an empty array if none are set.
	 */
	private function get_notify_emails() {
		$raw = get_option( GBRR_SYNC_NOTIFY_EMAIL_OPTION, '' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$emails = array_filter( array_map( 'trim', explode( ',', $raw ) ), 'is_email' );
		return array_values( $emails );
	}

	// -----------------------------------------------------------------------
	// One-time repair of legacy review rows (bumped on schema-affecting changes)
	// -----------------------------------------------------------------------

	public function maybe_repair_reviews() {
		if ( get_option( GBRR_SYNC_DB_OPTION ) === GBRR_SYNC_VERSION ) {
			return;
		}

		$reviews = get_option( GBRR_SYNC_OPTION_BASE . '_reviews', array() );

		if ( is_array( $reviews ) && ! empty( $reviews ) ) {
			$repaired = 0;

			foreach ( $reviews as $key => $row ) {
				if ( ! is_array( $row ) || array_key_exists( 'checked', $row ) ) {
					continue; // Already native-shaped.
				}

				$fixed = $this->normalize_review_row( $row, $row, $row['place_id'] ?? '' );

				// Legacy rows have no known retrieval timestamp — mark them
				// as imported instead so the admin table shows "Imported".
				$fixed['retrieved'] = $row['retrieved'] ?? null;
				$fixed['imported']  = $row['imported'] ?? ( $row['time'] ?? time() );

				$reviews[ $key ] = $fixed;
				$repaired++;
			}

			if ( $repaired > 0 ) {
				update_option( GBRR_SYNC_OPTION_BASE . '_reviews', $reviews, 'no' );
				wp_cache_delete( 'reviews',          GBRR_SYNC_OPTION_BASE );
				wp_cache_delete( 'reviews_shuffled', GBRR_SYNC_OPTION_BASE );
				delete_transient( GBRR_SYNC_OPTION_BASE . '_reviews_shuffled' );
				$this->log( 'repair', sprintf( 'v%s repair: backfilled native row keys on %d legacy review(s).', GBRR_SYNC_VERSION, $repaired ) );
			}
		}

		update_option( GBRR_SYNC_DB_OPTION, GBRR_SYNC_VERSION, 'no' );
	}

	// -----------------------------------------------------------------------
	// Connection / IP test
	// -----------------------------------------------------------------------

	/**
	 * Tests the stored Google Places API key against the active location.
	 * Returns an array with keys: api_status, api_error, place_name, time.
	 */
	public function run_conn_test() {
		$result = array(
			'api_status' => null,
			'api_error'  => null,
			'place_name' => null,
			'time'       => time(),
		);

		$api_key  = get_option( GBRR_SYNC_OPTION_BASE . '_api_key' );
		$place_id = get_option( GBRR_SYNC_OPTION_BASE . '_place_id' );

		if ( empty( $api_key ) || empty( $place_id ) ) {
			$result['api_status'] = 'skipped';
			$result['api_error']  = 'No API key or Place ID configured in the parent plugin.';
			return $result;
		}

		$url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query( array(
			'placeid' => $place_id,
			'key'     => $api_key,
			'fields'  => 'name,rating',
		) );

		$resp = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $resp ) ) {
			$result['api_status'] = 'wp_error';
			$result['api_error']  = $this->redact_key( $resp->get_error_message() );
			return $result;
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		$result['api_status'] = $body['status'] ?? 'UNKNOWN';
		$result['place_name'] = $body['result']['name'] ?? null;

		if ( isset( $body['error_message'] ) ) {
			$result['api_error'] = $this->redact_key( $body['error_message'] );
		}

		return $result;
	}

	// -----------------------------------------------------------------------
	// Places API fetch
	// -----------------------------------------------------------------------

	private function fetch_place( $place_id, $api_key, $fields, $language, $translate ) {
		$args = array(
			'placeid'                 => $place_id,
			'key'                     => $api_key,
			'fields'                  => implode( ',', $fields ),
			'reviews_sort'            => 'most_relevant',
			'reviews_no_translations' => $translate ? 'false' : 'true',
		);
		if ( ! empty( $language ) ) {
			$args['language'] = $language;
		}

		$url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query( $args );

		return wp_remote_get( $url, array( 'timeout' => 15 ) );
	}

	// -----------------------------------------------------------------------
	// Review merging
	// -----------------------------------------------------------------------

	private function merge_reviews( $existing, $new_reviews, $place_id ) {
		// Native rows start their `id` counter at max(existing ids) + 1
		// (see g-business-reviews-rating's set_reviews()); mirror that
		// here so IDs we assign don't collide with what the parent
		// plugin assigns on its own hourly sync.
		$max_id = 0;
		foreach ( $existing as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && is_numeric( $row['id'] ) && $row['id'] > $max_id ) {
				$max_id = $row['id'];
			}
		}

		foreach ( $new_reviews as $review ) {
			if ( ! isset( $review['time'], $review['rating'], $review['author_name'] ) ) {
				continue;
			}
			// Must match the parent plugin's own key formula exactly
			// (time_rating_md5(author+text)) or every review we write
			// gets treated as unrecognized on the parent's next hourly
			// sync and re-keyed via its slower author-matching fallback.
			$key = $review['time'] . '_' . $review['rating'] . '_' . md5( $review['author_name'] . '_' . mb_substr( (string) ( $review['text'] ?? '' ), 0, 100 ) );
			$row = ( isset( $existing[ $key ] ) && is_array( $existing[ $key ] ) ) ? $existing[ $key ] : array();

			$next_id = null;
			if ( empty( $row ) ) {
				$max_id++;
				$next_id = $max_id;
			}

			$existing[ $key ] = $this->normalize_review_row( $row, $review, $place_id, $next_id );
		}
		return $existing;
	}

	/**
	 * Returns a review row shaped like the parent plugin's own native
	 * rows (g-business-reviews-rating's set_reviews()).
	 *
	 * Rows missing keys — especially `checked` — fail the parent's
	 * "recently checked" guard and are re-processed (remote avatar
	 * fetch included) on every run, which is what caused the
	 * wp-admin 502s. Keys the parent manages itself (`id`, `order`,
	 * `checked`, `avatar`) are preserved when present, never invented.
	 *
	 * $next_id is only used when $row is empty (a genuinely new
	 * review) — it must be pre-computed by the caller as
	 * max(existing ids) + 1, matching the parent's own id assignment.
	 */
	private function normalize_review_row( $row, $review, $place_id, $next_id = null ) {
		$is_new = empty( $row );

		$normalized = array(
			'id'                        => $row['id'] ?? $next_id,
			'place_id'                  => $place_id,
			'order'                     => $row['order'] ?? 0,
			'checked'                   => $row['checked'] ?? null,
			'retrieved'                 => time(),
			'imported'                  => $row['imported'] ?? ( $is_new ? false : null ),
			'time_estimate'             => $row['time_estimate'] ?? false,
			'removable'                 => $row['removable'] ?? false,
			'status'                    => $row['status'] ?? true,
			'profile_photo_url'         => $review['profile_photo_url'] ?? ( $row['profile_photo_url'] ?? '' ),
			'author_name'               => $review['author_name'] ?? ( $row['author_name'] ?? '' ),
			'author_url'                => $review['author_url'] ?? ( $row['author_url'] ?? '' ),
			'language'                  => $review['language'] ?? ( $row['language'] ?? '' ),
			'rating'                    => $review['rating'] ?? ( $row['rating'] ?? null ),
			'relative_time_description' => $review['relative_time_description'] ?? ( $row['relative_time_description'] ?? '' ),
			'text'                      => $review['text'] ?? ( $row['text'] ?? '' ),
			'time'                      => $review['time'] ?? ( $row['time'] ?? null ),
			'translated'                => $review['translated'] ?? ( $row['translated'] ?? false ),
		);

		// Preserve the parent plugin's locally cached avatar filename if it exists.
		if ( isset( $row['avatar'] ) ) {
			$normalized['avatar'] = $row['avatar'];
		}

		return $normalized;
	}

	// -----------------------------------------------------------------------
	// Logging
	// -----------------------------------------------------------------------

	private function log( $type, $message ) {
		$log = get_option( GBRR_SYNC_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		if ( count( $log ) >= 500 ) {
			$log = array_slice( $log, -499, null, true );
		}
		$log[] = array(
			'type'    => $type,
			'message' => $message,
			'time'    => time(),
		);
		update_option( GBRR_SYNC_LOG_OPTION, $log, 'no' );
	}

	// -----------------------------------------------------------------------
	// Admin
	// -----------------------------------------------------------------------

	public function admin_menu() {
		add_options_page(
			'GBRR Location Sync',
			'GBRR Sync',
			'manage_options',
			'gbrr-location-sync',
			array( $this, 'admin_page' )
		);
	}

	public function dependency_notice() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== 'settings_page_gbrr-location-sync' ) {
			return;
		}
		if ( ! $this->parent_plugin_active() ) {
			echo '<div class="notice notice-error"><p><strong>GBRR Location Sync:</strong> '
				. esc_html( 'The "Reviews and Rating – Google Reviews" plugin is not active. This plugin requires it.' )
				. '</p></div>';
		}
	}

	public function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Access denied.' );
		}

		$places    = get_option( GBRR_SYNC_OPTION_BASE . '_places', array() );
		$log       = get_option( GBRR_SYNC_LOG_OPTION, array() );
		$next_run  = wp_next_scheduled( GBRR_SYNC_CRON_HOOK );
		$active_id = get_option( GBRR_SYNC_OPTION_BASE . '_place_id' );

		$flash      = get_transient( 'gbrr_sync_flash' );
		$conn_result = get_transient( 'gbrr_conn_result' );
		delete_transient( 'gbrr_sync_flash' );
		delete_transient( 'gbrr_conn_result' );
		?>
		<div class="wrap">
			<h1>GBRR Location Sync</h1>

			<?php if ( $flash ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $flash ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $this->parent_plugin_active() ) : ?>
				<div class="notice notice-error"><p>
					<strong>Dependency missing:</strong>
					The "Reviews and Rating – Google Reviews" plugin is not active.
				</p></div>
			<?php endif; ?>

			<!-- Status / action bar -->
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin:16px 0;display:flex;align-items:center;gap:40px;flex-wrap:wrap;">
				<div>
					<strong>Next scheduled sync</strong><br>
					<?php if ( $next_run ) : ?>
						<?php echo esc_html( gmdate( 'Y-m-d H:i:s', $next_run ) . ' UTC' ); ?>
						<span style="color:#666;margin-left:8px;">(<?php echo esc_html( human_time_diff( $next_run ) ); ?> from now)</span>
					<?php else : ?>
						<span style="color:#d63638">Not scheduled &mdash; try deactivating and reactivating the plugin.</span>
					<?php endif; ?>
				</div>
				<div>
					<strong>Locations tracked</strong><br>
					<?php echo esc_html( count( $places ) ); ?>
				</div>
				<div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'gbrr_conn_test', 'gbrr_conn_nonce' ); ?>
						<input type="hidden" name="action" value="gbrr_conn_test">
						<button class="button" type="submit">&#x1F50D; Test Connection &amp; IP</button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'gbrr_sync_now', 'gbrr_nonce' ); ?>
						<input type="hidden" name="action" value="gbrr_sync_now">
						<button class="button button-primary" type="submit">&#x21bb; Sync All Locations Now</button>
					</form>
				</div>
			</div>

			<!-- Connection test result -->
			<?php if ( $conn_result ) :
				$api_ok       = ( 'OK' === ( $conn_result['api_status'] ?? '' ) );
				$box_color    = $api_ok ? '#f0f6fc' : '#fef2f2';
				$border_color = $api_ok ? '#72aee6' : '#d63638';
			?>
			<div style="background:<?php echo esc_attr( $box_color ); ?>;border:1px solid <?php echo esc_attr( $border_color ); ?>;border-radius:4px;padding:16px 20px;margin-bottom:16px;">
				<h3 style="margin-top:0;">Connection Test Result
					<span style="font-size:13px;font-weight:normal;margin-left:8px;color:#666;">
						<?php echo esc_html( gmdate( 'Y-m-d H:i:s', $conn_result['time'] ) . ' UTC' ); ?>
					</span>
				</h3>
				<table style="border-collapse:collapse;width:100%;max-width:640px;">
					<tr>
						<td style="padding:5px 12px 5px 0;font-weight:600;white-space:nowrap;">Places API status</td>
						<td style="padding:5px 0;">
							<?php if ( $api_ok ) : ?>
								<span style="color:#00a32a;font-weight:600;">&#10004; OK &mdash; key accepted, API responding</span>
								<?php if ( $conn_result['place_name'] ) : ?>
									<span style="margin-left:8px;color:#666;">(<?php echo esc_html( $conn_result['place_name'] ); ?>)</span>
								<?php endif; ?>
							<?php elseif ( 'skipped' === $conn_result['api_status'] ) : ?>
								<span style="color:#996600;">&#9888; Skipped &mdash; <?php echo esc_html( $conn_result['api_error'] ?? '' ); ?></span>
							<?php else : ?>
								<span style="color:#d63638;font-weight:600;">&#10008; <?php echo esc_html( $conn_result['api_status'] ?? 'FAILED' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( ! empty( $conn_result['api_error'] ) && 'skipped' !== $conn_result['api_status'] ) : ?>
					<tr>
						<td style="padding:5px 12px 5px 0;font-weight:600;white-space:nowrap;">Error detail</td>
						<td style="padding:5px 0;color:#d63638;"><?php echo esc_html( $conn_result['api_error'] ); ?></td>
					</tr>
					<?php endif; ?>
				</table>
			</div>
			<?php endif; ?>

			<!-- Error email notifications -->
			<h2 style="margin-top:24px;">Error Email Notifications</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin-bottom:28px;max-width:640px;">
				<?php wp_nonce_field( 'gbrr_save_notify', 'gbrr_notify_nonce' ); ?>
				<input type="hidden" name="action" value="gbrr_save_notify">
				<p>
					<label>
						<input type="checkbox" name="gbrr_notify_enabled" value="1" <?php checked( get_option( GBRR_SYNC_NOTIFY_ENABLED_OPTION, false ) ); ?>>
						Email me when a sync run has one or more errors
					</label>
				</p>
				<p>
					<label for="gbrr_notify_email" style="display:block;font-weight:600;margin-bottom:4px;">Notify email address(es)</label>
					<input type="text" id="gbrr_notify_email" name="gbrr_notify_email" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" value="<?php echo esc_attr( get_option( GBRR_SYNC_NOTIFY_EMAIL_OPTION, '' ) ); ?>">
					<br><span style="color:#666;font-size:12px;">Comma-separate multiple addresses. One email is sent per sync run (not per error) summarizing every location that failed.</span>
				</p>
				<p>
					<button class="button button-primary" type="submit">Save Notification Settings</button>
				</p>
			</form>

			<!-- Locations table -->
			<h2 style="margin-top:24px;">Saved Locations</h2>
			<?php if ( empty( $places ) ) : ?>
				<p>No locations found. Configure locations in the Reviews and Rating plugin first.</p>
			<?php else : ?>
			<table class="widefat striped" style="margin-bottom:28px;">
				<thead>
					<tr>
						<th>Name / Address</th>
						<th>Place ID</th>
						<th>Rating</th>
						<th>Reviews</th>
						<th>Last Synced</th>
						<th>Status</th>
						<th>Default</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $places as $p ) :
						$pid       = $p['place_id'] ?? '';
						$is_active = ( $pid === $active_id );
						$age_h     = isset( $p['time'] ) ? ( time() - $p['time'] ) / 3600 : null;
						$ok        = ! empty( $p['status'] );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $p['name'] ?? '—' ); ?></strong><br>
							<small style="color:#666;"><?php echo esc_html( $p['vicinity'] ?? $p['formatted_address'] ?? '' ); ?></small>
						</td>
						<td><code style="font-size:11px;"><?php echo esc_html( $pid ); ?></code></td>
						<td><?php echo isset( $p['rating'] ) && $p['rating'] !== null ? esc_html( $p['rating'] ) . ' &#9733;' : '&mdash;'; ?></td>
						<td><?php echo isset( $p['rating_count'] ) && $p['rating_count'] !== null ? esc_html( number_format( $p['rating_count'] ) ) : '&mdash;'; ?></td>
						<td>
							<?php if ( $age_h !== null ) :
								echo $age_h < 1
									? esc_html( round( $age_h * 60 ) ) . 'm ago'
									: ( $age_h < 24
										? esc_html( round( $age_h, 1 ) ) . 'h ago'
										: esc_html( round( $age_h / 24, 1 ) ) . 'd ago' );
							else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $ok ) : ?>
								<span style="color:#00a32a;">&#10004; OK</span>
							<?php else : ?>
								<span style="color:#d63638;">&#10008; FAIL</span>
							<?php endif; ?>
						</td>
						<td><?php echo $is_active ? '<span style="color:#00a32a;">&#10004;</span>' : ''; ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<!-- Log -->
			<h2>Sync Log <small style="font-weight:normal;font-size:13px;">(most recent 50 entries)</small></h2>
			<?php
			$tail = array_slice( array_reverse( $log ), 0, 50 );
			if ( empty( $tail ) ) : ?>
				<p>No log entries yet. Run a sync to see activity here.</p>
			<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th style="width:170px;">Time (UTC)</th>
						<th style="width:90px;">Type</th>
						<th>Message</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$colors = array(
						'error'    => '#d63638',
						'skip'     => '#996600',
						'sync'     => '#00a32a',
						'complete' => '#0073aa',
						'manual'   => '#0073aa',
						'conntest' => '#8B5CF6',
						'repair'   => '#8B5CF6',
					);
					foreach ( $tail as $entry ) :
						$color = $colors[ $entry['type'] ] ?? '#555';
					?>
					<tr>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $entry['time'] ) ); ?></td>
						<td><span style="color:<?php echo esc_attr( $color ); ?>;font-weight:600;"><?php echo esc_html( strtoupper( $entry['type'] ) ); ?></span></td>
						<td><?php echo esc_html( $entry['message'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// POST handlers
	// -----------------------------------------------------------------------

	public function handle_manual_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Access denied.' );
		}
		check_admin_referer( 'gbrr_sync_now', 'gbrr_nonce' );

		$this->log( 'manual', 'Manual sync triggered by admin user ' . get_current_user_id() );
		$results = $this->run_sync();

		$ok   = count( array_filter( $results, function( $r ) { return $r['ok']; } ) );
		$fail = count( $results ) - $ok;

		$msg = sprintf(
			'Sync complete: %d location%s refreshed%s.',
			$ok,
			$ok === 1 ? '' : 's',
			$fail > 0 ? ", {$fail} failed (see log below)" : ''
		);
		set_transient( 'gbrr_sync_flash', $msg, 60 );

		wp_safe_redirect( admin_url( 'options-general.php?page=gbrr-location-sync' ) );
		exit;
	}

	public function handle_conn_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Access denied.' );
		}
		check_admin_referer( 'gbrr_conn_test', 'gbrr_conn_nonce' );

		$result = $this->run_conn_test();

		// Log a summary line
		$this->log( 'conntest', sprintf(
			'Connection test — Places API: %s%s%s',
			$result['api_status'] ?? 'unknown',
			! empty( $result['place_name'] ) ? ' (' . $result['place_name'] . ')' : '',
			! empty( $result['api_error'] )  ? ' — ' . $result['api_error'] : ''
		) );

		set_transient( 'gbrr_conn_result', $result, 60 );

		wp_safe_redirect( admin_url( 'options-general.php?page=gbrr-location-sync' ) );
		exit;
	}

	public function handle_save_notify() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Access denied.' );
		}
		check_admin_referer( 'gbrr_save_notify', 'gbrr_notify_nonce' );

		$enabled = ! empty( $_POST['gbrr_notify_enabled'] );
		$emails  = isset( $_POST['gbrr_notify_email'] ) ? sanitize_text_field( wp_unslash( $_POST['gbrr_notify_email'] ) ) : '';

		// The field's placeholder shows the site admin email as a hint;
		// make that real instead of just cosmetic — a blank field falls
		// back to it rather than failing validation.
		if ( '' === trim( $emails ) ) {
			$emails = get_option( 'admin_email' );
		}

		$valid_emails = array_filter( array_map( 'trim', explode( ',', $emails ) ), 'is_email' );

		if ( $enabled && empty( $valid_emails ) ) {
			set_transient( 'gbrr_sync_flash', 'Error notifications were not enabled: please enter at least one valid email address.', 60 );
		} else {
			update_option( GBRR_SYNC_NOTIFY_ENABLED_OPTION, $enabled, 'yes' );
			update_option( GBRR_SYNC_NOTIFY_EMAIL_OPTION, implode( ', ', $valid_emails ), 'yes' );
			set_transient( 'gbrr_sync_flash', 'Notification settings saved.', 60 );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=gbrr-location-sync' ) );
		exit;
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function redact_key( $message ) {
		if ( ! is_string( $message ) ) {
			return $message;
		}
		return preg_replace( '/([?&]key=)[^&\s]+/i', '$1REDACTED', $message );
	}

	private function parent_plugin_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'g-business-reviews-rating/g-business-reviews-rating.php' );
	}
}
