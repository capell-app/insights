@php
    use Capell\Insights\Actions\GetInsightsTrackerScriptAction;
    use Illuminate\Support\Facades\URL;

    $eventsUrl = config('capell-insights.require_signed_beacons', false) === true
        ? URL::temporarySignedRoute(
            'capell-insights.events',
            now()->addMinutes((int) config('capell-insights.signed_beacon_ttl_minutes', 60)),
        )
        : route('capell-insights.events');

    $insightsConfig = [
        'eventsUrl' => $eventsUrl,
        'consentUrl' => route('capell-insights.consent'),
        'trackPageViews' => config('capell-insights.track_page_views', true) === true,
        'trackClicks' => config('capell-insights.track_clicks', true) === true,
        'automaticClickTracking' => config('capell-insights.automatic_click_tracking', true) === true,
        'ignoredSelectors' => config('capell-insights.ignored_selectors', []),
        'policyVersion' => config('capell-insights.policy_version', '1.0'),
    ];

    $insightsScript = GetInsightsTrackerScriptAction::run();
@endphp

<script type="application/json" data-capell-insights-tracker>
    {!! json_encode($insightsConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}
</script>
<script>
    {!! $insightsScript !!}
</script>
