<?php

declare(strict_types=1);

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Insights\Health\InsightsHealthCheck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

it('reports a compatible capell api version', function (): void {
    expect(InsightsHealthCheck::compatibleCapellApiVersion())->toBe('^4.0');
});

it('runs real diagnostics returning check results', function (): void {
    $results = InsightsHealthCheck::runDiagnostics();

    expect($results)->toHaveCount(5)
        ->and($results->every(static fn (mixed $result): bool => $result instanceof DoctorCheckResultData))->toBeTrue();
});

it('passes when tables, beacon routes, and hash secret are present', function (): void {
    $results = InsightsHealthCheck::runDiagnostics();

    expect(InsightsHealthCheck::passed())->toBeTrue()
        ->and($results->every(static fn (DoctorCheckResultData $result): bool => $result->passed))->toBeTrue();
});

it('fails the storage table check when an insights table is missing', function (): void {
    Schema::drop('insights_events');

    $check = new InsightsHealthCheck;

    expect($check->missingTables())->toContain('insights_events')
        ->and($check->storageTablesCheck()->passed)->toBeFalse()
        ->and(InsightsHealthCheck::passed())->toBeFalse();
});

it('confirms the public beacon and consent routes are registered', function (): void {
    $check = new InsightsHealthCheck;

    expect($check->missingBeaconRoutes())->toBe([])
        ->and($check->beaconRoutesCheck()->passed)->toBeTrue();
});

it('confirms the frontend tracker render hook emits tracker output', function (): void {
    $check = new InsightsHealthCheck;

    expect($check->hasFrontendTrackerRenderHook())->toBeTrue()
        ->and($check->frontendTrackerRenderHookCheck()->passed)->toBeTrue()
        ->and($check->hasFrontendTrackerRenderHook(new RenderHookRegistry))->toBeFalse();
});

it('confirms the retention purge command is scheduled monthly', function (): void {
    $check = new InsightsHealthCheck;
    $emptySchedule = new Schedule;
    $scheduledPurge = new Schedule;
    $scheduledPurge->command('insights:purge')->monthly();

    expect($check->hasPurgeSchedule())->toBeTrue()
        ->and($check->purgeScheduleCheck()->passed)->toBeTrue()
        ->and($check->hasPurgeSchedule($emptySchedule))->toBeFalse()
        ->and($check->hasPurgeSchedule($scheduledPurge))->toBeTrue();
});

it('fails the visitor hash secret check when only the public default salt is available', function (): void {
    Config::set('capell-insights.hash_salt', ' capell-insights ');
    Config::set('app.key', '');

    $check = new InsightsHealthCheck;

    expect($check->hasSecureVisitorHashSecret())->toBeFalse()
        ->and($check->visitorHashSecretCheck()->passed)->toBeFalse()
        ->and(InsightsHealthCheck::passed())->toBeFalse();
});

it('fails the visitor hash secret check when no usable salt or app key is available', function (): void {
    Config::set('capell-insights.hash_salt');
    Config::set('app.key', 'base64:');

    $check = new InsightsHealthCheck;
    $result = $check->visitorHashSecretCheck();

    expect($check->hasSecureVisitorHashSecret())->toBeFalse()
        ->and($result->passed)->toBeFalse()
        ->and($result->message)->not->toContain('base64:');
});

it('passes the visitor hash secret check when a custom salt is configured', function (): void {
    Config::set('capell-insights.hash_salt', 'a-private-production-salt');
    Config::set('app.key', '');

    $check = new InsightsHealthCheck;

    expect($check->hasSecureVisitorHashSecret())->toBeTrue()
        ->and($check->visitorHashSecretCheck()->passed)->toBeTrue();
});

it('passes the visitor hash secret check when the salt is derived from the application key', function (): void {
    Config::set('capell-insights.hash_salt', 'capell-insights');
    Config::set('app.key', 'base64:' . base64_encode(str_repeat('i', 32)));

    $check = new InsightsHealthCheck;

    expect($check->hasSecureVisitorHashSecret())->toBeTrue()
        ->and($check->visitorHashSecretCheck()->passed)->toBeTrue();
});
