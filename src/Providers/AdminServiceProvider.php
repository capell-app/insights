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
use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Filament\Pages\InsightsPage;
use Capell\Insights\Filament\Settings\Contributors\InsightsDashboardSettingsContributor;
use Capell\Insights\Filament\Widgets\LiveInsightsStatsWidget;
use Capell\Insights\Filament\Widgets\PopularPagesWidget;
use Capell\Insights\Filament\Widgets\RecentJourneysWidget;
use Capell\Insights\Filament\Widgets\TopActionsWidget;
use Capell\Insights\Filament\Widgets\TrendingPagesWidget;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Override;
use RuntimeException;

class AdminServiceProvider extends ServiceProvider
{
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
            ->registerDashboardWidgets()
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

        $this->commands([PurgeInsightsDataCommand::class]);

        return $this;
    }

    private function registerDashboardWidgets(): self
    {
        CapellAdmin::registerDashboardWidget(PopularPagesWidget::class, DashboardEnum::Main, DashboardEnum::MarketingStudio);
        CapellAdmin::registerDashboardWidget(TrendingPagesWidget::class, DashboardEnum::Main, DashboardEnum::MarketingStudio);
        CapellAdmin::registerDashboardWidget(LiveInsightsStatsWidget::class, DashboardEnum::Main, DashboardEnum::MarketingStudio);
        CapellAdmin::registerDashboardWidget(RecentJourneysWidget::class, DashboardEnum::Main, DashboardEnum::MarketingStudio);
        CapellAdmin::registerDashboardWidget(TopActionsWidget::class, DashboardEnum::Main, DashboardEnum::MarketingStudio);

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
        static $overview = null;

        if ($overview instanceof Collection) {
            return $overview;
        }

        $overview = BuildInsightsOverviewStatsAction::run(new InsightsWindowData(
            startsAt: CarbonImmutable::now()->subDays(30)->startOfDay(),
            endsAt: CarbonImmutable::now()->endOfDay(),
        ));

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
        });

        return $this;
    }
}
