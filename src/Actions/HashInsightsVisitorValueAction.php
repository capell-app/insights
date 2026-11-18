<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static string|null run(?string $value, ?int $siteId)
 */
final class HashInsightsVisitorValueAction
{
    use AsFake;
    use AsObject;

    public function handle(?string $value, ?int $siteId): ?string
    {
        if (config('capell-insights.hash_visitor_data', true) !== true) {
            return null;
        }

        if ($value === null || trim($value) === '') {
            return null;
        }

        $salt = ResolveInsightsHashSaltAction::run($siteId);

        return $salt === null ? null : hash_hmac('sha256', $value, $salt);
    }
}
