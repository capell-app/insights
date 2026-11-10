<?php

declare(strict_types=1);

use Capell\Tests\Support\CsrfExemptRoutes;

it('reaches every CSRF-exempt endpoint without a CSRF token while CSRF protection is enforced', function (): void {
    CsrfExemptRoutes::assertDeclaredExemptionsHold('Capell\\Insights\\Http\\Controllers\\');
});
