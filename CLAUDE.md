# CLAUDE.md

Project notes for Claude Code working in this repo.

## Project

- **Path:** `C:\code\gbrr-location-sync` (moved from `C:\www\gbrr-location-sync`)
- **Repo:** https://github.com/rstibal/gbrr-location-sync (public, GPLv2+)
- **What it is:** a single-file WordPress helper plugin (`gbrr-location-sync.php`) for
  [Reviews and Rating – Google Reviews](https://wordpress.org/plugins/g-business-reviews-rating/)
  (`g-business-reviews-rating`). The parent only auto-refreshes the active location on its
  hourly cron; this plugin syncs *all* saved locations daily via WP-Cron
  (`gbrr_location_sync_run`) and re-derives each location's `default` flag.
- Current version: see the `Version:` header and `GBRR_SYNC_VERSION` in
  `gbrr-location-sync.php` — keep the two in sync.

## Working on the code

- The plugin writes straight into the **parent plugin's own options**
  (`google_business_reviews_rating_places`, `_reviews`, `_result`, `_result_valid`, …).
  Row shapes must match what the parent's native code produces — see `merge_reviews()` /
  `normalize_review_row()`, last audited against parent 6.1 (`index.php` `set_reviews()` /
  `set_review_identity()`, `dashboard/sync.php` `retrieve_data()`).
  Before changing how parent options are read or written, get the current parent release from
  wordpress.org (not a copy from a site folder under `C:\www`, which is usually stale) and check
  against it; past mismatches were non-obvious.
- Parent 6.x has two API modes, set by `_api_version`: empty means the legacy Place Details
  API, `1` means Places API (New), which uses different field names, headers and response
  shape. Fresh 6.x installs default to `1`; sites upgraded from 5.x stay legacy. Every request
  this plugin makes must follow that option (`api_version()`). A Google error like
  `Unsupported field name 'displayName'` (or a legacy field name rejected by the new API)
  means a request went to the wrong API for the site's mode. It says nothing about the
  API key: one key with both APIs enabled is shared across sites in different modes.
- Parent 6.x keys review rows `<place_id>_<author id>` and re-keys stored rows to that on
  load; 5.x (and 6.x without an author ID) uses `time_rating_md5(author_text)`. A key that
  doesn't match the parent's makes a duplicate row. `parent_keys_by_author()` detects 6.x.
- The parent's hourly sync can write the same options while our requests are in flight.
  `run_sync()` therefore saves each location as soon as it's fetched via `persist_place()`,
  which re-reads the options with `fresh_option()` (bypassing the request's option cache)
  and merges in only that location's changes. Don't go back to read-once / write-at-end.
- Every write of the whole review store goes through `read_stored_reviews()`, which reads
  the row straight from the database. `get_option()` returns its default both when the
  store doesn't exist and when it can't be read (failed query, data that won't
  unserialize); treating an unreadable store as empty and saving erased about 3,800
  imported reviews on injurylawyers.com in Nov 2026 (the store was ~4–5 MB). Refuse to
  write on a read error, re-read after writing, and put the previous bytes back if the
  new value doesn't read back.
- `guard_reviews_save()` is a `pre_update_option_google_business_reviews_rating_reviews`
  filter, so it checks every plugin's save of the store, the parent's included; returning
  `$old_value` makes WordPress skip the save. It blocks a save that removes more than 5
  reviews from saved locations, except all reviews of one non-active location (the
  parent's `delete_place()` saves the reviews *before* removing the location), and a
  save over an unreadable store unless it keeps about the last known count
  (`gbrr_location_sync_reviews_known`; lets the parent's failed-import rollback through).
  Our own saves that remove rows on purpose (the repair's dedupe, `restore_backup()`)
  set `$this->guard_off`.
- Daily backups (`maybe_backup_reviews()`, run at the start of `run_sync()`) are gzipped
  copies of the raw stored bytes in `wp-content/uploads/gbrr-location-sync/` (random file
  names, `.htaccess` + `index.php`), listed in `gbrr_location_sync_backups`. The newest 7
  are kept plus the one with the most reviews, so a slow loss can't age every good copy
  out. `restore_backup()` only adds missing reviews (never removes or changes one), skips
  locations no longer saved, gives colliding IDs new ones, and backs up the store first.
- `maybe_repair_reviews()` re-runs whenever `GBRR_SYNC_VERSION` changes; it must stay
  idempotent. It backfills missing keys (skipping rows that already have a `checked` key)
  and, on parent 6.x, removes rows that duplicate a `<place_id>_<author id>` row under
  another key (left by versions before 1.4.0), keeping the parent's row.
- The retrieved-reviews list on our page is paged (`GBRR_SYNC_REVIEWS_PER_PAGE`, `?rpage=`) and
  searchable (`?rsearch=`, server-side over author, text and ID); keep both when changing it, since
  one location can hold thousands of reviews.
- Optional `gbrr_location_sync_hide_parent_reviews`: `maybe_hide_parent_reviews()` buffers the parent's
  settings page (`page=google_business_reviews_rating[_settings]`) and `hide_parent_reviews_list()`
  swaps its `.reviews-table` block for a note, ending at the next `<dialog id="review-order-dialog"`,
  `<p class="note help">` or `</section>`. If the parent's markup changes it must leave the page
  untouched. The parent still builds the list server-side; this only spares the browser.
- Plugin-owned options: `gbrr_location_sync_log`, `gbrr_sync_db_version`,
  `gbrr_location_sync_notify_enabled`, `gbrr_location_sync_notify_email`,
  `gbrr_location_sync_backups`, `gbrr_location_sync_reviews_known`,
  `gbrr_location_sync_hide_parent_reviews`.
  Admin page: **Settings → GBRR Sync** (`options-general.php?page=gbrr-location-sync`).
- Never log or display the API key; route error text through `redact_key()`.

## Testing

There's no test suite. At minimum run `php -l gbrr-location-sync.php` (PHP is at `/c/php/php`).
For behavior checks, a throwaway script that stubs the WordPress functions it touches
(`get_option`, `update_option`, `wp_remote_get`, …) and `require`s the plugin works well;
note the `admin_post` handlers end in `exit`, so check their results in a shutdown function.
Cover both API modes (legacy and `_api_version` = 1) and both parent generations (5.x keying
and 6.x keying, toggled by whether `google_business_reviews_rating::set_review_identity`
exists). `_api_version` = 1 only occurs with parent 6.x, so skip that pairing with 5.x.
Remember the fallback review key has no place ID, so identical mock reviews at two
locations collapse into one row.

The user tests the local zip on their live sites before anything is pushed or released.
Commit, then wait for them to say to push and release.

## Releases

Versions follow semantic versioning (`MAJOR.MINOR.PATCH`), no prerelease tags. Patch:
bug fixes only, no new settings or features. Minor: new features or settings that don't
break existing setups. Major: breaking changes (dropping support for an older parent-plugin
version, irreversible changes to stored data, removing a setting). Any change to the shipped
files (`gbrr-location-sync.php`, `README.md`, `LICENSE`) bumps the version; a change to
anything else, like this file, doesn't.

Bump the `Version:` header and `GBRR_SYNC_VERSION`, commit, push, then publish a GitHub
release tagged `vX.Y.Z`. `.github/workflows/release.yml` builds the zip on `ubuntu-latest`,
fails if the tag doesn't match the header or any zip entry contains a backslash, and
attaches the zip to the release. The zip contains only `gbrr-location-sync.php`,
`README.md` and `LICENSE`. After the workflow finishes, download the release zip
(`gh release download vX.Y.Z`) and check its files match the local zip the user tested.

**Never build a zip on Windows with `Compress-Archive`** (or similar): it writes backslashes
into entry names, and the plugin then installs broken on Linux hosts. This has broken a real
release before. `git archive --format=zip` is safe: it always writes forward slashes.

**A local copy of the zip follows every commit**, for testing before a push. A git
`post-commit` hook (`.git/hooks/post-commit`) builds `gbrr-location-sync-X.Y.Z.zip` in the
repo root from `HEAD` (`git archive --format=zip --prefix=gbrr-location-sync/` over the same
three files release.yml copies, version read from the committed `GBRR_SYNC_VERSION`) and
deletes any older `gbrr-location-sync-*.zip`. `*.zip` is in `.gitignore`. Hooks aren't
version-controlled, so a fresh clone doesn't have it; recreate it from this description if
it's missing (same shape as `C:\code\activity-monitor`'s hook). It never fails a commit.

Commits go directly to `master` (solo project).
