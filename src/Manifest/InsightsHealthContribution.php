<?php

declare(strict_types=1);

namespace Capell\Insights\Manifest;

use Capell\Core\Contracts\Extensions\ChecksExtensionHealth;

final class InsightsHealthContribution implements ChecksExtensionHealth
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
