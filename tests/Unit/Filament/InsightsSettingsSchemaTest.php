<?php

declare(strict_types=1);

use Capell\Insights\Filament\Settings\InsightsSettingsSchema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component as LivewireComponent;
use PHPUnit\Framework\Assert;

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

it('adds translated helper text to insights settings', function (): void {
    $livewire = new class extends LivewireComponent implements HasSchemas
    {
        use InteractsWithSchemas;
    };
    $schema = Schema::make($livewire);
    $schema->components(InsightsSettingsSchema::make($schema));

    $components = array_values(array_filter(
        $schema->getComponents(),
        static fn (mixed $component): bool => $component instanceof Component,
    ));
    $components = flattenAttachedInsightsSettingsComponents($components);

    $expectedHelpers = [
        'default_consent_region' => 'Fallback consent region saved in Insights settings for use when automatic region detection is unavailable. Leave blank to use capell-insights.default_consent_region; if that config value is also unset, the fallback region is unknown.',
        'policy_version' => 'Consent policy version saved in Insights settings. Consent records and policy checks currently read capell-insights.policy_version; update that config value for changes to take effect.',
        'retention_days' => __('capell-insights::settings.retention_days_helper'),
        'hash_visitor_data' => 'Visitor hashing choice saved in Insights settings. Visit and consent storage currently read capell-insights.hash_visitor_data; update that config value for changes to take effect.',
        'hash_salt' => __('capell-insights::settings.hash_salt_helper'),
        'ignored_paths' => __('capell-insights::settings.ignored_paths_helper'),
        'ignored_selectors' => __('capell-insights::settings.ignored_selectors_helper'),
        'route_prefix' => __('capell-insights::settings.route_prefix_helper'),
    ];

    foreach ($expectedHelpers as $field => $expectedHelper) {
        $translation = __("capell-insights::settings.{$field}_helper");
        $component = collect($components)
            ->first(fn (mixed $component): bool => $component instanceof Component
                && method_exists($component, 'getName')
                && $component->getName() === $field);

        Assert::assertInstanceOf(Component::class, $component);
        expect($translation)
            ->toBeString()
            ->not->toBeEmpty()
            ->not->toBe("capell-insights::settings.{$field}_helper")
            ->toBe($expectedHelper)
            ->and(insightsHelperText($component))->toBe($expectedHelper);
    }
});

function insightsHelperText(Component $component): string
{
    $belowContent = $component->getChildComponents('below_content');

    Assert::assertCount(1, $belowContent);
    Assert::assertInstanceOf(Text::class, $belowContent[0]);

    $content = $belowContent[0]->getContent();
    Assert::assertIsString($content);

    return $content;
}

/**
 * @param  array<array-key, Component>  $components
 * @return list<Component>
 */
function flattenAttachedInsightsSettingsComponents(array $components): array
{
    /** @var list<Component> $flattenedComponents */
    $flattenedComponents = [];

    foreach ($components as $component) {
        $flattenedComponents[] = $component;
        $childComponents = array_values(array_filter(
            $component->getChildComponents(),
            static fn (mixed $childComponent): bool => $childComponent instanceof Component,
        ));

        array_push($flattenedComponents, ...flattenAttachedInsightsSettingsComponents($childComponents));
    }

    return $flattenedComponents;
}

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

it('leads with enabled and recommended consent before collapsed advanced groups', function (): void {
    $schema = InsightsSettingsSchema::make(Schema::make());
    $children = $schema[0]->getDefaultChildComponents();
    Assert::assertIsArray($children);
    Assert::assertInstanceOf(Toggle::class, $children[0]);
    Assert::assertInstanceOf(Toggle::class, $children[1]);
    Assert::assertInstanceOf(Section::class, $children[2]);
    expect($children[0]->getName())->toBe('enabled')
        ->and($children[1]->getName())->toBe('require_consent_for_all_regions')
        ->and($children[2]->isCollapsed())->toBeTrue();
    $groups = $children[2]->getDefaultChildComponents();
    Assert::assertIsArray($groups);
    foreach (['Collection', 'Consent and retention', 'Developer: endpoint, hashing and exclusions'] as $index => $heading) {
        Assert::assertInstanceOf(Section::class, $groups[$index]);
        expect($groups[$index]->getHeading())->toBe($heading);
    }
});
