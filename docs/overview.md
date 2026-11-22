# Insights

<!-- prettier-ignore-start -->

Your own site counts page views, clicks and the links that brought people in, and keeps them in its own database, with no outside analytics company involved. A site owner or marketing lead can then see which pages get read and which campaign link worked. Counting needs background jobs running on your server.

Visitors in the UK, the European Economic Area and Switzerland are asked first. A banner offers Accept all, Reject non-essential or Manage choices, and no page views or clicks are recorded for someone who says no. By default, the same goes for visitors whose country can't be worked out. Browsers that send a Do Not Track or Global Privacy Control signal are never counted. So your totals run lower than your real visitor numbers.

The Insights page in the admin lets you pick a site, a language and a date range, and compare it with the period before. It shows popular and trending pages, where visitors came from and how many visitors took each action you name. Records older than a year are deleted for you, and you can change that.

## What it does

Insights is Capell's first-party web analytics package. It records page views, clicks, custom or conversion events, acquisition sources, and visitor journeys on your own application, then turns that data into admin reports.

## Your screens

- **Insights**: the Monitoring page for overview statistics, live activity, popular and trending pages, recent journeys, top actions, and acquisition sources.
- **Dashboard**: can also show the Insights reporting widgets and overview statistics. The **Marketing** workspace links to Insights under **Performance**.
- **Insights settings**: control tracking, consent, retention, visitor hashing and exclusions. Saved changes take effect on the next page load.

## What you can do

- Review traffic, content performance, visitor journeys, actions, and acquisition sources.
- Configure page-view, click, and automatic click tracking. The form-tracking field is reserved for an integration; the bundled tracker does not currently read it.
- Choose whether every region needs consent, set the default consent region and policy version, and configure the retention period.
- Exclude paths and CSS selectors from tracking. The public beacon route prefix is set in deployment configuration.

## Where to find it

Go to **Insights** under **Monitoring** in the admin. The page requires the `View:InsightsPage` permission. Open the extension's **Insights** settings surface to change tracking and privacy behaviour.

Each bundled widget is also restricted to the configured admin or super-admin roles and its dashboard visibility setting. Granting `View:InsightsPage` alone does not make a hidden widget visible. The overview, popular pages, trending pages, top actions, and acquisition widgets use the active dashboard date range and site context. **Live statistics** is installation-wide, and **Recent journeys** applies the date range but not the selected site. Those two widgets can expose paths and visit identifiers from other sites, so do not enable them for site-limited operators until the consuming application adds site scoping.

## Saved settings and deployment configuration

The saved `InsightsSettings` control enablement, the page-view, click and automatic-click toggles, consent for all regions, the default consent region, the policy version, visitor hashing, ignored paths, ignored selectors and retention. The tracker, the event and consent endpoints, and the purge command read them on every request. Saving the settings clears cached public pages, so visitors get the new tracker configuration on their next page load.

The matching `capell-insights` configuration values seed these settings when the package is installed or upgraded. After that, the configuration is used only if the settings cannot be loaded, such as before the settings migrations have run. To change a control on a running site, save it in the admin; changing the configuration alone has no effect.

The beacon route prefix and the hash salt stay in deployment configuration only. Routes register before settings can be read, and the salt is a secret. After changing the prefix, rebuild cached configuration and routes.

The bundled browser script does not listen for form submission events or capture field values. With click tracking and automatic click tracking on, a submit button can still appear as a `form_submit` click action.

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
- If Privacy Centre is installed, Insights consent decisions are mirrored into its consent ledger. Privacy Centre subject erasure anonymizes the associated Insights visit and consent hashes.
- Data is retained for 365 days by default. `insights:purge` runs monthly, so expired rows can remain until the next run; an ad-hoc `--days` value must be a positive integer. `insights:rollups:rebuild` rebuilds the most recent 30 days by default and accepts inclusive `--from` and `--to` dates. Keep the scheduler running, or run the commands during maintenance when necessary.
- Diagnostics checks the Insights tables, beacon routes, frontend tracker hook, both schedules, and the visitor hash secret.

---

For how to use Insights, see the [admin guide](admin-guide.md).
For developers: see the [README](../README.md).

<!-- prettier-ignore-end -->
