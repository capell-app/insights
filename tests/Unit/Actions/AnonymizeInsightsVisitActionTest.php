<?php

declare(strict_types=1);

use Capell\Insights\Data\InsightsConsentData;
use Capell\Insights\Data\InsightsEventMetadataData;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Enums\InsightsConsentStatus;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Models\InsightsConsent;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Capell\PrivacyCenter\Actions\AnonymizePrivacySubjectAction;

it('registers insights visit anonymization with the privacy center eraser registry', function (): void {
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

    $affectedRecords = AnonymizePrivacySubjectAction::run($visit);
    $anonymizedVisit = $visit->refresh();
    $consent = InsightsConsent::query()->where('visit_id', $visit->getKey())->firstOrFail();
    $event = InsightsEvent::query()->firstOrFail();

    expect($affectedRecords)->toBe(1)
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
