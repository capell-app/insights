<?php

declare(strict_types=1);

namespace Capell\Insights\Filament\Widgets;

use Capell\Admin\Contracts\CapellFilamentWidgetContract;
use Capell\Admin\Filament\Concerns\GatedByRoleAndSettings;
use Capell\Insights\Actions\BuildRecentJourneysQueryAction;
use Capell\Insights\Filament\Widgets\Concerns\BuildsInsightsDashboardWindow;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Collection;
use Override;

final class RecentJourneysFilamentWidget extends BaseWidget implements CapellFilamentWidgetContract
{
    use BuildsInsightsDashboardWindow;
    use GatedByRoleAndSettings;

    /** @var list<string> */
    protected static array $rolesConfigKeys = ['admin', 'super_admin'];

    protected static string $settingsKey = 'insights_recent_journeys';

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = ['md' => 1];

    protected static ?int $sort = 4;

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->getRecords())
            ->queryStringIdentifier('insights-recent-journeys')
            ->paginated(false)
            ->searchable(false)
            ->heading(__('capell-insights::widgets.recent_journeys'))
            ->columns([
                TextColumn::make('visit')
                    ->label(__('capell-insights::widgets.visit')),
                TextColumn::make('steps')
                    ->label(__('capell-insights::widgets.steps'))
                    ->numeric(),
                TextColumn::make('last_path')
                    ->label(__('capell-insights::widgets.last_path')),
            ]);
    }

    /**
     * @return Collection<int, array{id: string, visit: string, steps: int, landing_url: string, last_path: string}>
     */
    private function getRecords(): Collection
    {
        return BuildRecentJourneysQueryAction::run(5, $this->getInsightsWindow())
            ->map(
                /**
                 * @param  array{id: int, visit: string, steps: int<0, max>, landing_url: string, last_path: string}  $journey
                 * @return array{id: string, visit: string, steps: int, landing_url: string, last_path: string}
                 */
                fn (array $journey): array => [
                    ...$journey,
                    'id' => 'journey-' . $journey['id'],
                ],
            );
    }
}
