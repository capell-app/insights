<?php

declare(strict_types=1);

namespace Capell\Insights\Providers;

use Capell\Admin\Data\Extensions\ExtensionManagementSurfaceData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Actions\Install\RegisterProviderCommandsInCurrentProcessAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\FrontendHookRegistrar;
use Capell\Insights\Actions\AnonymizeInsightsVisitAction;
use Capell\Insights\Console\Commands\PurgeInsightsDataCommand;
use Capell\Insights\Console\Commands\RebuildInsightsDailyRollupsCommand;
use Capell\Insights\Filament\Settings\InsightsSettingsSchema;
use Capell\Insights\Metrics\InsightsTrafficMetricsCollector;
use Capell\Insights\Models\InsightsConsent;
use Capell\Insights\Models\InsightsDailyRollup;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Capell\Insights\Settings\InsightsSettings;
use Capell\Insights\Settings\InsightsSettingsMigrationProvider;
use Capell\Insights\Support\RenderHooks\RegisterInsightsTrackerHook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Override;
use ReflectionProperty;
use Spatie\LaravelPackageTools\Package;

final class InsightsServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell-insights';

    public static string $packageName = 'capell-app/insights';

    #[Override]
    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasConfigFile('capell-insights')
            ->hasTranslations()
            ->hasViews(self::$name)
            ->hasMigrations([
                '2026_05_10_190855_01_create_insights_visits_table',
                '2026_05_10_190855_02_create_insights_consents_table',
                '2026_05_10_190855_03_create_insights_events_table',
                '2026_05_10_190855_05_import_legacy_page_views',
                '2026_06_06_000001_create_insights_daily_rollups_table',
                '2026_07_22_000001_add_path_digest_to_insights_daily_rollups_table',
                '2026_09_25_000001_invalidate_legacy_insights_html_cache',
            ]);
    }

    #[Override]
    public function registeringPackage(): void
    {
        // The original admin provider emitted contributions before the main provider.
        $this->app->register(AdminServiceProvider::class);

        parent::registeringPackage();
    }

    #[Override]
    public function packageRegistered(): void
    {
        $this->registerSettingsMigrations();
    }

    #[Override]
    protected function bootInstalledRuntime(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', self::$name);
        $this->publishSettingsMigrations();
        // Late activation may run inside a foreign route group.
        $router = $this->app->make(Router::class);
        $groups = new ReflectionProperty(Router::class, 'groupStack');
        $previous = $groups->getValue($router);
        $groups->setValue($router, []);
        try {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        } finally {
            $groups->setValue($router, $previous);
        }

        if (config('capell-insights.enabled', true) === true && $this->app->bound(FrontendHookRegistrar::class)) {
            resolve(FrontendHookRegistrar::class)->contribute(
                location: RenderHookLocation::BodyEnd,
                extension: new RegisterInsightsTrackerHook,
                owner: RegisterInsightsTrackerHook::OWNER,
                key: RegisterInsightsTrackerHook::KEY,
                cacheSafe: true,
            );
        }

        $this->registerModels()
            ->registerSettings()
            ->registerPrivacyCenterEraser()
            ->registerProtectedTables();
        $this->surface()->metricCollector(InsightsTrafficMetricsCollector::class);
        if ($this->app->runningInConsole()) {
            RegisterProviderCommandsInCurrentProcessAction::run(function (): void {
                $this->commands([PurgeInsightsDataCommand::class, RebuildInsightsDailyRollupsCommand::class]);
            });
        }

        $this->registerSchedule(static function (Schedule $schedule): void {
            $schedule->command('insights:purge')->monthly();
            $schedule->command('insights:rollups:rebuild')->daily();
        });
    }

    #[Override]
    protected function isPackageInstalled(): bool
    {
        return CapellCore::isPackageInstalled(self::$packageName);
    }

    private function publishSettingsMigrations(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        /** @var InsightsSettingsMigrationProvider $provider */
        $provider = $this->app->make(InsightsSettingsMigrationProvider::class);

        $this->publishes([
            $provider->path() . '/2026_05_10_190856_01_create_insights_settings.php' => database_path('settings/2026_05_10_190856_01_create_insights_settings.php'),
            $provider->path() . '/2026_06_14_000001_rename_insights_form_tracking_setting.php' => database_path('settings/2026_06_14_000001_rename_insights_form_tracking_setting.php'),
        ], 'capell-insights-settings');
    }

    private function registerModels(): self
    {
        $this->surface()->models([
            InsightsVisit::class,
            InsightsConsent::class,
            InsightsEvent::class,
            InsightsDailyRollup::class,
        ]);

        return $this;
    }

    private function registerSettings(): self
    {
        $this->surface()->settingsClass('insights', InsightsSettings::class);
        $this->surface()->settingsSchema('insights', InsightsSettingsSchema::class);
        CapellAdmin::registerExtensionManagementSurface(ExtensionManagementSurfaceData::settings(
            packageName: self::$packageName,
            label: 'capell-insights::settings.fieldset',
            settingsGroup: 'insights',
            icon: 'heroicon-o-chart-pie',
        ));

        return $this;
    }

    private function registerSettingsMigrations(): self
    {
        $this->app->singleton(InsightsSettingsMigrationProvider::class);

        return $this;
    }

    private function registerPrivacyCenterEraser(): self
    {
        $registryClass = implode('\\', ['Capell', 'PrivacyCenter', 'Support', 'PrivacySubjectEraserRegistry']);

        if (! class_exists($registryClass) || ! $this->app->bound($registryClass)) {
            return $this;
        }

        $registry = $this->app->make($registryClass);

        if (! is_object($registry) || ! method_exists($registry, 'register')) {
            return $this;
        }

        $registry->register('insights', static function (Model $subject): int {
            if (! $subject instanceof InsightsVisit) {
                return 0;
            }

            AnonymizeInsightsVisitAction::run($subject);

            return 1;
        });

        return $this;
    }

    private function registerProtectedTables(): self
    {
        CapellCore::registerProtectedTable(fn (): string => config('capell-insights.tables.visits', 'insights_visits'));
        CapellCore::registerProtectedTable(fn (): string => config('capell-insights.tables.consents', 'insights_consents'));
        CapellCore::registerProtectedTable(fn (): string => config('capell-insights.tables.events', 'insights_events'));
        CapellCore::registerProtectedTable(fn (): string => $this->configString('capell-insights.tables.daily_rollups', 'insights_daily_rollups'));

        return $this;
    }

    private function configString(string $key, string $fallback): string
    {
        $value = config($key, $fallback);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
