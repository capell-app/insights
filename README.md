# Insights

<!-- prettier-ignore-start -->

## What This Plugin Adds

Insights is an **Available**, **Schema-owning** Capell package in the **Capell Marketing & Growth** product group. It ships as `capell-app/insights` and extends these surfaces: admin, frontend.

Insights records first-party page views, events, journeys, conversions, and consent state for reporting inside Capell.

Admins can review overview metrics, trends, journeys, and dashboard widgets. Public pages send consent-aware events through the Insights beacon.

Evidence: [`src/Actions/IngestInsightsBeaconAction.php`](src/Actions/IngestInsightsBeaconAction.php), [`src/Actions/RecordInsightsEventAction.php`](src/Actions/RecordInsightsEventAction.php), [`routes/web.php`](routes/web.php), [`tests/Feature/Events/InsightsBeaconControllerTest.php`](tests/Feature/Events/InsightsBeaconControllerTest.php), [`src/Filament/Pages/InsightsPage.php`](src/Filament/Pages/InsightsPage.php), [`src/Support/RenderHooks/RegisterInsightsTrackerHook.php`](src/Support/RenderHooks/RegisterInsightsTrackerHook.php), [`tests/Feature/Filament/InsightsWidgetsTest.php`](tests/Feature/Filament/InsightsWidgetsTest.php), [`tests/Feature/Frontend/InsightsRenderHookTest.php`](tests/Feature/Frontend/InsightsRenderHookTest.php).

Status details:

- Status: Available
- Tier: premium
- Bundle: marketing-growth
- Composer package: `capell-app/insights`
- Namespace: `Capell\Insights`
- Theme key: not applicable

## Why It Matters

**For developers:** Typed Actions handle beacon validation, event storage, rollups, consent, and retention instead of spreading analytics logic across controllers and views.

**For teams:** Teams can inspect site activity and conversion paths without sending visitor analytics to a third-party reporting service.

Evidence: [`src/Actions/ValidateInsightsBeaconRequestAction.php`](src/Actions/ValidateInsightsBeaconRequestAction.php), [`src/Actions/RebuildInsightsDailyRollupsAction.php`](src/Actions/RebuildInsightsDailyRollupsAction.php), [`src/Actions/PurgeInsightsDataAction.php`](src/Actions/PurgeInsightsDataAction.php), [`tests/Feature/Reports/RebuildInsightsDailyRollupsActionTest.php`](tests/Feature/Reports/RebuildInsightsDailyRollupsActionTest.php), [`docs/overview.admin.md`](docs/overview.admin.md), [`src/Actions/BuildJourneyTimelineAction.php`](src/Actions/BuildJourneyTimelineAction.php), [`src/Actions/BuildFunnelConversionReportAction.php`](src/Actions/BuildFunnelConversionReportAction.php).

## Screens And Workflow

Screenshot contract: `docs/screenshots.json`.

![Insights overview dashboard widgets](docs/screenshots/insights-overview-dashboard-widgets.png)

![Popular pages widget](docs/screenshots/popular-pages-widget.png)

- Insights overview dashboard widgets (admin, required evidence).
- Popular pages widget (admin, required evidence).
- Recent journeys widget (admin, required evidence).
- Insights settings screen (admin, required evidence).
- Frontend page with tracker active (frontend, supplementary evidence).
- Consent banner flow (frontend, supplementary evidence).

## Technical Shape

- Service providers: `Capell\Insights\Providers\InsightsServiceProvider`, `Capell\Insights\Providers\AdminServiceProvider`.
- Config files: `packages/insights/config/capell-insights.php`.
- Migrations: `packages/insights/database/migrations/2026_05_10_190855_01_create_insights_visits_table.php`, `packages/insights/database/migrations/2026_05_10_190855_02_create_insights_consents_table.php`, `packages/insights/database/migrations/2026_05_10_190855_03_create_insights_events_table.php`, `packages/insights/database/migrations/2026_05_10_190855_05_import_legacy_page_views.php`, `packages/insights/database/migrations/2026_06_06_000001_create_insights_daily_rollups_table.php`, `packages/insights/database/migrations/2026_07_22_000001_add_path_digest_to_insights_daily_rollups_table.php`.
- Settings migrations: `packages/insights/database/settings/2026_05_10_190856_01_create_insights_settings.php`, `packages/insights/database/settings/2026_06_14_000001_rename_insights_form_tracking_setting.php`.
- Settings classes: `InsightsSettings`, `InsightsSettingsMigrationProvider`.
- Models: `InsightsConsent`, `InsightsDailyRollup`, `InsightsEvent`, `InsightsVisit`.
- Filament classes: `InsightsPage`, `InsightsDashboardSettingsContributor`, `InsightsSettingsSchema`, `AcquisitionSourcesFilamentWidget`, `BuildsInsightsDashboardWindow`, `InsightsOverviewStatsFilamentWidget`, `LiveInsightsStatsFilamentWidget`, `PopularPagesFilamentWidget`, `RecentJourneysFilamentWidget`, `TopActionsFilamentWidget`, `TrendingPagesFilamentWidget`.
- Route files: `packages/insights/routes/web.php`.
- Actions: `AnonymizeInsightsVisitAction`, `BuildAcquisitionSourcesQueryAction`, `BuildFunnelConversionReportAction`, `BuildInsightsDigestAction`, `BuildInsightsOverviewStatsAction`, `BuildJourneyTimelineAction`, `BuildLiveInsightsStatsAction`, `BuildPopularPagesQueryAction`, `BuildRecentJourneysQueryAction`, `BuildTopActionsQueryAction`, `BuildTrendingPagesQueryAction`, `CreateInsightsVisitAction`, `and 18 more`.
- Data objects: `InsightsBeaconData`, `InsightsConsentData`, `InsightsDigestData`, `InsightsEventData`, `InsightsEventMetadataData`, `InsightsJourneyStepData`, `InsightsPageSummaryData`, `InsightsRequestContextData`, `InsightsVisitData`, `InsightsWindowData`.
- Jobs: `ProcessInsightsBeaconJob`.
- Command signatures: `insights:purge`, `insights:rollups:rebuild`.
- Scheduled commands: `insights:purge (monthly; package registered)`, `insights:rollups:rebuild (daily; package registered)`.
- Console command classes: `PurgeInsightsDataCommand`, `RebuildInsightsDailyRollupsCommand`.
- Manifest contributions: `admin-page: Capell\Insights\Manifest\InsightsAdminPageContribution`, `console-command: Capell\Insights\Manifest\InsightsConsoleCommandsContribution`, `dashboard-widget: Capell\Insights\Manifest\InsightsDashboardFilamentWidgetsContribution`, `health-check: Capell\Insights\Manifest\InsightsHealthContribution`, `migration: Capell\Insights\Manifest\InsightsMigrationsContribution`, `model: Capell\Insights\Manifest\InsightsModelsContribution`, `overview-stat: Capell\Insights\Manifest\InsightsOverviewStatsContribution`, `route: Capell\Insights\Manifest\InsightsRoutesContribution`, `scheduled-job: Capell\Insights\Manifest\InsightsDailyRollupsScheduleContribution`, `scheduled-job: Capell\Insights\Manifest\InsightsPurgeScheduleContribution`, `setting: Capell\Insights\Manifest\InsightsSettingsContribution`.
- Health checks: `Capell\Insights\Health\InsightsHealthCheck`.
- Blade views: `packages/insights/resources/views/components/consent-banner.blade.php`, `packages/insights/resources/views/filament/pages/insights.blade.php`, `packages/insights/resources/views/tracker.blade.php`.
- Cache tags: `insights`.

## Data Model

- Required tables: `insights_visits`, `insights_consents`, `insights_events`, `insights_daily_rollups`.
- Models: `InsightsConsent`, `InsightsDailyRollup`, `InsightsEvent`, `InsightsVisit`.
- Core record references in migrations: `sites via site_id`, `languages via language_id`.
- Migration files: `2026_05_10_190855_01_create_insights_visits_table.php`, `2026_05_10_190855_02_create_insights_consents_table.php`, `2026_05_10_190855_03_create_insights_events_table.php`, `2026_05_10_190855_05_import_legacy_page_views.php`, `2026_06_06_000001_create_insights_daily_rollups_table.php`, `2026_07_22_000001_add_path_digest_to_insights_daily_rollups_table.php`.
- Migration impact: run host migrations through the package install flow before opening package surfaces.
- Deletion/retention behaviour: migrations declare null-on-delete relationships; retention is scheduled through `insights:purge` (monthly; registered by the package provider).

## Install Impact

- Required packages: `capell-app/admin`, `capell-app/core`, `capell-app/frontend`.
- Admin navigation: declares `admin-page: InsightsAdminPageContribution`; each Filament page or resource controls its own navigation visibility.
- Admin/editor extensions: `dashboard-widget: InsightsDashboardFilamentWidgetsContribution`, `overview-stat: InsightsOverviewStatsContribution`.
- Permissions: `View:InsightsPage`.
- Public routes: loads `routes/web.php`; registers `InsightsRoutesContribution`.
- Database changes: package migrations are declared.
- Config: `config/capell-insights.php`.
- Settings: `Capell\Insights\Settings\InsightsSettings`.
- Queues or schedules: scheduled commands `insights:purge (monthly; package registered)`, `insights:rollups:rebuild (daily; package registered)`; queue jobs `ProcessInsightsBeaconJob`.
- Cache tags: `insights`.
- Commands: `insights:purge`, `insights:rollups:rebuild`.

## Common Pitfalls

- Keep required Capell packages on compatible v4 releases: `capell-app/admin`, `capell-app/core`, `capell-app/frontend`.
- Run migrations before opening package resources or public routes.
- Review package configuration before production-like verification: `config/capell-insights.php`, `Capell\Insights\Settings\InsightsSettings`.
- Review middleware, throttling, signatures, and public-output safety in `routes/web.php` before exposing routes.
- Keep the host Laravel scheduler running so package-registered schedules can execute: `insights:purge (monthly; package registered)`, `insights:rollups:rebuild (daily; package registered)`.
- Keep public Blade and cached HTML free of authoring markers, model IDs, permissions, signed editor URLs, and lazy database queries.
- Custom write integrations must preserve invalidation for `insights` cache tags.

## Troubleshooting

| Symptom | Likely cause | Check | Fix |
| --- | --- | --- | --- |
| Package surface is missing after install | Provider or manifest is not loaded | Confirm `capell.json`, package `composer.json`, and provider registration | Reinstall the package, refresh Composer autoload, and clear host caches |
| Admin screen or command fails on missing table | Package migrations have not run | Check the tables listed in `Data Model` | Run host migrations and rerun the focused package test |
| Route returns unexpected output | Route cache, middleware, or signed URL setup does not match the package route file | Check the route files listed in `Technical Shape` | Clear route cache and verify middleware before exposing public routes |
| Background work does not run | Queue worker or declared schedule is not active | Check the jobs and scheduled commands listed in `Technical Shape` | Start the queue worker or host scheduler, then run the focused command or package test |
| Public output leaks unexpected state | Render data, cache variation, or authoring boundary has regressed | Check public Blade, cache tags, and public-output safety tests | Move data loading out of Blade and rerun the package public-output tests |

## Quick Start

1. Install the package: `composer require capell-app/insights`.
2. Run the required setup: `php artisan migrate`.
3. Open the package admin surface at `/screenshot-fixtures/insights/insights-overview-dashboard-widgets` and confirm Insights is available.

## Next Steps

- [Package docs](docs/README.md)
- [Overview](docs/overview.md)
- [Admin guide](docs/admin-guide.md)
- Configuration files: [`config/capell-insights.php`](config/capell-insights.php).
- [Troubleshooting](#troubleshooting)
- [Screenshot contract](docs/screenshots.json)
- [Marketplace assets](docs/assets/marketplace/)
- [Capell content language plan](../../docs/CONTENT_LANGUAGE_PLAN.md)
- [Capell documentation design system](../../docs/DESIGN_SYSTEM.md)
- [Capell and package ERD notes](../../docs/erd/capell-and-package-erds.md)
- Related packages: [Privacy Center](../privacy-center/README.md).
- Focused tests: `vendor/bin/pest packages/insights/tests --configuration=phpunit.xml`.

<!-- prettier-ignore-end -->
