<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ?string run(?int $siteId = null, ?CarbonImmutable $at = null)
 */
final class ResolveInsightsHashSaltAction
{
    use AsFake;
    use AsObject;

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
            sprintf('%s:site:%s:day:%s', self::APP_KEY_DERIVATION_CONTEXT, $siteId ?? 'unknown', $this->localDay($siteId, $at)),
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

    /**
     * The salt changes at each midnight in the site's timezone and is derived, never stored,
     * so yesterday's visitor hashes cannot be recomputed or linked to today's.
     */
    private function localDay(?int $siteId, ?CarbonImmutable $at): string
    {
        return ($at ?? now()->toImmutable())
            ->setTimezone(ResolveInsightsSiteTimezoneAction::run($siteId))
            ->toDateString();
    }
}
