<?php

declare(strict_types=1);

use Capell\Insights\Actions\ExportInsightsDigestCsvAction;
use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Models\InsightsVisit;
use Carbon\CarbonImmutable;

it('encodes request acquisition sources at the CSV boundary', function (): void {
    $visit = InsightsVisit::factory()->create([
        'consent_region' => InsightsConsentRegion::OutsideUkOrEurope,
        'utm_source' => ' =1+1',
        'utm_medium' => '@email',
        'utm_campaign' => 'spring',
        'started_at' => CarbonImmutable::parse('2026-06-08 09:00:00'),
        'last_seen_at' => CarbonImmutable::parse('2026-06-08 09:10:00'),
    ]);
    $window = new InsightsWindowData(
        startsAt: CarbonImmutable::parse('2026-06-08 00:00:00'),
        endsAt: CarbonImmutable::parse('2026-06-08 23:59:59'),
    );
    $csv = ExportInsightsDigestCsvAction::run($window);
    $rows = array_map(static fn (string $line): array => str_getcsv($line, escape: ''), explode("\n", trim($csv)));
    $row = array_values(array_filter($rows, static fn (array $row): bool => $row[0] === 'acquisition'))[0];
    expect($row[1])->toBe("'=1+1")
        ->and($row[3])->toBe('1')
        ->and($row[6])->toStartWith("'@email")
        ->and($visit->refresh()->utm_source)->toBe(' =1+1');
});
