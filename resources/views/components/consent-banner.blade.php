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
            --capell-insights-banner-bg: color-mix(in srgb, Canvas 94%, CanvasText 6%);
            --capell-insights-banner-fg: CanvasText;
            --capell-insights-banner-muted: color-mix(in srgb, CanvasText 66%, Canvas 34%);
            --capell-insights-banner-border: color-mix(in srgb, CanvasText 18%, transparent);
            --capell-insights-banner-accent: var(--theme-primary, var(--foundation-primary-action, CanvasText));
            --capell-insights-banner-accent-fg: var(--theme-primary-contrast, Canvas);
            --capell-insights-banner-focus: color-mix(in srgb, var(--capell-insights-banner-accent) 32%, transparent);
            position: fixed;
            inset-inline: 0;
            bottom: 0;
            z-index: 60;
            display: flex;
            justify-content: center;
            color: var(--capell-insights-banner-fg);
            font-family:
                'IBM Plex Sans',
                ui-sans-serif,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                'Segoe UI',
                sans-serif;
            font-size: 1rem;
        }

        .capell-insights-consent-banner[hidden] {
            display: none;
        }

        .capell-insights-consent-banner__panel {
            display: grid;
            width: 100%;
            gap: 0.9rem;
            border-top: 1px solid var(--capell-insights-banner-border);
            background: var(--capell-insights-banner-bg);
            box-shadow: 0 -1rem 2.5rem rgb(0 0 0 / 12%);
            padding: 1rem clamp(1rem, 4vw, 2rem);
        }
        @media(min-width: 56rem)
        {
                   .capell-insights-consent-banner__panel {
                       grid-template-columns: minmax(18rem, 1fr) auto;
                       align-items: center;
                   }
               }

               .capell-insights-consent-banner__title {
                   margin: 0;
                   font-size: 1rem;
                   font-weight: 650;
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
                   margin-top: 0;
               }

               .capell-insights-consent-banner__actions {
                   align-items: center;
               }

               .capell-insights-consent-banner__choices {
                   grid-column: 1 / -1;
                   border-top: 1px solid var(--capell-insights-banner-border);
                   padding-top: 0.9rem;
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
                   border-radius: 0.25rem;
                   background: transparent;
                   color: inherit;
                   padding: 0.625rem 0.85rem;
                   font-family: inherit;
                   font-size: 0.875rem;
                   font-weight: 650;
                   line-height: 1.2;
                   transition:
                       background-color 150ms ease,
                       border-color 150ms ease,
                       color 150ms ease,
                       transform 150ms ease;
               }

               .capell-insights-consent-banner__button:hover {
                   border-color: color-mix(in srgb, var(--capell-insights-banner-fg) 34%, transparent);
                   background: color-mix(in srgb, CanvasText 6%, transparent);
               }

               .capell-insights-consent-banner__button:focus-visible {
                   outline: 3px solid var(--capell-insights-banner-focus);
                   outline-offset: 2px;
               }

               .capell-insights-consent-banner__button:active {
                   transform: translateY(1px);
               }

               .capell-insights-consent-banner__button--primary {
                   border-color: var(--capell-insights-banner-accent);
                   background: var(--capell-insights-banner-accent);
                   color: var(--capell-insights-banner-accent-fg);
               }

               .capell-insights-consent-banner__button--primary:hover {
                   border-color: var(--capell-insights-banner-accent);
                   background: color-mix(in srgb, var(--capell-insights-banner-accent) 88%, black 12%);
               }
    </style>

    <div class="capell-insights-consent-banner__panel">
        <div>
            <h2
                id="capell-insights-consent-title"
                class="capell-insights-consent-banner__title"
            >
                {{ __('capell-insights::consent.banner.title') }}
            </h2>

            <p id="capell-insights-consent-description" class="capell-insights-consent-banner__description">
                {{ __('capell-insights::consent.banner.description') }}
            </p>
        </div>

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
