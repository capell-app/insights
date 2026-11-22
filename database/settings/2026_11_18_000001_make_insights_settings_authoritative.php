<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Saved values for these settings had no effect before this release:
     * tracking followed the capell-insights config. Copy the config in force
     * so the first release that reads saved settings keeps behaving the same.
     *
     * @var array<string, mixed>
     */
    private const array AUTHORITATIVE_DEFAULTS = [
        'enabled' => true,
        'track_page_views' => true,
        'track_clicks' => true,
        'automatic_click_tracking' => true,
        'require_consent_for_all_regions' => false,
        'policy_version' => '1.0',
        'hash_visitor_data' => true,
        'ignored_paths' => ['/admin*', '/livewire*', '/capell/insights*', '/_debugbar*', '/_clockwork*', '/storage*'],
        'ignored_selectors' => ['[data-capell-insights-ignore]', '[wire\\:click]'],
    ];

    public function up(): void
    {
        foreach (self::AUTHORITATIVE_DEFAULTS as $key => $default) {
            $value = $this->configured($key, $default);

            if ($this->migrator->exists('insights.' . $key)) {
                $this->migrator->update('insights.' . $key, static fn (): mixed => $value);

                continue;
            }

            $this->migrator->add('insights.' . $key, $value);
        }

        // Route prefix and hash salt stay deployment config: routes register
        // before settings load, and the salt is a secret. Form tracking was
        // never implemented.
        $this->migrator->deleteIfExists('insights.route_prefix');
        $this->migrator->deleteIfExists('insights.hash_salt');
        $this->migrator->deleteIfExists('insights.track_forms');
    }

    public function down(): void
    {
        foreach (['route_prefix' => 'capell/insights', 'hash_salt' => null, 'track_forms' => false] as $key => $value) {
            if (! $this->migrator->exists('insights.' . $key)) {
                $this->migrator->add('insights.' . $key, $value);
            }
        }
    }

    private function configured(string $key, mixed $default): mixed
    {
        $value = config('capell-insights.' . $key, $default);

        return match (true) {
            is_bool($default) => $value === true,
            is_string($default) => is_string($value) && trim($value) !== '' ? $value : $default,
            default => is_array($value) ? array_values(array_filter($value, is_string(...))) : $default,
        };
    }
};
