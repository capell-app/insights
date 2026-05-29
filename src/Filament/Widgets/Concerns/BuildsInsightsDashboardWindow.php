<?php

declare(strict_types=1);

namespace Capell\Insights\Filament\Widgets\Concerns;

use Capell\Admin\Filament\Concerns\HasDashboardDateRange;
use Capell\Insights\Data\InsightsWindowData;
use Spatie\Permission\PermissionRegistrar;

trait BuildsInsightsDashboardWindow
{
    use HasDashboardDateRange;

    private function getInsightsWindow(): InsightsWindowData
    {
        [$rangeStart, $rangeEnd] = $this->getDashboardDateRange();

        return new InsightsWindowData(
            startsAt: $rangeStart,
            endsAt: $rangeEnd,
            siteId: $this->currentDashboardSiteId(),
        );
    }

    private function currentDashboardSiteId(): ?int
    {
        $siteId = resolve(PermissionRegistrar::class)->getPermissionsTeamId();

        return is_numeric($siteId) ? (int) $siteId : null;
    }
}
