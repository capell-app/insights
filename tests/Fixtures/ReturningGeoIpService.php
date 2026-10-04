<?php

declare(strict_types=1);

namespace Capell\Insights\Tests\Fixtures;

use Override;
use RuntimeException;
use Torann\GeoIP\Location;
use Torann\GeoIP\Services\AbstractService;

final class ReturningGeoIpService extends AbstractService
{
    public int $lookups = 0;

    public bool $unavailable = false;

    #[Override]
    public function locate(mixed $ip): Location|false|null
    {
        $this->lookups++;

        if ($this->unavailable) {
            throw new RuntimeException('Geography provider unavailable');
        }

        return match ($this->config('result')) {
            'missing' => null,
            'false' => false,
            default => new Location([
                'ip' => $ip,
                'iso_code' => $this->config('result') === 'invalid' ? 'ZZ' : 'US',
                'city' => $this->config('result') === 'default' ? 'Boston' : 'New Haven',
                'default' => $this->config('result') === 'default',
            ]),
        };
    }
}
