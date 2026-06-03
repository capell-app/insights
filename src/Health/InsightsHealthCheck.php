<?php

declare(strict_types=1);

namespace Capell\Insights\Health;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;
use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class InsightsHealthCheck implements ChecksExtensionHealth
{
    /**
     * @var list<string>
     */
    private const array BEACON_ROUTE_NAMES = [
        'capell-insights.events',
        'capell-insights.consent',
    ];

    public static function compatibleCapellApiVersion(): string
    {
        return '^4.0';
    }

    /**
     * @return Collection<int, DoctorCheckResultData>
     */
    public static function runDiagnostics(): Collection
    {
        $check = new self;

        return collect([
            $check->storageTablesCheck(),
            $check->beaconRoutesCheck(),
            $check->visitorHashSecretCheck(),
        ]);
    }

    public static function passed(): bool
    {
        return self::runDiagnostics()
            ->every(static fn (DoctorCheckResultData $result): bool => $result->passed);
    }

    /**
     * Asserts every analytics storage table exists.
     */
    public function storageTablesCheck(): DoctorCheckResultData
    {
        $missingTables = $this->missingTables();

        return new DoctorCheckResultData(
            label: 'Insights storage tables',
            passed: $missingTables === [],
            message: $missingTables === []
                ? 'The visits, consents, and events tables are present.'
                : 'Missing tables: ' . implode(', ', $missingTables) . '.',
            remediation: $missingTables === []
                ? null
                : 'Run the Capell migrations to create the insights storage tables.',
        );
    }

    /**
     * Asserts the public beacon and consent routes are registered.
     */
    public function beaconRoutesCheck(): DoctorCheckResultData
    {
        $missingRoutes = $this->missingBeaconRoutes();

        return new DoctorCheckResultData(
            label: 'Insights beacon routes',
            passed: $missingRoutes === [],
            message: $missingRoutes === []
                ? 'The events beacon and consent endpoints are registered.'
                : 'Missing routes: ' . implode(', ', $missingRoutes) . '.',
            remediation: $missingRoutes === []
                ? null
                : 'Ensure the insights web routes are loaded and capell-insights.route_prefix is configured.',
        );
    }

    /**
     * Asserts a non-default secret is available for hashing visitor identifiers.
     */
    public function visitorHashSecretCheck(): DoctorCheckResultData
    {
        $hasSecureSecret = $this->hasSecureVisitorHashSecret();

        return new DoctorCheckResultData(
            label: 'Insights visitor hash secret',
            passed: $hasSecureSecret,
            message: $hasSecureSecret
                ? 'A non-default secret is configured for hashing visitor identifiers.'
                : 'Visitor identifiers are hashed with the public default salt and are reversible.',
            remediation: $hasSecureSecret
                ? null
                : 'Set capell-insights.hash_salt to a private value (or rely on app.key) before production.',
        );
    }

    /**
     * @return list<string>
     */
    public function missingTables(): array
    {
        return array_values(collect($this->requiredTableNames())
            ->reject(static fn (string $tableName): bool => Schema::hasTable($tableName))
            ->values()
            ->all());
    }

    /**
     * @return list<string>
     */
    public function missingBeaconRoutes(): array
    {
        return array_values(collect(self::BEACON_ROUTE_NAMES)
            ->reject(static fn (string $routeName): bool => Route::has($routeName))
            ->values()
            ->all());
    }

    public function hasSecureVisitorHashSecret(): bool
    {
        $salt = config('capell-insights.hash_salt');

        if (is_string($salt) && $salt !== '' && $salt !== 'capell-insights') {
            return true;
        }

        $applicationKey = config('app.key');

        return is_string($applicationKey) && $applicationKey !== '';
    }

    /**
     * @return list<string>
     */
    private function requiredTableNames(): array
    {
        $tables = config('capell-insights.tables', []);

        $resolve = static function (string $key, string $fallback) use ($tables): string {
            $value = is_array($tables) ? ($tables[$key] ?? null) : null;

            return is_string($value) && $value !== '' ? $value : $fallback;
        };

        return [
            $resolve('visits', 'insights_visits'),
            $resolve('consents', 'insights_consents'),
            $resolve('events', 'insights_events'),
        ];
    }
}
