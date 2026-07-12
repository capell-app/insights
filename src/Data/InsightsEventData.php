<?php

declare(strict_types=1);

namespace Capell\Insights\Data;

use Capell\Insights\Enums\InsightsEventType;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class InsightsEventData extends Data
{
    public function __construct(
        public InsightsEventType $type,
        public string $url,
        public ?string $title = null,
        public ?string $eventName = null,
        public ?string $label = null,
        public ?string $location = null,
        public ?string $targetSelector = null,
        public ?int $viewportX = null,
        public ?int $viewportY = null,
        public ?int $documentX = null,
        public ?int $documentY = null,
        public ?InsightsEventMetadataData $metadata = null,
    ) {}

    public function path(): string
    {
        $path = parse_url($this->url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return '/';
        }

        return $path;
    }

    public function withoutUrlCredentialsOrQuery(): self
    {
        return new self(
            type: $this->type,
            url: $this->minimizedUrl(),
            title: $this->title,
            eventName: $this->eventName,
            label: $this->label,
            location: $this->location,
            targetSelector: $this->targetSelector,
            viewportX: $this->viewportX,
            viewportY: $this->viewportY,
            documentX: $this->documentX,
            documentY: $this->documentY,
            metadata: $this->metadata,
        );
    }

    private function minimizedUrl(): string
    {
        $scheme = parse_url($this->url, PHP_URL_SCHEME);
        $host = parse_url($this->url, PHP_URL_HOST);
        $port = parse_url($this->url, PHP_URL_PORT);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return $this->path();
        }

        return sprintf(
            '%s://%s%s%s',
            strtolower($scheme),
            strtolower($host),
            is_int($port) ? ':' . $port : '',
            $this->path(),
        );
    }
}
