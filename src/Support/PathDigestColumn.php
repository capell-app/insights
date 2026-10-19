<?php

declare(strict_types=1);

namespace Capell\Insights\Support;

use Capell\Core\Enums\Database\DatabaseCapability;
use Capell\Core\Facades\CapellDatabase;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Whether the database computes `path_digest` itself.
 *
 * The server family only says whether a generated digest is supported. A table
 * restored from a schema dump can still hold a generated column on a family that
 * does not create one (MariaDB rejects an explicit value for it), so the real
 * column definition decides when the dialect can inspect it.
 */
final class PathDigestColumn
{
    public static function isGenerated(Connection $connection, string $table): bool
    {
        $schema = CapellDatabase::for($connection)->schemaDialect();
        $declared = $schema->supports(DatabaseCapability::HashGeneratedColumn, $connection);

        if (! $schema->supports(DatabaseCapability::GeneratedColumnInspection, $connection)) {
            return $declared;
        }

        $inspection = $schema->inspectGeneratedColumn($table, 'path_digest', $connection);

        // Only dialects that report a generation expression can be read this way.
        if (! str_contains($inspection->sql, 'generation_expression')) {
            return $declared;
        }

        try {
            $row = $connection->selectOne($inspection->sql, $inspection->bindings);
        } catch (Throwable) {
            return $declared;
        }

        return self::fromInspection($declared, is_object($row) ? $row : null);
    }

    public static function fromInspection(bool $declared, ?object $row): bool
    {
        if ($row === null) {
            return $declared;
        }

        $expression = $row->generation_expression ?? null;

        return is_string($expression) && trim($expression) !== '';
    }
}
