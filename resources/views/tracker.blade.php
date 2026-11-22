@php
    /** @var \Capell\Insights\Data\InsightsRuntimeSettingsData $runtimeSettings */
    use Capell\Insights\Actions\GetInsightsTrackerScriptAction;

    $eventsUrl = route('capell-insights.events', [], false);

    $expiresDays = config('capell-insights.consent_expires_days', 180);
    $consentExpiresDays = is_numeric($expiresDays) && (int) $expiresDays >= 1 ? (int) $expiresDays : null;

    $insightsConfig = [
        'eventsUrl' => $eventsUrl,
        'consentUrl' => route('capell-insights.consent', [], false),
        'consentPolicyUrl' => route('capell-insights.consent-policy', [], false),
        'consentRequired' => true,
        'consentRestorationRequired' => config('capell-insights.consent_restoration_required', false) === true,
        'trackPageViews' => $runtimeSettings->trackPageViews,
        'trackClicks' => $runtimeSettings->trackClicks,
        'automaticClickTracking' => $runtimeSettings->automaticClickTracking,
        'honorPrivacySignals' => config('capell-insights.honor_privacy_signals', true) === true,
        'ignoredSelectors' => $runtimeSettings->ignoredSelectors,
        'policyVersion' => $runtimeSettings->policyVersion,
        'consentExpiresDays' => $consentExpiresDays,
    ];

    $insightsScript = GetInsightsTrackerScriptAction::run();
@endphp

@if (config('capell-insights.consent_banner_enabled', true) === true)
    @include('capell-insights::components.consent-banner')
@endif

<script
    type="application/json"
    data-capell-insights-tracker
>
    {!! json_encode($insightsConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}
</script>
<script>
    {!! $insightsScript !!}
</script>
