<?php

declare(strict_types=1);

use Capell\Insights\Actions\BuildFunnelConversionReportAction;
use Capell\Insights\Actions\RecordConversionAction;
use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Carbon\CarbonImmutable;

it('records server-side conversion events with package metadata', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
    ]);

    $event = RecordConversionAction::run(
        visitUuid: $visit->uuid,
        eventName: 'campaign.lead',
        url: 'https://example.test/pricing',
        label: 'Pricing lead',
        sourcePackage: 'capell-app/campaign-studio',
        value: 250.0,
        currency: 'GBP',
    );

    $metadata = $event?->metadata;

    expect($event)->toBeInstanceOf(InsightsEvent::class)
        ->and($event?->event_name)->toBe('campaign.lead')
        ->and($metadata?->sourcePackage)->toBe('capell-app/campaign-studio')
        ->and($metadata?->conversionValue)->toBe(250.0)
        ->and($metadata?->conversionCurrency)->toBe('GBP');
});

it('builds funnel reports from named conversion events', function (): void {
    $firstVisit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
    ]);
    $secondVisit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
    ]);
    $window = new InsightsWindowData(
        startsAt: CarbonImmutable::parse('2026-06-01 00:00:00'),
        endsAt: CarbonImmutable::parse('2026-06-30 23:59:59'),
    );

    RecordConversionAction::run($firstVisit->uuid, 'landing.viewed', 'https://example.test/');
    RecordConversionAction::run($firstVisit->uuid, 'lead.submitted', 'https://example.test/contact');
    RecordConversionAction::run($secondVisit->uuid, 'landing.viewed', 'https://example.test/');

    $report = BuildFunnelConversionReportAction::run($window, [
        'landing.viewed',
        'lead.submitted',
    ], 'Lead funnel');

    expect($report['name'])->toBe('Lead funnel')
        ->and($report['visitors'])->toBe(2)
        ->and($report['steps'])->toBe([
            [
                'name' => 'landing.viewed',
                'visitors' => 2,
                'conversion_rate' => 100.0,
            ],
            [
                'name' => 'lead.submitted',
                'visitors' => 1,
                'conversion_rate' => 50.0,
            ],
        ]);
});
