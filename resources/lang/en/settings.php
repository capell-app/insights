<?php

declare(strict_types=1);

return [
    'advanced' => 'Advanced',
    'collection' => 'Collection',
    'consent_retention' => 'Consent and retention',
    'developer' => 'Developer: endpoint, hashing and exclusions',
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
    'hash_salt' => 'Hash salt',
    'hash_salt_helper' => 'Optional private salt saved in Insights settings. The hashing runtime currently reads capell-insights.hash_salt; leave that config value empty to derive a salt from APP_KEY.',
    'hash_visitor_data' => 'Hash visitor data',
    'hash_visitor_data_helper' => 'Visitor hashing choice saved in Insights settings. Visit and consent storage currently read capell-insights.hash_visitor_data; update that config value for changes to take effect.',
    'ignored_paths' => 'Ignored paths',
    'ignored_paths_helper' => 'One path pattern per line saved in Insights settings. Runtime filtering currently reads capell-insights.ignored_paths; update that config value for changes to take effect.',
    'ignored_selectors' => 'Ignored selectors',
    'ignored_selectors_helper' => 'One CSS selector per line saved in Insights settings. The frontend tracker currently reads capell-insights.ignored_selectors; update that config value for changes to take effect.',
    'policy_version' => 'Policy version',
    'policy_version_helper' => 'Consent policy version saved in Insights settings. Consent records and policy checks currently read capell-insights.policy_version; update that config value for changes to take effect.',
    'require_consent_for_all_regions' => 'Require consent for all regions',
    'retention_days' => 'Retention',
    'retention_days_helper' => 'Monthly cleanup removes events, consents, and rollups older than this retention period, plus inactive visits with no remaining events.',
    'route_prefix' => 'Route prefix',
    'route_prefix_helper' => 'Route prefix saved in Insights settings. Route registration currently reads capell-insights.route_prefix; update that config value for endpoint URLs to change.',
    'track_clicks' => 'Track clicks',
    'track_forms' => 'Track forms',
    'track_page_views' => 'Track page views',
];
