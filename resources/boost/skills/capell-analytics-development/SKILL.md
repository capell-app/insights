---
name: capell-analytics-development
description: Use when editing Capell Analytics beacons, consent, journeys, or reporting.
---

# Capell Analytics

First-party visits, events, consent, journeys, page views, clicks, and analytics widgets.

## Look

- `packages/analytics/src`
- `packages/analytics/docs`
- `packages/analytics/README.md`

## Rules

- Keep frontend beacon writes consent-aware and low overhead.
- Reporting widgets should read from Actions or query services.
- Retention/settings changes must not expose personal data unexpectedly.
- Run `vendor/bin/pest packages/analytics/tests`.
