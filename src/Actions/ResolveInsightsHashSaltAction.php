<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * @method static ?string run(?int $siteId = null, ?CarbonImmutable $at = null)
 */
final class ResolveInsightsHashSaltAction
{
    use AsAction;

    private const string APP_KEY_DERIVATION_CONTEXT = 'capell-insights';

    public function handle(?int $siteId = null, ?CarbonImmutable $at = null): ?string
    {
        $rootSalt = $this->configuredPrivateSalt();

        $applicationKey = $this->applicationKey();
        $rootSalt ??= $applicationKey !== null
            ? hash_hmac('sha256', self::APP_KEY_DERIVATION_CONTEXT, $applicationKey)
            : null;

        if ($rootSalt === null) {
            return null;
        }

        return hash_hmac(
            'sha256',
            sprintf('%s:site:%s:period:%d', self::APP_KEY_DERIVATION_CONTEXT, $siteId ?? 'unknown', $this->rotationPeriod($at)),
            $rootSalt,
        );
    }

    private function configuredPrivateSalt(): ?string
    {
        $configuredSalt = config('capell-insights.hash_salt');

        if (! is_string($configuredSalt)) {
            return null;
        }

        $configuredSalt = trim($configuredSalt);

        if ($configuredSalt === '' || $configuredSalt === 'capell-insights') {
            return null;
        }

        return $configuredSalt;
    }

    private function applicationKey(): ?string
    {
        $applicationKey = config('app.key');

        if (! is_string($applicationKey)) {
            return null;
        }

        $applicationKey = trim($applicationKey);

        if ($applicationKey === '' || $applicationKey === 'base64:') {
            return null;
        }

        return $applicationKey;
    }

    private function rotationPeriod(?CarbonImmutable $at): int
    {
        $rotationDays = config('capell-insights.hash_rotation_days', 30);
        $resolvedRotationDays = is_numeric($rotationDays) && (int) $rotationDays > 0 ? (int) $rotationDays : 30;
        $date = $at ?? now()->toImmutable();

        return ($date->year * 1_000) + intdiv($date->dayOfYear, $resolvedRotationDays);
    }
}
