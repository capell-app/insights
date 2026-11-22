<?php

declare(strict_types=1);

namespace Capell\Insights\Support\RenderHooks;

use Capell\Core\Contracts\Rendering\RenderHookExtensionInterface;
use Capell\Core\Data\Rendering\RenderHookContext;
use Capell\Frontend\Facades\Frontend;
use Capell\Insights\Actions\ResolveInsightsRuntimeSettingsAction;
use Capell\Insights\Data\InsightsRuntimeSettingsData;
use Illuminate\Support\Str;

final class RegisterInsightsTrackerHook implements RenderHookExtensionInterface
{
    public const string OWNER = 'capell-app/insights';

    public const string KEY = 'insights-tracker';

    public const string RUNTIME_SETTINGS_FRONTEND_DATA_KEY = 'insights.runtime_settings';

    public function render(RenderHookContext $context): string
    {
        $runtimeSettings = $this->runtimeSettings();

        if (! $this->shouldRenderForCurrentRequest($runtimeSettings)) {
            return '';
        }

        return view('capell-insights::tracker', ['runtimeSettings' => $runtimeSettings])->render();
    }

    public function shouldRenderForCurrentRequest(?InsightsRuntimeSettingsData $runtimeSettings = null): bool
    {
        $runtimeSettings ??= $this->runtimeSettings();

        if (! $runtimeSettings->enabled || config('capell-insights.require_signed_beacons', false) === true) {
            return false;
        }

        $path = '/' . trim(request()->path(), '/');
        foreach ($runtimeSettings->ignoredPaths as $ignoredPath) {
            if (Str::is('/' . trim($ignoredPath, '/'), $path)) {
                return false;
            }
        }

        return true;
    }

    // Settings are prepared before the guarded public render, which must not query.
    private function runtimeSettings(): InsightsRuntimeSettingsData
    {
        $prepared = Frontend::getFrontendData(self::RUNTIME_SETTINGS_FRONTEND_DATA_KEY);

        return $prepared instanceof InsightsRuntimeSettingsData ? $prepared : ResolveInsightsRuntimeSettingsAction::run();
    }
}
