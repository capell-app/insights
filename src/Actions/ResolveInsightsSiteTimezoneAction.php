<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use DateTimeZone;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Resolves the timezone whose midnight starts an Insights reporting day for a site:
 * a per-site override, then the Insights default, then the application timezone, then UTC.
 *
 * @method static string run(?int $siteId = null)
 */
final class ResolveInsightsSiteTimezoneAction
{
    use AsFake;
    use AsObject;

    public function handle(?int $siteId = null): string
    {
        $siteTimezones = config('capell-insights.site_timezones', []);
        $candidates = [
            is_array($siteTimezones) && $siteId !== null ? ($siteTimezones[$siteId] ?? null) : null,
            config('capell-insights.timezone'),
            config('app.timezone'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, DateTimeZone::listIdentifiers(), true)) {
                return $candidate;
            }
        }

        return 'UTC';
    }
}
