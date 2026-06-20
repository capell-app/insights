<?php

declare(strict_types=1);

use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\Extensions\ExtensionManagementSurfaceRegistry;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Insights\Filament\Pages\InsightsPage;
use Capell\Insights\Filament\Settings\InsightsSettingsSchema;
use Capell\Insights\Filament\Widgets\LiveInsightsStatsFilamentWidget;
use Capell\Insights\Providers\InsightsServiceProvider;
use Capell\Insights\Settings\InsightsSettings;
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
    expect(CapellAdmin::getDashboardFilamentWidgets(DashboardEnum::MarketingStudio))
        ->toContain(LiveInsightsStatsFilamentWidget::class);
});

it('registers insights settings and extension settings surface', function (): void {
    $settingsRegistry = resolve(SettingsSchemaRegistry::class);

    expect($settingsRegistry->getSettingsClass('insights'))->toBe(InsightsSettings::class)
        ->and($settingsRegistry->getSchemas('insights'))->toContain(InsightsSettingsSchema::class);

    $surfaces = resolve(ExtensionManagementSurfaceRegistry::class)
        ->surfacesForPackage(InsightsServiceProvider::$packageName);

    expect($surfaces[0]->settingsGroup ?? null)->toBe('insights');
});
