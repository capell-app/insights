<?php

declare(strict_types=1);

use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\Extensions\ExtensionManagementSurfaceRegistry;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Capell\Insights\Console\Commands\PurgeInsightsDataCommand;
use Capell\Insights\Console\Commands\RebuildInsightsDailyRollupsCommand;
use Capell\Insights\Filament\Pages\InsightsPage;
use Capell\Insights\Filament\Settings\InsightsSettingsSchema;
use Capell\Insights\Filament\Widgets\LiveInsightsStatsFilamentWidget;
use Capell\Insights\Providers\InsightsServiceProvider;
use Capell\Insights\Settings\InsightsSettings;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
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
        ->and(Route::has('capell-insights.consent'))->toBeTrue()
        ->and(Route::has('capell-insights.consent-policy'))->toBeTrue();
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

it('preserves a cold console and registers insights commands in an already running console', function (bool $started): void {
    $application = app();
    $originalKernel = $application->make(ConsoleKernelContract::class);
    $kernel = new Kernel($application, $application->make(Dispatcher::class));
    $application->instance(ConsoleKernelContract::class, $kernel);
    Artisan::clearResolvedInstance(ConsoleKernelContract::class);

    try {
        expect(Artisan::getFacadeRoot())->toBe($kernel);
        $originalCommand = null;
        if ($started) {
            $kernel->all();
            $originalCommand = new PurgeInsightsDataCommand;
            $originalCommand->setAliases(['insights:purge:registration-probe']);
            $kernel->registerCommand($originalCommand);
        }

        $provider = new InsightsServiceProvider($application);
        new ReflectionMethod($provider, 'bootInstalledRuntime')->invoke($provider);

        if (! $started) {
            // This is the console's construction boundary: resolving all commands
            // here would hide premature Artisan construction by the provider.
            expect(new ReflectionProperty(Kernel::class, 'artisan')->getValue($kernel))->toBeNull();

            return;
        }

        $commands = $kernel->all();
        expect($commands['insights:purge'] ?? null)->toBe($originalCommand)
            ->and($commands['insights:purge:registration-probe'] ?? null)->toBe($originalCommand)
            ->and($commands['insights:rollups:rebuild'] ?? null)->toBeInstanceOf(RebuildInsightsDailyRollupsCommand::class);
    } finally {
        $application->instance(ConsoleKernelContract::class, $originalKernel);
        Artisan::clearResolvedInstance(ConsoleKernelContract::class);
    }
})->with(['cold console' => false, 'already running console' => true]);

it('leaves an unresolved console kernel for normal startup', function (): void {
    $application = app();
    $originalKernel = $application->make(ConsoleKernelContract::class);
    unset($application[ConsoleKernelContract::class]);
    Artisan::clearResolvedInstance(ConsoleKernelContract::class);
    $constructed = 0;
    $application->singleton(ConsoleKernelContract::class, static function () use ($application, &$constructed): Kernel {
        $constructed++;

        return new Kernel($application, $application->make(Dispatcher::class));
    });

    try {
        $provider = new InsightsServiceProvider($application);
        new ReflectionMethod($provider, 'bootInstalledRuntime')->invoke($provider);

        expect($constructed)->toBe(0);
    } finally {
        $application->instance(ConsoleKernelContract::class, $originalKernel);
        Artisan::clearResolvedInstance(ConsoleKernelContract::class);
    }
});
