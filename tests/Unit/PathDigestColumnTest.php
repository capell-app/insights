<?php

declare(strict_types=1);

use Capell\Insights\Support\PathDigestColumn;

it('trusts the real column definition over the declared server capability', function (bool $declared, ?string $expression, bool $expected): void {
    $row = (object) ['generation_expression' => $expression];

    expect(PathDigestColumn::fromInspection($declared, $row))->toBe($expected);
})->with([
    'generated on a family that declares application-managed digests' => [false, 'sha2(`path`,256)', true],
    'plain column on a family that declares generated digests' => [true, '', false],
    'plain column with a null expression' => [true, null, false],
    'plain column, application-managed' => [false, '', false],
    'generated column, generated family' => [true, 'sha2(`path`,256)', true],
]);

it('falls back to the declared capability when the column cannot be inspected', function (bool $declared): void {
    expect(PathDigestColumn::fromInspection($declared, null))->toBe($declared);
})->with([true, false]);
