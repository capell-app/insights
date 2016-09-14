<?php

declare(strict_types=1);

namespace Capell\Insights\Http\Controllers;

use Capell\Insights\Actions\ResolveInsightsConsentPolicyAction;
use Illuminate\Http\JsonResponse;

final class InsightsConsentPolicyController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(
            ResolveInsightsConsentPolicyAction::run()->toArray(),
            headers: ['Cache-Control' => 'private, no-store'],
        );
    }
}
