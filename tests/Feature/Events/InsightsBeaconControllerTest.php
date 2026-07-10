<?php

declare(strict_types=1);

use Capell\Insights\Data\InsightsEventMetadataData;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Enums\InsightsConsentStatus;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Jobs\ProcessInsightsBeaconJob;
use Capell\Insights\Models\InsightsConsent;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

it('does not store a uk or europe event without insights consent', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::UkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this->postJson(route('capell-insights.events'), pageViewPayload($visit))
        ->assertNoContent();

    expect(InsightsEvent::query()->count())->toBe(0);
});

it('does not store events after uk or europe insights consent is revoked', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::UkOrEurope,
        'consent_status' => InsightsConsentStatus::RejectedNonEssential,
    ]);

    InsightsConsent::factory()->create([
        'visit_id' => $visit->getKey(),
        'status' => InsightsConsentStatus::Granular,
        'categories' => [
            'essential' => true,
            'insights' => true,
            'marketing' => false,
            'preferences' => false,
        ],
        'decided_at' => now()->subMinute()->toImmutable(),
    ]);

    InsightsConsent::factory()->create([
        'visit_id' => $visit->getKey(),
        'status' => InsightsConsentStatus::RejectedNonEssential,
        'categories' => [
            'essential' => true,
            'insights' => false,
            'marketing' => false,
            'preferences' => false,
        ],
        'decided_at' => now()->toImmutable(),
    ]);

    $this->postJson(route('capell-insights.events'), [
        'visit_id' => $visit->uuid,
        'events' => [
            pageViewEvent(),
            clickEvent(),
        ],
    ])->assertNoContent();

    expect(InsightsEvent::query()->count())->toBe(0);
});

it('stores a page view after insights consent is granted', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::UkOrEurope,
        'consent_status' => InsightsConsentStatus::AcceptedAll,
    ]);

    $this->postJson(route('capell-insights.events'), pageViewPayload($visit))
        ->assertNoContent();

    $event = InsightsEvent::query()->firstOrFail();

    expect($event->visit_id)->toBe($visit->getKey())
        ->and($event->type)->toBe(InsightsEventType::PageView)
        ->and($event->url)->toBe('https://example.test/')
        ->and($event->path)->toBe('/')
        ->and($event->sequence)->toBe(1);
});

it('rejects beacon posts from invalid origins', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this
        ->withHeader('Origin', 'https://evil.example')
        ->postJson(route('capell-insights.events'), pageViewPayload($visit))
        ->assertForbidden();

    expect(InsightsEvent::query()->count())->toBe(0);
});

it('allows configured beacon origins when referer contains a path', function (): void {
    config()->set('capell-insights.allowed_beacon_origins', ['https://trusted.example']);

    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this
        ->withHeader('Referer', 'https://trusted.example/page')
        ->postJson(route('capell-insights.events'), pageViewPayload($visit))
        ->assertNoContent();

    expect(InsightsEvent::query()->count())->toBe(1);
});

it('can require signed beacon urls for event posts', function (): void {
    config()->set('capell-insights.require_signed_beacons', true);

    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this->postJson(route('capell-insights.events'), pageViewPayload($visit))
        ->assertForbidden();

    $this->postJson(
        URL::temporarySignedRoute('capell-insights.events', now()->addMinute()),
        pageViewPayload($visit),
    )->assertNoContent();

    expect(InsightsEvent::query()->count())->toBe(1);
});

it('honors browser privacy signals before validating or recording events', function (string $header): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this
        ->withHeader($header, '1')
        ->postJson(route('capell-insights.events'), pageViewPayload($visit))
        ->assertNoContent();

    expect(InsightsEvent::query()->count())->toBe(0);
})->with([
    'Sec-GPC',
    'DNT',
    'X-Do-Not-Track',
]);

it('can opt out of privacy-signal beacon suppression', function (): void {
    config()->set('capell-insights.honor_privacy_signals', false);

    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this
        ->withHeader('Sec-GPC', '1')
        ->postJson(route('capell-insights.events'), pageViewPayload($visit))
        ->assertNoContent();

    expect(InsightsEvent::query()->count())->toBe(1);
});

it('stores a mixed event batch with one visit lookup and sequential events', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this->postJson(route('capell-insights.events'), [
        'visit_id' => $visit->uuid,
        'events' => [
            pageViewEvent(['url' => 'https://example.test/first']),
            clickEvent(['url' => 'https://example.test/first']),
            pageViewEvent(['url' => 'https://example.test/admin/pages']),
            pageViewEvent(['url' => 'https://example.test/asset.css']),
        ],
    ])->assertNoContent();

    $events = InsightsEvent::query()->orderBy('sequence')->get();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('sequence')->all())->toBe([1, 2])
        ->and($events->pluck('path')->all())->toBe(['/first', '/first'])
        ->and($visit->refresh()->last_seen_at)->not->toBeNull();
});

it('stores an outside-region page view with default settings', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this->postJson(route('capell-insights.events'), pageViewPayload($visit))
        ->assertNoContent();

    expect(InsightsEvent::query()->count())->toBe(1);
});

it('creates a visit for first outside-region event posts without an existing visit id', function (): void {
    config()->set('capell-insights.default_consent_region', InsightsConsentRegion::OutsideUkOrEurope->value);

    $this->postJson(route('capell-insights.events'), [
        'visit_id' => null,
        'events' => [
            pageViewEvent(),
        ],
    ])
        ->assertOk()
        ->assertJsonStructure(['visit_id'])
        ->assertCookie('capell_insights_visit')
        ->assertCookieMissing((string) config('session.cookie'));

    $visit = InsightsVisit::query()->firstOrFail();
    $event = InsightsEvent::query()->firstOrFail();

    expect($event->visit_id)->toBe($visit->getKey())
        ->and($visit->consent_region)->toBe(InsightsConsentRegion::OutsideUkOrEurope)
        ->and($visit->consent_status)->toBe(InsightsConsentStatus::Pending);
});

it('does not create a first visit when server region requires consent', function (): void {
    config()->set('capell-insights.default_consent_region', InsightsConsentRegion::UkOrEurope->value);

    $this->postJson(route('capell-insights.events'), [
        'visit_id' => null,
        'events' => [
            pageViewEvent(),
        ],
    ])->assertNoContent();

    expect(InsightsVisit::query()->count())->toBe(0)
        ->and(InsightsEvent::query()->count())->toBe(0);
});

it('stores click location fields', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this->postJson(route('capell-insights.events'), clickPayload($visit))
        ->assertNoContent();

    $event = InsightsEvent::query()->firstOrFail();
    $metadata = $event->metadata;

    throw_unless($metadata instanceof InsightsEventMetadataData, RuntimeException::class, 'Expected click event metadata to be stored.');

    expect($event->type)->toBe(InsightsEventType::Click)
        ->and($event->event_name)->toBe('cta_click')
        ->and($event->label)->toBe('Book a demo')
        ->and($event->location)->toBe('home.hero')
        ->and($event->target_selector)->toBe('button[data-capell-insights]')
        ->and($event->viewport_x)->toBe(24)
        ->and($event->viewport_y)->toBe(50)
        ->and($event->document_x)->toBe(24)
        ->and($event->document_y)->toBe(650)
        ->and($metadata->nearestLandmark)->toBe('main');
});

it('skips events on ignored paths', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this->postJson(route('capell-insights.events'), pageViewPayload($visit, [
        'url' => 'https://example.test/admin/pages',
    ]))->assertNoContent();

    expect(InsightsEvent::query()->count())->toBe(0);
});

it('skips admin livewire beacon and asset paths', function (string $url): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this->postJson(route('capell-insights.events'), pageViewPayload($visit, [
        'url' => $url,
    ]))->assertNoContent();

    expect(InsightsEvent::query()->count())->toBe(0);
})->with([
    'https://example.test/admin/login',
    'https://example.test/livewire/update',
    'https://example.test/capell/insights/events',
    'https://example.test/app.js',
]);

it('returns unprocessable for invalid event type', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this->postJson(route('capell-insights.events'), pageViewPayload($visit, [
        'type' => 'not-real',
    ]))->assertUnprocessable();

    expect(InsightsEvent::query()->count())->toBe(0);
});

it('returns unprocessable for overlong urls before persistence', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this->postJson(route('capell-insights.events'), pageViewPayload($visit, [
        'url' => 'https://example.test/' . str_repeat('a', 512),
    ]))->assertUnprocessable();

    expect(InsightsEvent::query()->count())->toBe(0);
});

it('returns unprocessable for arbitrary nested metadata', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this->postJson(route('capell-insights.events'), clickPayload($visit, [
        'metadata' => [
            'nearest_landmark' => 'main',
            'attributes' => [
                'nested' => true,
            ],
        ],
    ]))->assertUnprocessable();

    expect(InsightsEvent::query()->count())->toBe(0);
});

it('returns no content for successful beacon posts', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this->postJson(route('capell-insights.events'), pageViewPayload($visit))
        ->assertStatus(204)
        ->assertNoContent();
});

it('does not require a csrf token for beacon posts', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this->post(route('capell-insights.events'), pageViewPayload($visit))
        ->assertStatus(204)
        ->assertNoContent();
});

it('queues event batches for established visits', function (): void {
    config()->set('capell-insights.ingest.queue_enabled', true);
    Queue::fake();

    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this->postJson(route('capell-insights.events'), pageViewPayload($visit))
        ->assertNoContent();

    expect(InsightsEvent::query()->count())->toBe(0);

    $queuedJob = null;

    Queue::assertPushed(ProcessInsightsBeaconJob::class, function (ProcessInsightsBeaconJob $job) use (&$queuedJob, $visit): bool {
        $queuedJob = $job;

        return $job->data->visitUuid === $visit->uuid;
    });

    expect($queuedJob)->toBeInstanceOf(ProcessInsightsBeaconJob::class);

    $queuedJob->handle();

    expect(InsightsEvent::query()->count())->toBe(1);
});

it('rotates expired visits synchronously before queueing later batches', function (): void {
    config()->set('capell-insights.ingest.queue_enabled', true);
    config()->set('capell-insights.session_timeout_minutes', 30);
    Queue::fake();

    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
        'last_seen_at' => now()->subHour()->toImmutable(),
    ]);

    $response = $this->postJson(route('capell-insights.events'), pageViewPayload($visit));

    $response
        ->assertOk()
        ->assertJsonStructure(['visit_id'])
        ->assertCookie('capell_insights_visit');

    Queue::assertNothingPushed();

    $nextVisit = InsightsVisit::query()->where('uuid', $response->json('visit_id'))->firstOrFail();

    expect($nextVisit->uuid)->not->toBe($visit->uuid)
        ->and(InsightsEvent::query()->firstOrFail()->visit_id)->toBe($nextVisit->getKey());
});

it('can sample event ingestion before persistence or queue dispatch', function (): void {
    config()->set('capell-insights.ingest.queue_enabled', true);
    config()->set('capell-insights.ingest.sample_rate', 0.0);
    Queue::fake();

    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this->postJson(route('capell-insights.events'), pageViewPayload($visit))
        ->assertNoContent();

    Queue::assertNothingPushed();
    expect(InsightsEvent::query()->count())->toBe(0);
});

it('clamps client event timestamps to the configured ingestion window', function (): void {
    $now = CarbonImmutable::parse('2026-07-10 12:00:00');

    $this->travelTo($now);
    config()->set('capell-insights.ingest.max_past_minutes', 60);
    config()->set('capell-insights.ingest.max_future_minutes', 5);

    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'consent_status' => InsightsConsentStatus::Pending,
    ]);

    $this->postJson(route('capell-insights.events'), [
        'visit_id' => $visit->uuid,
        'events' => [
            pageViewEvent(['occurred_at' => $now->subDay()->toIso8601String()]),
            clickEvent(['occurred_at' => $now->addDay()->toIso8601String()]),
        ],
    ])->assertNoContent();

    $occurredAt = InsightsEvent::query()->orderBy('sequence')->pluck('occurred_at');

    expect(CarbonImmutable::parse((string) $occurredAt[0])->equalTo($now->subHour()))->toBeTrue()
        ->and(CarbonImmutable::parse((string) $occurredAt[1])->equalTo($now->addMinutes(5)))->toBeTrue();
});

/**
 * @param  array<string, mixed>  $eventOverrides
 * @return array<string, mixed>
 */
function pageViewPayload(InsightsVisit $visit, array $eventOverrides = []): array
{
    return [
        'visit_id' => $visit->uuid,
        'events' => [
            pageViewEvent($eventOverrides),
        ],
    ];
}

/**
 * @param  array<string, mixed>  $eventOverrides
 * @return array<string, mixed>
 */
function clickPayload(InsightsVisit $visit, array $eventOverrides = []): array
{
    return [
        'visit_id' => $visit->uuid,
        'events' => [
            clickEvent($eventOverrides),
        ],
    ];
}

/**
 * @param  array<string, mixed>  $eventOverrides
 * @return array<string, mixed>
 */
function pageViewEvent(array $eventOverrides = []): array
{
    return array_merge([
        'type' => InsightsEventType::PageView->value,
        'url' => 'https://example.test/',
        'title' => 'Home',
        'occurred_at' => now()->toIso8601String(),
    ], $eventOverrides);
}

/**
 * @param  array<string, mixed>  $eventOverrides
 * @return array<string, mixed>
 */
function clickEvent(array $eventOverrides = []): array
{
    return array_merge([
        'type' => 'click',
        'url' => 'https://example.test/',
        'title' => 'Home',
        'occurred_at' => now()->toIso8601String(),
        'event_name' => 'cta_click',
        'label' => 'Book a demo',
        'location' => 'home.hero',
        'target_selector' => 'button[data-capell-insights]',
        'viewport_x' => 24,
        'viewport_y' => 50,
        'document_x' => 24,
        'document_y' => 650,
        'metadata' => ['nearest_landmark' => 'main'],
    ], $eventOverrides);
}
