<?php

declare(strict_types=1);

namespace Capell\Insights\Tests\Fixtures;

use Capell\Insights\Filament\Settings\InsightsSettingsSchema;
use Capell\Insights\Settings\InsightsSettings;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Livewire\Component;

/** @property Schema $form */
final class InsightsSettingsForm extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $submitted = [];

    public function mount(): void
    {
        $this->form->fill(resolve(InsightsSettings::class)->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components(InsightsSettingsSchema::make($schema));
    }

    public function submit(): void
    {
        $settings = resolve(InsightsSettings::class);
        $settings->fill($this->form->getState())->save();
        $this->submitted = $settings->toArray();
    }

    public function render(): string
    {
        return '<form wire:submit="submit">{{ $this->form }}<button type="submit">Save</button></form>';
    }
}
