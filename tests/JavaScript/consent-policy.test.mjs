import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'

const source = readFileSync(
    new URL('../../resources/js/capell-insights.js', import.meta.url),
    'utf8',
)

function browser({
    consent = null,
    consentPersistence = 'success',
    privacy = {},
    storageUnavailable = false,
} = {}) {
    const requests = []
    const timers = new Map()
    const documentListeners = new Map()
    const windowListeners = new Map()
    const bannerListeners = new Map()
    const rootStyles = new Map()
    const storage = new Map()
    let timerId = 0
    let resolvePolicy
    let rejectPolicy
    let resolveConsent
    let rejectConsent
    let visibilityState = 'visible'
    const policy = new Promise((resolve, reject) => {
        resolvePolicy = resolve
        rejectPolicy = reject
    })
    if (consent) {
        storage.set('capell_insights_consent', JSON.stringify(consent))
    }
    const config = {
        eventsUrl: '/capell/insights/events',
        consentUrl: '/capell/insights/consent',
        consentPolicyUrl: '/capell/insights/consent-policy',
        consentRequired: true,
        policyVersion: '1.0',
        honorPrivacySignals: true,
        trackPageViews: true,
        trackClicks: true,
    }
    const banner = {
        hidden: true,
        offsetHeight: 64,
        addEventListener: (name, callback) =>
            bannerListeners.set(name, callback),
        contains: () => true,
        querySelector: () => null,
        querySelectorAll: () => [],
    }
    const document = {
        title: 'Public page',
        cookie: '',
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
            documentListeners.set(name, callback),
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
                if (storageUnavailable) throw new Error('Storage unavailable')
                storage.set(key, value)
            },
        },
        setTimeout: (callback, delay) => {
            timers.set(++timerId, { callback, delay })
            return timerId
        },
        clearTimeout: (id) => timers.delete(id),
        addEventListener: (name, callback) =>
            windowListeners.set(name, callback),
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
                payload.status === 'accepted_all'
                    ? ['essential', 'insights']
                    : ['essential'],
        })
    const fetch = (url, options = {}) => {
        requests.push({ url, ...options })
        if (url.endsWith('/consent-policy')) return policy
        if (url.endsWith('/consent')) {
            const payload = JSON.parse(options.body)

            if (consentPersistence === 'failed') {
                return Promise.reject(new Error('Consent persistence failed'))
            }

            if (consentPersistence === 'non-ok') {
                return Promise.resolve({ ok: false })
            }

            if (consentPersistence === 'delayed') {
                return new Promise((resolve, reject) => {
                    resolveConsent = () => resolve(consentResponse(payload))
                    rejectConsent = reject
                })
            }

            return Promise.resolve(consentResponse(payload))
        }
        return Promise.resolve(response({ visit_id: 'visit' }))
    }
    vm.runInNewContext(source, {
        window,
        document,
        navigator: privacy,
        fetch,
        URL,
        Blob,
        AbortController,
    })
    return {
        api: window.CapellInsights,
        bannerHeight: () => rootStyles.get('--capell-insights-banner-height'),
        bannerVisible: () => !banner.hidden,
        clickConsent: (action) => {
            const button = {
                closest: (selector) =>
                    selector === '[data-capell-insights-consent-action]'
                        ? button
                        : null,
                getAttribute: (name) =>
                    name === 'data-capell-insights-consent-action'
                        ? action
                        : null,
            }

            bannerListeners.get('click')?.({ target: button })
        },
        hideDocument: () => {
            visibilityState = 'hidden'
            documentListeners.get('visibilitychange')?.()
        },
        pagehide: () => windowListeners.get('pagehide')?.(),
        requests,
        resolve: (data) => resolvePolicy(response(data)),
        resolveConsent: () => resolveConsent?.(),
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

async function settle() {
    for (let i = 0; i < 12; i++) await Promise.resolve()
}

test('publishes the API immediately but drops events until the private policy resolves', async () => {
    const page = browser()
    assert.ok(page.api)
    page.api.track({ type: 'click', event_name: 'before-policy' })
    page.api.flush()
    assert.deepEqual(page.events(), [])
    assert.equal(
        page.requests.filter((request) =>
            request.url.endsWith('/consent-policy'),
        ).length,
        1,
    )
    page.resolve({ consent_required: false })
    await settle()
    await page.runTimers()
    assert.deepEqual(
        page.events().map((event) => event.type),
        ['page_view'],
    )
    page.api.track({ type: 'click', event_name: 'after-policy' })
    page.api.flush()
    assert.deepEqual(
        page.events().map((event) => event.event_name ?? event.type),
        ['page_view', 'after-policy'],
    )
})

for (const storageUnavailable of [false, true]) {
    test(`retains early acceptance and sends the first page once with unavailable storage ${storageUnavailable}`, async () => {
        const page = browser({ storageUnavailable })
        page.api.consent({ status: 'accepted_all' })
        await settle()
        page.api.flush()
        assert.deepEqual(page.events(), [])
        page.resolve({ consent_required: true })
        await settle()
        await page.runTimers()
        assert.deepEqual(
            page.events().map((event) => event.type),
            ['page_view'],
        )
        page.api.consent({ status: 'accepted_all' })
        await settle()
        page.api.flush()
        assert.equal(page.events().length, 1)
    })
}

test('a timed out policy remains strict even when a permissive reply arrives late', async () => {
    const page = browser()
    await page.runTimers(5000)
    page.resolve({ consent_required: false })
    await settle()
    page.api.track({ type: 'click' })
    page.api.flush()
    assert.deepEqual(page.events(), [])
    page.api.consent({ status: 'accepted_all' })
    await settle()
    await page.runTimers()
    assert.deepEqual(
        page.events().map((event) => event.type),
        ['page_view'],
    )
})

for (const failure of ['reject', 'malformed', 'unavailable']) {
    test(`keeps the strict policy after ${failure} but honours an acknowledged choice`, async () => {
        const page = browser()
        page[failure]()
        await settle()
        page.api.track({ type: 'click' })
        page.api.flush()
        assert.deepEqual(page.events(), [])
        page.api.consent({ status: 'accepted_all' })
        await settle()
        await page.runTimers()
        assert.deepEqual(
            page.events().map((event) => event.type),
            ['page_view'],
        )
    })
}

for (const consent of [
    { status: 'rejected_non_essential', categories: ['essential'] },
    { status: 'granular', categories: ['essential', 'preferences'] },
]) {
    test(`preserves ${consent.status} outside consent-required regions`, async () => {
        const page = browser({ consent: { ...consent, policy_version: '1.0' } })
        page.resolve({ consent_required: false })
        await settle()
        page.api.track({ type: 'click' })
        page.api.flush()
        assert.deepEqual(page.events(), [])
    })
}

for (const privacy of [{ globalPrivacyControl: true }, { doNotTrack: '1' }]) {
    test(`short-circuits privacy signal ${JSON.stringify(privacy)} before policy lookup`, () => {
        const page = browser({ privacy })
        assert.deepEqual(page.requests, [])
        assert.equal(page.api, undefined)
    })
}

test('discards queued events if consent is revoked before flushing', async () => {
    const page = browser()
    page.resolve({ consent_required: false })
    await settle()
    page.api.track({ type: 'click' })
    page.api.consent({ status: 'rejected_non_essential' })
    await settle()
    page.api.flush()
    assert.deepEqual(page.events(), [])
})

for (const lifecycleFlush of ['pagehide', 'hideDocument']) {
    test(`blocks queued telemetry before ${lifecycleFlush} while rejection persistence is pending`, async () => {
        const page = browser({ consentPersistence: 'delayed' })
        page.resolve({ consent_required: false })
        await settle()
        page.api.track({ type: 'click', event_name: 'before-rejection' })

        page.clickConsent('reject')
        page[lifecycleFlush]()
        await page.runTimers()

        assert.deepEqual(page.events(), [])

        page.api.track({ type: 'click', event_name: 'after-rejection' })
        page.api.flush()
        assert.deepEqual(page.events(), [])

        page.resolveConsent()
        await settle()
        assert.deepEqual(page.events(), [])
    })
}

for (const consentPersistence of ['failed', 'non-ok']) {
    test(`keeps tracking blocked when rejection persistence is ${consentPersistence}`, async () => {
        const page = browser({ consentPersistence })
        page.resolve({ consent_required: false })
        await settle()
        page.api.track({ type: 'click', event_name: 'before-rejection' })

        page.clickConsent('reject')
        await settle()
        page.api.track({ type: 'click', event_name: 'after-rejection' })
        page.api.flush()
        await page.runTimers()

        assert.deepEqual(page.events(), [])
        assert.equal(page.storedConsent()?.status, 'rejected_non_essential')
        assert.deepEqual(page.storedConsent()?.categories, [])
    })
}

test('hides the consent banner when the private policy does not require consent', async () => {
    const page = browser()

    assert.equal(page.bannerVisible(), true)
    assert.equal(page.bannerHeight(), '64px')

    page.resolve({ consent_required: false })
    await settle()

    assert.equal(page.bannerVisible(), false)
    assert.equal(page.bannerHeight(), '0px')
})

test('keeps the consent banner visible when the private policy requires consent', async () => {
    const page = browser()

    page.resolve({ consent_required: true })
    await settle()

    assert.equal(page.bannerVisible(), true)
    assert.equal(page.bannerHeight(), '64px')
})

for (const data of [
    null,
    {},
    { consent_required: 'false' },
    { consent_required: 0 },
]) {
    test(`requires consent for invalid policy ${JSON.stringify(data)}`, async () => {
        const page = browser()
        page.resolve(data)
        await settle()
        page.api.track({ type: 'click' })
        page.api.flush()
        assert.deepEqual(page.events(), [])
    })
}

test('uses acknowledged analytics categories in a strict region', async () => {
    const page = browser({
        consent: {
            status: 'granular',
            categories: ['essential', 'insights'],
            policy_version: '1.0',
        },
    })
    assert.deepEqual(page.events(), [])
    page.resolve({ consent_required: true })
    await settle()
    await page.runTimers()
    assert.deepEqual(
        page.events().map((event) => event.type),
        ['page_view'],
    )
})

test('requires a fresh choice when the stored policy version is obsolete', async () => {
    const page = browser({
        consent: {
            status: 'accepted_all',
            categories: ['essential', 'insights'],
            policy_version: 'old',
        },
    })
    page.resolve({ consent_required: true })
    await settle()
    await page.runTimers()
    assert.deepEqual(page.events(), [])
})
