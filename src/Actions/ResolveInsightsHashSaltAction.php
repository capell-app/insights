<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

final class ResolveInsightsHashSaltAction
{
    use AsAction;

    public function handle(): string
    {
        $configuredSalt = config('capell-insights.hash_salt');

        if (is_string($configuredSalt) && $configuredSalt !== '' && $configuredSalt !== 'capell-insights') {
            return $configuredSalt;
        }

        $applicationKey = config('app.key');

        if (is_string($applicationKey) && $applicationKey !== '') {
            return hash_hmac('sha256', 'capell-insights', $applicationKey);
        }

        return 'capell-insights';
    }
}
