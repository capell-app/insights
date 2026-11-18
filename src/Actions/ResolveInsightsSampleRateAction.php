<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The configured share of visits Insights keeps, clamped to 0..1.
 *
 * @method static float run()
 */
final class ResolveInsightsSampleRateAction
{
    use AsFake;
    use AsObject;

    /**
     * How many real visits each kept visit stands for, so totals can be scaled back up.
     */
    public static function weight(): float
    {
        $rate = self::run();

        return $rate > 0.0 ? round(1 / $rate, 4) : 1.0;
    }

    public function handle(): float
    {
        $configuredRate = config('capell-insights.ingest.sample_rate', 1.0);

        return is_numeric($configuredRate) ? max(0.0, min(1.0, (float) $configuredRate)) : 1.0;
    }
}
