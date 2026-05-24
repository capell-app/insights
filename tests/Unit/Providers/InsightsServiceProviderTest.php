<?php

declare(strict_types=1);

use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Facades\CapellCore;
use Capell\Insights\Filament\Pages\InsightsPage;
use Capell\Insights\Filament\Widgets\LiveInsightsStatsWidget;
use Capell\Insights\Providers\InsightsServiceProvider;
use Illuminate\Support\Facades\Route;

it('registers the insights package metadata', function (): void {
    $package = CapellCore::getPackage(InsightsServiceProvider::$packageName);

    expect($package->name)->toBe(InsightsServiceProvider::$packageName);
});

it('loads the insights config', function (): void {
    expect(config('capell-insights.enabled'))->toBeTrue()
        ->and(config('capell-insights.route_prefix'))->toBe('capell/insights');
});

it('registers insights routes', function (): void {
    expect(Route::has('capell-insights.events'))->toBeTrue()
        ->and(Route::has('capell-insights.consent'))->toBeTrue();
});

it('places insights first in monitoring navigation', function (): void {
    expect(InsightsPage::getNavigationGroup())->toBe((string) __('capell-admin::navigation.group_monitoring'))
        ->and(InsightsPage::getNavigationSort())->toBe(1);
});

it('registers insights widgets with marketing studio', function (): void {
    expect(CapellAdmin::getDashboardWidgets(DashboardEnum::MarketingStudio))
        ->toContain(LiveInsightsStatsWidget::class);
});
