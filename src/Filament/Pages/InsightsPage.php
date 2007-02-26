<?php

declare(strict_types=1);

namespace Capell\Insights\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Insights\Actions\BuildInsightsWorkspaceAction;
use Capell\Insights\Data\InsightsWindowData;
use Capell\Insights\Filament\Settings\InsightsSettingsSchema;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Override;
use Spatie\Permission\PermissionRegistrar;

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
    public static function getNavigationGroup(): string
    {
        return __('capell-admin::navigation.group_monitoring');
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

        try {
            $this->validate([
                'startsOn' => ['required', 'date_format:Y-m-d'],
                'endsOn' => ['required', 'date_format:Y-m-d', 'after_or_equal:startsOn', 'before_or_equal:today'],
                'languageId' => ['nullable', Rule::in($languages->pluck('id')->all())],
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

            return $viewData;
        }

        $workspace = BuildInsightsWorkspaceAction::run(
            new InsightsWindowData($startsAt, $endsAt, $site->id, $this->languageId),
            $this->compare,
            $this->trendMetric,
            $funnelSteps,
        );

        return [...$viewData, 'workspace' => $workspace, 'sections' => $workspace->sections];
    }
}
