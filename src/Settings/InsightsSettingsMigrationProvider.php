<?php

declare(strict_types=1);

namespace Capell\Insights\Settings;

use Capell\Frontend\Contracts\SettingsMigrationProviderInterface;

final class InsightsSettingsMigrationProvider implements SettingsMigrationProviderInterface
{
    /**
     * @return array<int, string>
     */
    public function getSettingMigrations(): array
    {
        return [
            '2026_05_10_190856_01_create_insights_settings',
            '2026_06_14_000001_rename_insights_form_tracking_setting',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function migrations(): array
    {
        return $this->getSettingMigrations();
    }

    public function path(): string
    {
        return dirname(__DIR__, 2) . '/database/settings';
    }
}
