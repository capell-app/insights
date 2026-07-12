<?php

declare(strict_types=1);

namespace Capell\Insights\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Insights\Filament\Widgets\AcquisitionSourcesFilamentWidget;
use Capell\Insights\Filament\Widgets\InsightsOverviewStatsFilamentWidget;
use Capell\Insights\Filament\Widgets\LiveInsightsStatsFilamentWidget;
use Capell\Insights\Filament\Widgets\PopularPagesFilamentWidget;
use Capell\Insights\Filament\Widgets\RecentJourneysFilamentWidget;
use Capell\Insights\Filament\Widgets\TopActionsFilamentWidget;
use Capell\Insights\Filament\Widgets\TrendingPagesFilamentWidget;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Override;

final class InsightsPage extends Page
{
    use HasPageShield;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ChartBar;

    protected static ?int $navigationSort = 1;

    protected string $view = 'capell-insights::filament.pages.insights';

    protected static ?string $slug = 'insights';

    #[Override]
    public static function getNavigationLabel(): string
    {
        return __('capell-insights::widgets.insights');
    }

    #[Override]
    public static function getNavigationGroup(): string
    {
        return __('capell-admin::navigation.group_monitoring');
    }

    #[Override]
    public function getTitle(): string
    {
        return __('capell-insights::widgets.insights');
    }

    #[Override]
    public function getSubheading(): string
    {
        return __('capell-insights::widgets.insights_hint');
    }

    #[Override]
    protected function getHeaderWidgets(): array
    {
        return [
            InsightsOverviewStatsFilamentWidget::class,
            LiveInsightsStatsFilamentWidget::class,
            PopularPagesFilamentWidget::class,
            TrendingPagesFilamentWidget::class,
            RecentJourneysFilamentWidget::class,
            TopActionsFilamentWidget::class,
            AcquisitionSourcesFilamentWidget::class,
        ];
    }
}
