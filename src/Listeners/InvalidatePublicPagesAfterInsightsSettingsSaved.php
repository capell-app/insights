<?php

declare(strict_types=1);

namespace Capell\Insights\Listeners;

use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Site;
use Capell\Insights\Settings\InsightsSettings;
use Spatie\LaravelSettings\Events\SettingsSaved;

/**
 * The tracker and its configuration are rendered into cached public pages, so
 * a saved change only reaches visitors once those pages are rebuilt.
 */
final class InvalidatePublicPagesAfterInsightsSettingsSaved
{
    public function handle(SettingsSaved $event): void
    {
        if (! $event->settings instanceof InsightsSettings) {
            return;
        }

        $surrogateKeys = Site::query()
            ->pluck('id')
            ->map(static fn (mixed $siteId): string => 'site-' . (is_scalar($siteId) ? (string) $siteId : ''))
            ->reject(static fn (string $key): bool => $key === 'site-')
            ->all();

        if ($surrogateKeys === []) {
            return;
        }

        event(new FrontendSurrogateKeysInvalidated(array_values($surrogateKeys)));
    }
}
