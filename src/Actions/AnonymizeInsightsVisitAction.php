<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Models\InsightsVisit;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static InsightsVisit run(InsightsVisit $visit)
 */
final class AnonymizeInsightsVisitAction
{
    use AsFake;
    use AsObject;

    public function handle(InsightsVisit $visit): InsightsVisit
    {
        return DB::transaction(function () use ($visit): InsightsVisit {
            $visit->events()->update([
                'visit_id' => null,
                'url' => '/erased-insights-event',
                'path' => '/erased-insights-event',
                'title' => null,
                'label' => null,
                'location' => null,
                'target_selector' => null,
                'viewport_x' => null,
                'viewport_y' => null,
                'document_x' => null,
                'document_y' => null,
                'metadata' => null,
            ]);

            $visit->consents()->update([
                'ip_hash' => null,
                'user_agent_hash' => null,
            ]);

            $visit->forceFill([
                'uuid' => $this->erasedUuid($visit),
                'landing_url' => '/erased-insights-visit',
                'referrer_url' => null,
                'utm_source' => null,
                'utm_medium' => null,
                'utm_campaign' => null,
                'ip_hash' => null,
                'user_agent_hash' => null,
                'legacy_session_id' => null,
            ])->save();

            return $visit->refresh();
        });
    }

    private function erasedUuid(InsightsVisit $visit): string
    {
        $key = $visit->getKey();
        $suffix = is_int($key) || is_string($key) ? (string) $key : hash('xxh3', spl_object_hash($visit));
        $numericSuffix = preg_replace('/\D/', '', $suffix) ?: (string) hexdec(substr(hash('xxh3', $suffix), 0, 8));

        return sprintf('00000000-0000-4000-8000-%012d', ((int) $numericSuffix) % 1000000000000);
    }
}
