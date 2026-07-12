<?php

declare(strict_types=1);

namespace Capell\Insights\Console\Commands;

use Capell\Insights\Actions\RebuildInsightsDailyRollupsAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class RebuildInsightsDailyRollupsCommand extends Command
{
    protected $signature = 'insights:rollups:rebuild {--from= : Start date, inclusive} {--to= : End date, inclusive}';

    protected $description = 'Rebuild daily Insights aggregate rollups.';

    public function handle(): int
    {
        $resolvedStartsAt = $this->resolveDateOption('from');
        $resolvedEndsAt = $this->resolveDateOption('to');

        if ($resolvedStartsAt === false || $resolvedEndsAt === false) {
            return self::FAILURE;
        }

        $startsAt = $resolvedStartsAt?->startOfDay();
        $endsAt = $resolvedEndsAt?->endOfDay();

        $rollups = RebuildInsightsDailyRollupsAction::run($startsAt, $endsAt);

        $this->info(sprintf('Rebuilt %s insights daily rollups.', $rollups));

        return self::SUCCESS;
    }

    private function resolveDateOption(string $option): CarbonImmutable|false|null
    {
        $value = $this->option($option);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            $this->error(sprintf('The --%s option must be a valid date.', $option));

            return false;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            $this->error(sprintf('The --%s option must be a valid date.', $option));

            return false;
        }
    }
}
