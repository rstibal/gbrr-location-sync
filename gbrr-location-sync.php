<?php
/**
 * Plugin Name: GBRR Location Sync
 * Plugin URI:  https://robstibal.com
 * Description: Automatically syncs all saved locations in Reviews and Rating – Google Reviews (g-business-reviews-rating) daily via WP-Cron, and fixes the duplicate-default-location bug that occurs after any manual location cycle.
 * Version:     1.7.0
 * Author:      Rob Stibal
 * Author URI:  https://robstibal.com
 * License:     GPLv2 or later
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GBRR_SYNC_VERSION',     '1.7.0' );
define( 'GBRR_SYNC_OPTION_BASE', 'google_business_reviews_rating' );
define( 'GBRR_SYNC_CRON_HOOK',   'gbrr_location_sync_run' );
define( 'GBRR_SYNC_LOG_OPTION',  'gbrr_location_sync_log' );
define( 'GBRR_SYNC_DB_OPTION',   'gbrr_sync_db_version' );
define( 'GBRR_SYNC_NOTIFY_ENABLED_OPTION', 'gbrr_location_sync_notify_enabled' );
define( 'GBRR_SYNC_NOTIFY_EMAIL_OPTION',   'gbrr_location_sync_notify_email' );
define( 'GBRR_SYNC_BACKUPS_OPTION',        'gbrr_location_sync_backups' );
define( 'GBRR_SYNC_KNOWN_COUNT_OPTION',    'gbrr_location_sync_reviews_known' );
define( 'GBRR_SYNC_BACKUPS_KEEP',          7 );
define( 'GBRR_SYNC_HIDE_PARENT_OPTION',    'gbrr_location_sync_hide_parent_reviews' );
define( 'GBRR_SYNC_REVIEWS_PER_PAGE',      100 );

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
		add_action( 'admin_post_gbrr_save_display', array( $this, 'handle_save_display' ) );
		add_action( 'admin_init',                  array( $this, 'maybe_hide_parent_reviews' ) );
		add_action( 'admin_post_gbrr_backup_now',  array( $this, 'handle_backup_now' ) );
		add_action( 'admin_post_gbrr_restore_backup', array( $this, 'handle_restore_backup' ) );
		// Checks every save of the review store, the parent plugin's included.
		add_filter( 'pre_update_option_' . GBRR_SYNC_OPTION_BASE . '_reviews', array( $this, 'guard_reviews_save' ), 10, 2 );
	}

	/** True while restore_backup() saves, which only ever adds reviews. */
	private $guard_off = false;

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

	/**
	 * Returns the per-location results keyed by place ID, or false when
	 * the run was skipped (no API key or no saved locations).
	 */
	public function run_sync() {
		// Daily copy of the review store, taken before anything is written.
		$this->maybe_backup_reviews();

		$api_key = get_option( GBRR_SYNC_OPTION_BASE . '_api_key' );
		$places  = get_option( GBRR_SYNC_OPTION_BASE . '_places', array() );

		if ( empty( $api_key ) ) {
			$this->log( 'skip', 'No API key configured in the parent plugin; aborting sync.' );
			$this->notify_skipped( 'No Google Places API key is configured in the Reviews and Rating plugin, so no locations could be synced.' );
			return false;
		}

		if ( ! is_array( $places ) || empty( $places ) ) {
			$this->log( 'skip', 'No saved locations; aborting sync.' );
			return false;
		}

		// Each location can take up to the 15s request timeout; don't let
		// PHP's default 30s limit kill a multi-location run part-way.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 + 20 * count( $places ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// Parent 6.x can use Places API (New), which takes different field
		// names; mirror its own fallback to the defaults when the stored
		// list is missing or still holds legacy names (dashboard/sync.php).
		$api_version = $this->api_version();
		$fields      = get_option( GBRR_SYNC_OPTION_BASE . '_retrieval_fields', null );
		if ( $api_version && ( ! is_array( $fields ) || in_array( 'name', $fields, true ) ) ) {
			$fields = array(
				'displayName',
				'formattedAddress',
				'googleMapsUri',
				'iconMaskBaseUri',
				'id',
				'shortFormattedAddress',
				'rating',
				'reviews',
				'userRatingCount',
			);
		} elseif ( ! is_array( $fields ) ) {
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

		$results = array();

		foreach ( $places as $place ) {
			$pid = isset( $place['place_id'] ) ? $place['place_id'] : null;
			if ( ! $pid ) {
				continue;
			}

			$response = $this->fetch_place( $pid, $api_key, $fields, $language, $translate, $api_version );

			if ( is_wp_error( $response ) ) {
				$safe_error = $this->redact_key( $response->get_error_message() );
				$this->persist_place( $pid, array( 'status' => false ) );
				$results[ $pid ] = array( 'ok' => false, 'error' => $safe_error );
				$this->log( 'error', sprintf( 'WP_Error for %s (%s): %s', $pid, $place['vicinity'] ?? '', $safe_error ) );
				continue;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			// Places API (New) replies have no `status`; errors come back
			// as { "error": { "status", "message" } }.
			if ( ! is_array( $data ) || empty( $data ) || ( ! $api_version && ! isset( $data['status'] ) ) ) {
				$this->persist_place( $pid, array( 'status' => false ) );
				$results[ $pid ] = array( 'ok' => false, 'error' => 'Empty or malformed API response' );
				$this->log( 'error', sprintf( 'Malformed response for %s (%s)', $pid, $place['vicinity'] ?? '' ) );
				continue;
			}

			if ( $api_version ) {
				$status = isset( $data['error'] ) ? ( $data['error']['status'] ?? (string) ( $data['error']['code'] ?? 'ERROR' ) ) : 'OK';
			} else {
				$status = $data['status'];
			}

			if ( 'OK' !== $status ) {
				$this->persist_place( $pid, array( 'status' => false ) );
				$error_msg = $api_version ? ( $data['error']['message'] ?? $status ) : ( $data['error_message'] ?? $status );
				$error_msg = $this->redact_key( $error_msg );
				$results[ $pid ] = array( 'ok' => false, 'error' => $error_msg );
				$this->log( 'error', sprintf( 'API status "%s" for %s (%s): %s', $status, $pid, $place['vicinity'] ?? '', $error_msg ) );
				continue;
			}

			// Build the place snapshot update. Fields fall back to the
			// stored value when absent, except rating / rating_count:
			// when they were requested, absence means "no ratings yet".
			$r      = $api_version ? $this->legacy_place_fields( $data ) : $data['result'];
			$update = array(
				'time'   => time(),
				'status' => isset( $r['name'] ),
			);
			foreach ( array( 'name', 'formatted_address', 'vicinity', 'icon' ) as $key ) {
				if ( isset( $r[ $key ] ) ) {
					$update[ $key ] = $r[ $key ];
				}
			}
			if ( in_array( 'rating', $fields, true ) ) {
				$update['rating'] = $r['rating'] ?? null;
			}
			if ( in_array( $api_version ? 'userRatingCount' : 'user_ratings_total', $fields, true ) ) {
				$update['rating_count'] = $r['user_ratings_total'] ?? null;
			}

			$new_reviews = ( isset( $r['reviews'] ) && is_array( $r['reviews'] ) ) ? $r['reviews'] : array();
			if ( $api_version ) {
				$new_reviews = array_map( function( $review ) use ( $language, $translate ) {
					return $this->legacy_review_fields( $review, $language, $translate );
				}, array_filter( $new_reviews, 'is_array' ) );
			}

			// Saved per location (not once at the end) so a run cut short
			// still keeps the locations it finished.
			$reviews_error = $this->persist_place( $pid, $update, $new_reviews, $data );
			if ( null !== $reviews_error ) {
				$error_msg       = 'Reviews not saved: ' . $reviews_error . '. Stored reviews were left as they were.';
				$results[ $pid ] = array( 'ok' => false, 'error' => $error_msg );
				$this->log( 'error', sprintf( '%s (%s): %s', $pid, $place['vicinity'] ?? '', $error_msg ) );
				continue;
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

		// Fix the default flag: exactly one entry should be true, matching
		// the active location as it stands now (it may have changed mid-run).
		$active_id = $this->fresh_option( GBRR_SYNC_OPTION_BASE . '_place_id' );
		$places    = $this->fresh_option( GBRR_SYNC_OPTION_BASE . '_places', array() );
		if ( is_array( $places ) ) {
			foreach ( $places as $idx => $place ) {
				if ( is_array( $place ) ) {
					$places[ $idx ]['default'] = ( ( $place['place_id'] ?? null ) === $active_id );
				}
			}
			update_option( GBRR_SYNC_OPTION_BASE . '_places', $places, 'yes' );
		}

		$ok_count = count( array_filter( $results, function( $r ) { return $r['ok']; } ) );
		$this->log( 'complete', sprintf(
			'Sync complete. %d/%d locations updated successfully.',
			$ok_count,
			count( $results )
		) );

		$this->maybe_notify_errors( $results, is_array( $places ) ? $places : array() );

		return $results;
	}

	/**
	 * Saves one location's sync outcome.
	 *
	 * The parent plugin's own hourly sync (or an admin editing locations)
	 * can write `_places` / `_reviews` while our HTTP requests are in
	 * flight, so each save re-reads the current options and merges only
	 * this location's changes into them, rather than writing back a copy
	 * read at the start of the run. A location removed mid-run stays
	 * removed.
	 */
	private function persist_place( $pid, $update, $new_reviews = array(), $data = null ) {
		$places = $this->fresh_option( GBRR_SYNC_OPTION_BASE . '_places', array() );
		$found  = false;
		if ( is_array( $places ) ) {
			foreach ( $places as $idx => $place ) {
				if ( is_array( $place ) && ( $place['place_id'] ?? null ) === $pid ) {
					$places[ $idx ] = array_merge( $place, $update );
					$found          = true;
				}
			}
		}
		if ( ! $found ) {
			return; // Location was removed mid-run; don't write anything for it.
		}
		update_option( GBRR_SYNC_OPTION_BASE . '_places', $places, 'yes' );

		$reviews_error = null;
		if ( ! empty( $new_reviews ) ) {
			// Never build on a store we couldn't read: saving "nothing + the
			// few reviews Google returned" over it erases every stored review.
			$store = $this->read_stored_reviews();
			if ( isset( $store['error'] ) ) {
				$reviews_error = $store['error'];
			} else {
				$reviews_all   = $this->merge_reviews( $store['reviews'], $new_reviews, $pid );
				$reviews_error = $this->save_reviews_store( $reviews_all, $store['raw'] );
			}
		}

		// Refresh _result for the active/default location
		if ( null !== $data && $pid === $this->fresh_option( GBRR_SYNC_OPTION_BASE . '_place_id' ) ) {
			update_option( GBRR_SYNC_OPTION_BASE . '_result',       $data, 'no' );
			update_option( GBRR_SYNC_OPTION_BASE . '_result_valid', $data, 'no' );
			wp_cache_delete( 'result',       GBRR_SYNC_OPTION_BASE );
			wp_cache_delete( 'result_valid', GBRR_SYNC_OPTION_BASE );
		}

		return $reviews_error;
	}

	/**
	 * Reads the parent's review store straight from the database,
	 * bypassing every cache.
	 *
	 * get_option() returns its default both when the option doesn't exist
	 * and when the stored value can't be read (a failed query, or
	 * serialized data that no longer unserializes). Only the first means
	 * "no reviews yet"; treating the second the same way and saving would
	 * erase the whole store. Returns ['reviews' => array, 'raw' => ?string]
	 * or ['error' => string].
	 */
	private function read_stored_reviews() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			GBRR_SYNC_OPTION_BASE . '_reviews'
		) );
		if ( null === $raw ) {
			if ( '' !== (string) $wpdb->last_error ) {
				return array( 'error' => 'database error reading the stored reviews: ' . $wpdb->last_error );
			}
			return array( 'reviews' => array(), 'raw' => null ); // No store yet.
		}
		$value = maybe_unserialize( $raw );
		if ( is_array( $value ) ) {
			return array( 'reviews' => $value, 'raw' => $raw );
		}
		// The parent stores an emptied store as '' (NULL) or an empty value.
		if ( in_array( trim( (string) $raw ), array( '', 'N;', 'b:0;', 's:0:"";' ), true ) ) {
			return array( 'reviews' => array(), 'raw' => $raw );
		}
		return array( 'error' => sprintf( 'the stored reviews (%s bytes) could not be read', number_format( strlen( $raw ) ) ) );
	}

	/**
	 * Saves the whole review store and checks it reads back. Large stores
	 * can save in a form that doesn't read back (the parent's own import
	 * checks for this too); if so, the previous bytes are put back.
	 * Returns null on success or an error string.
	 */
	private function save_reviews_store( $reviews, $previous_raw ) {
		update_option( GBRR_SYNC_OPTION_BASE . '_reviews', $reviews, 'no' );
		$check = $this->read_stored_reviews();
		if ( isset( $check['error'] ) ) {
			if ( null !== $previous_raw ) {
				global $wpdb;
				$wpdb->update( $wpdb->options, array( 'option_value' => $previous_raw ), array( 'option_name' => GBRR_SYNC_OPTION_BASE . '_reviews' ) );
			}
			wp_cache_delete( GBRR_SYNC_OPTION_BASE . '_reviews', 'options' );
			return 'the saved reviews did not read back (' . $check['error'] . '), so the previous copy was put back';
		}
		wp_cache_delete( 'reviews',          GBRR_SYNC_OPTION_BASE );
		wp_cache_delete( 'reviews_shuffled', GBRR_SYNC_OPTION_BASE );
		delete_transient( GBRR_SYNC_OPTION_BASE . '_reviews_shuffled' );
		return null;
	}

	/** Number of review rows per place ID. */
	private function count_by_place( $reviews ) {
		$counts = array();
		foreach ( $reviews as $row ) {
			if ( is_array( $row ) && isset( $row['place_id'] ) && is_string( $row['place_id'] ) ) {
				$counts[ $row['place_id'] ] = ( $counts[ $row['place_id'] ] ?? 0 ) + 1;
			}
		}
		return $counts;
	}

	/** Place IDs of the saved locations. */
	private function saved_place_ids() {
		$places = $this->fresh_option( GBRR_SYNC_OPTION_BASE . '_places', array() );
		$ids    = array();
		foreach ( is_array( $places ) ? $places : array() as $place ) {
			if ( is_array( $place ) && isset( $place['place_id'] ) && is_string( $place['place_id'] ) ) {
				$ids[] = $place['place_id'];
			}
		}
		return $ids;
	}

	// -----------------------------------------------------------------------
	// Save guard for the review store
	// -----------------------------------------------------------------------

	/**
	 * pre_update_option filter on the parent's review store. Runs for every
	 * plugin's save, the parent's hourly refresh included. Returning
	 * $old_value makes WordPress skip the save.
	 *
	 * Blocks a save when the stored reviews can't be read and the new value
	 * is much smaller than the last known store, or when it would remove
	 * more than 5 reviews from saved locations. Removing every review of
	 * one non-active location is allowed: that's how the parent deletes a
	 * location (it saves the reviews before it removes the location).
	 */
	public function guard_reviews_save( $value, $old_value ) {
		if ( $this->guard_off ) {
			return $value;
		}
		$new   = is_array( $value ) ? $value : array();
		$store = $this->read_stored_reviews();

		if ( isset( $store['error'] ) ) {
			$known = (int) get_option( GBRR_SYNC_KNOWN_COUNT_OPTION, 0 );
			if ( count( $new ) + 5 >= $known ) {
				return $value; // e.g. the parent putting back its own copy after a failed import.
			}
			return $this->block_reviews_save( sprintf(
				"the stored reviews can't be read right now (%s), and the new value has only %s of the %s reviews last known to be stored",
				$store['error'],
				number_format( count( $new ) ),
				number_format( $known )
			), $old_value );
		}

		$stored = $store['reviews'];
		if ( empty( $stored ) ) {
			$this->remember_review_count( count( $new ) );
			return $value;
		}

		$before = $this->count_by_place( $stored );
		$after  = $this->count_by_place( $new );
		$lost   = array();
		foreach ( $this->saved_place_ids() as $pid ) {
			$drop = ( $before[ $pid ] ?? 0 ) - ( $after[ $pid ] ?? 0 );
			if ( $drop > 0 ) {
				$lost[ $pid ] = $drop;
			}
		}
		$total = array_sum( $lost );

		$allowed = ( $total <= 5 );
		if ( ! $allowed && 1 === count( $lost ) ) {
			$pid     = key( $lost );
			$allowed = ( $pid !== $this->fresh_option( GBRR_SYNC_OPTION_BASE . '_place_id' ) && 0 === ( $after[ $pid ] ?? 0 ) );
		}
		if ( $allowed ) {
			$this->remember_review_count( count( $new ) );
			return $value;
		}

		$parts = array();
		foreach ( $lost as $pid => $drop ) {
			$parts[] = sprintf( '%s: %s of %s', $pid, number_format( $drop ), number_format( $before[ $pid ] ) );
		}
		return $this->block_reviews_save( sprintf(
			'it would remove %s stored review(s) from %d saved location(s) (%s)',
			number_format( $total ),
			count( $lost ),
			implode( '; ', $parts )
		), $old_value );
	}

	/** Logs a blocked save (once an hour per message) and emails (once every 6 hours). */
	private function block_reviews_save( $reason, $old_value ) {
		$message = 'Blocked a save of the review store: ' . $reason . '. The stored reviews were left as they were.';
		$log_key = 'gbrr_guard_' . md5( $message );
		if ( ! get_transient( $log_key ) ) {
			set_transient( $log_key, 1, HOUR_IN_SECONDS );
			$this->log( 'guard', $message );
		}
		$to = $this->get_notify_recipients();
		if ( ! empty( $to ) && ! get_transient( 'gbrr_guard_mailed' ) ) {
			set_transient( 'gbrr_guard_mailed', 1, 6 * HOUR_IN_SECONDS );
			wp_mail(
				$to,
				sprintf( '[%s] GBRR Location Sync: blocked a save that would have lost reviews', get_bloginfo( 'name' ) ),
				sprintf(
					"GBRR Location Sync stopped a save of the Reviews and Rating review store on %s:\n\n%s\n\nNothing was lost. If a plugin keeps trying, it's blocked again each time; further emails are held for 6 hours. See Settings \xE2\x86\x92 GBRR Sync on %s for the log and the review backups.",
					gmdate( 'Y-m-d H:i:s' ) . ' UTC',
					$message,
					home_url()
				)
			);
		}
		return $old_value;
	}

	private function remember_review_count( $count ) {
		if ( (int) get_option( GBRR_SYNC_KNOWN_COUNT_OPTION, 0 ) !== $count ) {
			update_option( GBRR_SYNC_KNOWN_COUNT_OPTION, $count, 'no' );
		}
	}

	// -----------------------------------------------------------------------
	// Review backups
	// -----------------------------------------------------------------------

	/** The backup folder in uploads, created with web access blocked, or null. */
	private function backup_dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}
		$dir = rtrim( $uploads['basedir'], '/\\' ) . '/gbrr-location-sync';
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		if ( ! is_file( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		return $dir;
	}

	/** The backup list, newest first. */
	private function get_backups() {
		$index = get_option( GBRR_SYNC_BACKUPS_OPTION, array() );
		return is_array( $index ) ? array_values( array_filter( $index, 'is_array' ) ) : array();
	}

	/**
	 * Saves a gzipped copy of the stored review bytes, at most once every
	 * 20 hours unless $force. Never backs up a store it can't read, or an
	 * empty one. Keeps the newest GBRR_SYNC_BACKUPS_KEEP plus the one with
	 * the most reviews. Returns the new entry, an error string, or null
	 * when there was nothing to do.
	 */
	public function maybe_backup_reviews( $force = false, $now = null ) {
		$now   = ( null === $now ) ? time() : (int) $now;
		$index = $this->get_backups();
		if ( ! $force && ! empty( $index ) && $now - (int) ( $index[0]['time'] ?? 0 ) < 20 * HOUR_IN_SECONDS ) {
			return null;
		}

		$store = $this->read_stored_reviews();
		if ( isset( $store['error'] ) ) {
			$error = 'Backup skipped: ' . $store['error'] . '.';
			$this->log( 'error', $error );
			return $error;
		}
		if ( empty( $store['reviews'] ) || null === $store['raw'] ) {
			return null;
		}
		$this->remember_review_count( count( $store['reviews'] ) );

		$dir  = $this->backup_dir();
		$gz   = function_exists( 'gzencode' ) ? gzencode( $store['raw'], 6 ) : false;
		$file = 'reviews-' . gmdate( 'Ymd-His', $now ) . '-' . wp_generate_password( 16, false ) . '.gz';
		if ( null === $dir || false === $gz || false === @file_put_contents( $dir . '/' . $file, $gz ) ) {
			$error = 'Backup failed: could not write to wp-content/uploads/gbrr-location-sync.';
			$this->log( 'error', $error );
			return $error;
		}

		$entry = array(
			'file'   => $file,
			'time'   => $now,
			'count'  => count( $store['reviews'] ),
			'places' => $this->count_by_place( $store['reviews'] ),
			'bytes'  => strlen( $store['raw'] ),
		);
		$index[] = $entry;
		usort( $index, function ( $a, $b ) { return (int) $b['time'] <=> (int) $a['time']; } );

		$keep    = array_slice( $index, 0, GBRR_SYNC_BACKUPS_KEEP );
		$largest = null;
		foreach ( $index as $e ) {
			if ( null === $largest || (int) $e['count'] > (int) $largest['count'] ) {
				$largest = $e;
			}
		}
		if ( $largest && ! in_array( $largest['file'], array_column( $keep, 'file' ), true ) ) {
			$keep[] = $largest;
		}
		foreach ( $index as $e ) {
			if ( ! in_array( $e['file'], array_column( $keep, 'file' ), true ) ) {
				@unlink( $dir . '/' . basename( $e['file'] ) );
			}
		}
		update_option( GBRR_SYNC_BACKUPS_OPTION, $keep, 'no' );

		$this->log( 'backup', sprintf( 'Backed up %s stored review(s) (%s KB).', number_format( $entry['count'] ), number_format( $entry['bytes'] / 1024 ) ) );
		return $entry;
	}

	/**
	 * Adds back the reviews in a backup that are missing from the store.
	 * Never removes or changes a stored review, and skips reviews of
	 * locations that are no longer saved. A restored review whose ID is
	 * taken gets a new one. Works when the store can't be read at all.
	 */
	public function restore_backup( $file ) {
		$entry = null;
		foreach ( $this->get_backups() as $e ) {
			if ( ( $e['file'] ?? null ) === $file ) {
				$entry = $e;
				break;
			}
		}
		$dir = $this->backup_dir();
		if ( null === $entry || null === $dir || ! is_file( $dir . '/' . basename( $file ) ) ) {
			return array( 'ok' => false, 'message' => 'That backup was not found.' );
		}
		$raw    = @file_get_contents( $dir . '/' . basename( $file ) );
		$raw    = ( false !== $raw && function_exists( 'gzdecode' ) ) ? @gzdecode( $raw ) : false;
		$backup = ( false !== $raw ) ? maybe_unserialize( $raw ) : false;
		if ( ! is_array( $backup ) ) {
			return array( 'ok' => false, 'message' => 'That backup could not be read.' );
		}

		$store = $this->read_stored_reviews();
		if ( isset( $store['error'] ) ) {
			$reviews      = array(); // Unreadable: nothing in it can be kept anyway.
			$previous_raw = null;
		} else {
			$reviews      = $store['reviews'];
			$previous_raw = $store['raw'];
			if ( ! empty( $reviews ) ) {
				$this->maybe_backup_reviews( true ); // So this restore can be undone.
			}
		}

		$places = $this->saved_place_ids();
		$used   = array();
		$max_id = 0;
		foreach ( $reviews as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && is_numeric( $row['id'] ) ) {
				$used[ (int) $row['id'] ] = true;
				$max_id = max( $max_id, (int) $row['id'] );
			}
		}

		$added = 0;
		foreach ( $backup as $key => $row ) {
			$pid = is_array( $row ) ? ( $row['place_id'] ?? null ) : null;
			if ( ! is_string( $pid ) || ! in_array( $pid, $places, true ) || isset( $reviews[ $key ] ) ) {
				continue;
			}
			if ( isset( $row['author_id'] ) && isset( $reviews[ $pid . '_' . $row['author_id'] ] ) ) {
				continue; // Same review, stored under the parent's newer key.
			}
			$id = ( isset( $row['id'] ) && is_numeric( $row['id'] ) ) ? (int) $row['id'] : 0;
			if ( $id < 1 || isset( $used[ $id ] ) ) {
				$id = $max_id + 1;
			}
			$row['id']       = $id;
			$used[ $id ]     = true;
			$max_id          = max( $max_id, $id );
			$reviews[ $key ] = $row;
			$added++;
		}

		$when = gmdate( 'Y-m-d H:i', (int) $entry['time'] ) . ' UTC';
		if ( 0 === $added && ! isset( $store['error'] ) ) {
			return array( 'ok' => true, 'added' => 0, 'message' => sprintf( 'Nothing to restore: every review in the backup from %s is already stored.', $when ) );
		}

		$this->guard_off = true;
		$error           = $this->save_reviews_store( $reviews, $previous_raw );
		$this->guard_off = false;
		if ( null !== $error ) {
			$this->log( 'error', 'Restore failed: ' . $error . '.' );
			return array( 'ok' => false, 'message' => 'Restore failed: ' . $error . '.' );
		}
		$this->remember_review_count( count( $reviews ) );

		$message = sprintf( 'Restored %s review(s) from the backup of %s. The store now has %s.', number_format( $added ), $when, number_format( count( $reviews ) ) );
		$this->log( 'restore', $message );
		return array( 'ok' => true, 'added' => $added, 'message' => $message );
	}

	/**
	 * get_option() that bypasses this request's option cache, so writes
	 * made by other requests since this one started are seen.
	 */
	private function fresh_option( $name, $default = false ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return get_option( $name, $default );
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
		$failures = array_filter( $results, function( $r ) { return ! $r['ok']; } );
		if ( empty( $failures ) ) {
			return;
		}

		$to = $this->get_notify_recipients();
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
	 * Emails the configured address(es) when a run could not sync any
	 * location at all (e.g. the API key is missing), if notifications
	 * are enabled.
	 */
	private function notify_skipped( $reason ) {
		$to = $this->get_notify_recipients();
		if ( empty( $to ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( '[%s] GBRR Location Sync: sync skipped', $site_name );
		$body      = sprintf(
			"GBRR Location Sync ran on %s but did not sync any locations:\n\n%s\n\nSee Settings \xE2\x86\x92 GBRR Sync on %s for the full log.",
			gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			$reason,
			home_url()
		);

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Returns the notification recipients, or an empty array if
	 * notifications are disabled or no valid address is configured.
	 */
	private function get_notify_recipients() {
		if ( ! get_option( GBRR_SYNC_NOTIFY_ENABLED_OPTION, false ) ) {
			return array();
		}
		return $this->get_notify_emails();
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

		$store = $this->read_stored_reviews();
		if ( isset( $store['error'] ) ) {
			// Try again on a later admin page load rather than write over
			// it. Log it once, not on every page load.
			$message = sprintf( 'v%s repair skipped: %s. Stored reviews were left as they were.', GBRR_SYNC_VERSION, $store['error'] );
			$log     = get_option( GBRR_SYNC_LOG_OPTION, array() );
			$last    = is_array( $log ) && ! empty( $log ) ? end( $log ) : null;
			if ( ( $last['message'] ?? null ) !== $message ) {
				$this->log( 'error', $message );
			}
			return;
		}
		$reviews = $store['reviews'];

		if ( ! empty( $reviews ) ) {
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

			// Before 1.4.0 this plugin keyed rows by time / rating / text
			// only, so on parent 6.x — which keys by place + author ID —
			// each review it re-fetched got a second row the parent
			// couldn't re-key. Drop those; the parent's row is kept.
			$duplicates = 0;
			if ( $this->parent_keys_by_author() ) {
				foreach ( $reviews as $key => $row ) {
					if ( ! is_array( $row ) || ! is_string( $row['place_id'] ?? null ) || '' === $row['place_id'] ) {
						continue;
					}
					$author_id = $row['author_id'] ?? $this->review_author_id( $row['author_url'] ?? null );
					if ( null === $author_id ) {
						continue;
					}
					$author_key = $row['place_id'] . '_' . $author_id;
					if ( $author_key !== $key && isset( $reviews[ $author_key ] ) && is_array( $reviews[ $author_key ] ) ) {
						unset( $reviews[ $key ] );
						$duplicates++;
					}
				}
			}

			if ( $repaired > 0 || $duplicates > 0 ) {
				// Removing duplicates can drop many rows from one location,
				// which the save guard would block; they all exist twice.
				$this->guard_off = true;
				$save_error      = $this->save_reviews_store( $reviews, $store['raw'] );
				$this->guard_off = false;
				if ( null !== $save_error ) {
					$this->log( 'error', sprintf( 'v%s repair not saved: %s.', GBRR_SYNC_VERSION, $save_error ) );
					return; // Retried on a later admin page load.
				}
				if ( $duplicates > 0 ) {
					$this->log( 'repair', sprintf( 'v%s repair: removed %d duplicate review row(s).', GBRR_SYNC_VERSION, $duplicates ) );
				}
				if ( $repaired > 0 ) {
					$this->log( 'repair', sprintf( 'v%s repair: backfilled native row keys on %d legacy review(s).', GBRR_SYNC_VERSION, $repaired ) );
				}
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

		$api_version = $this->api_version();

		if ( $api_version ) {
			$resp = wp_remote_get( 'https://places.googleapis.com/v' . $api_version . '/places/' . rawurlencode( $place_id ), array(
				'timeout' => 10,
				'headers' => array(
					'X-Goog-Api-Key'   => $api_key,
					'X-Goog-FieldMask' => 'displayName,rating',
				),
			) );
		} else {
			$url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query( array(
				'placeid' => $place_id,
				'key'     => $api_key,
				'fields'  => 'name,rating',
			) );

			$resp = wp_remote_get( $url, array( 'timeout' => 10 ) );
		}

		if ( is_wp_error( $resp ) ) {
			$result['api_status'] = 'wp_error';
			$result['api_error']  = $this->redact_key( $resp->get_error_message() );
			return $result;
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $api_version ) {
			if ( ! is_array( $body ) ) {
				$result['api_status'] = 'UNKNOWN';
			} elseif ( isset( $body['error'] ) ) {
				$result['api_status'] = $body['error']['status'] ?? (string) ( $body['error']['code'] ?? 'ERROR' );
				$result['api_error']  = $this->redact_key( $body['error']['message'] ?? null );
			} else {
				$result['api_status'] = 'OK';
				$result['place_name'] = $body['displayName']['text'] ?? null;
			}
			return $result;
		}

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

	private function fetch_place( $place_id, $api_key, $fields, $language, $translate, $api_version = null ) {
		if ( $api_version ) {
			// Places API (New): key and field mask go in headers, and it
			// has no review sort or no-translations parameter.
			$url = 'https://places.googleapis.com/v' . $api_version . '/places/' . rawurlencode( $place_id );
			if ( ! empty( $language ) ) {
				$url .= '?languageCode=' . rawurlencode( $language );
			}

			return wp_remote_get( $url, array(
				'timeout' => 15,
				'headers' => array(
					'X-Goog-Api-Key'   => $api_key,
					'X-Goog-FieldMask' => implode( ',', $fields ),
				),
			) );
		}

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

	/**
	 * The Places API version the parent plugin uses: 1 for Places API
	 * (New), null for the legacy Place Details API. Parent 6.x stores it
	 * in `_api_version`; older parents never set it.
	 */
	private function api_version() {
		$version = get_option( GBRR_SYNC_OPTION_BASE . '_api_version', null );
		return ( is_numeric( $version ) && intval( $version ) >= 1 ) ? intval( $version ) : null;
	}

	/**
	 * Maps a Places API (New) place to the legacy `result` keys the rest
	 * of the sync reads, the same way the parent fills `_places` from it.
	 */
	private function legacy_place_fields( $data ) {
		$r = array();
		if ( isset( $data['displayName']['text'] ) ) {
			$r['name'] = $data['displayName']['text'];
		}
		if ( isset( $data['formattedAddress'] ) ) {
			$r['formatted_address'] = $data['formattedAddress'];
		}
		if ( isset( $data['shortFormattedAddress'] ) ) {
			$r['vicinity'] = $data['shortFormattedAddress'];
		}
		if ( ! empty( $data['iconMaskBaseUri'] ) ) {
			$r['icon'] = $data['iconMaskBaseUri'] . '.svg';
		}
		if ( isset( $data['rating'] ) ) {
			$r['rating'] = $data['rating'];
		}
		if ( isset( $data['userRatingCount'] ) ) {
			$r['user_ratings_total'] = $data['userRatingCount'];
		}
		if ( isset( $data['reviews'] ) ) {
			$r['reviews'] = $data['reviews'];
		}
		return $r;
	}

	/**
	 * Maps a Places API (New) review to the legacy review keys
	 * merge_reviews() reads, following the parent 6.x set_reviews():
	 * text is the original unless a translation into the site language
	 * was asked for, and `key_text` / `extra` carry what only the new
	 * API has (key input and the extra native row keys).
	 */
	private function legacy_review_fields( $review, $language, $translate ) {
		$original   = ( isset( $review['originalText'] ) && is_array( $review['originalText'] ) ) ? $review['originalText'] : array();
		$shown      = ( isset( $review['text'] ) && is_array( $review['text'] ) ) ? $review['text'] : array();
		$author     = ( isset( $review['authorAttribution'] ) && is_array( $review['authorAttribution'] ) ) ? $review['authorAttribution'] : array();
		$text       = is_string( $original['text'] ?? null ) ? $original['text'] : null;
		$lang       = is_string( $original['languageCode'] ?? null ) ? $original['languageCode'] : null;
		$translated = isset( $shown['languageCode'], $original['languageCode'] ) && $shown['languageCode'] !== $original['languageCode'];

		if ( ! empty( $language ) && $translate && $translated
			&& ( $shown['languageCode'] === $language || $shown['languageCode'] === preg_replace( '/^([a-z]{2}l?).*/i', '$1', $language ) ) ) {
			$text = is_string( $shown['text'] ?? null ) ? $shown['text'] : null;
			$lang = $language;
		}

		$original_text = ( null !== $text && is_string( $original['text'] ?? null ) && $original['text'] !== $text ) ? $original['text'] : null;
		$rating        = ( isset( $review['rating'] ) && is_numeric( $review['rating'] ) && $review['rating'] >= 1 && $review['rating'] <= 5 ) ? intval( $review['rating'] ) : null;
		$time          = isset( $review['publishTime'] ) ? ( strtotime( $review['publishTime'] ) ?: null ) : null;
		$visit_date    = null;
		if ( isset( $review['visitDate'] ) && is_array( $review['visitDate'] ) ) {
			$visit_date = array(
				'year'  => is_numeric( $review['visitDate']['year'] ?? null ) ? intval( $review['visitDate']['year'] ) : null,
				'month' => is_numeric( $review['visitDate']['month'] ?? null ) ? intval( $review['visitDate']['month'] ) : null,
			);
		}

		return array(
			'author_name'               => is_string( $author['displayName'] ?? null ) ? $author['displayName'] : null,
			'author_url'                => is_string( $author['uri'] ?? null ) ? $author['uri'] : null,
			'profile_photo_url'         => is_string( $author['photoUri'] ?? null ) ? $author['photoUri'] : null,
			'language'                  => ( null !== $text ) ? $lang : null,
			'rating'                    => $rating,
			'relative_time_description' => $review['relativePublishTimeDescription'] ?? '',
			'text'                      => $text,
			'time'                      => $time,
			'translated'                => $translated,
			'status'                    => null !== $rating,
			'key_text'                  => is_string( $original['text'] ?? null ) ? $original['text'] : '',
			'extra'                     => array(
				'reference'         => is_string( $review['name'] ?? null ) ? $review['name'] : null,
				'original_language' => is_string( $original['languageCode'] ?? null ) ? $original['languageCode'] : null,
				'original_text'     => $original_text,
				'review_url'        => is_string( $review['googleMapsUri'] ?? null ) ? $review['googleMapsUri'] : null,
				'visit_date'        => $visit_date,
			),
		);
	}

	/**
	 * Whether the parent keys review rows by place + author ID
	 * (`set_review_identity()`, added in parent 6.0) rather than only by
	 * time / rating / text.
	 */
	private function parent_keys_by_author() {
		return method_exists( 'google_business_reviews_rating', 'set_review_identity' );
	}

	/**
	 * The parent's author ID for a review: the long number in its Google
	 * Maps contributor URL, or null.
	 */
	private function review_author_id( $author_url ) {
		return ( is_string( $author_url ) && preg_match( '/(\d{20,120})/', $author_url, $m ) ) ? $m[1] : null;
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

		$by_author = $this->parent_keys_by_author();

		foreach ( $new_reviews as $review ) {
			if ( ! isset( $review['time'], $review['rating'], $review['author_name'] ) ) {
				continue;
			}
			// Must match the parent plugin's own key formula exactly, or
			// the review gets stored twice. Parent 6.x keys by place +
			// author ID (set_review_identity()) and re-keys every row to
			// that on load; without an author ID, and on 5.x, it's
			// time_rating_md5(author+text).
			$author_id    = $this->review_author_id( $review['author_url'] ?? null );
			$author_key   = ( null !== $author_id ) ? $place_id . '_' . $author_id : null;
			$fallback_key = $review['time'] . '_' . $review['rating'] . '_' . md5( $review['author_name'] . '_' . mb_substr( (string) ( $review['key_text'] ?? $review['text'] ?? '' ), 0, 100 ) );
			$key          = ( $by_author && null !== $author_key ) ? $author_key : $fallback_key;

			// Find the row under either key, moving it if it's under the
			// one the parent no longer uses.
			$row = array();
			foreach ( array_filter( array( $author_key, $fallback_key ) ) as $candidate ) {
				if ( isset( $existing[ $candidate ] ) && is_array( $existing[ $candidate ] ) ) {
					$row = $existing[ $candidate ];
					if ( $candidate !== $key ) {
						unset( $existing[ $candidate ] );
					}
					break;
				}
			}

			$next_id = null;
			if ( empty( $row ) ) {
				$max_id++;
				$next_id = $max_id;
			}

			$extra = ( isset( $review['extra'] ) && is_array( $review['extra'] ) ) ? $review['extra'] : array();
			if ( isset( $review['original_language'] ) ) {
				$extra['original_language'] = $review['original_language'];
			}
			if ( $by_author ) {
				$extra = array( 'author_id' => $author_id ) + $extra;
			}

			$existing[ $key ] = $this->normalize_review_row( $row, $review, $place_id, $next_id, $extra );
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
	 *
	 * $extra holds native keys only some parents / APIs have (author_id,
	 * and the Places API (New) reference, original_text, …).
	 */
	private function normalize_review_row( $row, $review, $place_id, $next_id = null, $extra = array() ) {
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
			'status'                    => $review['status'] ?? ( $row['status'] ?? true ),
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

		$normalized = array_merge( $normalized, $extra );

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

		if ( ! is_array( $places ) ) {
			$places = array();
		}
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		// Stored (retrieved) reviews, counted per location, plus the location picked
		// for the review list below the grid (?reviews=<place id>).
		$reviews = get_option( GBRR_SYNC_OPTION_BASE . '_reviews', array() );
		if ( ! is_array( $reviews ) ) {
			$reviews = array();
		}
		$review_counts = array();
		foreach ( $reviews as $review ) {
			if ( is_array( $review ) && isset( $review['place_id'] ) && is_string( $review['place_id'] ) ) {
				$review_counts[ $review['place_id'] ] = ( $review_counts[ $review['place_id'] ] ?? 0 ) + 1;
			}
		}
		$view_place = null;
		if ( isset( $_GET['reviews'] ) && is_string( $_GET['reviews'] ) ) {
			$requested = sanitize_text_field( wp_unslash( $_GET['reviews'] ) );
			foreach ( $places as $p ) {
				if ( isset( $p['place_id'] ) && $p['place_id'] === $requested ) {
					$view_place = $p;
					break;
				}
			}
		}
		$page_url = admin_url( 'options-general.php?page=gbrr-location-sync' );

		$flash      = get_transient( 'gbrr_sync_flash' );
		$conn_result = get_transient( 'gbrr_conn_result' );
		delete_transient( 'gbrr_sync_flash' );
		delete_transient( 'gbrr_conn_result' );
		?>
		<div class="wrap">
			<h1>GBRR Location Sync</h1>

			<?php if ( $flash ) :
				// Pre-1.3.2 flashes were plain strings.
				$flash_msg  = is_array( $flash ) ? ( $flash['message'] ?? '' ) : $flash;
				$flash_type = is_array( $flash ) && in_array( $flash['type'] ?? '', array( 'success', 'warning', 'error' ), true ) ? $flash['type'] : 'success';
			?>
				<div class="notice notice-<?php echo esc_attr( $flash_type ); ?> is-dismissible"><p><?php echo esc_html( $flash_msg ); ?></p></div>
			<?php endif; ?>

			<!-- Status / action bar -->
			<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin:16px 0;display:flex;align-items:center;gap:40px;flex-wrap:wrap;">
				<div>
					<strong>Next scheduled sync</strong><br>
					<?php if ( $next_run ) : ?>
						<?php echo esc_html( gmdate( 'Y-m-d H:i:s', $next_run ) . ' UTC' ); ?>
						<?php if ( $next_run > time() ) : ?>
							<span style="color:#666;margin-left:8px;">(<?php echo esc_html( human_time_diff( $next_run ) ); ?> from now)</span>
						<?php else : ?>
							<span style="color:#996600;margin-left:8px;">(overdue by <?php echo esc_html( human_time_diff( $next_run ) ); ?> &mdash; WP-Cron runs it on the next site visit)</span>
						<?php endif; ?>
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

			<!-- Parent plugin's Reviews tab -->
			<h2 style="margin-top:24px;">Reviews and Rating: Reviews Tab</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin-bottom:28px;max-width:640px;">
				<?php wp_nonce_field( 'gbrr_save_display', 'gbrr_display_nonce' ); ?>
				<input type="hidden" name="action" value="gbrr_save_display">
				<p>
					<label>
						<input type="checkbox" name="gbrr_hide_parent_reviews" value="1" <?php checked( get_option( GBRR_SYNC_HIDE_PARENT_OPTION, false ) ); ?>>
						Hide the list of every review on the Reviews tab
					</label>
					<br><span style="color:#666;font-size:12px;">For locations with thousands of reviews, that list can freeze the browser. This replaces it with a link to the paged list below. The rating, count and Review Order buttons stay. The parent plugin still builds the list on the server, so this speeds up the browser, not the server.</span>
				</p>
				<p>
					<button class="button button-primary" type="submit">Save</button>
				</p>
			</form>

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
						<th>Reviews on Google</th>
						<th>Retrieved</th>
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
							<?php $retrieved = $review_counts[ $pid ] ?? 0;
							if ( $retrieved > 0 ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'reviews', rawurlencode( $pid ), $page_url ) . '#gbrr-reviews' ); ?>"><?php echo esc_html( number_format( $retrieved ) . ( 1 === $retrieved ? ' review' : ' reviews' ) ); ?></a>
							<?php else : ?>
								0
							<?php endif; ?>
						</td>
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

			<?php if ( $view_place ) :
				$view_pid     = $view_place['place_id'];
				$view_reviews = array_filter( $reviews, function ( $review ) use ( $view_pid ) {
					return is_array( $review ) && ( $review['place_id'] ?? null ) === $view_pid;
				} );
				uasort( $view_reviews, function ( $a, $b ) {
					return (int) ( $b['time'] ?? 0 ) <=> (int) ( $a['time'] ?? 0 );
				} );

				// Optional search over author, review text and ID.
				$view_all_total = count( $view_reviews );
				$view_search    = ( isset( $_GET['rsearch'] ) && is_string( $_GET['rsearch'] ) ) ? trim( sanitize_text_field( wp_unslash( $_GET['rsearch'] ) ) ) : '';
				if ( '' !== $view_search ) {
					$view_reviews = array_filter( $view_reviews, function ( $review ) use ( $view_search ) {
						foreach ( array( $review['author_name'] ?? '', $review['text'] ?? '', $review['id'] ?? '' ) as $field ) {
							if ( is_scalar( $field ) && '' !== (string) $field && false !== ( function_exists( 'mb_stripos' ) ? mb_stripos( (string) $field, $view_search ) : stripos( (string) $field, $view_search ) ) ) {
								return true;
							}
						}
						return false;
					} );
				}

				// One page of the list at a time; a location can hold thousands.
				$view_total = count( $view_reviews );
				$view_pages = max( 1, (int) ceil( $view_total / GBRR_SYNC_REVIEWS_PER_PAGE ) );
				$view_page  = ( isset( $_GET['rpage'] ) && is_string( $_GET['rpage'] ) && ctype_digit( $_GET['rpage'] ) ) ? max( 1, (int) $_GET['rpage'] ) : 1;
				$view_page  = min( $view_page, $view_pages );
				$view_first = ( $view_page - 1 ) * GBRR_SYNC_REVIEWS_PER_PAGE;
				$view_reviews = array_slice( $view_reviews, $view_first, GBRR_SYNC_REVIEWS_PER_PAGE, true );
				$view_pager = '';
				if ( $view_pages > 1 ) {
					$view_pager = '<div class="tablenav-pages" style="margin:8px 0;float:none;">'
						. '<span style="margin-right:12px;">' . esc_html( sprintf( 'Showing %s–%s of %s', number_format( $view_first + 1 ), number_format( $view_first + count( $view_reviews ) ), number_format( $view_total ) ) ) . '</span>'
						. paginate_links( array(
							'base'         => $page_url . '&reviews=' . rawurlencode( $view_pid ) . ( '' !== $view_search ? '&rsearch=' . rawurlencode( $view_search ) : '' ) . '&rpage=%#%',
							'format'       => '',
							'current'      => $view_page,
							'total'        => $view_pages,
							'add_fragment' => '#gbrr-reviews',
							'prev_text'    => '&laquo; Previous',
							'next_text'    => 'Next &raquo;',
						) )
						. '</div>';
				}
			?>
			<!-- Retrieved reviews for one location -->
			<h2 id="gbrr-reviews">Retrieved Reviews: <?php echo esc_html( $view_place['name'] ?? $view_pid ); ?>
				<small style="font-weight:normal;font-size:13px;margin-left:8px;"><a href="<?php echo esc_url( $page_url ); ?>">Close</a></small>
			</h2>
			<?php $view_address = $view_place['vicinity'] ?? $view_place['formatted_address'] ?? '';
			if ( '' !== $view_address ) : ?>
				<p style="margin-top:-8px;color:#666;"><?php echo esc_html( $view_address ); ?></p>
			<?php endif; ?>
			<p class="description">The reviews stored for this location by Reviews and Rating, newest first, <?php echo esc_html( number_format( GBRR_SYNC_REVIEWS_PER_PAGE ) ); ?> to a page, with a search box. <?php if ( get_option( GBRR_SYNC_HIDE_PARENT_OPTION, false ) ) : ?>To hide or reorder reviews, turn the Reviews tab list back on above, then use the plugin's own <?php else : ?>Hide or reorder them on the plugin's own <?php endif; ?><a href="<?php echo esc_url( admin_url( 'options-general.php?page=google_business_reviews_rating_settings' ) ); ?>">Reviews tab</a>.</p>
			<?php if ( 0 === $view_all_total ) : ?>
				<p>No reviews are stored for this location yet.</p>
			<?php else : ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) . '#gbrr-reviews' ); ?>" style="margin:12px 0;">
				<input type="hidden" name="page" value="gbrr-location-sync">
				<input type="hidden" name="reviews" value="<?php echo esc_attr( $view_pid ); ?>">
				<input type="search" name="rsearch" value="<?php echo esc_attr( $view_search ); ?>" class="regular-text" placeholder="Search author, review text or ID" aria-label="Search reviews">
				<button class="button" type="submit">Search</button>
				<?php if ( '' !== $view_search ) : ?>
					<a class="button-link" style="margin-left:8px;" href="<?php echo esc_url( add_query_arg( 'reviews', rawurlencode( $view_pid ), $page_url ) . '#gbrr-reviews' ); ?>">Clear search</a>
					<span style="margin-left:8px;color:#666;"><?php echo esc_html( sprintf( '%s of %s reviews match', number_format( $view_total ), number_format( $view_all_total ) ) ); ?></span>
				<?php endif; ?>
			</form>
			<?php if ( 0 === $view_total ) : ?>
				<p>No reviews match your search.</p>
			<?php else : ?>
			<?php echo $view_pager; // Built from escaped parts and paginate_links(). ?>
			<table class="widefat striped" style="margin-bottom:28px;">
				<thead>
					<tr>
						<th style="width:50px;">ID</th>
						<th style="width:110px;">Date</th>
						<th style="width:90px;">Rating</th>
						<th style="width:180px;">Author</th>
						<th>Review</th>
						<th style="width:80px;">Shown</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $view_reviews as $review ) :
						$rating = isset( $review['rating'] ) && is_numeric( $review['rating'] ) ? max( 0, min( 5, (int) $review['rating'] ) ) : null;
						$author = $review['author_name'] ?? '';
						$text   = $review['text'] ?? '';
					?>
					<tr>
						<td><?php echo isset( $review['id'] ) && is_numeric( $review['id'] ) ? esc_html( (int) $review['id'] ) : '&mdash;'; ?></td>
						<td><?php echo ! empty( $review['time'] ) && is_numeric( $review['time'] ) ? esc_html( gmdate( 'Y-m-d', (int) $review['time'] ) ) : '&mdash;'; ?></td>
						<td><?php echo null !== $rating ? '<span style="color:#dba617;" title="' . esc_attr( $rating . ' of 5' ) . '">' . str_repeat( '&#9733;', $rating ) . '</span>' . str_repeat( '&#9734;', 5 - $rating ) : '&mdash;'; ?></td>
						<td>
							<?php if ( ! empty( $review['author_url'] ) ) : ?>
								<a href="<?php echo esc_url( $review['author_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( '' !== $author ? $author : 'Unknown' ); ?></a>
							<?php else : ?>
								<?php echo esc_html( '' !== $author ? $author : 'Unknown' ); ?>
							<?php endif; ?>
						</td>
						<td>
							<?php echo '' !== $text ? nl2br( esc_html( $text ) ) : '<em style="color:#666;">No text (rating only)</em>'; ?>
							<?php if ( ! empty( $review['review_url'] ) ) : ?>
								<br><a href="<?php echo esc_url( $review['review_url'] ); ?>" target="_blank" rel="noopener noreferrer" style="font-size:12px;">View on Google</a>
							<?php endif; ?>
						</td>
						<td><?php echo ! empty( $review['status'] ) ? '<span style="color:#00a32a;">Yes</span>' : '<span style="color:#666;">Hidden</span>'; ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php echo $view_pager; // Built from escaped parts and paginate_links(). ?>
			<?php endif; ?>
			<?php endif; ?>
			<?php endif; ?>

			<!-- Review backups -->
			<?php
			$backups        = $this->get_backups();
			$largest_backup = null;
			foreach ( $backups as $b ) {
				if ( null === $largest_backup || (int) $b['count'] > (int) $largest_backup['count'] ) {
					$largest_backup = $b;
				}
			}
			$stored_count = count( $reviews );
			?>
			<h2 id="gbrr-backups">Review Backups</h2>
			<p class="description" style="max-width:780px;">A copy of the stored reviews is saved once a day, before the daily sync, in <code>wp-content/uploads/gbrr-location-sync/</code>. The <?php echo esc_html( GBRR_SYNC_BACKUPS_KEEP ); ?> newest are kept, plus the one with the most reviews. Restoring adds back the reviews a backup has that are missing now; it never removes or changes a stored review, and skips locations that are no longer saved.</p>
			<?php if ( $largest_backup && $stored_count + 5 < (int) $largest_backup['count'] ) : ?>
				<div class="notice notice-warning inline"><p><strong>Fewer reviews are stored than in a backup.</strong> <?php echo esc_html( sprintf( 'The store has %s reviews; the backup from %s UTC has %s. If reviews went missing, restore that backup below.', number_format( $stored_count ), gmdate( 'Y-m-d H:i', (int) $largest_backup['time'] ), number_format( (int) $largest_backup['count'] ) ) ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0;">
				<?php wp_nonce_field( 'gbrr_backup_now', 'gbrr_backup_nonce' ); ?>
				<input type="hidden" name="action" value="gbrr_backup_now">
				<button class="button" type="submit">Back Up Reviews Now</button>
			</form>
			<?php if ( empty( $backups ) ) : ?>
				<p>No backups yet. The first one is made at the next daily sync, or use the button above.</p>
			<?php else : ?>
			<table class="widefat striped" style="margin-bottom:28px;max-width:780px;">
				<thead>
					<tr>
						<th>Backed up (UTC)</th>
						<th>Reviews</th>
						<th>Size</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $backups as $b ) :
						$is_largest = ( $largest_backup && $b['file'] === $largest_backup['file'] );
					?>
					<tr>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $b['time'] ) ); ?><?php echo $is_largest ? ' <span style="color:#666;">(most reviews)</span>' : ''; ?></td>
						<td><?php echo esc_html( number_format( (int) $b['count'] ) ); ?></td>
						<td><?php echo esc_html( number_format( (int) ( $b['bytes'] ?? 0 ) / 1024 ) . ' KB' ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Add back the reviews from this backup that are missing now? Stored reviews are not removed or changed.');">
								<?php wp_nonce_field( 'gbrr_restore_backup', 'gbrr_restore_nonce' ); ?>
								<input type="hidden" name="action" value="gbrr_restore_backup">
								<input type="hidden" name="gbrr_backup_file" value="<?php echo esc_attr( $b['file'] ); ?>">
								<button class="button" type="submit">Restore Missing Reviews</button>
							</form>
						</td>
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
						'guard'    => '#d63638',
						'backup'   => '#0073aa',
						'restore'  => '#8B5CF6',
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

		if ( false === $results ) {
			$this->set_flash( 'Sync skipped: no API key or no saved locations are configured in the Reviews and Rating plugin (see log below).', 'warning' );
		} else {
			$ok   = count( array_filter( $results, function( $r ) { return $r['ok']; } ) );
			$fail = count( $results ) - $ok;

			$msg = sprintf(
				'Sync complete: %d location%s refreshed%s.',
				$ok,
				$ok === 1 ? '' : 's',
				$fail > 0 ? ", {$fail} failed (see log below)" : ''
			);
			$this->set_flash( $msg, $fail > 0 ? 'warning' : 'success' );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=gbrr-location-sync' ) );
		exit;
	}

	public function handle_backup_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Access denied.' );
		}
		check_admin_referer( 'gbrr_backup_now', 'gbrr_backup_nonce' );

		$result = $this->maybe_backup_reviews( true );
		if ( is_array( $result ) ) {
			$this->set_flash( sprintf( 'Backed up %s stored review(s).', number_format( $result['count'] ) ) );
		} elseif ( is_string( $result ) ) {
			$this->set_flash( $result, 'error' );
		} else {
			$this->set_flash( 'There are no stored reviews to back up.', 'warning' );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=gbrr-location-sync' ) . '#gbrr-backups' );
		exit;
	}

	public function handle_restore_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Access denied.' );
		}
		check_admin_referer( 'gbrr_restore_backup', 'gbrr_restore_nonce' );

		$file   = ( isset( $_POST['gbrr_backup_file'] ) && is_string( $_POST['gbrr_backup_file'] ) ) ? sanitize_file_name( wp_unslash( $_POST['gbrr_backup_file'] ) ) : '';
		$result = $this->restore_backup( $file );
		$this->set_flash( $result['message'], $result['ok'] ? 'success' : 'error' );

		wp_safe_redirect( admin_url( 'options-general.php?page=gbrr-location-sync' ) . '#gbrr-backups' );
		exit;
	}

	/**
	 * Starts buffering the parent plugin's settings page when its Reviews tab
	 * list is switched off, so the list can be cut out of the output.
	 */
	public function maybe_hide_parent_reviews() {
		if ( ! get_option( GBRR_SYNC_HIDE_PARENT_OPTION, false ) ) {
			return;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		$page = ( isset( $_GET['page'] ) && is_string( $_GET['page'] ) ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! preg_match( '/^google_business_reviews_rating(?:_settings)?$/', $page ) ) {
			return;
		}
		ob_start( array( $this, 'hide_parent_reviews_list' ) );
	}

	/**
	 * Output-buffer callback: swaps the parent's list of every review (the
	 * .reviews-table block on its Reviews tab) for a short note. The parent
	 * builds the list in PHP regardless; this keeps the browser from having to
	 * load thousands of rows. Returns the page untouched if the markup isn't
	 * what it expects.
	 */
	public function hide_parent_reviews_list( $html ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}
		$open  = '<div class="reviews-table">';
		$start = strpos( $html, $open );
		if ( false === $start ) {
			return $html;
		}

		// The list is followed by the review-order dialog, a footnote or the
		// end of the tab, whichever the parent prints. Review text is escaped,
		// so none of these can appear inside the list itself.
		$end = false;
		foreach ( array( '<dialog id="review-order-dialog"', '<p class="note help">', '</section>' ) as $marker ) {
			$at = strpos( $html, $marker, $start );
			if ( false !== $at && ( false === $end || $at < $end ) ) {
				$end = $at;
			}
		}
		if ( false === $end ) {
			return $html;
		}
		$close = strrpos( substr( $html, 0, $end ), '</div>' );
		if ( false === $close || $close < $start ) {
			return $html;
		}

		$link = add_query_arg(
			'reviews',
			rawurlencode( (string) get_option( GBRR_SYNC_OPTION_BASE . '_place_id', '' ) ),
			admin_url( 'options-general.php?page=gbrr-location-sync' )
		) . '#gbrr-reviews';
		$note = '<div class="reviews-table"><p class="description">The list of reviews is turned off here by GBRR Location Sync, because it is slow to load. '
			. '<a href="' . esc_url( $link ) . '">Browse the retrieved reviews in Settings &rarr; GBRR Sync</a>, which shows them a page at a time.</p></div>';

		return substr( $html, 0, $start ) . $note . substr( $html, $close + strlen( '</div>' ) );
	}

	public function handle_save_display() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Access denied.' );
		}
		check_admin_referer( 'gbrr_save_display', 'gbrr_display_nonce' );

		$hide = ! empty( $_POST['gbrr_hide_parent_reviews'] );
		update_option( GBRR_SYNC_HIDE_PARENT_OPTION, $hide ? '1' : '', 'yes' );
		$this->set_flash( $hide ? 'The list of reviews on the Reviews and Rating Reviews tab is now hidden.' : 'The list of reviews on the Reviews and Rating Reviews tab is shown again.' );

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
		// back to it when enabling. Disabling with a blank field leaves
		// it blank rather than filling in the admin email.
		if ( '' === trim( $emails ) && $enabled ) {
			$emails = get_option( 'admin_email' );
		}

		$entries        = array_values( array_filter( array_map( 'trim', explode( ',', $emails ) ), 'strlen' ) );
		$valid_emails   = array_values( array_filter( $entries, 'is_email' ) );
		$invalid_emails = array_values( array_diff( $entries, $valid_emails ) );

		if ( ! empty( $invalid_emails ) ) {
			$this->set_flash( 'Notification settings were not saved — not a valid email address: ' . implode( ', ', $invalid_emails ), 'error' );
		} elseif ( $enabled && empty( $valid_emails ) ) {
			$this->set_flash( 'Error notifications were not enabled: please enter at least one valid email address.', 'error' );
		} else {
			update_option( GBRR_SYNC_NOTIFY_ENABLED_OPTION, $enabled, 'yes' );
			update_option( GBRR_SYNC_NOTIFY_EMAIL_OPTION, implode( ', ', $valid_emails ), 'yes' );
			$this->set_flash( 'Notification settings saved.' );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=gbrr-location-sync' ) );
		exit;
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Queues a one-shot admin notice for the settings page.
	 * $type is a WP notice class suffix: success, warning or error.
	 */
	private function set_flash( $message, $type = 'success' ) {
		set_transient( 'gbrr_sync_flash', array( 'message' => $message, 'type' => $type ), 60 );
	}

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
