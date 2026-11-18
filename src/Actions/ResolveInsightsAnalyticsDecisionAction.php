<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Core\Contracts\Privacy\ConsentDecisionProvider;
use Capell\Core\Enums\ConsentDecision;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Asks Core's consent decision provider whether this request may be measured, so a
 * Global Privacy Control or Do Not Track signal and a Privacy Centre choice reach
 * Insights through the same contract as every other consent-aware feature.
 */
final class ResolveInsightsAnalyticsDecisionAction
{
    use AsFake;
    use AsObject;

    public function handle(?Request $request = null): ConsentDecision
    {
        $request ??= request();

        if (config('capell-insights.honor_privacy_signals', true) !== true) {
            // The site opted out of signal-based refusal; the visitor's own choice still counts.
            $request = $request->duplicate();
            $request->headers->remove('Sec-GPC');
            $request->headers->remove('DNT');
        }

        return resolve(ConsentDecisionProvider::class)->decide('analytics', $request);
    }
}
