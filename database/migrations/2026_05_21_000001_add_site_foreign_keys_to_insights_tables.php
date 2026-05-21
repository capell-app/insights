<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'insights_visits',
        'insights_events',
    ];

    public function up(): void
    {
        if (! $this->canAddForeignKeys()) {
            return;
        }

        foreach ($this->tables as $tableName) {
            $this->nullOrphanedSiteIds($tableName);

            try {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    $table->foreign('site_id', $tableName . '_site_id_foreign')
                        ->references('id')
                        ->on('sites')
                        ->nullOnDelete();
                });
            } catch (Throwable) {
                // Existing installations may already have the retrofit constraint.
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'site_id')) {
                continue;
            }

            try {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    $table->dropForeign($tableName . '_site_id_foreign');
                });
            } catch (Throwable) {
                // Constraint may not exist on this connection.
            }
        }
    }

    private function canAddForeignKeys(): bool
    {
        return DB::connection()->getDriverName() !== 'sqlite'
            && Schema::hasTable('sites');
    }

    private function nullOrphanedSiteIds(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'site_id')) {
            return;
        }

        DB::table($tableName)
            ->whereNotNull('site_id')
            ->whereNotIn('site_id', DB::table('sites')->select('id'))
            ->update(['site_id' => null]);
    }
};
