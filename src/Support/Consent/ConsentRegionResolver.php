<?php

declare(strict_types=1);

namespace Capell\Insights\Support\Consent;

use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Settings\InsightsSettings;
use Illuminate\Http\Request;
use Throwable;
use Torann\GeoIP\GeoIP;
use Torann\GeoIP\Location;

final class ConsentRegionResolver
{
    private const array UK_AND_EUROPE_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE',
        'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT',
        'RO', 'SK', 'SI', 'ES', 'SE', 'GB', 'IS', 'LI', 'NO', 'CH',
    ];

    public function resolve(): InsightsConsentRegion
    {
        $edgeRegion = $this->resolveEdgeRegion(request());

        if ($edgeRegion instanceof InsightsConsentRegion) {
            return $edgeRegion;
        }

        $geoipService = config('geoip.service');

        if (function_exists('geoip') && app()->bound('geoip') && is_string($geoipService) && $geoipService !== '') {
            try {
                $geoip = resolve('geoip');
                $ip = request()->ip();

                if (! $geoip instanceof GeoIP || $ip === null || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return InsightsConsentRegion::Unknown;
                }

                return $this->resolveProviderRegion($geoip, $ip);
            } catch (Throwable) {
                // A failed lookup must not enable tracking through a permissive default.
                return InsightsConsentRegion::Unknown;
            }
        }

        return $this->resolveConfiguredRegion() ?? InsightsConsentRegion::Unknown;
    }

    public function resolveFromLocation(mixed $location): InsightsConsentRegion
    {
        $countryCode = $this->countryCodeFromLocation($location);

        if ($countryCode === null || data_get($location, 'default', false)) {
            return InsightsConsentRegion::Unknown;
        }

        return $this->regionForCountryCode($countryCode);
    }

    private function resolveProviderRegion(GeoIP $geoip, string $ip): InsightsConsentRegion
    {
        // This lookup is always for the requesting visitor, so both cache modes apply.
        $cache = in_array($geoip->config('cache', 'none'), ['all', 'some'], true)
            ? $geoip->getCache()
            : null;
        // The ordinary GeoIP cache can contain defaults whose flags were overwritten.
        $key = 'capell-insights:consent-country:v1:' . $ip;
        $cachedRegion = $this->resolveFromLocation($cache?->get($key));

        if ($cachedRegion !== InsightsConsentRegion::Unknown) {
            return $cachedRegion;
        }

        // getLocation() hides failures behind fallback hydration and rewrites default flags.
        $location = $geoip->getService()->locate($ip);
        $region = $this->resolveFromLocation($location);

        if ($region !== InsightsConsentRegion::Unknown) {
            $cache?->set($key, new Location([
                'iso_code' => $this->countryCodeFromLocation($location),
                'default' => false,
            ]));
        }

        return $region;
    }

    private function regionForCountryCode(string $countryCode): InsightsConsentRegion
    {
        if (in_array($countryCode, self::UK_AND_EUROPE_COUNTRY_CODES, true)) {
            return InsightsConsentRegion::UkOrEurope;
        }

        return InsightsConsentRegion::OutsideUkOrEurope;
    }

    private function resolveEdgeRegion(Request $request): ?InsightsConsentRegion
    {
        $parameter = config('capell-insights.edge_country_server_parameter', 'CAPELL_EDGE_COUNTRY');

        // HTTP_* keys are client headers, never server-authenticated edge metadata.
        if (! is_string($parameter) || $parameter === '' || str_starts_with(strtoupper($parameter), 'HTTP_')) {
            return null;
        }

        // FPM imports process variables into $_SERVER. local_only excludes
        // FastCGI request params, which ordinary getenv() would also return.
        if (getenv($parameter, true) !== false) {
            return null;
        }

        $countryCode = $this->normalizeCountryCode($request->server($parameter));

        if ($countryCode === null) {
            return null;
        }

        return $this->regionForCountryCode($countryCode);
    }

    private function resolveConfiguredRegion(): ?InsightsConsentRegion
    {
        // The admin setting is the editor-facing control; the config value is
        // only a deployment fallback when no setting has been saved.
        $configuredRegion = $this->settingsRegion() ?? config('capell-insights.default_consent_region');

        if (! is_string($configuredRegion)) {
            return null;
        }

        return InsightsConsentRegion::tryFrom($configuredRegion);
    }

    private function settingsRegion(): ?string
    {
        if (! app()->bound(InsightsSettings::class)) {
            return null;
        }

        /** @var InsightsSettings $settings */
        $settings = resolve(InsightsSettings::class);

        return $settings->default_consent_region;
    }

    private function countryCodeFromLocation(mixed $location): ?string
    {
        $countryCode = null;

        if (is_array($location)) {
            $countryCode = $location['iso_code']
                ?? $location['isoCode']
                ?? $location['country_code']
                ?? $location['countryCode']
                ?? null;
        }

        if (is_object($location)) {
            $countryCode = $location->iso_code
                ?? $location->isoCode
                ?? $location->country_code
                ?? $location->countryCode
                ?? null;
        }

        return $this->normalizeCountryCode($countryCode);
    }

    private function normalizeCountryCode(mixed $countryCode): ?string
    {
        if (! is_string($countryCode)) {
            return null;
        }

        if (! in_array($countryCode, Iso3166CountryCodes::ALPHA_2, true)) {
            return null;
        }

        return $countryCode;
    }
}
