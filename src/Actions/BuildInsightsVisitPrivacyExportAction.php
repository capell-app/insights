<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Models\InsightsConsent;
use Capell\Insights\Models\InsightsEvent;
use Capell\Insights\Models\InsightsVisit;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Discloses what a visit record holds about the visitor. The keyed hashes of the IP address,
 * user agent and visitor are left out: they identify the visit inside this system, are not
 * reversible, and mean nothing to the person they are disclosed to. The legacy session id is
 * also left out: it is the raw session identifier copied from the old page view table, so it
 * can be a live session token, and the erasure clears it.
 *
 * @method static array<string, mixed> run(Model $subject)
 */
final class BuildInsightsVisitPrivacyExportAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array<string, mixed>
     */
    public function handle(Model $subject): array
    {
        if (! $subject instanceof InsightsVisit) {
            return [];
        }

        return [
            'visit' => [
                'legacy_session_id_present' => $subject->legacy_session_id !== null,
                'uuid' => $subject->uuid,
                'landing_url' => $subject->landing_url,
                'referrer_url' => $subject->referrer_url,
                'utm_source' => $subject->utm_source,
                'utm_medium' => $subject->utm_medium,
                'utm_campaign' => $subject->utm_campaign,
                'consent_region' => $subject->consent_region->value,
                'consent_status' => $subject->consent_status->value,
                'started_at' => $subject->started_at?->toIso8601String(),
                'last_seen_at' => $subject->last_seen_at?->toIso8601String(),
            ],
            'consents' => $subject->consents()->oldest('id')->get()
                ->map(static fn (InsightsConsent $consent): array => [
                    'consent_region' => $consent->consent_region->value,
                    'status' => $consent->status->value,
                    'categories' => $consent->categories->toArray(),
                    'policy_version' => $consent->policy_version,
                    'decided_at' => $consent->decided_at?->toIso8601String(),
                ])->values()->all(),
            'events' => $subject->events()->orderBy('sequence')->get()
                ->map(static fn (InsightsEvent $event): array => [
                    'type' => $event->type->value,
                    'url' => $event->url,
                    'path' => $event->path,
                    'title' => $event->title,
                    'event_name' => $event->event_name,
                    'label' => $event->label,
                    'location' => $event->location,
                    'target_selector' => $event->target_selector,
                    'viewport_x' => $event->viewport_x,
                    'viewport_y' => $event->viewport_y,
                    'document_x' => $event->document_x,
                    'document_y' => $event->document_y,
                    'metadata' => $event->metadata?->toArray(),
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }
}
