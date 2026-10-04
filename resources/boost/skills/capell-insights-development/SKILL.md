---
name: capell-insights-development
description: First-party visits, events, consent, journeys, page views, clicks, and insights widgets. Use when editing Capell Insights beacons, consent, journeys, or reporting.
---

# Capell Insights

First-party visits, events, consent, journeys, page views, clicks, and insights widgets.

## Look

- `packages/insights/src`
- `packages/insights/docs`
- `packages/insights/README.md`

## Rules

- Keep frontend beacon writes consent-aware and low overhead.
- Reporting widgets should read from Actions or query services.
- Retention/settings changes must not expose personal data unexpectedly.
- Run `vendor/bin/pest packages/insights/tests`.
