# Insights

<!-- prettier-ignore-start -->

## What it does

Insights is Capell's first-party web analytics package. It records page views, clicks, optional form activity, acquisition sources, and visitor journeys on your own application, then turns that data into admin reports.

## Your screens

- **Insights**: the Monitoring page for overview statistics, live activity, popular and trending pages, recent journeys, top actions, and acquisition sources.
- **Dashboard and Marketing Studio**: can also show the Insights reporting widgets and overview statistics.
- **Insights settings**: control tracking, consent, retention, visitor hashing, exclusions, and the public beacon route prefix.

## What you can do

- Review traffic, content performance, visitor journeys, actions, and acquisition sources for the selected dashboard date range and site.
- Enable or disable page-view, click, form, and automatic click tracking.
- Choose whether every region needs consent, set the default consent region and policy version, and configure the retention period.
- Exclude paths and CSS selectors from tracking, and set the public beacon route prefix when it conflicts with your application's routes.

## Where to find it

Go to **Insights** under **Monitoring** in the admin. The page requires the `View:InsightsPage` permission. Open the extension's **Insights** settings surface to change tracking and privacy behavior.

## Good to know

- The report widgets use the active dashboard date range and site context, so make sure the dashboard site selection is correct before comparing periods.
- Tracking runs through Capell's public beacon and consent endpoints; no third-party analytics script is required. The frontend tracker is not injected on ignored paths.
- Visitor identifiers are hashed by default. Configure a private hash salt for production, or the package derives one from the application key. Diagnostics reports whether a usable hash secret is available.
- UK and Europe traffic requires Insights consent by default. Enable **Require consent for all regions** to apply that rule everywhere. Changing the policy version makes prior consent ineligible for tracking until the visitor provides consent for the new policy.
- If Privacy Center is installed, Insights consent decisions are mirrored into its consent ledger. Privacy Center subject erasure anonymizes the associated Insights visit and consent hashes.
- Data is retained for 365 days by default. `insights:purge` runs monthly and `insights:rollups:rebuild` runs daily; keep the application scheduler running, or run those commands during maintenance when necessary.
- Diagnostics checks the Insights tables, beacon routes, frontend tracker hook, both schedules, and the visitor hash secret.

---

For how to use Insights, see the [admin guide](admin-guide.md).
For developers: see the [README](../README.md).

<!-- prettier-ignore-end -->
