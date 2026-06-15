<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if ($this->migrator->exists('insights.track_form-builder') && ! $this->migrator->exists('insights.track_forms')) {
            $this->migrator->rename('insights.track_form-builder', 'insights.track_forms');

            return;
        }

        if (! $this->migrator->exists('insights.track_forms')) {
            $this->migrator->add('insights.track_forms', false);
        }

        $this->migrator->deleteIfExists('insights.track_form-builder');
    }

    public function down(): void
    {
        if ($this->migrator->exists('insights.track_forms') && ! $this->migrator->exists('insights.track_form-builder')) {
            $this->migrator->rename('insights.track_forms', 'insights.track_form-builder');
        }
    }
};
