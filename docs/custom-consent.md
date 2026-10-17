# A custom consent interface

When a site already has a consent interface, set `capell-insights.consent_banner_enabled` to `false` and forward its explicit choices through `window.CapellInsights.consent()`. Keep the tracker hook enabled. Disabling the package banner does not grant permission to track.

The method returns a promise resolving to `true` only after the current choice has a valid persistence acknowledgement, or when it exactly matches an existing acknowledged decision for the current policy and expiry period. Network failures, invalid acknowledgements and superseded choices resolve to `false`. Withdrawing the Insights category stops queued and new Insights events immediately, even if persistence fails.

```js
const acknowledged = await window.CapellInsights.consent({
    status: 'granular',
    terms_accepted: termsAccepted,
    categories: {
        insights: analyticsChosen,
        marketing: marketingChosen,
        preferences: preferencesChosen,
    },
})
```

Supply `termsAccepted` from the fresh user decision. Use `status: 'rejected_non_essential'` for rejection and `status: 'accepted_all'` only when all optional categories were explicitly selected.

Set `capell-insights.consent_restoration_required` to `true` when the custom interface restores its own remembered choice. This keeps optional tracking blocked from startup until a matching read-only restoration succeeds or the user makes a fresh acknowledged choice. The default is `false` for the package’s standalone consent interface.

To restore an earlier interface choice, call `restoreConsent()` with its status and categories. This is read-only: it does not post a consent record, accept new terms, renew an expired decision or change the stored decision. It resolves to `true` only when the tracker already holds a matching acknowledgement for the current policy, within the configured `consent_expires_days` period. A `false` result requires a fresh choice; keep optional analytics disabled while restoration is pending or unsuccessful. Never call `consent()` automatically to renew an old choice on page load.

The API can be absent when the tracker is excluded or privacy signals disable it. An absent method or an older API returning no acknowledgement must not be treated as a successful restoration. The server remains authoritative for consent policy, category and expiry checks.

The browser uses the same `consent_expires_days` setting as event ingestion (180 days by default). `null`, `false`, invalid values and values below one disable expiry. An expired or obsolete choice blocks queued and new events until a fresh decision is acknowledged, including in regions where consent is otherwise optional.

The tracker disposes its listeners, queued events and pending acknowledgements on `livewire:navigating` and page departure, and replaces an earlier tracker when the script runs again. Custom integrations may call `window.CapellInsights.dispose()` before removing the tracker outside those navigation flows. A disposed API cannot grant consent or send new events.

On a persisted `pageshow` (back/forward cache restoration), the tracker creates one fresh instance, re-reads the remembered choice and fetches the private region policy again before tracking. Custom interfaces with `consent_restoration_required` enabled must repeat their read-only handoff to that fresh instance.

Consent writes remain ordered across tracker replacements for the lifetime of the document. Disposal invalidates the old tracker’s response and skips its queued choices; an already sent request still settles before the replacement sends a later choice. Restoration and acknowledgement reuse remain unavailable while that write queue is busy. The tracker does not treat request abortion as cancellation of a server write.
