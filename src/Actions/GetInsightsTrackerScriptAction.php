<?php

declare(strict_types=1);

namespace Capell\Insights\Actions;

use Capell\Insights\Providers\InsightsServiceProvider;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use ReflectionClass;
use RuntimeException;

final class GetInsightsTrackerScriptAction
{
    use AsFake;
    use AsObject;

    public function handle(): string
    {
        $providerPath = (new ReflectionClass(InsightsServiceProvider::class))->getFileName();
        $path = dirname(is_string($providerPath) ? $providerPath : __DIR__, 3) . '/resources/js/capell-insights.js';
        $modifiedAt = file_exists($path) ? (int) filemtime($path) : 0;

        return Cache::rememberForever(
            sprintf('capell-insights.tracker-script.%s', $modifiedAt),
            static function () use ($path): string {
                $contents = file_get_contents($path);

                throw_unless(is_string($contents), RuntimeException::class, 'Unable to read Capell Insights tracker script.');

                return $contents;
            },
        );
    }
}
