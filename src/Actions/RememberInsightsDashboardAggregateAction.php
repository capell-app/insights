<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsWindowData;
use Closure;
use Illuminate\Support\Facades\Cache;
use JsonException;
use Lorisleiva\Actions\Concerns\AsAction;

final class RememberInsightsDashboardAggregateAction
{
    use AsAction;

    private const string CACHE_PREFIX = 'capell-insights:dashboard:';

    private const string CACHE_TAG = 'insights';

    public static function flush(): void
    {
        if (! Cache::supportsTags()) {
            return;
        }

        Cache::tags([self::CACHE_TAG])->flush();
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $parts
     */
    public static function key(string $name, array $parts): string
    {
        try {
            $encodedParts = json_encode($parts, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $encodedParts = serialize($parts);
        }

        return self::CACHE_PREFIX . $name . ':' . hash('sha256', $encodedParts);
    }

    /**
     * @param  array<string, bool|float|int|string|null>  $parts
     */
    public static function windowKey(string $name, ?InsightsWindowData $window, array $parts = []): string
    {
        return self::key($name, [
            'locale' => app()->getLocale(),
            'starts_at' => $window?->startsAt->toIso8601String(),
            'ends_at' => $window?->endsAt->toIso8601String(),
            'site_id' => $window?->siteId,
            'language_id' => $window?->languageId,
            ...$parts,
        ]);
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    public function handle(string $key, Closure $callback): mixed
    {
        $ttlSeconds = $this->ttlSeconds();

        if ($ttlSeconds < 1) {
            return $callback();
        }

        if (Cache::supportsTags()) {
            return Cache::tags([self::CACHE_TAG])->remember($key, $ttlSeconds, $callback);
        }

        return Cache::remember($key, $ttlSeconds, $callback);
    }

    private function ttlSeconds(): int
    {
        $ttlSeconds = config('capell-insights.dashboard_cache_ttl_seconds', 60);

        return is_int($ttlSeconds) ? $ttlSeconds : 60;
    }
}
