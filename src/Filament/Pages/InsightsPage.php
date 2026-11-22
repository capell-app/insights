<?php

declare(strict_types=1);

namespace Capell\Insights\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Enums\NavigationGroupEnum;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Insights\Actions\BuildInsightsWorkspaceAction;
use Capell\Insights\Actions\ExportInsightsDigestCsvAction;
use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Filament\Settings\InsightsSettingsSchema;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Override;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class InsightsPage extends Page
{
    use HasPageShield;

    public ?int $siteId = null;

    public ?int $languageId = null;

    public string $startsOn = '';

    public string $endsOn = '';

    public bool $compare = false;

    public string $trendMetric = 'page-views';

    public string $funnelSteps = '';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ChartBar;

    protected static ?int $navigationSort = 1;

    protected string $view = 'capell-insights::filament.pages.insights';

    protected static ?string $slug = 'insights';

    #[Override]
    public static function getNavigationLabel(): string
    {
        return __('capell-insights::widgets.insights');
    }

    #[Override]
    public static function getNavigationGroup(): NavigationGroupEnum
    {
        return NavigationGroupEnum::Reports;
    }

    #[Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->can('View:InsightsPage') ?? false;
    }

    #[Override]
    public function getTitle(): string
    {
        return __('capell-insights::widgets.insights');
    }

    #[Override]
    public function getSubheading(): string
    {
        return __('capell-insights::widgets.insights_hint');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->startsOn = CarbonImmutable::today()->subDays(6)->toDateString();
        $this->endsOn = CarbonImmutable::today()->toDateString();
        $siteIds = SiteScope::applyForCurrentActor(Site::query(), 'id', denyWhenMissingActor: true)->pluck('id');
        $activeSiteId = resolve(PermissionRegistrar::class)->getPermissionsTeamId();
        $siteId = $siteIds->contains($activeSiteId) ? $activeSiteId : $siteIds->first();
        $this->siteId = is_numeric($siteId) ? (int) $siteId : null;
    }

    public function updatedSiteId(): void
    {
        $this->languageId = null;
    }

    public function applyFilters(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->resetErrorBag();
    }

    public function downloadDigestCsv(): ?StreamedResponse
    {
        abort_unless(self::canAccess(), 403);
        $site = SiteScope::applyForCurrentActor(Site::query(), 'id', denyWhenMissingActor: true)
            ->with('language')
            ->find($this->siteId);

        if (! $site instanceof Site) {
            Notification::make()->title(__('capell-insights::workspace.export_needs_site'))->warning()->send();

            return null;
        }

        $languageIds = $site->languages()->pluck($site->languages()->getRelated()->qualifyColumn('id'))->push($site->language?->id)->filter()->all();
        $filters = $this->validatedFilters($site, $languageIds);

        if ($filters === null) {
            Notification::make()->title(__('capell-insights::workspace.export_invalid_filters'))->warning()->send();

            return null;
        }

        [$window, $funnelSteps] = $filters;
        $csv = ExportInsightsDigestCsvAction::run($window, $funnelSteps);
        $filename = sprintf('insights-digest-%s-to-%s.csv', $window->startsAt->toDateString(), $window->endsAt->toDateString());

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    #[Override]
    protected function getViewData(): array
    {
        abort_unless(self::canAccess(), 403);
        $sites = SiteScope::applyForCurrentActor(Site::query(), 'id', denyWhenMissingActor: true)->with('language')->get();
        $site = $sites->firstWhere('id', $this->siteId);
        abort_if($this->siteId !== null && ! $site instanceof Site, 403);
        $languages = $site instanceof Site
            ? $site->languages()->get()->merge($site->language instanceof Language ? [$site->language] : [])->unique('id')
            : collect();
        $viewData = [
            'sites' => $sites->mapWithKeys(fn (Site $site): array => [$site->id => $site->name])->all(),
            'languages' => $languages->mapWithKeys(fn (Language $language): array => [$language->id => $language->name])->all(),
            'workspace' => null,
            'sections' => ['content' => [], 'journeys' => [], 'acquisition' => [], 'funnel' => []],
        ];
        if (! $site instanceof Site) {
            return $viewData;
        }

        $filters = $this->validatedFilters($site, $languages->pluck('id')->all());

        if ($filters === null) {
            return $viewData;
        }

        [$window, $funnelSteps] = $filters;
        $workspace = BuildInsightsWorkspaceAction::run($window, $this->compare, $this->trendMetric, $funnelSteps);

        return array_replace($viewData, ['workspace' => $workspace, 'sections' => $workspace->sections]);
    }

    /** @return array<int, Action> */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportDigestCsv')
                ->label(__('capell-insights::workspace.export_digest_csv'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): ?StreamedResponse => $this->downloadDigestCsv()),
        ];
    }

    /**
     * @param  array<int, mixed>  $languageIds
     * @return array{0: InsightsWindowData, 1: list<string>}|null
     */
    private function validatedFilters(Site $site, array $languageIds): ?array
    {
        try {
            $this->validate([
                'startsOn' => ['required', 'date_format:Y-m-d'],
                'endsOn' => ['required', 'date_format:Y-m-d', 'after_or_equal:startsOn', 'before_or_equal:today'],
                'languageId' => ['nullable', Rule::in($languageIds)],
                'trendMetric' => ['required', Rule::in(['unique-visits', 'page-views', 'clicks'])],
                'funnelSteps' => ['string', 'max:1000'],
            ]);
            $funnelSteps = InsightsSettingsSchema::textareaToList($this->funnelSteps);
            if (count($funnelSteps) > 10) {
                throw ValidationException::withMessages(['funnelSteps' => __('capell-insights::workspace.funnel_limit')]);
            }

            $startsAt = CarbonImmutable::parse($this->startsOn)->startOfDay();
            $endsAt = CarbonImmutable::parse($this->endsOn)->endOfDay();
            if ($startsAt->diffInDays($endsAt) > 366) {
                throw ValidationException::withMessages(['startsOn' => __('capell-insights::workspace.range_limit')]);
            }
        } catch (ValidationException $validationException) {
            $this->setErrorBag($validationException->validator->errors());

            return null;
        }

        return [new InsightsWindowData($startsAt, $endsAt, $site->id, $this->languageId), $funnelSteps];
    }
}
