# Changelog

All notable changes to `capell-app/insights` will be documented in this file.

## Unreleased

- Added configurable session boundaries so stale persistent visit cookies rotate into fresh journey visits.
- Added configurable bot and self-traffic filtering for Insights event recording.
- Hardened privacy defaults: consent region is now resolved server-side instead of trusting the browser, and visitor hashes derive from `APP_KEY` when no private `hash_salt` override is configured.
- Prepared package metadata and documentation for ongoing Capell 4.x package work.

## 2026-06-03

- Replaced the stub `InsightsHealthCheck` with real Diagnostics probes for storage tables, public beacon/consent routes, and a non-default visitor hash secret.
- Removed two phantom migration names (`06_add_page_url_hit_columns`, `add_site_foreign_keys_to_insights_tables`) registered by `InsightsServiceProvider` that had no files on disk, and added a guard test asserting every registered migration exists.
- Rewrote the marketplace summary, manifest/composer descriptions, and referenced the committed admin and frontend screenshots in the marketplace manifest.
