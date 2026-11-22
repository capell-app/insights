<?php

declare(strict_types=1);

namespace Capell\Insights\Data;

use Spatie\LaravelData\Data;

/**
 * The tracking and consent choices currently in force, taken from the saved Insights settings.
 */
final class InsightsRuntimeSettingsData extends Data
{
    /**
     * @param  list<string>  $ignoredPaths
     * @param  list<string>  $ignoredSelectors
     */
    public function __construct(
        public bool $enabled,
        public bool $trackPageViews,
        public bool $trackClicks,
        public bool $automaticClickTracking,
        public bool $requireConsentForAllRegions,
        public string $policyVersion,
        public bool $hashVisitorData,
        public array $ignoredPaths,
        public array $ignoredSelectors,
    ) {}
}
