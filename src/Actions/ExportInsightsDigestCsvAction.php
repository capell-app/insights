<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsDigestData;
use Capell\Insights\Data\InsightsWindowData;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * @method static string run(InsightsWindowData $window, list<string> $funnelSteps = [], int $limit = 10)
 */
final class ExportInsightsDigestCsvAction
{
    use AsAction;

    /**
     * @param  list<string>  $funnelSteps
     */
    public function handle(InsightsWindowData $window, array $funnelSteps = [], int $limit = 10): string
    {
        $digest = BuildInsightsDigestAction::run($window, $funnelSteps, $limit);

        return $this->toCsv([
            ['section', 'label', 'value', 'visits', 'clicks', 'conversion_rate', 'extra'],
            ...$this->overviewRows($digest),
            ...$this->popularPageRows($digest),
            ...$this->acquisitionRows($digest),
            ...$this->funnelRows($digest),
        ]);
    }

    /**
     * @return list<list<string>>
     */
    private function overviewRows(InsightsDigestData $digest): array
    {
        return array_map(
            static fn (array $stat): array => [
                'overview',
                (string) ($stat['label'] ?? $stat['id'] ?? ''),
                (string) ($stat['value'] ?? 0),
                '',
                '',
                '',
                (string) ($stat['id'] ?? ''),
            ],
            $digest->overviewStats,
        );
    }

    /**
     * @return list<list<string>>
     */
    private function popularPageRows(InsightsDigestData $digest): array
    {
        return array_map(
            static fn (array $page): array => [
                'popular_page',
                (string) ($page['path'] ?? ''),
                (string) ($page['page_views'] ?? 0),
                (string) ($page['unique_visits'] ?? 0),
                (string) ($page['clicks'] ?? 0),
                '',
                (string) ($page['url'] ?? ''),
            ],
            $digest->popularPages,
        );
    }

    /**
     * @return list<list<string>>
     */
    private function acquisitionRows(InsightsDigestData $digest): array
    {
        return array_map(
            static fn (array $source): array => [
                'acquisition',
                (string) ($source['source'] ?? ''),
                '',
                (string) ($source['visits'] ?? 0),
                '',
                '',
                implode(' | ', [
                    (string) ($source['medium'] ?? ''),
                    (string) ($source['campaign'] ?? ''),
                    (string) ($source['referrer'] ?? ''),
                ]),
            ],
            $digest->acquisitionSources,
        );
    }

    /**
     * @return list<list<string>>
     */
    private function funnelRows(InsightsDigestData $digest): array
    {
        return array_map(
            static fn (array $step): array => [
                'funnel',
                (string) ($step['name'] ?? ''),
                (string) ($step['visitors'] ?? 0),
                (string) ($step['visitors'] ?? 0),
                '',
                (string) ($step['conversion_rate'] ?? 0.0),
                (string) ($digest->funnel['name'] ?? ''),
            ],
            $digest->funnel['steps'] ?? [],
        );
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function toCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'r+');

        throw_if($stream === false, RuntimeException::class, 'Unable to open temporary CSV stream.');

        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }

        rewind($stream);

        $csv = stream_get_contents($stream);
        fclose($stream);

        throw_if($csv === false, RuntimeException::class, 'Unable to read temporary CSV stream.');

        return $csv;
    }
}
