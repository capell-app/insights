<?php

declare(strict_types=1);

use Capell\Insights\Filament\Settings\InsightsSettingsSchema;
use Capell\Insights\Settings\InsightsSettings;
use Capell\Insights\Tests\Fixtures\InsightsSettingsForm;
use Livewire\Livewire;
use Spatie\LaravelSettings\Migrations\SettingsMigrator;
use Spatie\LaravelSettings\Models\SettingsProperty;

it('loads insights settings defaults', function (): void {
    /** @var SettingsMigrator $settingsMigrator */
    $settingsMigrator = resolve(SettingsMigrator::class);
    $expectedKeys = [
        'insights.enabled',
        'insights.track_page_views',
        'insights.track_clicks',
        'insights.track_forms',
        'insights.automatic_click_tracking',
        'insights.require_consent_for_all_regions',
        'insights.default_consent_region',
        'insights.policy_version',
        'insights.retention_days',
        'insights.hash_visitor_data',
        'insights.hash_salt',
        'insights.ignored_paths',
        'insights.ignored_selectors',
        'insights.route_prefix',
    ];

    foreach ($expectedKeys as $expectedKey) {
        expect($settingsMigrator->exists($expectedKey))->toBeTrue();
    }

    expect(resolve(InsightsSettings::class)->retention_days)->toBe(365);
    expect($settingsMigrator->exists('insights.track_form-builder'))->toBeFalse();
});

it('normalizes textarea settings lists', function (): void {
    expect(InsightsSettingsSchema::listToTextarea(['/admin*', '/livewire*']))->toBe('/admin*' . PHP_EOL . '/livewire*')
        ->and(InsightsSettingsSchema::textareaToList("/admin*\n\n /livewire* \n"))->toBe(['/admin*', '/livewire*']);
});

it('renames the legacy form tracking setting without losing its value', function (): void {
    /** @var SettingsMigrator $settingsMigrator */
    $settingsMigrator = resolve(SettingsMigrator::class);

    $settingsMigrator->deleteIfExists('insights.track_forms');
    $settingsMigrator->deleteIfExists('insights.track_form-builder');
    $settingsMigrator->add('insights.track_form-builder', true);

    $migration = require __DIR__ . '/../../../database/settings/2026_06_14_000001_rename_insights_form_tracking_setting.php';
    $migration->up();

    expect($settingsMigrator->exists('insights.track_forms'))->toBeTrue()
        ->and($settingsMigrator->exists('insights.track_form-builder'))->toBeFalse()
        ->and(SettingsProperty::get('insights.track_forms'))->toBeTrue();
});

it('round trips every advanced control through the collapsed settings form', function (): void {
    $settings = resolve(InsightsSettings::class);
    $settings->enabled = false;
    $settings->track_page_views = false;
    $settings->track_clicks = false;
    $settings->track_forms = true;
    $settings->automatic_click_tracking = false;
    $settings->require_consent_for_all_regions = true;
    $settings->default_consent_region = 'uk_or_europe';
    $settings->retention_days = 42;
    $settings->policy_version = '2.0';
    $settings->hash_visitor_data = true;
    $settings->hash_salt = 'private-test-salt';
    $settings->ignored_paths = ['/private*', '/account*'];
    $settings->ignored_selectors = ['[data-private]'];
    $settings->route_prefix = 'measure';
    $settings->save();

    $component = Livewire::test(InsightsSettingsForm::class)
        ->assertSeeInOrder(['Enable insights', 'Require consent for all regions', 'Advanced', 'Collection', 'Consent and retention', 'Developer: endpoint'])
        ->assertSee('Recommended: require consent in every region')
        ->call('submit')
        ->assertHasNoErrors();
    expect($component->get('submitted'))->toBe($settings->toArray());
});
