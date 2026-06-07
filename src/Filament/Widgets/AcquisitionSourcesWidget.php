<?php

declare(strict_types=1);

namespace Capell\Insights\Filament\Widgets;

use Capell\Admin\Contracts\CapellWidgetContract;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Insights\Actions\BuildAcquisitionSourcesQueryAction;
use Capell\Insights\Filament\Widgets\Concerns\BuildsInsightsDashboardWindow;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;
use Override;

final class AcquisitionSourcesWidget extends BaseWidget implements CapellWidgetContract
{
    use BuildsInsightsDashboardWindow;
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['admin', 'super_admin'];

    protected static string $settingsKey = 'insights_acquisition_sources';

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = ['md' => 1];

    protected static ?int $sort = 6;

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->getRecords())
            ->queryStringIdentifier('insights-acquisition-sources')
            ->paginated(false)
            ->searchable(false)
            ->heading(__('capell-insights::widgets.acquisition_sources'))
            ->columns([
                TextColumn::make('source')
                    ->label(__('capell-insights::widgets.source')),
                TextColumn::make('medium')
                    ->label(__('capell-insights::widgets.medium')),
                TextColumn::make('campaign')
                    ->label(__('capell-insights::widgets.campaign')),
                TextColumn::make('referrer')
                    ->label(__('capell-insights::widgets.referrer')),
                TextColumn::make('visits')
                    ->label(__('capell-insights::widgets.visits'))
                    ->numeric(),
            ]);
    }

    /**
     * @return Collection<int, array{id: string, source: string, medium: string, campaign: string, referrer: string, visits: int}>
     */
    private function getRecords(): Collection
    {
        return BuildAcquisitionSourcesQueryAction::run($this->getInsightsWindow(), 5)
            ->map(fn (array $summary, int $index): array => [
                'id' => 'acquisition-source-' . $index,
                ...$summary,
            ]);
    }
}
