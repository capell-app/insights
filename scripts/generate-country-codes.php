<?php

declare(strict_types=1);

// Extract data without executing the downloaded PHP. See tracking-and-consent.md.
$sourcePath = $argv[1] ?? '';
$source = $sourcePath !== '' ? file_get_contents($sourcePath) : false;

if (! is_string($source)
    || hash('sha256', $source) !== '690066d136c1e5b6536d791077114d39889d64814afb79562c6f66c1fdb6eed0') {
    throw new RuntimeException('Expected the pinned league/iso3166 4.3.0 src/ISO3166.php source.');
}

preg_match_all("/'alpha2' => '([A-Z]{2})'/", $source, $matches);
$codes = array_values(array_unique($matches[1]));
sort($codes);

if (count($codes) !== 249) {
    throw new RuntimeException('Expected 249 distinct ISO 3166-1 alpha-2 codes.');
}

$rows = array_map(
    static fn (array $row): string => '        ' . implode(', ', array_map(static fn (string $code): string => "'{$code}'", $row)) . ',',
    array_chunk($codes, 12),
);
$data = implode("\n", $rows);
$output = <<<PHP
<?php

declare(strict_types=1);

namespace Capell\Insights\Support\Consent;

// Generated from league/iso3166 4.3.0; regenerate with scripts/generate-country-codes.php.
final class Iso3166CountryCodes
{
    public const array ALPHA_2 = [
{$data}
    ];
}

PHP;

if (file_put_contents(__DIR__ . '/../src/Support/Consent/Iso3166CountryCodes.php', $output) === false) {
    throw new RuntimeException('Could not write the generated country codes.');
}

fwrite(STDOUT, "Generated 249 ISO 3166-1 alpha-2 codes.\n");
