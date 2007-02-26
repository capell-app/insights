<x-filament-panels::page>
    <form
        wire:submit="applyFilters"
        class="space-y-4"
    >
        <x-filament::section
            :heading="__('capell-insights::workspace.filters')"
        >
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label
                        for="insights-siteId"
                        class="text-sm font-medium"
                        >{{ __('capell-insights::workspace.site') }}</label
                    >
                    <x-filament::input.wrapper>
                        <x-filament::input.select
                            id="insights-siteId"
                            wire:model.live="siteId"
                        >
                            @foreach ($sites as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach</x-filament::input.select
                    ></x-filament::input.wrapper>
                    @error('siteId')
                        <p role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label
                        for="insights-languageId"
                        class="text-sm font-medium"
                        >{{ __('capell-insights::workspace.language') }}</label
                    >
                    <x-filament::input.wrapper>
                        <x-filament::input.select
                            id="insights-languageId"
                            wire:model="languageId"
                        >
                            <option value="">
                                {{ __('capell-insights::workspace.all_languages') }}
                            </option>
                            @foreach ($languages as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach</x-filament::input.select
                    ></x-filament::input.wrapper>
                    @error('languageId')
                        <p role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label
                        for="insights-startsOn"
                        class="text-sm font-medium"
                        >{{ __('capell-insights::workspace.from') }}</label
                    >
                    <x-filament::input.wrapper>
                        <x-filament::input
                            id="insights-startsOn"
                            type="date"
                            wire:model="startsOn"
                            required
                    /></x-filament::input.wrapper>
                    @error('startsOn')
                        <p role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label
                        for="insights-endsOn"
                        class="text-sm font-medium"
                        >{{ __('capell-insights::workspace.to') }}</label
                    >
                    <x-filament::input.wrapper>
                        <x-filament::input
                            id="insights-endsOn"
                            type="date"
                            wire:model="endsOn"
                            required
                    /></x-filament::input.wrapper>
                    @error('endsOn')
                        <p role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-4">
                <label class="flex items-center gap-2"
                    ><x-filament::input.checkbox
                        wire:model="compare"
                    />{{ __('capell-insights::workspace.compare') }}</label
                >
                <div>
                    <label
                        for="insights-trend"
                        class="text-sm font-medium"
                        >{{ __('capell-insights::workspace.trend_metric') }}</label
                    >
                    <x-filament::input.wrapper>
                        <x-filament::input.select
                            id="insights-trend"
                            wire:model="trendMetric"
                        >
                            <option value="unique-visits">
                                {{ __('capell-insights::workspace.visitors') }}
                            </option>
                            <option value="page-views">
                                {{ __('capell-insights::workspace.page_views') }}
                            </option>
                            <option value="clicks">
                                {{ __('capell-insights::workspace.engagement') }}
                            </option>
                        </x-filament::input.select></x-filament::input.wrapper
                    >
                    @error('trendMetric')
                        <p role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <x-filament::button
                    type="submit"
                    wire:loading.attr="disabled"
                    >{{ __('capell-insights::workspace.apply') }}</x-filament::button
                >
            </div>
        </x-filament::section>
        <x-filament::section
            :heading="__('capell-insights::workspace.overview')"
        >
            <p wire:loading role="status">{{ __('capell-insights::workspace.loading') }}</p>
            <div
                wire:loading.remove
                class="space-y-4"
            >
                @if ($workspace)
                    <p>{{ __('capell-insights::workspace.period', ['from' => $startsOn, 'to' => $endsOn]) }}</p>
                    @unless ($workspace->trackingEnabled)
                        <p role="status">{{ __('capell-insights::workspace.disabled') }}</p>
                    @endunless
                    @if ($workspace->latestEventAt)
                        <p>{{ __('capell-insights::workspace.freshness', ['time' => $workspace->latestEventAt->toDateTimeString()]) }}</p>
                        <p>{{ __('capell-insights::workspace.cache_hint', ['seconds' => config('capell-insights.dashboard_cache_ttl_seconds', 60)]) }}</p>
                    @else
                        <p role="status">{{ __('capell-insights::workspace.no_data') }}</p>
                        <p>{{ __('capell-insights::workspace.consent') }}</p>
                    @endif
                    @if ($workspace->consentRequiredEverywhere)
                        <p>{{ __('capell-insights::workspace.consent_required') }}</p>
                    @endif
                    @if ($workspace?->stale)
                        <p role="status">{{ __('capell-insights::workspace.stale') }}</p>
                    @endif
                    <dl class="grid gap-4 sm:grid-cols-3">
                        @foreach ($workspace->metrics as $metric)
                            <div
                                class="rounded-xl bg-gray-50 p-4 dark:bg-white/5"
                            >
                                <dt class="text-sm">{{ $metric['label'] }}</dt>
                                <dd class="text-3xl font-semibold">
                                    {{ number_format($metric['value']) }}
                                </dd>
                                @if ($metric['previous'] !== null)
                                    <dd class="text-sm">
                                        {{ __('capell-insights::workspace.previous', ['value' => number_format($metric['previous'])]) }}
                                    </dd>
                                @endif
                            </div>
                        @endforeach
                    </dl>
                    <h3 class="font-semibold">
                        {{ __('capell-insights::workspace.trend') }}
                    </h3>
                    <p>{{ $workspace->trendChange !== null ? __('capell-insights::workspace.trend_change', ['change' => ($workspace->trendChange > 0 ? '+' : '') . $workspace->trendChange]) : __('capell-insights::workspace.comparison_off') }}</p>
                @else
                    <p role="status">{{ __('capell-insights::workspace.' . ($sites === [] ? 'no_site' : 'invalid_filters')) }}</p>
                @endif
            </div>
        </x-filament::section>
        @foreach ($sections as $section => $tables)
            <x-filament::section
                :heading="__('capell-insights::workspace.' . $section)"
                :description="__('capell-insights::workspace.' . $section . '_question')"
                collapsible
                collapsed
            >
                <p wire:loading role="status">{{ __('capell-insights::workspace.loading') }}</p>
                <div
                    wire:loading.remove
                    class="space-y-6"
                >
                    @if ($workspace?->stale)
                        <p role="status">{{ __('capell-insights::workspace.stale') }}</p>
                    @endif
                    @if ($section === 'funnel')
                        <div>
                            <label
                                for="insights-funnel"
                                class="text-sm font-medium"
                                >{{ __('capell-insights::workspace.funnel_steps') }}</label
                            >
                            <textarea
                                id="insights-funnel"
                                wire:model="funnelSteps"
                                rows="3"
                                maxlength="1000"
                                aria-describedby="insights-funnel-help"
                                class="w-full rounded-lg border-gray-300 bg-white text-gray-950 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            ></textarea>
                            <p id="insights-funnel-help" class="text-sm">{{ __('capell-insights::workspace.funnel_helper') }}</p>
                            @error('funnelSteps')
                                <p role="alert">{{ $message }}</p>
                            @enderror
                            <x-filament::button
                                type="submit"
                                wire:loading.attr="disabled"
                                >{{ __('capell-insights::workspace.apply') }}</x-filament::button
                            >
                        </div>
                    @endif
                    @foreach ($tables as $table)
                        <div class="min-w-0 space-y-2">
                            <h3 class="font-semibold">{{ $table->title }}</h3>
                            @if ($table->rows === [])
                                <p>{{ __('capell-insights::workspace.section_empty') }}</p>
                            @else
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-sm">
                                        <caption class="sr-only">
                                            {{ $table->title }}
                                        </caption>
                                        <thead>
                                            <tr>
                                                @foreach ($table->headings as $heading)
                                                    <th
                                                        scope="col"
                                                        class="px-3 py-2"
                                                    >
                                                        {{ $heading }}
                                                    </th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($table->rows as $row)
                                                <tr
                                                    class="border-t border-gray-200 dark:border-gray-700"
                                                >
                                                    @foreach ($row as $cell)
                                                        <td
                                                            class="break-all px-3 py-2"
                                                        >
                                                            {{ $cell }}
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    @endforeach
                    @if ($section === 'content' && ! $compare)
                        <p>{{ __('capell-insights::workspace.comparison_off') }}</p>
                    @endif
                </div>
            </x-filament::section>
        @endforeach
    </form>
</x-filament-panels::page>
