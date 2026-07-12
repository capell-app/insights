<?php

declare(strict_types=1);

namespace Capell\Insights\Manifest;

use Capell\Core\Contracts\Extensions\ExtensionContribution;
use Capell\Core\Contracts\Extensions\RunsScheduledExtensionJob;

final class InsightsPurgeScheduleContribution implements ExtensionContribution, RunsScheduledExtensionJob
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^0.0';
    }
}
