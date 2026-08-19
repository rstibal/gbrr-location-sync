# GBRR Location Sync

A small WordPress helper plugin for [Reviews and Rating – Google Reviews](https://wordpress.org/plugins/g-business-reviews-rating/) (`g-business-reviews-rating`).

## What it does

- Automatically syncs **all** saved Google Business locations once a day via WP-Cron, instead of only the single active/default location the parent plugin refreshes.
- Merges newly fetched reviews into the parent plugin's review store, preserving the row shape the parent expects (`checked`, `order`, `avatar`, etc.) so its "recently checked" guard doesn't re-process every row and re-fetch avatars on each run.
- Fixes the duplicate-default-location bug that can occur after a manual location cycle by re-deriving the `default` flag on every saved location from the active place ID.
- Adds an admin settings page (**Settings → GBRR Sync**) showing:
  - Next scheduled sync time
  - Per-location status (rating, review count, last synced, OK/FAIL)
  - A manual "Sync All Locations Now" action
  - A Google Places API connection/key test
  - A rolling sync log (last 500 entries, most recent 50 shown)
- One-time repair routine that backfills missing keys on legacy review rows after upgrading.
- Optional error email notifications: enable in **Settings → GBRR Sync** and enter one or more addresses (comma-separated) to get a single summary email whenever a sync run has one or more failed locations.

## Requirements

- WordPress 5.0+
- PHP 7.4+
- [Reviews and Rating – Google Reviews](https://wordpress.org/plugins/g-business-reviews-rating/) plugin active, with an API key and at least one saved location already configured.

## Installation

1. Copy `gbrr-location-sync.php` into `wp-content/plugins/gbrr-location-sync/`, or upload the release zip (see below) via **Plugins → Add New → Upload Plugin**.
2. Activate **GBRR Location Sync** from the WordPress Plugins screen.
3. Visit **Settings → GBRR Sync** to check status or trigger a manual sync.

## Building a release zip

Run `.\build-release.ps1` from the repo root. It uses `git archive` (never
`Compress-Archive`) to build `dist/gbrr-location-sync-<version>.zip`, with
every file under a top-level `gbrr-location-sync/` folder as WordPress'
installer requires.

`Compress-Archive` — and naive zipping on Windows generally — embeds
literal backslashes in zip entry names instead of the forward slashes the
zip format requires. The archive opens fine locally, but on the Linux
hosts WordPress normally runs on, files land with backslashes *in* their
filenames instead of in subfolders, and the plugin fails to install
correctly. `git archive` always writes forward-slash paths regardless of
host OS, so it's the only supported way to build a release here. The
script also verifies every entry in the resulting zip before handing back
the path, and fails loudly if anything looks wrong.

## Notes

- This plugin reads its configuration (API key, saved locations, active place ID) directly from the parent plugin's options — it has no settings of its own beyond scheduling.
- API keys are redacted from any logged error messages.

## License

GPLv2 or later — see [LICENSE](LICENSE).
