# Changelog

All notable changes to `capell-app/insights` will be documented in this file.

## Unreleased

- Prepared package metadata and documentation for ongoing Capell 4.x package work.

## 2026-06-03

- Replaced the stub `InsightsHealthCheck` with real Diagnostics probes for storage tables, public beacon/consent routes, and a non-default visitor hash secret.
- Removed two phantom migration names (`06_add_page_url_hit_columns`, `add_site_foreign_keys_to_insights_tables`) registered by `InsightsServiceProvider` that had no files on disk, and added a guard test asserting every registered migration exists.
- Rewrote the marketplace summary, manifest/composer descriptions, and referenced the committed admin and frontend screenshots in the marketplace manifest.
