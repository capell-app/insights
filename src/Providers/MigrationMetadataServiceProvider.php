<?php

declare(strict_types=1);

namespace Capell\Insights\Providers;

use Capell\Core\Support\Migration\MigrationRootRegistry;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Override;

final class MigrationMetadataServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/capell-insights.php', 'capell-insights');

        $this->callAfterResolving(MigrationRootRegistry::class, static function (MigrationRootRegistry $registry): void {
            $registry->register('capell-app/insights', __DIR__ . '/../../database/migrations', [
                '2026_05_10_190855_01_create_insights_visits_table' => Config::string('capell-insights.tables.visits', 'insights_visits'),
                '2026_05_10_190855_02_create_insights_consents_table' => Config::string('capell-insights.tables.consents', 'insights_consents'),
                '2026_05_10_190855_03_create_insights_events_table' => self::baseTableName('capell-insights.tables.events', 'insights_events'),
                '2026_06_06_000001_create_insights_daily_rollups_table' => self::baseTableName('capell-insights.tables.daily_rollups', 'insights_daily_rollups'),
            ]);
        });
    }

    private static function baseTableName(string $key, string $fallback): string
    {
        $name = config($key);

        return is_string($name) && $name !== '' ? $name : $fallback;
    }
}
