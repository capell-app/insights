<?php

declare(strict_types=1);

namespace Capell\Insights\Providers;

use Capell\Admin\Contracts\DashboardSettingsContributor;
use Capell\Admin\Data\MarketingStudioActionData;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Enums\MarketingStudioSectionEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Facades\CapellCore;
use Capell\Insights\Actions\BuildInsightsOverviewStatsAction;
use Capell\Insights\Console\Commands\PurgeInsightsDataCommand;
use Capell\Insights\Console\Commands\RebuildInsightsDailyRollupsCommand;
use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Filament\Pages\InsightsPage;
use Capell\Insights\Filament\Settings\Contributors\InsightsDashboardSettingsContributor;
use Capell\Insights\Filament\Widgets\AcquisitionSourcesFilamentWidget;
use Capell\Insights\Filament\Widgets\LiveInsightsStatsFilamentWidget;
use Capell\Insights\Filament\Widgets\PopularPagesFilamentWidget;
use Capell\Insights\Filament\Widgets\RecentJourneysFilamentWidget;
use Capell\Insights\Filament\Widgets\TopActionsFilamentWidget;
use Capell\Insights\Filament\Widgets\TrendingPagesFilamentWidget;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Override;
use RuntimeException;

class AdminServiceProvider extends ServiceProvider
{
    private const string REQUEST_INSIGHTS_OVERVIEW_CACHE_KEY = 'capell.insights.admin.overview';

    #[Override]
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (! $this->isPackageInstalled()) {
            return;
        }

        $this
            ->registerDashboardSettingsContributor()
            ->registerCommands()
            ->registerPages()
            ->registerOverviewStats()
            ->registerDashboardFilamentWidgets()
            ->registerMarketingStudioActions()
            ->registerSchedule();
    }

    protected function isPackageInstalled(): bool
    {
        return CapellCore::isPackageInstalled(InsightsServiceProvider::$packageName);
    }

    private function registerDashboardSettingsContributor(): self
    {
        $this->app->tag([InsightsDashboardSettingsContributor::class], DashboardSettingsContributor::TAG);

        return $this;
    }

    private function registerCommands(): self
    {
        if (! $this->app->runningInConsole()) {
            return $this;
        }

        $this->commands([
            PurgeInsightsDataCommand::class,
            RebuildInsightsDailyRollupsCommand::class,
        ]);

        return $this;
    }

    private function registerDashboardFilamentWidgets(): self
    {
        CapellAdmin::registerDashboardFilamentWidget(PopularPagesFilamentWidget::class, DashboardEnum::Main, DashboardEnum::MarketingStudio);
        CapellAdmin::registerDashboardFilamentWidget(TrendingPagesFilamentWidget::class, DashboardEnum::Main, DashboardEnum::MarketingStudio);
        CapellAdmin::registerDashboardFilamentWidget(LiveInsightsStatsFilamentWidget::class, DashboardEnum::Main, DashboardEnum::MarketingStudio);
        CapellAdmin::registerDashboardFilamentWidget(RecentJourneysFilamentWidget::class, DashboardEnum::Main, DashboardEnum::MarketingStudio);
        CapellAdmin::registerDashboardFilamentWidget(TopActionsFilamentWidget::class, DashboardEnum::Main, DashboardEnum::MarketingStudio);
        CapellAdmin::registerDashboardFilamentWidget(AcquisitionSourcesFilamentWidget::class, DashboardEnum::Main, DashboardEnum::MarketingStudio);

        return $this;
    }

    private function registerMarketingStudioActions(): self
    {
        CapellAdmin::registerMarketingStudioAction(new MarketingStudioActionData(
            key: 'insights.performance',
            label: fn (): string => InsightsPage::getNavigationLabel(),
            url: fn (): string => InsightsPage::getUrl(),
            section: MarketingStudioSectionEnum::Performance,
            icon: 'heroicon-o-presentation-chart-line',
            sort: 20,
        ));

        return $this;
    }

    private function registerOverviewStats(): self
    {
        foreach (['page-views' => 130, 'unique-visits' => 131, 'clicks' => 132] as $metricId => $sort) {
            CapellAdmin::registerOverviewStat(
                key: 'insights_overview.' . $metricId,
                label: fn (): string => $this->insightsOverviewStat($metricId)['label'],
                value: fn (): int => $this->insightsOverviewStat($metricId)['value'],
                group: fn (): string => __('capell-insights::settings.fieldset'),
                sort: $sort,
                settingsKey: 'insights_overview',
                settingsLabel: fn (): string => __('capell-insights::widgets.insights_overview'),
            );
        }

        return $this;
    }

    /**
     * @return Collection<int, array{id: string, label: string, value: int}>
     */
    private function insightsOverview(): Collection
    {
        $request = $this->currentRequest();

        if ($request instanceof Request) {
            $cachedOverview = $request->attributes->get(self::REQUEST_INSIGHTS_OVERVIEW_CACHE_KEY);

            if ($cachedOverview instanceof Collection) {
                return $cachedOverview;
            }
        }

        $overview = BuildInsightsOverviewStatsAction::run(new InsightsWindowData(
            startsAt: CarbonImmutable::now()->subDays(30)->startOfDay(),
            endsAt: CarbonImmutable::now()->endOfDay(),
        ));

        if ($request instanceof Request) {
            $request->attributes->set(self::REQUEST_INSIGHTS_OVERVIEW_CACHE_KEY, $overview);
        }

        return $overview;
    }

    /**
     * @return array{id: string, label: string, value: int}
     */
    private function insightsOverviewStat(string $metricId): array
    {
        $stat = $this->insightsOverview()->firstWhere('id', $metricId);

        throw_unless(is_array($stat), RuntimeException::class, sprintf('Insights overview metric [%s] is not available.', $metricId));

        return $stat;
    }

    private function registerPages(): self
    {
        CapellAdmin::registerExtensionPage(InsightsServiceProvider::$packageName, InsightsPage::class);

        return $this;
    }

    private function registerSchedule(): self
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('insights:purge')->monthly();
            $schedule->command('insights:rollups:rebuild')->daily();
        });

        return $this;
    }

    private function currentRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        return $request instanceof Request ? $request : null;
    }
}
