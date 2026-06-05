<section
    class="capell-insights-consent-banner"
    data-capell-insights-consent-banner
    hidden
    role="dialog"
    aria-modal="false"
    aria-labelledby="capell-insights-consent-title"
    aria-describedby="capell-insights-consent-description"
>
    <style>
        .capell-insights-consent-banner {
            --capell-insights-banner-bg: color-mix(
                in srgb,
                Canvas 94%,
                CanvasText 6%
            );
            --capell-insights-banner-fg: CanvasText;
            --capell-insights-banner-muted: color-mix(
                in srgb,
                CanvasText 66%,
                Canvas 34%
            );
            --capell-insights-banner-border: color-mix(
                in srgb,
                CanvasText 18%,
                transparent
            );
            --capell-insights-banner-accent: #0f766e;
            --capell-insights-banner-accent-fg: #ffffff;
            position: fixed;
            right: 1rem;
            bottom: 1rem;
            left: 1rem;
            z-index: 60;
            display: flex;
            justify-content: center;
            color: var(--capell-insights-banner-fg);
            font: inherit;
        }

        .capell-insights-consent-banner[hidden] {
            display: none;
        }

        .capell-insights-consent-banner__panel {
            width: min(42rem, 100%);
            border: 1px solid var(--capell-insights-banner-border);
            border-radius: 0.5rem;
            background: var(--capell-insights-banner-bg);
            box-shadow: 0 1.25rem 3rem rgb(0 0 0 / 18%);
            padding: 1rem;
        }

        .capell-insights-consent-banner__title {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 0;
            line-height: 1.4;
        }

        .capell-insights-consent-banner__description,
        .capell-insights-consent-banner__hint {
            margin: 0.35rem 0 0;
            color: var(--capell-insights-banner-muted);
            font-size: 0.875rem;
            line-height: 1.5;
        }

        .capell-insights-consent-banner__actions,
        .capell-insights-consent-banner__choices {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.9rem;
        }

        .capell-insights-consent-banner__choice {
            display: grid;
            gap: 0.1rem;
            min-width: min(100%, 11rem);
        }

        .capell-insights-consent-banner__choice-label {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            font-size: 0.875rem;
            font-weight: 650;
        }

        .capell-insights-consent-banner__button {
            min-height: 2.5rem;
            cursor: pointer;
            border: 1px solid var(--capell-insights-banner-border);
            border-radius: 0.375rem;
            background: transparent;
            color: inherit;
            padding: 0.625rem 0.85rem;
            font: inherit;
            font-size: 0.875rem;
            font-weight: 650;
            line-height: 1.2;
        }

        .capell-insights-consent-banner__button--primary {
            border-color: var(--capell-insights-banner-accent);
            background: var(--capell-insights-banner-accent);
            color: var(--capell-insights-banner-accent-fg);
        }
    </style>

    <div class="capell-insights-consent-banner__panel">
        <h2
            id="capell-insights-consent-title"
            class="capell-insights-consent-banner__title"
        >
            {{ __('capell-insights::consent.banner.title') }}
        </h2>

        <p
            id="capell-insights-consent-description"
            class="capell-insights-consent-banner__description"
        >
            {{ __('capell-insights::consent.banner.description') }}
        </p>

        <div class="capell-insights-consent-banner__actions">
            <button
                type="button"
                class="capell-insights-consent-banner__button capell-insights-consent-banner__button--primary"
                data-capell-insights-consent-action="accept"
            >
                {{ __('capell-insights::consent.banner.accept_all') }}
            </button>

            <button
                type="button"
                class="capell-insights-consent-banner__button"
                data-capell-insights-consent-action="reject"
            >
                {{ __('capell-insights::consent.banner.reject') }}
            </button>

            <button
                type="button"
                class="capell-insights-consent-banner__button"
                data-capell-insights-consent-action="manage"
                aria-expanded="false"
                aria-controls="capell-insights-consent-choices"
            >
                {{ __('capell-insights::consent.banner.manage') }}
            </button>
        </div>

        <div
            id="capell-insights-consent-choices"
            class="capell-insights-consent-banner__choices"
            data-capell-insights-consent-choices
            hidden
        >
            <label class="capell-insights-consent-banner__choice">
                <span class="capell-insights-consent-banner__choice-label">
                    <input
                        type="checkbox"
                        data-capell-insights-consent-category="insights"
                    />
                    {{ __('capell-insights::consent.banner.insights') }}
                </span>
                <span class="capell-insights-consent-banner__hint">
                    {{ __('capell-insights::consent.banner.insights_help') }}
                </span>
            </label>

            <label class="capell-insights-consent-banner__choice">
                <span class="capell-insights-consent-banner__choice-label">
                    <input
                        type="checkbox"
                        data-capell-insights-consent-category="marketing"
                    />
                    {{ __('capell-insights::consent.banner.marketing') }}
                </span>
                <span class="capell-insights-consent-banner__hint">
                    {{ __('capell-insights::consent.banner.marketing_help') }}
                </span>
            </label>

            <label class="capell-insights-consent-banner__choice">
                <span class="capell-insights-consent-banner__choice-label">
                    <input
                        type="checkbox"
                        data-capell-insights-consent-category="preferences"
                    />
                    {{ __('capell-insights::consent.banner.preferences') }}
                </span>
                <span class="capell-insights-consent-banner__hint">
                    {{ __('capell-insights::consent.banner.preferences_help') }}
                </span>
            </label>

            <button
                type="button"
                class="capell-insights-consent-banner__button capell-insights-consent-banner__button--primary"
                data-capell-insights-consent-action="save"
            >
                {{ __('capell-insights::consent.banner.save') }}
            </button>
        </div>
    </div>
</section>
