<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The consent route is exempt from CSRF tokens because it is served on cached pages, so a hostile page could otherwise
 * post a consent choice for a visitor with a top-level form. A browser states where a request came from in headers the
 * page cannot set, so the choice is recorded only for a request that came from this site. A request that does not say
 * where it came from is refused.
 *
 * @method static void run(Request $request)
 */
final class RequireSameOriginConsentRequestAction
{
    use AsFake;
    use AsObject;

    public function handle(Request $request): void
    {
        $fetchSite = $request->headers->get('Sec-Fetch-Site');

        if (is_string($fetchSite) && $fetchSite !== '') {
            throw_unless(strtolower($fetchSite) === 'same-origin', AccessDeniedHttpException::class, 'Insights consent must come from this site.');

            return;
        }

        $source = $request->headers->get('Origin');

        if (! is_string($source) || $source === '') {
            $source = $request->headers->get('Referer');
        }

        throw_unless(is_string($source) && $this->origin($source) === $this->origin($request->getSchemeAndHttpHost()), AccessDeniedHttpException::class, 'Insights consent must come from this site.');
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        return $scheme . '://' . strtolower($parts['host']) . ':' . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }
}
