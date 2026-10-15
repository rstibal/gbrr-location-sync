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
  `normalize_review_row()`, which were audited against the parent's `index.php` (v5.10).
  Before changing how parent options are read or written, get a copy of the parent's source
  and check against it; past mismatches were non-obvious.
- The parent's hourly sync can write the same options while our requests are in flight.
  `run_sync()` therefore saves each location as soon as it's fetched via `persist_place()`,
  which re-reads the options with `fresh_option()` (bypassing the request's option cache)
  and merges in only that location's changes. Don't go back to read-once / write-at-end.
- `maybe_repair_reviews()` re-runs whenever `GBRR_SYNC_VERSION` changes; it must stay
  idempotent (it skips rows that already have a `checked` key).
- Plugin-owned options: `gbrr_location_sync_log`, `gbrr_sync_db_version`,
  `gbrr_location_sync_notify_enabled`, `gbrr_location_sync_notify_email`.
  Admin page: **Settings → GBRR Sync** (`options-general.php?page=gbrr-location-sync`).
- Never log or display the API key; route error text through `redact_key()`.

## Testing

There's no test suite. At minimum run `php -l gbrr-location-sync.php` (PHP is at `/c/php/php`).
For behavior checks, a throwaway script that stubs the WordPress functions it touches
(`get_option`, `update_option`, `wp_remote_get`, …) and `require`s the plugin works well;
note the `admin_post` handlers end in `exit`, so check their results in a shutdown function.

## Releases

Bump the `Version:` header and `GBRR_SYNC_VERSION`, commit, push, then publish a GitHub
release tagged `vX.Y.Z`. `.github/workflows/release.yml` builds the zip on `ubuntu-latest`,
fails if the tag doesn't match the header or any zip entry contains a backslash, and
attaches the zip to the release. The zip contains only `gbrr-location-sync.php`,
`README.md` and `LICENSE`.

**Never build a release zip on Windows** (`Compress-Archive` etc.): it writes backslashes into
entry names, and the plugin then installs broken on Linux hosts. This has broken a real
release before.

Commits go directly to `master` (solo project).
