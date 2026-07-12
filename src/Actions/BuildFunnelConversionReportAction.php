<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Enums\InsightsEventType;
use Capell\Insights\Models\InsightsEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static array{name: string, visitors: int, steps: list<array{name: string, visitors: int, conversion_rate: float}>} run(InsightsWindowData $window, list<string> $steps, string $name = 'default')
 */
final class BuildFunnelConversionReportAction
{
    use AsAction;

    /**
     * @param  list<string>  $steps
     * @return array{name: string, visitors: int, steps: list<array{name: string, visitors: int, conversion_rate: float}>}
     */
    public function handle(InsightsWindowData $window, array $steps, string $name = 'default'): array
    {
        $normalizedSteps = $this->normalizedSteps($steps);

        if ($normalizedSteps === []) {
            return [
                'name' => $name,
                'visitors' => 0,
                'steps' => [],
            ];
        }

        $visitorsByStep = $this->visitorsByStep($window, $normalizedSteps);
        $baselineVisitors = $visitorsByStep[$normalizedSteps[0]] ?? 0;

        return [
            'name' => $name,
            'visitors' => $baselineVisitors,
            'steps' => array_map(
                fn (string $step): array => [
                    'name' => $step,
                    'visitors' => $visitorsByStep[$step] ?? 0,
                    'conversion_rate' => $this->conversionRate($visitorsByStep[$step] ?? 0, $baselineVisitors),
                ],
                $normalizedSteps,
            ),
        ];
    }

    /**
     * @param  list<string>  $steps
     * @return list<string>
     */
    private function normalizedSteps(array $steps): array
    {
        $normalizedSteps = [];

        foreach ($steps as $step) {
            $normalizedStep = trim($step);

            if ($normalizedStep === '' || in_array($normalizedStep, $normalizedSteps, true)) {
                continue;
            }

            $normalizedSteps[] = $normalizedStep;
        }

        return $normalizedSteps;
    }

    /**
     * @param  list<string>  $steps
     * @return array<string, int>
     */
    private function visitorsByStep(InsightsWindowData $window, array $steps): array
    {
        /** @var Collection<string, int> $visitors */
        $visitors = InsightsEvent::query()
            ->select([
                'event_name',
                DB::raw('COUNT(DISTINCT visit_id) as visitors'),
            ])
            ->where('type', InsightsEventType::Custom)
            ->whereIn('event_name', $steps)
            ->whereNotNull('visit_id')
            ->whereBetween('occurred_at', [$window->startsAt, $window->endsAt])
            ->when($window->siteId !== null, fn (Builder $builder): Builder => $builder->where('site_id', $window->siteId))
            ->when($window->languageId !== null, fn (Builder $builder): Builder => $builder->where('language_id', $window->languageId))
            ->groupBy('event_name')
            ->pluck('visitors', 'event_name');

        return $visitors
            ->mapWithKeys(fn (mixed $visitors, string $step): array => [$step => (int) $visitors])
            ->all();
    }

    private function conversionRate(int $visitors, int $baselineVisitors): float
    {
        if ($baselineVisitors < 1) {
            return 0.0;
        }

        return round(($visitors / $baselineVisitors) * 100, 1);
    }
}
