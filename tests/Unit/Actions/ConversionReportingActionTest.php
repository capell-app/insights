<?php

declare(strict_types=1);

use Capell\Insights\Actions\BuildFunnelConversionReportAction;
use Capell\Insights\Actions\BuildInsightsDigestAction;
use Capell\Insights\Actions\ExportInsightsDigestCsvAction;
use Capell\Insights\Actions\RecordConversionAction;
use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Enums\InsightsEventType;
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

it('builds insights digest data and exports it as CSV', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'utm_source' => 'newsletter',
        'utm_medium' => 'email',
        'utm_campaign' => 'spring',
        'started_at' => CarbonImmutable::parse('2026-06-08 09:00:00'),
        'last_seen_at' => CarbonImmutable::parse('2026-06-08 09:10:00'),
    ]);
    $window = new InsightsWindowData(
        startsAt: CarbonImmutable::parse('2026-06-08 00:00:00'),
        endsAt: CarbonImmutable::parse('2026-06-08 23:59:59'),
    );

    InsightsEvent::factory()->for($visit, 'visit')->create([
        'type' => InsightsEventType::PageView,
        'url' => 'https://example.test/pricing',
        'path' => '/pricing',
        'occurred_at' => CarbonImmutable::parse('2026-06-08 09:01:00'),
    ]);
    InsightsEvent::factory()->for($visit, 'visit')->create([
        'type' => InsightsEventType::Click,
        'url' => 'https://example.test/pricing',
        'path' => '/pricing',
        'occurred_at' => CarbonImmutable::parse('2026-06-08 09:02:00'),
    ]);
    RecordConversionAction::run($visit->uuid, 'campaign.spring.signup', 'https://example.test/pricing');

    $digest = BuildInsightsDigestAction::run($window, ['campaign.spring.signup'], 5);
    $csvRows = insightsDigestCsvRows(ExportInsightsDigestCsvAction::run($window, ['campaign.spring.signup'], 5));

    expect($digest->overviewStats)->toHaveCount(3)
        ->and($digest->popularPages[0]['path'])->toBe('/pricing')
        ->and($digest->acquisitionSources[0]['source'])->toBe('newsletter')
        ->and($digest->funnel['steps'][0]['visitors'])->toBe(1)
        ->and($csvRows[0])->toBe(['section', 'label', 'value', 'visits', 'clicks', 'conversion_rate', 'extra'])
        ->and($csvRows)->toContain(['popular_page', '/pricing', '1', '1', '1', '', 'https://example.test/pricing'])
        ->and($csvRows)->toContain(['funnel', 'campaign.spring.signup', '1', '1', '', '100', 'digest']);
});

/**
 * @return list<list<string>>
 */
function insightsDigestCsvRows(string $csv): array
{
    $rows = [];

    foreach (explode("\n", trim($csv)) as $row) {
        $fields = [];

        foreach (str_getcsv($row) as $field) {
            $fields[] = $field ?? '';
        }

        $rows[] = $fields;
    }

    return $rows;
}
