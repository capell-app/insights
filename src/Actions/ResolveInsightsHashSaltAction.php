<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveInsightsHashSaltAction
{
    use AsAction;

    public const string PUBLIC_DEFAULT_SALT = 'capell-insights';

    private const string APP_KEY_DERIVATION_CONTEXT = 'capell-insights';

    public function handle(): string
    {
        $configuredSalt = $this->configuredPrivateSalt();

        if ($configuredSalt !== null) {
            return $configuredSalt;
        }

        $applicationKey = $this->applicationKey();

        if ($applicationKey !== null) {
            return hash_hmac('sha256', self::APP_KEY_DERIVATION_CONTEXT, $applicationKey);
        }

        return self::PUBLIC_DEFAULT_SALT;
    }

    private function configuredPrivateSalt(): ?string
    {
        $configuredSalt = config('capell-insights.hash_salt');

        if (! is_string($configuredSalt)) {
            return null;
        }

        $configuredSalt = trim($configuredSalt);

        if ($configuredSalt === '' || $configuredSalt === self::PUBLIC_DEFAULT_SALT) {
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
}
