import { readFileSync } from 'node:fs'
import vm from 'node:vm'

const source = readFileSync(
    new URL('../../resources/js/capell-insights.js', import.meta.url),
    'utf8',
)

export function browser({
    consent = null,
    consentPersistence = 'success',
    consentCategories = undefined,
    consentExpiresDays = 180,
    consentRestorationRequired = false,
    privacy = {},
    storageUnavailable = false,
    storageWriteUnavailable = false,
    sharedStorage = new Map(),
    initialTime = Date.now(),
    cookie = '',
    sendBeacon = null,
} = {}) {
    const requests = []
    const timers = new Map()
    const documentListeners = new Map()
    const windowListeners = new Map()
    const bannerListeners = new Map()
    const addListener = (listeners, name, callback) => {
        if (!listeners.has(name)) listeners.set(name, new Set())
        listeners.get(name).add(callback)
    }
    const removeListener = (listeners, name, callback) =>
        listeners.get(name)?.delete(callback)
    const dispatch = (listeners, name, event) =>
        [...(listeners.get(name) ?? [])].forEach((callback) => callback(event))
    const rootStyles = new Map()
    const storage = sharedStorage
    let timerId = 0
    let currentTime = initialTime
    let resolvePolicy
    let rejectPolicy
    const pendingConsents = []
    let resolveConsent
    let rejectConsent
    let resolveConsentJson
    let visibilityState = 'visible'
    if (consent) {
        storage.set(
            'capell_insights_consent',
            JSON.stringify({
                decided_at: new Date(currentTime).toISOString(),
                ...consent,
            }),
        )
    }
    const config = {
        eventsUrl: '/capell/insights/events',
        consentUrl: '/capell/insights/consent',
        consentPolicyUrl: '/capell/insights/consent-policy',
        consentRequired: true,
        policyVersion: '1.0',
        consentExpiresDays,
        consentRestorationRequired,
        honorPrivacySignals: true,
        trackPageViews: true,
        trackClicks: true,
    }
    const choices = { hidden: true }
    const banner = {
        hidden: true,
        offsetHeight: 64,
        addEventListener: (name, callback) =>
            addListener(bannerListeners, name, callback),
        removeEventListener: (name, callback) =>
            removeListener(bannerListeners, name, callback),
        contains: () => true,
        querySelector: (selector) =>
            selector === '[data-capell-insights-consent-choices]'
                ? choices
                : null,
        querySelectorAll: () => [],
    }
    const document = {
        title: 'Public page',
        cookie,
        readyState: 'complete',
        body: { matches: () => false, closest: () => null },
        documentElement: {
            style: {
                setProperty: (name, value) => rootStyles.set(name, value),
            },
        },
        get visibilityState() {
            return visibilityState
        },
        querySelector: (selector) => {
            if (selector === '[data-capell-insights-tracker]') {
                return { textContent: JSON.stringify(config) }
            }

            if (selector === '[data-capell-insights-consent-banner]') {
                return banner
            }

            return null
        },
        addEventListener: (name, callback) =>
            addListener(documentListeners, name, callback),
        removeEventListener: (name, callback) =>
            removeListener(documentListeners, name, callback),
    }
    const window = {
        location: {
            origin: 'https://example.test',
            href: 'https://example.test/page',
        },
        localStorage: {
            getItem: (key) => {
                if (storageUnavailable) throw new Error('Storage unavailable')
                return storage.get(key) ?? null
            },
            setItem: (key, value) => {
                if (storageUnavailable || storageWriteUnavailable)
                    throw new Error('Storage unavailable')
                storage.set(key, value)
            },
        },
        setTimeout: (callback, delay) => {
            timers.set(++timerId, { callback, delay })
            return timerId
        },
        clearTimeout: (id) => timers.delete(id),
        addEventListener: (name, callback) =>
            addListener(windowListeners, name, callback),
        removeEventListener: (name, callback) =>
            removeListener(windowListeners, name, callback),
    }
    const response = (data) => ({
        ok: true,
        headers: { get: () => 'application/json' },
        json: async () => data,
    })
    const consentResponse = (payload) =>
        response({
            visit_id: 'visit',
            enabled_categories:
                consentCategories !== undefined
                    ? consentCategories
                    : payload.status === 'accepted_all'
                      ? ['essential', 'insights', 'marketing', 'preferences']
                      : [
                            'essential',
                            ...['insights', 'marketing', 'preferences'].filter(
                                (category) =>
                                    payload.status === 'granular' &&
                                    payload.categories?.[category] === true,
                            ),
                        ],
        })
    const fetch = (url, options = {}) => {
        requests.push({ url, ...options })
        if (url.endsWith('/consent-policy')) {
            return new Promise((resolve, reject) => {
                resolvePolicy = resolve
                rejectPolicy = reject
            })
        }
        if (url.endsWith('/consent')) {
            const payload = JSON.parse(options.body)

            if (consentPersistence === 'failed') {
                return Promise.reject(new Error('Consent persistence failed'))
            }

            if (consentPersistence === 'malformed')
                return Promise.resolve(response({}))

            if (consentPersistence === 'non-ok') {
                return Promise.resolve({ ok: false })
            }

            if (consentPersistence === 'delayed') {
                return new Promise((resolve, reject) => {
                    resolveConsent = () => resolve(consentResponse(payload))
                    rejectConsent = reject
                    pendingConsents.push({
                        resolve: resolveConsent,
                        reject: rejectConsent,
                    })
                })
            }

            if (consentPersistence === 'delayed-json') {
                const acknowledged = consentResponse(payload)
                return Promise.resolve({
                    ...acknowledged,
                    json: () =>
                        new Promise((resolve) => {
                            resolveConsentJson = async () =>
                                resolve(await acknowledged.json())
                        }),
                })
            }

            return Promise.resolve(consentResponse(payload))
        }
        return Promise.resolve(response({ visit_id: 'visit' }))
    }
    const context = vm.createContext({
        window,
        document,
        navigator: { ...privacy, ...(sendBeacon ? { sendBeacon } : {}) },
        fetch,
        URL,
        Blob,
        AbortController,
        Date: class extends Date {
            constructor(...args) {
                super(...(args.length ? args : [currentTime]))
            }

            static now() {
                return currentTime
            }
        },
    })
    const execute = () => vm.runInContext(source, context)
    execute()
    return {
        reinitialize: () => {
            execute()
            return window.CapellInsights
        },
        navigate: () => dispatch(documentListeners, 'livewire:navigating'),
        listenerCount: (name) => documentListeners.get(name)?.size ?? 0,
        timerCount: () => timers.size,
        now: () => currentTime,
        storageChanged: (
            key = 'capell_insights_consent',
            newValue = storage.get(key) ?? null,
            storageArea = window.localStorage,
        ) =>
            dispatch(windowListeners, 'storage', {
                key,
                newValue,
                storageArea,
            }),
        windowListenerCount: (name) => windowListeners.get(name)?.size ?? 0,
        advanceTime: (milliseconds) => {
            currentTime += milliseconds
        },
        api: window.CapellInsights,
        click: (target) =>
            dispatch(documentListeners, 'click', {
                target,
                clientX: 3,
                clientY: 4,
                pageX: 3,
                pageY: 4,
            }),
        storedVisit: () => storage.get('capell_insights_visit_id'),
        bannerHeight: () => rootStyles.get('--capell-insights-banner-height'),
        bannerVisible: () => !banner.hidden,
        choicesVisible: () => !choices.hidden,
        clickConsent: (action) => {
            const attributes = new Map()
            const button = {
                closest: (selector) =>
                    selector === '[data-capell-insights-consent-action]'
                        ? button
                        : null,
                setAttribute: (name, value) =>
                    attributes.set(name, String(value)),
                getAttribute: (name) =>
                    name === 'data-capell-insights-consent-action'
                        ? action
                        : (attributes.get(name) ?? null),
            }

            dispatch(bannerListeners, 'click', { target: button })
            return button
        },
        hideDocument: () => {
            visibilityState = 'hidden'
            dispatch(documentListeners, 'visibilitychange')
        },
        pagehide: () => dispatch(windowListeners, 'pagehide'),
        pageshow: () => {
            dispatch(windowListeners, 'pageshow', { persisted: true })
            return window.CapellInsights
        },
        requests,
        resolve: (data) => resolvePolicy(response(data)),
        replaceStoredConsent: (decision) =>
            storage.set('capell_insights_consent', JSON.stringify(decision)),
        resolveConsent: () => resolveConsent?.(),
        resolveConsentJson: () => resolveConsentJson?.(),
        resolveConsentAt: (index) => pendingConsents[index]?.resolve(),
        rejectConsent: () =>
            rejectConsent?.(new Error('Consent persistence failed')),
        storedConsent: () => {
            const stored = storage.get('capell_insights_consent')

            return stored ? JSON.parse(stored) : null
        },
        reject: () => rejectPolicy(new Error('Network unavailable')),
        malformed: () =>
            resolvePolicy({
                ok: true,
                json: async () => {
                    throw new Error('Invalid JSON')
                },
            }),
        unavailable: () =>
            resolvePolicy({
                ok: false,
                json: async () => ({ consent_required: false }),
            }),
        runTimers: async (maximumDelay = 150) => {
            for (const [id, timer] of [...timers]) {
                if (timer.delay <= maximumDelay) {
                    timers.delete(id)
                    timer.callback()
                }
            }
            await settle()
        },
        events: () =>
            requests
                .filter((request) => request.url.endsWith('/events'))
                .flatMap((request) => JSON.parse(request.body).events),
    }
}

export async function settle() {
    for (let i = 0; i < 12; i++) await Promise.resolve()
}
