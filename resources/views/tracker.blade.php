@php
    use Capell\Insights\Actions\GetInsightsTrackerScriptAction;
    use Capell\Insights\Enums\InsightsConsentRegion;
    use Capell\Insights\Support\Consent\ConsentRegionResolver;

    $consentRegion = app(ConsentRegionResolver::class)->resolve();
    $consentRequired = config('capell-insights.require_consent_for_all_regions', false) === true
        || $consentRegion === InsightsConsentRegion::UkOrEurope
        || $consentRegion === InsightsConsentRegion::Unknown;

    $eventsUrl = route('capell-insights.events', [], false);

    $insightsConfig = [
        'eventsUrl' => $eventsUrl,
        'consentUrl' => route('capell-insights.consent', [], false),
        'consentRequired' => $consentRequired,
        'trackPageViews' => config('capell-insights.track_page_views', true) === true,
        'trackClicks' => config('capell-insights.track_clicks', true) === true,
        'automaticClickTracking' => config('capell-insights.automatic_click_tracking', true) === true,
        'honorPrivacySignals' => config('capell-insights.honor_privacy_signals', true) === true,
        'ignoredSelectors' => config('capell-insights.ignored_selectors', []),
        'policyVersion' => config('capell-insights.policy_version', '1.0'),
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
