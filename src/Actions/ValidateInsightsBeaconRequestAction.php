<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ValidateInsightsBeaconRequestAction
{
    use AsAction;

    public function handle(Request $request): void
    {
        throw_if((bool) config('capell-insights.require_signed_beacons', false) && ! $request->hasValidSignature(), AccessDeniedHttpException::class, 'Invalid insights beacon signature.');

        if ((bool) config('capell-insights.validate_beacon_origin', true) === false) {
            return;
        }

        $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer');

        if (! is_string($origin) || $origin === '') {
            return;
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        $requestHost = $request->getHost();

        if (is_string($originHost) && strcasecmp($originHost, $requestHost) === 0) {
            return;
        }

        if ($this->allowedOriginsContain($origin)) {
            return;
        }

        throw new AccessDeniedHttpException('Invalid insights beacon origin.');
    }

    private function allowedOriginsContain(string $origin): bool
    {
        $allowedOrigins = config('capell-insights.allowed_beacon_origins', []);

        if (! is_array($allowedOrigins)) {
            return false;
        }

        $normalizedOrigin = $this->normalizedOrigin($origin);

        if ($normalizedOrigin === null) {
            return false;
        }

        foreach ($allowedOrigins as $allowedOrigin) {
            if (! is_string($allowedOrigin)) {
                continue;
            }

            if ($allowedOrigin === '') {
                continue;
            }

            if (hash_equals((string) $this->normalizedOrigin($allowedOrigin), $normalizedOrigin)) {
                return true;
            }
        }

        return false;
    }

    private function normalizedOrigin(string $origin): ?string
    {
        $scheme = parse_url($origin, PHP_URL_SCHEME);
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);

        if (! is_string($scheme) || ! is_string($host)) {
            return null;
        }

        return sprintf(
            '%s://%s%s',
            strtolower($scheme),
            strtolower($host),
            is_int($port) ? ':' . $port : '',
        );
    }
}
