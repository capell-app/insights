<?php

declare(strict_types=1);

it('contains the browser tracking primitives', function (): void {
    $source = insightsScriptSource();

    expect($source)
        ->toContain('navigator.sendBeacon')
        ->toContain('keepalive: true')
        ->toContain('data-capell-insights-ignore')
        ->toContain('data-capell-insights-label')
        ->toContain('data-capell-insights-location');
});

it('uses response-reading fetch for consent submissions', function (): void {
    $source = insightsScriptSource();
    $consentSource = scriptFunctionBody($source, 'consent');
    $submitConsentSource = scriptFunctionBody($source, 'submitConsent');

    expect($consentSource)
        ->toContain('submitConsent(payload)')
        ->not->toContain('sendJson(')
        ->not->toContain('navigator.sendBeacon');

    expect($submitConsentSource)
        ->toContain('fetch(config.consentUrl')
        ->toContain('keepalive: true')
        ->toContain('storeVisitId(response.visit_id)')
        ->toContain('storeConsentDecision(')
        ->toContain('policy_version: config.policyVersion')
        ->not->toContain('sendJson(')
        ->not->toContain('navigator.sendBeacon');
});

it('initializes the packaged consent banner controls', function (): void {
    $source = insightsScriptSource();
    $bannerSource = scriptFunctionBody($source, 'initializeConsentBanner');

    expect($source)
        ->toContain("var consentStorageKey = 'capell_insights_consent'")
        ->toContain("var consentBannerSelector = '[data-capell-insights-consent-banner]'")
        ->toContain('consentDecision.policy_version !== config.policyVersion');

    expect($bannerSource)
        ->toContain('data-capell-insights-consent-action')
        ->toContain('data-capell-insights-consent-choices')
        ->toContain('banner.hidden = false')
        ->toContain('var hadVisitId = Boolean(currentVisitId())')
        ->toContain('banner.hidden = true');
});

it('falls back to the server visit cookie when local storage is empty', function (): void {
    $source = insightsScriptSource();

    expect($source)
        ->toContain("var visitCookieName = 'capell_insights_visit'")
        ->toContain('document.cookie')
        ->toContain('currentVisitCookie()')
        ->toContain('return storedVisitId || currentVisitCookie()');
});

it('uses response-reading fetch when flushing events before a visit id exists', function (): void {
    $source = insightsScriptSource();
    $flushSource = scriptFunctionBody($source, 'flushEvents');

    expect($flushSource)
        ->toContain('var visitId = currentVisitId()')
        ->toContain('storeVisitId(response.visit_id)')
        ->toContain('!visitId');
});

it('does not persist raw dom ids in target selectors', function (): void {
    $source = insightsScriptSource();
    $selectorSource = scriptFunctionBody($source, 'selectorFor');

    expect($selectorSource)
        ->not->toContain('element.id')
        ->not->toContain("'#' +")
        ->not->toContain('CSS.escape');
});

function scriptFunctionBody(string $source, string $functionName): string
{
    $pattern = sprintf('/%s: function \\(payload\\) \\{(?P<body>.*?)\\n        \\}/s', preg_quote($functionName, '/'));

    if (preg_match($pattern, $source, $matches) === 1) {
        return $matches['body'];
    }

    $pattern = sprintf('/function %s\\([^)]*\\) \\{(?P<body>.*?)\\n    \\}/s', preg_quote($functionName, '/'));

    preg_match($pattern, $source, $matches);

    return $matches['body'] ?? '';
}

function insightsScriptSource(): string
{
    $source = file_get_contents(__DIR__ . '/../../../resources/js/capell-insights.js');

    throw_unless(is_string($source), RuntimeException::class, 'Expected capell-insights.js to be readable.');

    return $source;
}
