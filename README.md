# Analytics

Status: **Available, schema-owning** · Kind: **package** · Tier: **premium** · Bundle: **growth** · Contexts: **admin, frontend** · Product group: **Capell Growth**

## What This Plugin Adds

Analytics records first-party visits, events, consent decisions, page views, clicks, and journey data for Capell sites.

- Frontend beacon endpoints for events and consent.
- Render hook that can register the tracker.
- Dashboard widgets for overview stats, popular pages, top actions, journeys, and trending pages.
- Settings schema for analytics retention and behaviour.

## Why It Matters

**For developers:** Keeps analytics in Laravel actions and data objects, with explicit consent enums and configurable routes.

**For teams:** Gives site operators practical traffic and journey insight without sending the workflow through an external dashboard first.

## Screens And Workflow

Screenshots are generated from [docs/screenshots.json](docs/screenshots.json) during package deployment.

- Analytics overview dashboard widgets.
- Popular pages widget.
- Recent journeys widget.
- Analytics settings screen.
- Frontend page with tracker active.

## Technical Shape

- AnalyticsServiceProvider and AdminServiceProvider register routes, settings, and widgets.
- Config file: capell-analytics.php.
- Routes: POST capell/analytics/events and POST capell/analytics/consent by default.
- Models: AnalyticsVisit, AnalyticsConsent, AnalyticsEvent.
- Actions record page views, clicks, custom events, and consent updates.
- PurgeAnalyticsDataCommand supports retention cleanup.

## Data Model

- analytics_visits stores site, language, consent, landing URL, hashed visitor data, and start time.
- analytics_consents stores consent decisions for a visit.
- analytics_events stores event type, URL, path, metadata, and occurrence time.
- Visits relate to events and consents.
- Retention is governed by retention_days and purge actions.

## Install Impact

- Adds analytics tables and settings migration.
- Adds beacon and consent public POST routes.
- Adds dashboard widgets and analytics settings.
- Uses capell-analytics config keys for route prefix, consent, hashing, retention, and ignored paths.
- May need scheduled cleanup if retention should be enforced automatically.

## Commands

- `analytics:purge {--days= : Override analytics retention days}` (packages/analytics/src/Console/Commands/PurgeAnalyticsDataCommand.php)

## Admin And Access

- None proven in this package directory.

- Gate: AnalyticsOverviewStatsWidget: `admin`, `super_admin`
- Gate: PopularPagesWidget: `admin`, `super_admin`
- Gate: RecentJourneysWidget: `admin`, `super_admin`
- Gate: TopActionsWidget: `admin`, `super_admin`
- Gate: TrendingPagesWidget: `admin`, `super_admin`

## Common Pitfalls

- Exclude admin, Livewire, and analytics routes from tracking.
- Set hash_salt deliberately before production data is recorded.
- Consent settings must match the site privacy policy.

## Quick Start

1. Install the package with `composer require capell-app/analytics`.
2. Run the package migrations or the Capell package installer required by the host app.
3. Open the new admin or frontend surface and verify the result.

## Next Steps

- [docs/overview.md](docs/overview.md)
- [../site-search/README.md](../site-search/README.md)
- [../campaigns/README.md](../campaigns/README.md)
