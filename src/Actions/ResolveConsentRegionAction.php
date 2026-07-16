<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Enums\InsightsConsentRegion;
use Capell\Insights\Support\Consent\ConsentRegionResolver;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static InsightsConsentRegion run()
 */
final class ResolveConsentRegionAction
{
    use AsFake;
    use AsObject;

    public function handle(): InsightsConsentRegion
    {
        return resolve(ConsentRegionResolver::class)->resolve();
    }
}
