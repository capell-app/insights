<?php

declare(strict_types=1);

namespace Capell\Insights\Data;

use Spatie\LaravelData\Data;

final class InsightsBeaconData extends Data
{
    /**
     * @param  list<array{data: InsightsEventData, occurred_at: string|null}>  $events
     */
    public function __construct(
        public ?string $visitUuid,
        public array $events = [],
    ) {}

    /**
     * @param  array{visit_id?: mixed, events?: list<array<string, mixed>>}  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $visitUuid = isset($validated['visit_id']) && is_string($validated['visit_id'])
            ? $validated['visit_id']
            : null;

        $events = [];
        $eventPayloads = $validated['events'] ?? [];

        foreach ($eventPayloads as $eventPayload) {
            $occurredAt = isset($eventPayload['occurred_at']) && is_string($eventPayload['occurred_at'])
                ? $eventPayload['occurred_at']
                : null;

            $events[] = [
                'data' => InsightsEventData::from($eventPayload),
                'occurred_at' => $occurredAt,
            ];
        }

        return new self(
            visitUuid: $visitUuid,
            events: $events,
        );
    }

    public function forQueue(): self
    {
        return new self(
            visitUuid: $this->visitUuid,
            events: array_map(
                static fn (array $event): array => [
                    'data' => $event['data']->withoutUrlCredentialsOrQuery(),
                    'occurred_at' => $event['occurred_at'],
                ],
                $this->events,
            ),
        );
    }
}
