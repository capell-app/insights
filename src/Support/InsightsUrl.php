<?php

declare(strict_types=1);

namespace Capell\Insights\Support;

/**
 * Reduces visit URLs to what reports need, so query strings (tokens, emails, search terms) are never stored.
 */
final class InsightsUrl
{
    /** @var list<string> */
    public const array UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign'];

    private const int MAX_LENGTH = 2048;

    private const int MAX_UTM_LENGTH = 255;

    public static function originAndPath(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';

        return mb_substr(sprintf('%s://%s%s%s', $scheme, strtolower($parts['host']), $port, $path === '' ? '/' : $path), 0, self::MAX_LENGTH);
    }

    public static function host(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * @return array{utm_source: string|null, utm_medium: string|null, utm_campaign: string|null}
     */
    public static function utmParameters(?string $url): array
    {
        $parameters = ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null];
        $query = $url === null ? null : parse_url(trim($url), PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return $parameters;
        }

        parse_str($query, $values);

        foreach (self::UTM_KEYS as $key) {
            $value = $values[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $parameters[$key] = mb_substr(trim($value), 0, self::MAX_UTM_LENGTH);
            }
        }

        return $parameters;
    }
}
