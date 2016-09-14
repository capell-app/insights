<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\FrontendOutputCacheInvalidator;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->bound(FrontendOutputCacheInvalidator::class)) {
            return;
        }

        resolve(FrontendOutputCacheInvalidator::class)->invalidateAll();
    }

    public function down(): void
    {
        // Invalidated public cache output cannot and should not be restored.
    }
};
