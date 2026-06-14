<?php

declare(strict_types=1);

namespace Capell\Insights\Providers;

use Capell\Admin\Data\Extensions\ExtensionManagementSurfaceData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\FrontendHookRegistrar;
use Capell\Insights\Filament\Settings\InsightsSettingsSchema;
use Capell\Insights\Models\InsightsConsent;
use Capell\Insights\Models\InsightsDailyRollup;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Capell\Insights\Settings\InsightsSettings;
use Capell\Insights\Settings\InsightsSettingsMigrationProvider;
use Capell\Insights\Support\RenderHooks\RegisterInsightsTrackerHook;
use Override;
use Spatie\LaravelPackageTools\Package;

class InsightsServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell-insights';

    public static string $packageName = 'capell-app/insights';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasConfigFile('capell-insights')
            ->hasTranslations()
            ->hasViews(self::$name)
            ->hasRoute('web')
            ->hasMigrations([
                '2026_05_10_190855_01_create_insights_visits_table',
                '2026_05_10_190855_02_create_insights_consents_table',
                '2026_05_10_190855_03_create_insights_events_table',
                '2026_05_10_190855_05_import_legacy_page_views',
                '2026_06_06_000001_create_insights_daily_rollups_table',
            ]);
    }

    public function registeringPackage(): void
    {
        $this->app->register(AdminServiceProvider::class);
    }

    public function packageRegistered(): void
    {
        $this

            ->registerSettingsMigrations();

        $this->app->booted(function (): void {
            if (! $this->isPackageInstalled()) {
                return;
            }

            $this
                ->registerModels()
                ->registerSettings()
                ->registerProtectedTables();
        });
    }

    public function packageBooted(): void
    {
        if (! $this->isPackageInstalled()) {
            return;
        }

        if (config('capell-insights.enabled', true) === true && $this->app->bound(FrontendHookRegistrar::class)) {
            resolve(FrontendHookRegistrar::class)->contribute(
                location: RenderHookLocation::BodyEnd,
                extension: new RegisterInsightsTrackerHook,
                owner: 'capell-app/insights',
                key: 'insights-tracker',
                cacheSafe: false,
            );
        }

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

    #[Override]
    protected function isPackageInstalled(): bool
    {
        return CapellCore::isPackageInstalled(static::$packageName);
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
