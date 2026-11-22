<?php

declare(strict_types=1);

return [
    'advanced' => 'Advanced',
    'collection' => 'Collection',
    'consent_retention' => 'Consent and retention',
    'developer' => 'Developer: hashing and exclusions',
    'recommended_privacy' => 'Recommended: require consent in every region and keep visitor hashing enabled. Existing choices are preserved until you save.',

    'automatic_click_tracking' => 'Automatic click tracking',
    'default_consent_region' => 'Default consent region',
    'default_consent_region_helper' => 'Fallback consent region saved in Insights settings for use when automatic region detection is unavailable. Leave blank to use capell-insights.default_consent_region; if that config value is also unset, the fallback region is unknown.',
    'event_types' => [
        'page_view' => 'Page view',
        'click' => 'Click',
        'form' => 'Form submission',
        'custom' => 'Custom event',
        'consent' => 'Consent',
    ],
    'enabled' => 'Enable insights',
    'fieldset' => 'Insights',
    'hash_visitor_data' => 'Hash visitor data',
    'hash_visitor_data_helper' => 'Store visitor identifiers only as one-way hashes. When this is off, no visitor identifier is stored at all. The change affects only visits and consents recorded after you save.',
    'ignored_paths' => 'Ignored paths',
    'ignored_paths_helper' => 'One path pattern per line, such as /account*. Matching pages load no tracker and record no visits or events.',
    'ignored_selectors' => 'Ignored selectors',
    'ignored_selectors_helper' => 'One CSS selector per line. Clicks on matching elements, or inside them, are not recorded.',
    'policy_version' => 'Policy version',
    'policy_version_helper' => 'Change this when your consent policy changes. Visitors who agreed to an earlier version are asked again before anything is recorded.',
    'require_consent_for_all_regions' => 'Require consent for all regions',
    'retention_days' => 'Retention',
    'retention_days_helper' => 'Monthly cleanup removes events, consents, and rollups older than this retention period, plus inactive visits with no remaining events.',
    'track_clicks' => 'Track clicks',
    'track_page_views' => 'Track page views',
];
