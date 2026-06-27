<?php

declare(strict_types=1);

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Contracts\Extensions\RegistersExtensionFilamentWidget;
use Capell\Core\Contracts\Extensions\RegistersExtensionRoute;
use Capell\Core\Contracts\Extensions\RegistersExtensionSetting;
use Capell\Core\Contracts\Extensions\RunsScheduledExtensionJob;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\Insights\Console\Commands\PurgeInsightsDataCommand;
use Capell\Insights\Console\Commands\RebuildInsightsDailyRollupsCommand;
use Capell\Insights\Filament\Pages\InsightsPage;
use Capell\Insights\Filament\Widgets\AcquisitionSourcesFilamentWidget;
use Capell\Insights\Filament\Widgets\InsightsOverviewStatsFilamentWidget;
use Capell\Insights\Filament\Widgets\LiveInsightsStatsFilamentWidget;
use Capell\Insights\Filament\Widgets\PopularPagesFilamentWidget;
use Capell\Insights\Filament\Widgets\RecentJourneysFilamentWidget;
use Capell\Insights\Filament\Widgets\TopActionsFilamentWidget;
use Capell\Insights\Filament\Widgets\TrendingPagesFilamentWidget;
use Capell\Insights\Health\InsightsHealthCheck;
use Capell\Insights\Manifest\InsightsDailyRollupsScheduleContribution;
use Capell\Insights\Manifest\InsightsHealthContribution;
use Capell\Insights\Manifest\InsightsPurgeScheduleContribution;
use Capell\Insights\Manifest\InsightsRoutesContribution;
use Capell\Insights\Manifest\InsightsSettingsContribution;
use Capell\Insights\Models\InsightsConsent;
use Capell\Insights\Models\InsightsDailyRollup;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Capell\Insights\Settings\InsightsSettings;
use Illuminate\Support\Facades\Route;

function insightsPackagePath(string $path): string
{
    return dirname(__DIR__, 2) . '/' . $path;
}

/**
 * @return array<string, mixed>
 */
function insightsPackageJson(string $path): array
{
    $decoded = json_decode(
        (string) file_get_contents(insightsPackagePath($path)),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    throw_unless(is_array($decoded), RuntimeException::class, 'Expected Insights package JSON to decode to an array.');

    $normalized = [];

    foreach ($decoded as $key => $value) {
        throw_unless(is_string($key), RuntimeException::class, 'Expected Insights package JSON to use string keys.');

        $normalized[$key] = $value;
    }

    return $normalized;
}

/**
 * @param  array<string, mixed>  $manifest
 * @return array<string, mixed>
 */
function insightsContribution(array $manifest, string $type): array
{
    $contributes = $manifest['contributes'] ?? [];

    throw_unless(is_array($contributes), RuntimeException::class, 'Expected Insights contributions to be an array.');

    $contribution = collect($contributes)
        ->firstWhere('type', $type);

    throw_unless(is_array($contribution), RuntimeException::class, sprintf('Expected Insights contribution [%s] to exist.', $type));

    $normalized = [];

    foreach ($contribution as $key => $value) {
        throw_unless(is_string($key), RuntimeException::class, 'Expected Insights contribution keys to be strings.');

        $normalized[$key] = $value;
    }

    return $normalized;
}

/**
 * @param  array<string, mixed>  $manifest
 * @return list<array<string, mixed>>
 */
function insightsContributions(array $manifest, string $type): array
{
    $contributes = $manifest['contributes'] ?? [];

    throw_unless(is_array($contributes), RuntimeException::class, 'Expected Insights contributions to be an array.');

    $matchingContributions = array_values(collect($contributes)
        ->where('type', $type)
        ->all());

    $normalized = [];

    foreach ($matchingContributions as $contribution) {
        throw_unless(is_array($contribution), RuntimeException::class, sprintf('Expected Insights contribution [%s] entries to be arrays.', $type));

        $normalizedContribution = [];

        foreach ($contribution as $key => $value) {
            throw_unless(is_string($key), RuntimeException::class, 'Expected Insights contribution keys to be strings.');

            $normalizedContribution[$key] = $value;
        }

        $normalized[] = $normalizedContribution;
    }

    return $normalized;
}

/**
 * @return array<string, mixed>
 */
function insightsComposerJson(): array
{
    return insightsPackageJson('composer.json');
}

it('declares installed settings and page permission surfaces', function (): void {
    $manifest = insightsPackageJson('capell.json');
    $commands = $manifest['commands'] ?? null;
    $database = $manifest['database'] ?? null;

    throw_unless(is_array($commands), RuntimeException::class, 'Expected Insights commands metadata to be an array.');
    throw_unless(is_array($database), RuntimeException::class, 'Expected Insights database metadata to be an array.');

    $maintenanceCommands = $commands['maintenance'] ?? [];
    $requiredTables = $database['requiredTables'] ?? [];

    throw_unless(is_array($maintenanceCommands), RuntimeException::class, 'Expected Insights maintenance commands to be an array.');
    throw_unless(is_array($requiredTables), RuntimeException::class, 'Expected Insights required tables to be an array.');

    expect($manifest['settings'] ?? [])->toBe([
        InsightsSettings::class,
    ])->and($manifest['permissions'] ?? [])->toContain('View:InsightsPage')
        ->and($maintenanceCommands)->toContain('insights:rollups:rebuild')
        ->and($requiredTables)->toContain('insights_daily_rollups');
});

it('passes the Capell manifest validator', function (): void {
    $manifest = insightsPackageJson('capell.json');

    (new ManifestValidator)->validate(
        data: $manifest,
        composerJson: insightsComposerJson(),
        packageName: 'capell-app/insights',
        discoverySource: 'packages/insights/capell.json',
    );

    $contributionTraceability = $manifest['contributionTraceability'] ?? null;

    throw_unless(is_array($contributionTraceability), RuntimeException::class, 'Expected Insights contribution traceability to be an array.');

    expect($contributionTraceability['deferredContributions'] ?? null)->toBe([]);
});

it('declares the shipped admin page, widgets, models, routes, and overview stats', function (): void {
    $manifest = insightsPackageJson('capell.json');

    expect(insightsContribution($manifest, 'admin-page'))
        ->toMatchArray([
            'pageClass' => InsightsPage::class,
            'labelKey' => 'capell-insights::settings.fieldset',
            'permission' => 'View:InsightsPage',
        ]);

    expect(insightsContribution($manifest, 'dashboard-widget')['widgetClasses'] ?? null)->toBe([
        InsightsOverviewStatsFilamentWidget::class,
        PopularPagesFilamentWidget::class,
        TrendingPagesFilamentWidget::class,
        LiveInsightsStatsFilamentWidget::class,
        RecentJourneysFilamentWidget::class,
        TopActionsFilamentWidget::class,
        AcquisitionSourcesFilamentWidget::class,
    ])->and(insightsContribution($manifest, 'overview-stat')['keys'] ?? null)->toBe([
        'insights_overview',
        'insights_overview.page-views',
        'insights_overview.unique-visits',
        'insights_overview.clicks',
    ])->and(insightsContribution($manifest, 'model')['modelClasses'] ?? null)->toBe([
        InsightsVisit::class,
        InsightsConsent::class,
        InsightsEvent::class,
        InsightsDailyRollup::class,
    ])->and(insightsContribution($manifest, 'route'))
        ->toMatchArray([
            'routes' => ['capell-insights.events', 'capell-insights.consent'],
            'prefix' => 'capell/insights',
            'methods' => ['POST'],
            'middleware' => ['web', 'throttle:60,1'],
            'csrfExempt' => true,
        ]);

    expect(Route::has('capell-insights.events'))->toBeTrue()
        ->and(Route::has('capell-insights.consent'))->toBeTrue();
});

it('declares scheduled jobs, commands, settings, and health surfaces', function (): void {
    $manifest = insightsPackageJson('capell.json');
    $scheduledJobs = collect(insightsContributions($manifest, 'scheduled-job'))->keyBy('command');

    expect($scheduledJobs->all())->toHaveCount(2)
        ->and($scheduledJobs->get('insights:purge'))->toMatchArray([
            'class' => InsightsPurgeScheduleContribution::class,
            'name' => 'capell-insights-purge',
            'frequency' => 'monthly',
        ])
        ->and($scheduledJobs->get('insights:rollups:rebuild'))->toMatchArray([
            'class' => InsightsDailyRollupsScheduleContribution::class,
            'name' => 'capell-insights-daily-rollups',
            'frequency' => 'daily',
        ]);

    expect(insightsContribution($manifest, 'console-command'))
        ->toMatchArray([
            'commands' => ['insights:purge', 'insights:rollups:rebuild'],
            'commandClasses' => [
                PurgeInsightsDataCommand::class,
                RebuildInsightsDailyRollupsCommand::class,
            ],
        ])
        ->and(insightsContribution($manifest, 'setting'))
        ->toMatchArray([
            'class' => InsightsSettingsContribution::class,
            'settingsClass' => InsightsSettings::class,
            'settingsGroup' => 'insights',
        ])
        ->and(insightsContribution($manifest, 'health-check'))
        ->toMatchArray([
            'class' => InsightsHealthContribution::class,
            'checkClass' => InsightsHealthCheck::class,
        ]);
});

it('uses concrete contribution marker classes with the expected contracts', function (): void {
    $manifest = insightsPackageJson('capell.json');
    $contributes = $manifest['contributes'] ?? [];

    throw_unless(is_array($contributes), RuntimeException::class, 'Expected Insights contributions to be an array.');

    foreach ($contributes as $contribution) {
        throw_unless(is_array($contribution), RuntimeException::class, 'Expected Insights contribution entries to be arrays.');

        $contributionClass = $contribution['class'] ?? null;

        throw_unless(is_string($contributionClass), RuntimeException::class, 'Expected Insights contribution class to be a string.');

        expect(class_exists($contributionClass))->toBeTrue()
            ->and(is_subclass_of($contributionClass, ExtensionContribution::class))->toBeTrue();
    }

    $dashboardFilamentWidgetContributionClass = insightsContribution($manifest, 'dashboard-widget')['class'] ?? null;
    $overviewStatContributionClass = insightsContribution($manifest, 'overview-stat')['class'] ?? null;

    throw_unless(is_string($dashboardFilamentWidgetContributionClass), RuntimeException::class, 'Expected Insights dashboard widget contribution class.');
    throw_unless(is_string($overviewStatContributionClass), RuntimeException::class, 'Expected Insights overview stat contribution class.');

    expect(class_implements(InsightsRoutesContribution::class))->toContain(RegistersExtensionRoute::class)
        ->and(class_implements(InsightsPurgeScheduleContribution::class))->toContain(RunsScheduledExtensionJob::class)
        ->and(class_implements(InsightsDailyRollupsScheduleContribution::class))->toContain(RunsScheduledExtensionJob::class)
        ->and(class_implements(InsightsSettingsContribution::class))->toContain(RegistersExtensionSetting::class)
        ->and(class_implements(InsightsHealthContribution::class))->toContain(ChecksExtensionHealth::class)
        ->and(class_implements($dashboardFilamentWidgetContributionClass))->toContain(RegistersExtensionFilamentWidget::class)
        ->and(class_implements($overviewStatContributionClass))->toContain(RegistersExtensionFilamentWidget::class);
});

it('keeps marketplace screenshots backed by committed assets', function (): void {
    $manifest = insightsPackageJson('capell.json');
    $marketplace = $manifest['marketplace'] ?? null;

    throw_unless(is_array($marketplace), RuntimeException::class, 'Expected Insights marketplace metadata to be an array.');

    $screenshots = $marketplace['screenshots'] ?? null;

    throw_unless(is_array($screenshots), RuntimeException::class, 'Expected Insights marketplace screenshots to be an array.');

    expect($screenshots)->toHaveCount(4);

    foreach ($screenshots as $screenshot) {
        throw_unless(is_array($screenshot), RuntimeException::class, 'Expected Insights screenshot entries to be arrays.');

        $path = $screenshot['path'] ?? null;
        $alt = $screenshot['alt'] ?? null;
        $caption = $screenshot['caption'] ?? null;

        throw_unless(is_string($path), RuntimeException::class, 'Expected Insights screenshot path to be a string.');
        throw_unless(is_string($alt), RuntimeException::class, 'Expected Insights screenshot alt text to be a string.');
        throw_unless(is_string($caption), RuntimeException::class, 'Expected Insights screenshot caption to be a string.');

        expect($screenshot)->toHaveKeys(['path', 'alt', 'caption'])
            ->and($path)->not->toBeEmpty()
            ->and($alt)->not->toBeEmpty()
            ->and($caption)->not->toBeEmpty()
            ->and(insightsPackagePath($path))->toBeFile();
    }
});
