# Tracking And Consent

Insights records first-party activity through public policy, consent and event endpoints and a frontend render hook. The package should stay invisible to admin pages, Livewire traffic, debug surfaces, and any path listed in `capell-insights.ignored_paths`.

## Runtime Flow

1. `RegisterInsightsTrackerHook` injects a deterministic, cache-safe tracker when `capell-insights.enabled` is true and the frontend render hook registry is bound. Public rendering does not resolve visitor geography or create an Insights fragment sidecar.
2. The browser requests `GET /capell/insights/consent-policy` without cookies. Its private, no-store JSON contains only `consent_required`. Geography resolves from the nginx-authenticated server parameter, then GeoIP when available and configured. Once a lookup is attempted, its result is final: default/fallback locations, missing or invalid countries, and provider failures (caught by Torann or escaping the library) resolve to unknown and require consent. The saved admin region, then the configured default region, applies only when GeoIP is unavailable or has no configured service and no lookup is attempted; an unresolved fallback requires consent. The all-regions policy always requires consent. The browser API and banner are available immediately, but event queueing and flushing wait for this decision. Missing, malformed or failed responses and the five-second timeout settle on consent required; a late response cannot relax that fallback. Permitted event batches post to `POST /capell/insights/events`.
3. Consent changes post to `POST /capell/insights/consent`.
4. Controllers turn request payloads into `InsightsBeaconData`, `InsightsConsentData`, and `InsightsEventData`; consent jurisdiction is resolved server-side rather than trusted from the browser.
5. Actions write `InsightsVisit`, `InsightsConsent`, and `InsightsEvent` rows.
6. When Privacy Center is installed, `MirrorInsightsConsentToPrivacyCenterAction` mirrors the submitted cookie-category decisions into `privacy_consent_records` using Privacy Center's public record action. If Privacy Center is not installed, the mirror returns without side effects.
7. When `honor_privacy_signals` is enabled, the browser tracker exits before registering listeners if Global Privacy Control or Do-Not-Track is active, and the beacon endpoint drops requests carrying `Sec-GPC: 1`, `DNT: 1`, or `X-Do-Not-Track: 1`.
8. Server-side event recording only treats stored analytics consent as current when the saved `policy_version` matches config and `decided_at` is within `consent_expires_days`.

The route prefix comes from `capell-insights.route_prefix`. The policy GET uses `throttle:60,1` without session or cookie middleware. The consent POST uses `web` and `throttle:60,1`; the event POST uses encrypted/queued cookies and the configurable ingest throttle (30 per minute by default). Both POST endpoints skip CSRF verification.

## Trusted Edge Geography

Insights reads `CAPELL_EDGE_COUNTRY` through `$request->server()`. Configure a different FastCGI parameter name with `capell-insights.edge_country_server_parameter`; `HTTP_*` names are rejected because they represent client headers. The application never reads `CF-IPCountry`, forwarded addresses or a PHP Cloudflare range list. Nginx authenticates the original peer before supplying the parameter, even when real-IP processing has replaced `REMOTE_ADDR` with the visitor address.

The parameter must originate in the request's FastCGI parameters. PHP-FPM must keep `clear_env = yes`, and the chosen name must not be set in the OS/container environment, an FPM pool `env[...]` directive, `.env`, or application `putenv()` calls. FPM imports process environment variables into `$_SERVER`; the resolver rejects the parameter whenever `getenv($name, true)` finds a process value, including an empty value or one that differs from the request. The [PHP `getenv()` documentation](https://www.php.net/manual/en/function.getenv.php) explains why `local_only: true` is required: ordinary `getenv()` also reads FastCGI request parameters and would reject legitimate edge geography. A server-bag value alone cannot establish provenance; trust depends on these deployment requirements and nginx supplying the parameter on every PHP request.

Add these blocks inside nginx's `http` context. The `geo` block must contain exactly the Cloudflare ranges already trusted by the deployment's `set_real_ip_from` list, and both lists must be updated together. The following full range list comes from Cloudflare's published [IPv4](https://www.cloudflare.com/ips-v4) and [IPv6](https://www.cloudflare.com/ips-v6) lists, checked on 2026-09-30. Reconcile it with the deployment's complete real-IP configuration before use; do not add non-Cloudflare origin proxies. `$realip_remote_addr` preserves the original peer ([nginx real-IP documentation](https://nginx.org/en/docs/http/ngx_http_realip_module.html)); using `$remote_addr` here would instead check the rewritten visitor address.

```nginx
# http context; keep these networks identical to Cloudflare set_real_ip_from.
geo $realip_remote_addr $capell_cloudflare_peer {
    default 0;
    173.245.48.0/20 1;
    103.21.244.0/22 1;
    103.22.200.0/22 1;
    103.31.4.0/22 1;
    141.101.64.0/18 1;
    108.162.192.0/18 1;
    190.93.240.0/20 1;
    188.114.96.0/20 1;
    197.234.240.0/22 1;
    198.41.128.0/17 1;
    162.158.0.0/15 1;
    104.16.0.0/13 1;
    104.24.0.0/14 1;
    172.64.0.0/13 1;
    131.0.72.0/22 1;
    2400:cb00::/32 1;
    2606:4700::/32 1;
    2803:f800::/32 1;
    2405:b500::/32 1;
    2405:8100::/32 1;
    2a06:98c0::/29 1;
    2c0f:f248::/32 1;
}

map $capell_cloudflare_peer $capell_edge_country {
    default "";
    1 $http_cf_ipcountry;
}
```

Add the following to **each PHP FastCGI location** that serves the application, after its existing FastCGI includes. Declare it exactly once in each effective parameter set; nginx FastCGI parameter inheritance can be replaced by location-specific parameters. Send it on every PHP request, including an empty value for non-Cloudflare peers. Do not add `if_not_empty`, which would omit the parameter for those peers ([nginx FastCGI documentation](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_param)).

```nginx
fastcgi_param CAPELL_EDGE_COUNTRY $capell_edge_country;
```

Accept only a single uppercase, recognised ISO 3166-1 alpha-2 string. Missing, empty, `XX`, `T1`, `ZZ`, unassigned codes, aliases such as `UK`, arrays and comma-separated/duplicate values are ignored. Invalid or environment-sourced edge geography continues to GeoIP when available and configured. Any attempted lookup terminates resolution; it never continues to the saved/default region. Consent resolution calls the configured Torann provider directly, bypassing its location cache and fallback hydration: `getLocation()` catches provider exceptions and can return a configured default indistinguishable from a successful lookup. A truthy provider `default` flag, missing or invalid lookup country, empty result, non-public request IP or thrown error resolves to unknown and requires consent. Successful provider results are accepted even when their fields match `geoip.default_location`; configuration fields are never used to infer lookup success. Only when GeoIP is unavailable or unconfigured does resolution use the saved admin region, then the configured default.

The package ships a 249-code list derived from [league/iso3166 4.3.0](https://github.com/alcohol/iso3166/blob/4.3.0/src/ISO3166.php). Country validation uses this bundled list; no additional country-data dependency is needed in the host application.

Shared HTML remains identical across visitor countries: it starts with strict consent and fetches the visitor's policy after load. HTML-cache rejects the policy response's explicit `private, no-store` directives and JSON content type. Edge cache rules must respect those directives; do not force-cache the policy endpoint or embed its decision into shared HTML.

Acknowledged choices and categories take precedence over the regional default, including rejection outside the UK and Europe. An early acceptance waits for the policy decision and records the initial page view once. Acknowledgements remain effective for the current page when local storage is unavailable. GPC and DNT stop the browser before the policy request when privacy signals are honoured.

The package upgrade migration invalidates frontend output caches before the fragment-to-public transition is recorded, so an obsolete Insights sidecar cannot survive the deployment and silently omit the tracker. The HTML-cache invalidator fails the migration if any cache artefact cannot be removed; subsequent requests rebuild complete public HTML through the normal cache path. Rebuild shared HTML after changing tracker configuration or its policy version as well as rebuilding application configuration. Consuming applications should verify two visitor regions against the same cached page and include the policy GET, consent, CSRF and deferred requests in complete-visit load measurements. Queue workers and the stale-cache processor still need deployment-level verification; package tests do not establish production throughput.

## Config Keys

| Key                                               | Use                                                                                                                                                                     |
| ------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `capell-insights.enabled`                         | Turns tracker registration on or off.                                                                                                                                   |
| `capell-insights.route_prefix`                    | Prefix for beacon and consent routes.                                                                                                                                   |
| `capell-insights.track_page_views`                | Records page-view events when enabled.                                                                                                                                  |
| `capell-insights.track_clicks`                    | Records click events when enabled.                                                                                                                                      |
| `capell-insights.track_forms`                     | Reserved switch for package-level form tracking integrations.                                                                                                           |
| `capell-insights.automatic_click_tracking`        | Lets the frontend tracker capture clicks automatically.                                                                                                                 |
| `capell-insights.require_consent_for_all_regions` | Blocks tracking until consent exists, regardless of detected region.                                                                                                    |
| `capell-insights.edge_country_server_parameter`   | Trusted request FastCGI parameter name; defaults to `CAPELL_EDGE_COUNTRY`. `HTTP_*` names and process-environment values are rejected; FPM must keep `clear_env = yes`. |
| `capell-insights.default_consent_region`          | Deployment fallback when edge geography is unresolved, GeoIP is unavailable or unconfigured, and no admin region is saved. Never used after an attempted GeoIP lookup.  |
| `capell-insights.policy_version`                  | Stored with consent records so policy updates can be audited.                                                                                                           |
| `insights.retention_days` setting                 | Default cleanup window for `insights:purge`; falls back to `capell-insights.retention_days` only when package settings are unavailable.                                 |
| `capell-insights.hash_visitor_data`               | Hashes visitor identifiers before storage.                                                                                                                              |
| `capell-insights.hash_salt`                       | Optional private salt override for visitor hashing. When empty, Insights derives a stable salt from `APP_KEY`.                                                          |
| `capell-insights.ignored_paths`                   | Paths that should never be tracked.                                                                                                                                     |
| `capell-insights.ignored_selectors`               | Click targets the frontend tracker should skip.                                                                                                                         |
| `capell-insights.tables.*`                        | Table-name overrides, also used when registering protected tables.                                                                                                      |

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

Run retention cleanup in your installed Capell application:

```bash
php artisan insights:purge
```

Use `--days=<days>` to override the configured retention period for that run.

## Safety Notes

- Keep admin, Livewire, debug, storage, and beacon paths in `ignored_paths`.
- Leave `hash_salt` empty to derive visitor hashing from `APP_KEY`, or set a private package-specific salt before recording production data. Changing it later breaks visitor continuity.
- Resolve consent jurisdiction on the server through nginx-authenticated edge geography, GeoIP or the configured fallback; client-supplied region values are not authoritative.
- Treat raw IP addresses and user agents as sensitive. Prefer hashed fields unless a product requirement says otherwise.
