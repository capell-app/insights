<?php

declare(strict_types=1);

namespace Capell\Insights\Data;

use Spatie\LaravelData\Data;

final class InsightsReportTableData extends Data
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string>>  $rows
     */
    public function __construct(
        public readonly string $title,
        public readonly array $headings,
        public readonly array $rows,
    ) {}
}
