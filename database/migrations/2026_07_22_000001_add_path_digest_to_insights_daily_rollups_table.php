<?php

declare(strict_types=1);

use Capell\Core\Enums\Database\DatabaseCapability;
use Capell\Core\Facades\CapellDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string UNIQUE_INDEX = 'insights_rollups_day_scope_type_path_unique';

    public function up(): void
    {
        $tableName = $this->tableName();

        if (! Schema::hasTable($tableName)) {
            return;
        }

        $connection = Schema::getConnection();
        $schema = CapellDatabase::for($connection)->schemaDialect();
        $usesGeneratedDigest = $schema->supports(DatabaseCapability::HashGeneratedColumn, $connection);

        if (! Schema::hasColumn($tableName, 'path_digest')) {
            if ($usesGeneratedDigest) {
                $digest = $schema->hashColumn($tableName, 'path_digest', 'path');
                DB::statement($digest->sql, $digest->bindings);
            } else {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->char('path_digest', 64)->nullable();
                });
            }
        }

        if (! $usesGeneratedDigest) {
            DB::table($tableName)
                ->select(['id', 'path', 'path_digest'])
                ->orderBy('id')
                ->chunkById(500, function (Collection $rollups) use ($tableName): void {
                    $rollups->each(function (stdClass $rollup) use ($tableName): void {
                        $path = (string) $rollup->path;
                        $pathDigest = hash('sha256', $path);

                        if ($rollup->path_digest === $pathDigest) {
                            return;
                        }

                        DB::table($tableName)
                            ->where('id', (int) $rollup->id)
                            ->update(['path_digest' => $pathDigest]);
                    });
                });

            Schema::table($tableName, function (Blueprint $table): void {
                $table->char('path_digest', 64)->nullable(false)->change();
            });
        }

        if ($this->hasDigestUniqueIndex($tableName)) {
            return;
        }

        if (Schema::hasIndex($tableName, self::UNIQUE_INDEX)) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE_INDEX);
            });
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->unique(
                ['day', 'site_scope_id', 'language_scope_id', 'type', 'path_digest'],
                self::UNIQUE_INDEX,
            );
        });
    }

    public function down(): void
    {
        // Intentionally forward-only: the digest schema may have been created by
        // the previously published initial migration before this correction.
    }

    private function hasDigestUniqueIndex(string $tableName): bool
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['name'] ?? null) !== self::UNIQUE_INDEX) {
                continue;
            }

            return ($index['unique'] ?? false) === true
                && ($index['columns'] ?? []) === ['day', 'site_scope_id', 'language_scope_id', 'type', 'path_digest'];
        }

        return false;
    }

    private function tableName(): string
    {
        $tableName = config('capell-insights.tables.daily_rollups', 'insights_daily_rollups');

        throw_if(! is_string($tableName) || preg_match('/^[A-Za-z_]\w*$/', $tableName) !== 1, RuntimeException::class, 'Insights daily rollups table name must be a safe SQL identifier.');

        return $tableName;
    }
};
