<?php

declare(strict_types=1);

namespace Capell\Insights\Support\RenderHooks;

use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Data\RenderHookContext;
use Illuminate\Support\Str;

final class RegisterInsightsTrackerHook implements RenderHookExtensionInterface
{
    public function render(RenderHookContext $context): string
    {
        if (! $this->shouldRenderForCurrentRequest()) {
            return '';
        }

        return view('capell-insights::tracker')->render();
    }

    public function shouldRenderForCurrentRequest(): bool
    {
        if (config('capell-insights.require_signed_beacons', false) === true) {
            return false;
        }

        $path = '/' . trim(request()->path(), '/');
        $ignoredPaths = config('capell-insights.ignored_paths', []);

        if (! is_array($ignoredPaths)) {
            return true;
        }

        foreach ($ignoredPaths as $ignoredPath) {
            if (! is_string($ignoredPath)) {
                continue;
            }

            if (trim($ignoredPath) === '') {
                continue;
            }

            if (Str::is('/' . trim($ignoredPath, '/'), $path)) {
                return false;
            }
        }

        return true;
    }
}
