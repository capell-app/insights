<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\Insights\Actions\BuildAcquisitionSourcesQueryAction;
use Capell\Insights\Actions\CreateInsightsVisitAction;
use Capell\Insights\Actions\HashInsightsVisitorValueAction;
use Capell\Insights\Actions\RecordInsightsEventsAction;
use Capell\Insights\Data\InsightsEventData;
use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @return array<string, string> */
function integrityPageView(string $url = 'https://example.test/'): array
{
    return [
        'type' => InsightsEventType::PageView->value,
        'url' => $url,
        'title' => 'Home',
        'occurred_at' => now()->toIso8601String(),
    ];
}

/** @param array<string, mixed> $attributes */
function integrityOutsideVisit(array $attributes = []): InsightsVisit
{
    return InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        ...$attributes,
    ]);
}

it('reads campaign tags from the landing page and keeps only origin and path', function (): void {
    $request = Request::create('/capell/insights/events', 'POST', [], [], [], [
        'HTTP_REFERER' => 'https://example.test/pricing?utm_source=x&utm_medium=email&utm_campaign=launch&token=secret',
    ]);

    $visit = CreateInsightsVisitAction::run(
        $request,
        InsightsConsentRegion::OutsideUkOrEurope,
        'https://example.test/pricing?utm_source=x&utm_medium=email&utm_campaign=launch&token=secret',
        'https://mail.example.org/inbox/42?token=abc&email=person@example.org',
    );

    expect($visit->utm_source)->toBe('x')
        ->and($visit->utm_medium)->toBe('email')
        ->and($visit->utm_campaign)->toBe('launch')
        ->and($visit->landing_url)->toBe('https://example.test/pricing')
        ->and($visit->referrer_url)->toBe('https://mail.example.org/inbox/42');

    $sources = BuildAcquisitionSourcesQueryAction::run(new InsightsWindowData(
        startsAt: now()->subDay()->toImmutable(),
        endsAt: now()->addDay()->toImmutable(),
    ));

    expect($sources->pluck('source')->all())->toContain('x');
});

it('does not treat the visited site itself as the referrer', function (): void {
    $visit = CreateInsightsVisitAction::run(
        Request::create('/capell/insights/events', 'POST'),
        InsightsConsentRegion::OutsideUkOrEurope,
        'https://example.test/about',
        'https://example.test/',
    );

    expect($visit->referrer_url)->toBeNull()
        ->and($visit->utm_source)->toBeNull();
});

it('creates a new visit from the first beacon with its landing campaign and the browser referrer', function (): void {
    $site = Site::factory()->create();
    $request = Request::create('/capell/insights/events', 'POST');
    $request->attributes->set('site', $site);

    $events = RecordInsightsEventsAction::run(
        visitUuid: null,
        events: [['data' => InsightsEventData::from(integrityPageView('https://example.test/?utm_source=x')), 'occurred_at' => null]],
        request: $request,
        consentRegion: InsightsConsentRegion::OutsideUkOrEurope,
        referrerUrl: 'https://search.example.org/results?q=private',
    );

    $visit = InsightsVisit::query()->findOrFail($events->firstOrFail()->visit_id);

    expect($visit->utm_source)->toBe('x')
        ->and($visit->landing_url)->toBe('https://example.test/')
        ->and($visit->referrer_url)->toBe('https://search.example.org/results');
});

it('strips query strings from stored visit urls and recovers their campaign tags', function (): void {
    $visit = integrityOutsideVisit([
        'landing_url' => 'https://example.test/offer?utm_source=newsletter&token=secret',
        'referrer_url' => 'https://partner.example.org/link?token=abc',
    ]);

    $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_11_12_000002_strip_query_strings_from_insights_visit_urls.php';
    $migration->up();

    $visit->refresh();

    expect($visit->landing_url)->toBe('https://example.test/offer')
        ->and($visit->referrer_url)->toBe('https://partner.example.org/link')
        ->and($visit->utm_source)->toBe('newsletter');
});

it('ignores a posted visit id that belongs to another visitor cookie', function (): void {
    $owned = integrityOutsideVisit();
    $other = integrityOutsideVisit();

    $this->withCredentials()->withCookie(RecordInsightsEventsAction::VISIT_COOKIE, $owned->uuid)
        ->postJson(route('capell-insights.events'), [
            'visit_id' => $other->uuid,
            'events' => [integrityPageView()],
        ]);

    expect(InsightsEvent::query()->where('visit_id', $other->getKey())->count())->toBe(0);
});

it('ignores a posted visit id without a cookie when the browser does not match the visit', function (): void {
    $other = integrityOutsideVisit();

    $this->postJson(route('capell-insights.events'), [
        'visit_id' => $other->uuid,
        'events' => [integrityPageView()],
    ]);

    expect(InsightsEvent::query()->where('visit_id', $other->getKey())->count())->toBe(0);
});

it('accepts a posted visit id without a cookie from the browser that started the visit', function (): void {
    $visit = integrityOutsideVisit([
        'ip_hash' => HashInsightsVisitorValueAction::run('127.0.0.1', null),
        'user_agent_hash' => HashInsightsVisitorValueAction::run('Symfony', null),
    ]);

    $this->postJson(route('capell-insights.events'), [
        'visit_id' => $visit->uuid,
        'events' => [integrityPageView()],
    ])->assertNoContent();

    expect(InsightsEvent::query()->where('visit_id', $visit->getKey())->count())->toBe(1);
});

it('rejects out-of-range conversion values and coordinates', function (array $event): void {
    $visit = integrityOutsideVisit();

    $this->withCredentials()->withCookie(RecordInsightsEventsAction::VISIT_COOKIE, $visit->uuid)
        ->postJson(route('capell-insights.events'), [
            'visit_id' => $visit->uuid,
            'events' => [[...integrityPageView(), ...$event]],
        ])
        ->assertUnprocessable();

    expect(InsightsEvent::query()->count())->toBe(0);
})->with([
    'huge conversion value' => [['type' => 'custom', 'event_name' => 'order', 'metadata' => ['conversion_value' => 1e12, 'conversion_currency' => 'GBP']]],
    'negative conversion value' => [['type' => 'custom', 'event_name' => 'order', 'metadata' => ['conversion_value' => -5, 'conversion_currency' => 'GBP']]],
    'huge coordinate' => [['viewport_x' => 2_000_000_000]],
]);

it('records a resent beacon only once', function (): void {
    $visit = integrityOutsideVisit();
    $payload = [
        'visit_id' => $visit->uuid,
        'beacon_id' => (string) Str::uuid(),
        'events' => [integrityPageView(), integrityPageView('https://example.test/about')],
    ];

    $this->withCredentials()->withCookie(RecordInsightsEventsAction::VISIT_COOKIE, $visit->uuid)->postJson(route('capell-insights.events'), $payload)->assertNoContent();
    $this->withCredentials()->withCookie(RecordInsightsEventsAction::VISIT_COOKIE, $visit->uuid)->postJson(route('capell-insights.events'), $payload)->assertNoContent();

    expect(InsightsEvent::query()->where('visit_id', $visit->getKey())->count())->toBe(2);
});

it('records a retried queued beacon only once', function (): void {
    $visit = integrityOutsideVisit();
    $beaconId = (string) Str::uuid();
    $record = fn () => RecordInsightsEventsAction::run(
        visitUuid: $visit->uuid,
        events: [['data' => InsightsEventData::from(integrityPageView()), 'occurred_at' => null]],
        beaconId: $beaconId,
    );

    expect($record())->toHaveCount(1)
        ->and($record())->toHaveCount(0)
        ->and(DB::table((new InsightsEvent)->getTable())->where('visit_id', $visit->getKey())->count())->toBe(1);
});

it('does not record crawlers or speculative loads', function (array $headers): void {
    $visit = integrityOutsideVisit();

    $this->withHeaders($headers)
        ->withCredentials()->withCookie(RecordInsightsEventsAction::VISIT_COOKIE, $visit->uuid)
        ->postJson(route('capell-insights.events'), [
            'visit_id' => $visit->uuid,
            'events' => [integrityPageView()],
        ]);

    expect(InsightsEvent::query()->count())->toBe(0);
})->with([
    'link preview fetcher' => [['User-Agent' => 'facebookexternalhit/1.1']],
    'ai fetcher' => [['User-Agent' => 'Mozilla/5.0 (compatible; GPTBot/1.0)']],
    'scripted client' => [['User-Agent' => 'python-requests/2.31']],
    'prefetch' => [['Sec-Purpose' => 'prefetch']],
    'prerender' => [['Sec-Purpose' => 'prefetch;prerender']],
]);
