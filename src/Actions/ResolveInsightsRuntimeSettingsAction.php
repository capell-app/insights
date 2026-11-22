<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Data\InsightsRuntimeSettingsData;
use Capell\Insights\Settings\InsightsSettings;
use Illuminate\Database\QueryException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * @method static InsightsRuntimeSettingsData run()
 */
final class ResolveInsightsRuntimeSettingsAction
{
    use AsFake;
    use AsObject;

    public function handle(): InsightsRuntimeSettingsData
    {
        $settings = $this->savedSettings();

        // Saved settings are authoritative. Config only applies before the
        // settings migration has run, for example during installation.
        if ($settings instanceof InsightsSettings) {
            return new InsightsRuntimeSettingsData(
                enabled: $settings->enabled,
                trackPageViews: $settings->track_page_views,
                trackClicks: $settings->track_clicks,
                automaticClickTracking: $settings->automatic_click_tracking,
                requireConsentForAllRegions: $settings->require_consent_for_all_regions,
                policyVersion: $this->policyVersion($settings->policy_version),
                hashVisitorData: $settings->hash_visitor_data,
                ignoredPaths: $this->stringList($settings->ignored_paths),
                ignoredSelectors: $this->stringList($settings->ignored_selectors),
            );
        }

        return new InsightsRuntimeSettingsData(
            enabled: config('capell-insights.enabled', true) === true,
            trackPageViews: config('capell-insights.track_page_views', true) === true,
            trackClicks: config('capell-insights.track_clicks', true) === true,
            automaticClickTracking: config('capell-insights.automatic_click_tracking', true) === true,
            requireConsentForAllRegions: config('capell-insights.require_consent_for_all_regions', false) === true,
            policyVersion: $this->policyVersion(config('capell-insights.policy_version')),
            hashVisitorData: config('capell-insights.hash_visitor_data', true) === true,
            ignoredPaths: $this->stringList(config('capell-insights.ignored_paths', [])),
            ignoredSelectors: $this->stringList(config('capell-insights.ignored_selectors', [])),
        );
    }

    private function savedSettings(): ?InsightsSettings
    {
        if (! app()->bound(InsightsSettings::class)) {
            return null;
        }

        try {
            $settings = resolve(InsightsSettings::class);

            // Reading a property loads the stored values, so missing rows
            // surface here rather than at a call site.
            return is_bool($settings->enabled) ? $settings : null;
        } catch (MissingSettings|QueryException) {
            return null;
        }
    }

    private function policyVersion(mixed $value): string
    {
        return is_string($value) && trim($value) !== '' ? $value : '1.0';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            $values,
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));
    }
}
