# Insights

<!-- prettier-ignore-start -->

## What it does

Insights is Capell's first-party web analytics package. It records page views, clicks, custom or conversion events, acquisition sources, and visitor journeys on your own application, then turns that data into admin reports.

## Your screens

- **Insights**: the Monitoring page for overview statistics, live activity, popular and trending pages, recent journeys, top actions, and acquisition sources.
- **Dashboard and Marketing Studio**: can also show the Insights reporting widgets and overview statistics.
- **Insights settings**: store the intended tracking, consent, retention, visitor-hashing, exclusion, and public beacon configuration. See the runtime configuration boundary below before relying on a saved change.

## What you can do

- Review traffic, content performance, visitor journeys, actions, and acquisition sources.
- Configure page-view, click, and automatic click tracking. The form-tracking field is reserved for an integration; the bundled tracker does not currently read it.
- Choose whether every region needs consent, set the default consent region and policy version, and configure the retention period.
- Exclude paths and CSS selectors from tracking, and set the public beacon route prefix when it conflicts with your application's routes.

## Where to find it

Go to **Insights** under **Monitoring** in the admin. The page requires the `View:InsightsPage` permission. Open the extension's **Insights** settings surface to change tracking and privacy behavior.

Each bundled widget is also restricted to the configured admin or super-admin roles and its dashboard visibility setting. Granting `View:InsightsPage` alone does not make a hidden widget visible. The overview, popular pages, trending pages, top actions, and acquisition widgets use the active dashboard date range and site context. **Live statistics** is installation-wide, and **Recent journeys** applies the date range but not the selected site. Those two widgets can expose paths and visit identifiers from other sites, so do not enable them for site-limited operators until the consuming application adds site scoping.

## Runtime configuration boundary

The settings screen persists `InsightsSettings`, but the current tracker and event/consent Actions read the published `capell-insights` configuration for enablement, tracking toggles, consent rules, hashing, ignored paths/selectors, and the route prefix. The purge Action is the exception: it reads the saved retention setting when available. A saved admin change is therefore not sufficient for the other runtime controls unless the consuming application explicitly maps those settings into config. Apply the matching deployment configuration and rebuild cached configuration and routes after changing the beacon prefix.

The **Track forms** value is not passed to the bundled browser script. With click tracking and automatic click tracking enabled, a submit button can still appear as a `form_submit` click action, but the package does not listen for form submission events or capture field values.

## Collection and delivery

- The first event batch is written synchronously so the browser can receive a visit UUID. Later batches for an active visit are queued by default. Keep the configured queue worker running; the encrypted job has a 30-second timeout and three attempts with 1, 5, and 15-second backoffs. Queue delay is report delay, and exhausted jobs require the host application's normal failed-job recovery.
- The public event endpoint accepts at most 25 events per request, validates same-origin or explicitly allowed origins, and is throttled to 30 requests per minute by default. Consent is throttled separately to 60 requests per minute. Enabling signed beacons disables the bundled automatic tracker hook, so provide a signed integration before using that mode.
- Dashboard aggregates are cached for up to 60 seconds by default. Allow for that cache plus queue delay before treating a missing event as a tracking failure.

## Privacy and consent boundaries

Visitor IP addresses and user agents are HMAC-hashed by default, with a site- and period-specific salt derived from the configured private salt or `APP_KEY`. Insights still stores full page, landing, and referrer URLs, titles, UTM values, action labels and locations, simple target selectors, and click coordinates in plaintext. Do not put personal or secret data in URLs, page titles, campaign values, or `data-capell-insights-*` labels.

Global Privacy Control and Do Not Track stop the bundled browser tracker and cause matching beacon requests to be discarded while `honor_privacy_signals` is enabled. UK, European, and unresolved regions require Insights consent by default; traffic resolved outside those regions is opt-out unless **Require consent for all regions** is enabled.

Server-side consent expires after 180 days by default. The bundled browser decision only notices a policy-version change, not that age limit, so an expired visitor is not automatically re-prompted and later events are discarded until consent is submitted again. Use a policy-version update or a consuming consent flow when renewal is required.

## Good to know

- Tracking runs through Capell's public beacon and consent endpoints; no third-party analytics script is required. The frontend tracker is not injected on ignored paths.
- Visitor identifiers are hashed by default. Configure a private hash salt for production, or the package derives one from the application key. Diagnostics reports whether a usable hash secret is available.
- UK and Europe traffic requires Insights consent by default. Enable **Require consent for all regions** to apply that rule everywhere. Changing the policy version makes prior consent ineligible for tracking until the visitor provides consent for the new policy.
- If Privacy Center is installed, Insights consent decisions are mirrored into its consent ledger. Privacy Center subject erasure anonymizes the associated Insights visit and consent hashes.
- Data is retained for 365 days by default. `insights:purge` runs monthly, so expired rows can remain until the next run; an ad-hoc `--days` value must be a positive integer. `insights:rollups:rebuild` rebuilds the most recent 30 days by default and accepts inclusive `--from` and `--to` dates. Keep the scheduler running, or run the commands during maintenance when necessary.
- Diagnostics checks the Insights tables, beacon routes, frontend tracker hook, both schedules, and the visitor hash secret.

---

For how to use Insights, see the [admin guide](admin-guide.md).
For developers: see the [README](../README.md).

<!-- prettier-ignore-end -->
