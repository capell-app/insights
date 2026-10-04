<?php

declare(strict_types=1);

namespace Capell\Insights\Tests\Fixtures;

use Override;
use RuntimeException;
use Torann\GeoIP\Location;
use Torann\GeoIP\Services\AbstractService;

final class FailingGeoIpService extends AbstractService
{
    public int $lookups = 0;

    #[Override]
    public function locate(mixed $ip): Location
    {
        $this->lookups++;

        throw new RuntimeException('Geography provider unavailable');
    }
}
