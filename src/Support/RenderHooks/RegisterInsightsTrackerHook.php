<?php

declare(strict_types=1);

namespace Capell\Insights\Support\RenderHooks;

use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Support\Str;

class RegisterInsightsTrackerHook
{
    /** @param RenderHookRegistry<RenderHookContext> $registry */
    public function __construct(private readonly RenderHookRegistry $registry) {}

    public function register(): void
    {
        $this->registry->register(
            RenderHookLocation::BodyEnd,
            static fn (): string => self::shouldRenderForCurrentRequest()
                ? view('capell-insights::tracker')->render()
                : '',
        );
    }

    private static function shouldRenderForCurrentRequest(): bool
    {
        $path = '/' . trim(request()->path(), '/');
        $ignoredPaths = config('capell-insights.ignored_paths', []);

        if (! is_array($ignoredPaths)) {
            return true;
        }

        foreach ($ignoredPaths as $ignoredPath) {
            if (! is_string($ignoredPath)) {
                continue;
            }
            if (trim((string) $ignoredPath) === '') {
                continue;
            }
            if (Str::is('/' . trim($ignoredPath, '/'), $path)) {
                return false;
            }
        }

        return true;
    }
}
