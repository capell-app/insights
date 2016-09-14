# Tracking And Consent

Insights records first-party activity through public policy, consent and event endpoints and a frontend render hook. The package should stay invisible to admin pages, Livewire traffic, debug surfaces, and any path listed in `capell-insights.ignored_paths`.

## Runtime Flow

1. `RegisterInsightsTrackerHook` injects a deterministic, cache-safe tracker when `capell-insights.enabled` is true and the frontend render hook registry is bound. Public rendering does not resolve visitor geography or create an Insights fragment sidecar.
2. The browser requests `GET /capell/insights/consent-policy` without cookies. Its private, no-store JSON contains only `consent_required`, using the existing configured region, GeoIP and all-regions policy. The browser API and banner are available immediately, but event queueing and flushing wait for this decision. Missing, malformed or failed responses and the five-second timeout settle on consent required; a late response cannot relax that fallback. Permitted event batches post to `POST /capell/insights/events`.
3. Consent changes post to `POST /capell/insights/consent`.
4. Controllers turn request payloads into `InsightsBeaconData`, `InsightsConsentData`, and `InsightsEventData`; consent jurisdiction is resolved server-side rather than trusted from the browser.
5. Actions write `InsightsVisit`, `InsightsConsent`, and `InsightsEvent` rows.
6. When Privacy Center is installed, `MirrorInsightsConsentToPrivacyCenterAction` mirrors the submitted cookie-category decisions into `privacy_consent_records` using Privacy Center's public record action. If Privacy Center is not installed, the mirror returns without side effects.
7. When `honor_privacy_signals` is enabled, the browser tracker exits before registering listeners if Global Privacy Control or Do-Not-Track is active, and the beacon endpoint drops requests carrying `Sec-GPC: 1`, `DNT: 1`, or `X-Do-Not-Track: 1`.
8. Server-side event recording only treats stored analytics consent as current when the saved `policy_version` matches config and `decided_at` is within `consent_expires_days`.

The route prefix comes from `capell-insights.route_prefix`. The policy GET uses `throttle:60,1` without session or cookie middleware. The consent POST uses `web` and `throttle:60,1`; the event POST uses encrypted/queued cookies and the configurable ingest throttle (30 per minute by default). Both POST endpoints skip CSRF verification.

Acknowledged choices and categories take precedence over the regional default, including rejection outside the UK and Europe. An early acceptance waits for the policy decision and records the initial page view once. Acknowledgements remain effective for the current page when local storage is unavailable. GPC and DNT stop the browser before the policy request when privacy signals are honoured.

The package upgrade migration invalidates frontend output caches before the fragment-to-public transition is recorded, so an obsolete Insights sidecar cannot survive the deployment and silently omit the tracker. The HTML-cache invalidator fails the migration if any cache artefact cannot be removed; subsequent requests rebuild complete public HTML through the normal cache path. Rebuild shared HTML after changing tracker configuration or its policy version as well as rebuilding application configuration. Consuming applications should verify two visitor regions against the same cached page and include the policy GET, consent, CSRF and deferred requests in complete-visit load measurements. Queue workers and the stale-cache processor still need deployment-level verification; package tests do not establish production throughput.

## Config Keys

| Key                                               | Use                                                                                                                                     |
| ------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| `capell-insights.enabled`                         | Turns tracker registration on or off.                                                                                                   |
| `capell-insights.route_prefix`                    | Prefix for beacon and consent routes.                                                                                                   |
| `capell-insights.track_page_views`                | Records page-view events when enabled.                                                                                                  |
| `capell-insights.track_clicks`                    | Records click events when enabled.                                                                                                      |
| `capell-insights.track_forms`                     | Reserved switch for package-level form tracking integrations.                                                                           |
| `capell-insights.automatic_click_tracking`        | Lets the frontend tracker capture clicks automatically.                                                                                 |
| `capell-insights.require_consent_for_all_regions` | Blocks tracking until consent exists, regardless of detected region.                                                                    |
| `capell-insights.default_consent_region`          | Fallback consent region when the request cannot resolve one.                                                                            |
| `capell-insights.policy_version`                  | Stored with consent records so policy updates can be audited.                                                                           |
| `insights.retention_days` setting                 | Default cleanup window for `insights:purge`; falls back to `capell-insights.retention_days` only when package settings are unavailable. |
| `capell-insights.hash_visitor_data`               | Hashes visitor identifiers before storage.                                                                                              |
| `capell-insights.hash_salt`                       | Optional private salt override for visitor hashing. When empty, Insights derives a stable salt from `APP_KEY`.                          |
| `capell-insights.ignored_paths`                   | Paths that should never be tracked.                                                                                                     |
| `capell-insights.ignored_selectors`               | Click targets the frontend tracker should skip.                                                                                         |
| `capell-insights.tables.*`                        | Table-name overrides, also used when registering protected tables.                                                                      |

## Record a Custom Action

Use `RecordCustomActionAction` for server-side events that are not browser clicks or page views.

```php
use Capell\Insights\Actions\RecordCustomActionAction;
use Capell\Insights\Data\InsightsEventData;
use Capell\Insights\Enums\InsightsEventType;

RecordCustomActionAction::run(
    visitUuid: 'visit_01HXZ8QY9J2N3R4S5T6V7W8X9Y',
    data: new InsightsEventData(
        type: InsightsEventType::Custom,
        url: 'https://example.test/newsletter',
        eventName: 'newsletter_signup',
        label: 'Footer signup',
    ),
);
```

Keep custom event names stable. Store identifiers and dimensions, not full request bodies.

Use `RecordConversionAction` when a companion package records a commercial or campaign milestone:

```php
use Capell\Insights\Actions\RecordConversionAction;

RecordConversionAction::run(
    visitUuid: 'visit_01HXZ8QY9J2N3R4S5T6V7W8X9Y',
    eventName: 'campaign.lead',
    url: 'https://example.test/pricing',
    sourcePackage: 'capell-app/campaign-studio',
    value: 250.0,
    currency: 'GBP',
);
```

## Update Consent

Consent writes should go through `UpdateInsightsConsentAction` so the stored region, category, status, and policy version stay consistent.

```php
use Capell\Insights\Actions\UpdateInsightsConsentAction;
use Capell\Insights\Data\InsightsConsentData;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Enums\InsightsConsentStatus;
use Illuminate\Http\Request;

final class ConsentController
{
    public function __invoke(Request $request)
    {
        return UpdateInsightsConsentAction::run(
            request: $request,
            data: new InsightsConsentData(
                insights: true,
                marketing: false,
                preferences: true,
            ),
            status: InsightsConsentStatus::Granular,
            region: InsightsConsentRegion::UkOrEurope,
        );
    }
}
```

If the constructor shape changes, update this doc with the action in the same change.

## Retention

Use the package command for cleanup in the host app. In this repository, test the behavior directly:

```bash
vendor/bin/pest packages/insights/tests --configuration=phpunit.xml
node --test tests/JavaScript/insights-consent-policy.test.mjs
```

The runtime command is `insights:purge {--days=}` in the host application. This repository does not run `php artisan`.

## Safety Notes

- Keep admin, Livewire, debug, storage, and beacon paths in `ignored_paths`.
- Leave `hash_salt` empty to derive visitor hashing from `APP_KEY`, or set a private package-specific salt before recording production data. Changing it later breaks visitor continuity.
- Resolve consent jurisdiction on the server through `default_consent_region` or GeoIP; client-supplied region values are not authoritative.
- Treat raw IP addresses and user agents as sensitive. Prefer hashed fields unless a product requirement says otherwise.
