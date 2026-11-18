<?php

declare(strict_types=1);

use Capell\Core\Data\Privacy\PrivacySubject;
use Capell\Core\Enums\PrivacyErasureMode;
use Capell\Core\Support\Privacy\PrivacySubjectRegistry;
use Capell\Insights\Actions\BuildInsightsVisitPrivacyExportAction;
use Capell\Insights\Data\InsightsConsentData;
use Capell\Insights\Data\InsightsEventMetadataData;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Enums\InsightsConsentStatus;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Models\InsightsConsent;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

it('registers insights visit anonymization with the Core privacy registry', function (): void {
    $visit = InsightsVisit::factory()->create([
        'uuid' => '11111111-1111-4111-8111-111111111111',
        'landing_url' => 'https://example.test/private?email=reader@example.test',
        'referrer_url' => 'https://referrer.test/source?token=secret',
        'utm_source' => 'newsletter',
        'utm_medium' => 'email',
        'utm_campaign' => 'private-campaign',
        'ip_hash' => 'ip-hash',
        'user_agent_hash' => 'ua-hash',
        'legacy_session_id' => 'legacy-session',
    ]);
    InsightsConsent::factory()->create([
        'visit_id' => $visit->getKey(),
        'consent_region' => InsightsConsentRegion::UkOrEurope,
        'status' => InsightsConsentStatus::Granular,
        'categories' => new InsightsConsentData(insights: true),
        'ip_hash' => 'consent-ip-hash',
        'user_agent_hash' => 'consent-ua-hash',
    ]);
    InsightsEvent::factory()->create([
        'visit_id' => $visit->getKey(),
        'type' => InsightsEventType::Click,
        'url' => 'https://example.test/private?email=reader@example.test',
        'path' => '/private?email=reader@example.test',
        'title' => 'Private page',
        'label' => 'Sensitive label',
        'location' => 'Private section',
        'target_selector' => '#reader-email',
        'viewport_x' => 10,
        'viewport_y' => 20,
        'document_x' => 30,
        'document_y' => 40,
        'metadata' => new InsightsEventMetadataData(nearestLandmark: 'reader@example.test'),
    ]);

    $result = resolve(PrivacySubjectRegistry::class)->erase(PrivacySubject::forModel($visit), PrivacyErasureMode::Delete);
    $anonymizedVisit = $visit->refresh();
    $consent = InsightsConsent::query()->where('visit_id', $visit->getKey())->firstOrFail();
    $event = InsightsEvent::query()->firstOrFail();

    expect($result->failures)->toBe([])
        ->and($result->receipts)->toHaveCount(1)
        ->and($result->receipts[0]->contributor)->toBe('insights')
        ->and($result->receipts[0]->mode)->toBe(PrivacyErasureMode::Anonymize)
        ->and($result->receipts[0]->affectedRecords)->toBe(1)
        ->and($anonymizedVisit->uuid)->not->toBe('11111111-1111-4111-8111-111111111111')
        ->and($anonymizedVisit->landing_url)->toBe('/erased-insights-visit')
        ->and($anonymizedVisit->referrer_url)->toBeNull()
        ->and($anonymizedVisit->utm_source)->toBeNull()
        ->and($anonymizedVisit->utm_medium)->toBeNull()
        ->and($anonymizedVisit->utm_campaign)->toBeNull()
        ->and($anonymizedVisit->ip_hash)->toBeNull()
        ->and($anonymizedVisit->user_agent_hash)->toBeNull()
        ->and($anonymizedVisit->legacy_session_id)->toBeNull()
        ->and($consent->ip_hash)->toBeNull()
        ->and($consent->user_agent_hash)->toBeNull()
        ->and($event->visit_id)->toBeNull()
        ->and($event->url)->toBe('/erased-insights-event')
        ->and($event->path)->toBe('/erased-insights-event')
        ->and($event->title)->toBeNull()
        ->and($event->label)->toBeNull()
        ->and($event->location)->toBeNull()
        ->and($event->target_selector)->toBeNull()
        ->and($event->metadata)->toBeNull();
});

it('registers a visit export with the Core privacy registry that omits the keyed hashes', function (): void {
    $visit = InsightsVisit::factory()->create([
        'landing_url' => 'https://example.test/landing',
        'utm_source' => 'newsletter',
        'ip_hash' => 'secret-ip-hash',
        'user_agent_hash' => 'secret-ua-hash',
        'visitor_hash' => str_repeat('a', 64),
    ]);
    InsightsConsent::factory()->create([
        'visit_id' => $visit->getKey(),
        'categories' => new InsightsConsentData(insights: true),
        'ip_hash' => 'consent-ip-hash',
    ]);
    InsightsEvent::factory()->create(['visit_id' => $visit->getKey(), 'type' => InsightsEventType::Click, 'url' => 'https://example.test/landing', 'label' => 'Buy']);

    $registry = resolve(PrivacySubjectRegistry::class);
    $export = $registry->export(PrivacySubject::forModel($visit))['insights']->data;

    expect(data_get($export, 'visit.landing_url'))->toBe('https://example.test/landing')
        ->and(data_get($export, 'visit.utm_source'))->toBe('newsletter')
        ->and($export['consents'])->toHaveCount(1)
        ->and(data_get($export, 'events.0.label'))->toBe('Buy')
        ->and(json_encode($export))->not->toContain('secret-ip-hash')->not->toContain('secret-ua-hash')->not->toContain('consent-ip-hash')->not->toContain(str_repeat('a', 64))
        ->and($registry->export(PrivacySubject::forModel(InsightsEvent::query()->firstOrFail())))->not->toHaveKey('insights');
});

it('exports every field the erasure clears, apart from the keyed hashes, the raw legacy session id and internal links', function (): void {
    $visit = InsightsVisit::factory()->create([
        'landing_url' => 'https://example.test/a?x=1',
        'referrer_url' => 'https://referrer.test/',
        'utm_source' => 'newsletter',
        'utm_medium' => 'email',
        'utm_campaign' => 'spring',
        'ip_hash' => 'ip',
        'user_agent_hash' => 'ua',
        'visitor_hash' => str_repeat('b', 64),
        'legacy_session_id' => 'legacy-session',
    ]);
    InsightsConsent::factory()->create(['visit_id' => $visit->getKey(), 'ip_hash' => 'c-ip', 'user_agent_hash' => 'c-ua']);
    InsightsEvent::factory()->create([
        'visit_id' => $visit->getKey(),
        'type' => InsightsEventType::Click,
        'url' => 'https://example.test/a',
        'path' => '/a',
        'title' => 'A page',
        'event_name' => 'cta',
        'label' => 'Buy',
        'location' => 'Hero',
        'target_selector' => '#buy',
        'viewport_x' => 1,
        'viewport_y' => 2,
        'document_x' => 3,
        'document_y' => 4,
        'metadata' => new InsightsEventMetadataData(nearestLandmark: 'main'),
    ]);

    // Every nullable column must hold a value in the fixtures, so a column added later that the
    // erasure clears cannot be missed here simply because the fixture left it empty.
    $columnsByTable = [];
    $unfilled = static function (Model $model, array $structural) use (&$columnsByTable): array {
        $columnsByTable[$model->getTable()] ??= Schema::getColumns($model->getTable());

        return array_values(array_filter(
            array_map(
                static fn (array $column): string => $column['nullable'] && $model->getAttribute($column['name']) === null ? $column['name'] : '',
                $columnsByTable[$model->getTable()],
            ),
            static fn (string $name): bool => $name !== '' && ! in_array($name, $structural, true),
        ));
    };
    $eventForGuard = InsightsEvent::query()->firstOrFail();

    expect($unfilled($visit->refresh(), ['site_id', 'language_id', 'last_seen_at', 'sample_weight']))->toBe([])
        ->and($unfilled($eventForGuard, ['site_id', 'language_id', 'legacy_page_view_id', 'dedupe_key']))->toBe([]);

    $registry = resolve(PrivacySubjectRegistry::class);
    $export = $registry->export(PrivacySubject::forModel($visit))['insights']->data;
    $visitBefore = $visit->getAttributes();
    $eventBefore = InsightsEvent::query()->firstOrFail()->getAttributes();

    $registry->erase(PrivacySubject::forModel($visit), PrivacyErasureMode::Delete);

    $changed = static fn (array $before, array $after): array => array_keys(array_filter(
        $before,
        static fn (mixed $value, string $key): bool => $value !== ($after[$key] ?? null),
        ARRAY_FILTER_USE_BOTH,
    ));
    // Deliberately never exported. Visit: the keyed hashes, the legacy session id (the raw session
    // identifier copied from the old page view table, so possibly a live token), the rewritten uuid and
    // the update stamp. Event: its link to the visit and the update stamp.
    $visitNotExported = ['ip_hash', 'user_agent_hash', 'visitor_hash', 'legacy_session_id', 'uuid', 'updated_at'];
    $eventNotExported = ['visit_id', 'updated_at'];
    $erasedVisitFields = array_values(array_diff($changed($visitBefore, $visit->refresh()->getAttributes()), $visitNotExported));
    $erasedEventFields = array_values(array_diff($changed($eventBefore, InsightsEvent::query()->firstOrFail()->getAttributes()), $eventNotExported));

    expect(array_diff($erasedVisitFields, array_keys((array) data_get($export, 'visit'))))->toBe([])
        ->and(array_diff($erasedEventFields, array_keys((array) data_get($export, 'events.0'))))->toBe([])
        ->and(data_get($export, 'visit'))->not->toHaveKey('legacy_session_id')
        ->and($erasedEventFields)->toContain('path', 'location', 'metadata', 'viewport_x', 'document_y', 'target_selector');
});

it('discloses legacy session presence without exporting the session token', function (): void {
    $visit = InsightsVisit::factory()->create(['legacy_session_id' => 'private-live-session']);
    $export = BuildInsightsVisitPrivacyExportAction::run($visit);
    expect(data_get($export, 'visit.legacy_session_id_present'))->toBeTrue()
        ->and(json_encode($export))->not->toContain('private-live-session');
    $visit->forceFill(['legacy_session_id' => null])->save();
    expect(data_get(BuildInsightsVisitPrivacyExportAction::run($visit), 'visit.legacy_session_id_present'))->toBeFalse();
});
