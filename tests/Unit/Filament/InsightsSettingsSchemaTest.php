<?php

declare(strict_types=1);

use Capell\Insights\Filament\Settings\InsightsSettingsSchema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

it('builds insights settings with consent retention and privacy controls', function (): void {
    $schema = InsightsSettingsSchema::make(Schema::make());
    $components = flattenInsightsSettingsComponents($schema);
    $componentNames = insightSettingsComponentNames($schema);

    $defaultConsentRegion = collect($components)
        ->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'default_consent_region');

    expect($schema)->toHaveCount(1)
        ->and($schema[0])->toBeInstanceOf(Grid::class)
        ->and($componentNames)->toContain(
            'enabled',
            'track_page_views',
            'track_clicks',
            'track_forms',
            'automatic_click_tracking',
            'require_consent_for_all_regions',
            'default_consent_region',
            'policy_version',
            'retention_days',
            'hash_visitor_data',
            'hash_salt',
            'ignored_paths',
            'ignored_selectors',
            'route_prefix',
        )
        ->and($defaultConsentRegion)->toBeInstanceOf(Select::class)
        ->and($defaultConsentRegion->getOptions())->not->toBeEmpty()
        ->and(collect($components)->whereInstanceOf(Toggle::class))->toHaveCount(7)
        ->and(collect($components)->whereInstanceOf(TextInput::class))->toHaveCount(4)
        ->and(collect($components)->whereInstanceOf(Textarea::class))->toHaveCount(2);
});

it('normalizes ignored insight path and selector lists', function (): void {
    expect(InsightsSettingsSchema::listToTextarea(['/admin', '', '/account', 123]))
        ->toBe('/admin' . PHP_EOL . '/account')
        ->and(InsightsSettingsSchema::listToTextarea('/existing'))
        ->toBe('/existing')
        ->and(InsightsSettingsSchema::listToTextarea(false))
        ->toBe('')
        ->and(InsightsSettingsSchema::textareaToList(" /admin \n\n/account\n "))
        ->toBe(['/admin', '/account'])
        ->and(InsightsSettingsSchema::textareaToList(['/admin', ' ', '/account', null]))
        ->toBe(['/admin', '/account'])
        ->and(InsightsSettingsSchema::textareaToList(null))
        ->toBe([]);
});

/**
 * @param  array<int, mixed>  $components
 * @return array<int, mixed>
 */
function flattenInsightsSettingsComponents(array $components): array
{
    $flattenedComponents = [];

    foreach ($components as $component) {
        $flattenedComponents[] = $component;
        if (! is_object($component)) {
            continue;
        }

        if (! method_exists($component, 'getDefaultChildComponents')) {
            continue;
        }

        $childComponents = $component->getDefaultChildComponents();

        if (is_array($childComponents)) {
            array_push($flattenedComponents, ...flattenInsightsSettingsComponents($childComponents));
        }
    }

    return $flattenedComponents;
}

/**
 * @param  array<int, mixed>  $components
 * @return array<int, string>
 */
function insightSettingsComponentNames(array $components): array
{
    return collect(flattenInsightsSettingsComponents($components))
        ->filter(fn (mixed $component): bool => is_object($component) && method_exists($component, 'getName'))
        ->map(fn (mixed $component): string => $component->getName())
        ->values()
        ->all();
}
