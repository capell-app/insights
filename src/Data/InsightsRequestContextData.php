<?php

declare(strict_types=1);

namespace Capell\Insights\Data;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

final class InsightsRequestContextData extends Data
{
    public function __construct(
        public string $fullUrl,
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $referer,
        public ?string $origin,
        public ?int $siteId,
        public ?int $languageId,
        public ?string $utmSource,
        public ?string $utmMedium,
        public ?string $utmCampaign,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            fullUrl: $request->fullUrl(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            referer: $request->headers->get('referer'),
            origin: $request->headers->get('origin'),
            siteId: self::integerInput($request, 'site_id'),
            languageId: self::integerInput($request, 'language_id'),
            utmSource: self::stringInput($request, 'utm_source'),
            utmMedium: self::stringInput($request, 'utm_medium'),
            utmCampaign: self::stringInput($request, 'utm_campaign'),
        );
    }

    public function toRequest(): Request
    {
        $server = array_filter([
            'REMOTE_ADDR' => $this->ipAddress,
            'HTTP_USER_AGENT' => $this->userAgent,
            'HTTP_REFERER' => $this->referer,
            'HTTP_ORIGIN' => $this->origin,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return Request::create($this->fullUrl, 'POST', [
            'site_id' => $this->siteId,
            'language_id' => $this->languageId,
            'utm_source' => $this->utmSource,
            'utm_medium' => $this->utmMedium,
            'utm_campaign' => $this->utmCampaign,
        ], server: $server);
    }

    private static function integerInput(Request $request, string $key): ?int
    {
        $value = $request->input($key);

        return is_numeric($value) ? (int) $value : null;
    }

    private static function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
