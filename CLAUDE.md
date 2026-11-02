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
  this plugin makes must follow that option (`api_version()`).
- Parent 6.x keys review rows `<place_id>_<author id>` and re-keys stored rows to that on
  load; 5.x (and 6.x without an author ID) uses `time_rating_md5(author_text)`. A key that
  doesn't match the parent's makes a duplicate row. `parent_keys_by_author()` detects 6.x.
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
`README.md` and `LICENSE`.

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
