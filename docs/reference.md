# Insights reference

<!-- prettier-ignore-start -->

Developer reference for `capell-app/insights`. Start with the [README](../README.md) for what the extension does and how to install it.

## Package Details

Insights is an **Available**, **Schema-owning** Capell package in the **Capell Marketing & Growth** product group. It ships as `capell-app/insights` and extends these surfaces: admin, frontend.

- Status: Available
- Tier: free
- Bundle: marketing-growth
- Composer package: `capell-app/insights`
- Namespace: `Capell\Insights`
- Theme key: not applicable

## Technical Shape

### Service providers

- `Capell\Insights\Providers\MigrationMetadataServiceProvider`
- `Capell\Insights\Providers\InsightsServiceProvider`
- `Capell\Insights\Providers\AdminServiceProvider`

### Config files

- `packages/insights/config/capell-insights.php`

### Migrations

- `packages/insights/database/migrations/2026_05_10_190855_01_create_insights_visits_table.php`
- `packages/insights/database/migrations/2026_05_10_190855_02_create_insights_consents_table.php`
- `packages/insights/database/migrations/2026_05_10_190855_03_create_insights_events_table.php`
- `packages/insights/database/migrations/2026_05_10_190855_05_import_legacy_page_views.php`
- `packages/insights/database/migrations/2026_06_06_000001_create_insights_daily_rollups_table.php`
- `packages/insights/database/migrations/2026_07_22_000001_add_path_digest_to_insights_daily_rollups_table.php`
- `packages/insights/database/migrations/2026_09_25_000001_invalidate_legacy_insights_html_cache.php`

### Settings migrations

- `packages/insights/database/settings/2026_05_10_190856_01_create_insights_settings.php`
- `packages/insights/database/settings/2026_06_14_000001_rename_insights_form_tracking_setting.php`

### Settings classes

- `InsightsSettings`
- `InsightsSettingsMigrationProvider`

### Models

- `InsightsConsent`
- `InsightsDailyRollup`
- `InsightsEvent`
- `InsightsVisit`

### Filament classes

- `InsightsPage`
- `InsightsDashboardSettingsContributor`
- `InsightsSettingsSchema`
- `AcquisitionSourcesFilamentWidget`
- `BuildsInsightsDashboardWindow`
- `InsightsOverviewStatsFilamentWidget`
- `LiveInsightsStatsFilamentWidget`
- `PopularPagesFilamentWidget`
- `RecentJourneysFilamentWidget`
- `TopActionsFilamentWidget`
- `TrendingPagesFilamentWidget`

### Route files

- `packages/insights/routes/web.php`

### Actions

- `AnonymizeInsightsVisitAction`
- `BuildAcquisitionSourcesQueryAction`
- `BuildFunnelConversionReportAction`
- `BuildInsightsDigestAction`
- `BuildInsightsOverviewStatsAction`
- `BuildInsightsWorkspaceAction`
- `BuildJourneyTimelineAction`
- `BuildLiveInsightsStatsAction`
- `BuildPopularPagesQueryAction`
- `BuildRecentJourneysQueryAction`
- `BuildTopActionsQueryAction`
- `BuildTrendingPagesQueryAction`
- `CreateInsightsVisitAction`
- `ExportInsightsDigestCsvAction`
- `GetInsightsTrackerScriptAction`
- `ImportLegacyPageViewsAction`
- `IngestInsightsBeaconAction`
- `MirrorInsightsConsentToPrivacyCenterAction`
- `PurgeInsightsDataAction`
- `RebuildInsightsDailyRollupsAction`
- `RecordClickAction`
- `RecordConversionAction`
- `RecordCustomActionAction`
- `RecordInsightsEventAction`
- `RecordInsightsEventsAction`
- `RecordPageViewAction`
- `RememberInsightsDashboardAggregateAction`
- `RequireSameOriginConsentRequestAction`
- `ResolveConsentRegionAction`
- `ResolveInsightsConsentPolicyAction`
- `ResolveInsightsHashSaltAction`
- `UpdateInsightsConsentAction`
- `ValidateInsightsBeaconRequestAction`

### Data objects

- `InsightsBeaconData`
- `InsightsConsentData`
- `InsightsConsentPolicyData`
- `InsightsDigestData`
- `InsightsEventData`
- `InsightsEventMetadataData`
- `InsightsJourneyStepData`
- `InsightsPageSummaryData`
- `InsightsReportTableData`
- `InsightsRequestContextData`
- `InsightsVisitData`
- `InsightsWindowData`
- `InsightsWorkspaceData`

### Jobs

- `ProcessInsightsBeaconJob`

### Command signatures

- `insights:purge`
- `insights:rollups:rebuild`

### Scheduled commands

- `insights:purge (monthly; package registered)`
- `insights:rollups:rebuild (daily; package registered)`

### Console command classes

- `PurgeInsightsDataCommand`
- `RebuildInsightsDailyRollupsCommand`

### Manifest contributions

- `admin-page: Capell\Insights\Manifest\InsightsAdminPageContribution`
- `console-command: Capell\Insights\Manifest\InsightsConsoleCommandsContribution`
- `dashboard-widget: Capell\Insights\Manifest\InsightsDashboardFilamentWidgetsContribution`
- `health-check: Capell\Insights\Manifest\InsightsHealthContribution`
- `migration: Capell\Insights\Manifest\InsightsMigrationsContribution`
- `model: Capell\Insights\Manifest\InsightsModelsContribution`
- `overview-stat: Capell\Insights\Manifest\InsightsOverviewStatsContribution`
- `route: Capell\Insights\Manifest\InsightsRoutesContribution`
- `scheduled-job: Capell\Insights\Manifest\InsightsDailyRollupsScheduleContribution`
- `scheduled-job: Capell\Insights\Manifest\InsightsPurgeScheduleContribution`
- `setting: Capell\Insights\Manifest\InsightsSettingsContribution`

### Health checks

- `Capell\Insights\Health\InsightsHealthCheck`

### Blade views

- `packages/insights/resources/views/components/consent-banner.blade.php`
- `packages/insights/resources/views/filament/pages/insights.blade.php`
- `packages/insights/resources/views/tracker.blade.php`

### Cache tags

- `insights`


## Data Model

- Required tables: `insights_visits`, `insights_consents`, `insights_events`, `insights_daily_rollups`.
- Models: `InsightsConsent`, `InsightsDailyRollup`, `InsightsEvent`, `InsightsVisit`.
- Core record references in migrations: `sites via site_id`, `languages via language_id`.
- Migration files: `2026_05_10_190855_01_create_insights_visits_table.php`, `2026_05_10_190855_02_create_insights_consents_table.php`, `2026_05_10_190855_03_create_insights_events_table.php`, `2026_05_10_190855_05_import_legacy_page_views.php`, `2026_06_06_000001_create_insights_daily_rollups_table.php`, `2026_07_22_000001_add_path_digest_to_insights_daily_rollups_table.php`, `2026_09_25_000001_invalidate_legacy_insights_html_cache.php`.
- Migration impact: run host migrations through the package install flow before opening package surfaces.
- Deletion/retention behaviour: migrations declare null-on-delete relationships; retention is scheduled through `insights:purge` (monthly; registered by the package provider).

<!-- prettier-ignore-end -->
