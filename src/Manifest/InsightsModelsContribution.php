<?php

declare(strict_types=1);

namespace Capell\Insights\Manifest;

use Capell\Core\Contracts\Extensions\ExtensionContribution;

final class InsightsModelsContribution implements ExtensionContribution
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^0.0';
    }
}
