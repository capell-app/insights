<?php

declare(strict_types=1);

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Insights\Health\InsightsHealthCheck;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

it('reports a compatible capell api version', function (): void {
    expect(InsightsHealthCheck::compatibleCapellApiVersion())->toBe('^4.0');
});

it('runs real diagnostics returning check results', function (): void {
    $results = InsightsHealthCheck::runDiagnostics();

    expect($results)->toHaveCount(3)
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

it('fails the visitor hash secret check when only the public default salt is available', function (): void {
    Config::set('capell-insights.hash_salt', 'capell-insights');
    Config::set('app.key', '');

    $check = new InsightsHealthCheck;

    expect($check->hasSecureVisitorHashSecret())->toBeFalse()
        ->and($check->visitorHashSecretCheck()->passed)->toBeFalse()
        ->and(InsightsHealthCheck::passed())->toBeFalse();
});

it('passes the visitor hash secret check when a custom salt is configured', function (): void {
    Config::set('capell-insights.hash_salt', 'a-private-production-salt');
    Config::set('app.key', '');

    $check = new InsightsHealthCheck;

    expect($check->hasSecureVisitorHashSecret())->toBeTrue()
        ->and($check->visitorHashSecretCheck()->passed)->toBeTrue();
});
