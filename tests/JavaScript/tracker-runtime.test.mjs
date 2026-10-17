import assert from 'node:assert/strict'
import test from 'node:test'
import { browser, settle } from './browser.mjs'

for (const privacy of [
    { globalPrivacyControl: true },
    { doNotTrack: '1' },
    { msDoNotTrack: '1' },
]) {
    test(`privacy signal prevents all requests: ${JSON.stringify(privacy)}`, () => {
        const page = browser({ privacy })
        assert.equal(page.api, undefined)
        assert.deepEqual(page.requests, [])
    })
}

test('consent uses the current origin and acknowledges a visit before tracking', async () => {
    const beacons = []
    const page = browser({
        sendBeacon: (url, body) => {
            beacons.push({ url, body })
            return true
        },
    })
    page.resolve({ consent_required: true })
    await settle()
    assert.equal(page.bannerVisible(), true)
    page.clickConsent('accept')
    await settle()
    const consent = page.requests.find((request) =>
        request.url.endsWith('/consent'),
    )
    assert.equal(consent.url, 'https://example.test/capell/insights/consent')
    assert.equal(consent.keepalive, true)
    assert.equal(JSON.parse(consent.body).policy_version, '1.0')
    assert.equal(page.storedVisit(), 'visit')
    assert.deepEqual(page.storedConsent().categories, [
        'essential',
        'insights',
        'marketing',
        'preferences',
    ])
    assert.equal(page.bannerVisible(), false)
    assert.equal(
        beacons.some((beacon) => beacon.url.endsWith('/consent')),
        false,
    )
})

test('the server cookie identifies a visit without local storage', async () => {
    const page = browser({
        cookie: 'other=value; capell_insights_visit=cookie-visit',
        storageUnavailable: true,
    })
    page.resolve({ consent_required: false })
    await settle()
    page.api.flush()
    const batch = page.requests.find((request) =>
        request.url.endsWith('/events'),
    )
    assert.equal(batch.url, 'https://example.test/capell/insights/events')
    assert.equal(JSON.parse(batch.body).visit_id, 'cookie-visit')
})

test('the first batch reads the response and later batches can use a beacon', async () => {
    const beacons = []
    const page = browser({
        sendBeacon: (url, body) => {
            beacons.push({ url, body })
            return true
        },
    })
    page.resolve({ consent_required: false })
    await settle()
    page.api.flush()
    await settle()
    assert.equal(
        page.requests.find((request) => request.url.endsWith('/events'))
            .keepalive,
        true,
    )
    assert.equal(page.storedVisit(), 'visit')
    page.api.track({ type: 'click', event_name: 'after-visit' })
    page.api.flush()
    const payload = JSON.parse(await beacons.at(-1).body.text())
    assert.equal(payload.visit_id, 'visit')
    assert.equal(payload.events[0].event_name, 'after-visit')
})

const trackedButton = ({ ignored = false } = {}) => {
    const attributes = {
        'data-capell-insights': 'buy',
        'data-capell-insights-label': 'Buy the guide',
        'data-capell-insights-location': 'pricing',
    }
    const button = {
        nodeType: 1,
        tagName: 'BUTTON',
        id: 'private-user-42',
        textContent: 'Buy',
        matches: () => false,
        getAttribute: (name) => attributes[name] ?? null,
        closest: (selector) => {
            if (selector === '[data-capell-insights]') return button
            if (selector === '[data-capell-insights-ignore]' && ignored)
                return button
            return null
        },
    }
    return button
}

test('click tracking preserves author labels and drops ignored targets and raw ids', async () => {
    const page = browser()
    page.resolve({ consent_required: false })
    await settle()
    page.click(trackedButton())
    page.click(trackedButton({ ignored: true }))
    page.api.flush()
    const clicks = page.events().filter((event) => event.type === 'click')
    assert.equal(clicks.length, 1)
    assert.equal(clicks[0].label, 'Buy the guide')
    assert.equal(clicks[0].location, 'pricing')
    assert.equal(clicks[0].event_name, 'buy')
    assert.equal(clicks[0].target_selector, 'button[data-capell-insights]')
    assert.equal(JSON.stringify(clicks).includes('private-user-42'), false)
})

test('banner choices toggle accessibly and rejection keeps analytics disabled', async () => {
    const page = browser()
    page.resolve({ consent_required: true })
    await settle()
    const button = page.clickConsent('manage')
    assert.equal(page.choicesVisible(), true)
    assert.equal(button.getAttribute('aria-expanded'), 'true')
    page.clickConsent('manage')
    assert.equal(page.choicesVisible(), false)
    page.clickConsent('reject')
    await settle()
    assert.equal(page.bannerVisible(), false)
    assert.deepEqual(page.storedConsent().categories, ['essential'])
    page.api.track({ type: 'click', event_name: 'rejected-click' })
    page.api.flush()
    assert.deepEqual(page.events(), [])
})

test('custom consent restoration blocks remembered grants until a read-only handoff', async () => {
    const page = browser({
        consentRestorationRequired: true,
        consent: {
            status: 'accepted_all',
            categories: ['essential', 'insights', 'marketing', 'preferences'],
            policy_version: '1.0',
        },
    })
    const before = page.storedConsent()
    page.resolve({ consent_required: true })
    await settle()
    page.api.track({ type: 'custom' })
    page.api.flush()
    assert.deepEqual(page.events(), [])
    assert.equal(
        await page.api.restoreConsent({ status: 'accepted_all' }),
        true,
    )
    page.api.track({ type: 'custom' })
    page.api.flush()
    assert.equal(page.events().length, 2)
    assert.deepEqual(page.storedConsent(), before)
    assert.equal(
        page.requests.filter((request) => request.url.endsWith('/consent'))
            .length,
        0,
    )
})

test('navigation disposes listeners, queued events, policy timer and pending acknowledgement', async () => {
    const page = browser({ consentPersistence: 'delayed' })
    const oldApi = page.api
    const pending = oldApi.consent({
        status: 'accepted_all',
        terms_accepted: true,
    })
    page.navigate()
    assert.equal(page.listenerCount('click'), 0)
    assert.equal(page.listenerCount('visibilitychange'), 0)
    assert.equal(page.timerCount(), 0)
    page.resolveConsent()
    page.resolve({ consent_required: false })
    await settle()
    assert.equal(await pending, false)
    assert.equal(page.storedConsent(), null)
    oldApi.track({ type: 'custom' })
    oldApi.flush()
    assert.deepEqual(page.events(), [])
    const newApi = page.reinitialize()
    await settle()
    assert.notEqual(newApi, oldApi)
    assert.equal(page.listenerCount('click'), 1)
    assert.equal(page.listenerCount('visibilitychange'), 1)
    const thirdApi = page.reinitialize()
    assert.notEqual(thirdApi, newApi)
    assert.equal(page.listenerCount('click'), 1)
    assert.equal(page.listenerCount('visibilitychange'), 1)
})

test('disposal discards queued events and refuses queued consent writes', async () => {
    const page = browser({
        consentPersistence: 'delayed',
        consent: {
            status: 'accepted_all',
            categories: ['essential', 'insights', 'marketing', 'preferences'],
            policy_version: '1.0',
        },
    })
    page.resolve({ consent_required: true })
    await settle()
    page.api.track({ type: 'custom' })
    const first = page.api.consent({ status: 'rejected_non_essential' })
    const second = page.api.consent({
        status: 'accepted_all',
        terms_accepted: true,
    })
    page.navigate()
    page.resolveConsent()
    await settle()
    assert.equal(await first, false)
    assert.equal(await second, false)
    assert.equal(await page.api.consent({ status: 'accepted_all' }), false)
    assert.equal(
        page.requests.filter((request) => request.url.endsWith('/consent'))
            .length,
        1,
    )
    assert.equal(page.timerCount(), 0)
    assert.deepEqual(page.events(), [])
})

test('cached document restoration creates exactly one fresh tracker', async () => {
    const page = browser({ consentRestorationRequired: true })
    const oldApi = page.api
    page.pagehide()
    assert.equal(page.listenerCount('click'), 0)
    const resumedApi = page.pageshow()
    assert.notEqual(resumedApi, oldApi)
    assert.equal(page.listenerCount('click'), 1)
    assert.equal(page.listenerCount('visibilitychange'), 1)
    assert.equal(await oldApi.consent({ status: 'accepted_all' }), false)
    page.resolve({ consent_required: false })
    await settle()
    resumedApi.track({ type: 'custom' })
    resumedApi.flush()
    assert.deepEqual(page.events(), [])
})

test('replacement trackers serialize consent writes until the older server response settles', async () => {
    const page = browser({
        consentPersistence: 'delayed',
        consentRestorationRequired: true,
    })
    const firstApi = page.api
    const acceptance = firstApi.consent({
        status: 'accepted_all',
        terms_accepted: true,
    })
    const replacement = page.reinitialize()
    const rejection = replacement.consent({ status: 'rejected_non_essential' })
    await settle()
    const writes = () =>
        page.requests.filter((request) => request.url.endsWith('/consent'))
    assert.equal(writes().length, 1)
    assert.equal(JSON.parse(writes()[0].body).status, 'accepted_all')
    assert.equal(
        await replacement.restoreConsent({ status: 'rejected_non_essential' }),
        false,
    )
    page.resolveConsentAt(0)
    await settle()
    assert.equal(await acceptance, false)
    assert.equal(writes().length, 2)
    assert.equal(JSON.parse(writes()[1].body).status, 'rejected_non_essential')
    assert.equal(page.storedConsent().categories.length, 0)
    assert.equal(
        await replacement.restoreConsent({ status: 'rejected_non_essential' }),
        false,
    )
    page.resolveConsentAt(1)
    await settle()
    assert.equal(await rejection, true)
    assert.equal(page.storedConsent().status, 'rejected_non_essential')
    assert.deepEqual(page.storedConsent().categories, ['essential'])
    assert.equal(
        await replacement.restoreConsent({ status: 'rejected_non_essential' }),
        true,
    )
    assert.equal(
        await replacement.consent({ status: 'rejected_non_essential' }),
        true,
    )
    assert.equal(writes().length, 2)
    assert.deepEqual(page.events(), [])
})

test('replacement queues skip disposed choices and cannot reuse consent ahead of an outstanding write', async () => {
    const page = browser({
        consentPersistence: 'delayed',
        consent: {
            status: 'accepted_all',
            categories: ['essential', 'insights', 'marketing', 'preferences'],
            policy_version: '1.0',
        },
    })
    const first = page.api.consent({
        status: 'granular',
        categories: { insights: true, marketing: false, preferences: false },
        terms_accepted: true,
    })
    const discarded = page.api.consent({
        status: 'accepted_all',
        terms_accepted: true,
    })
    const replacement = page.reinitialize()
    assert.equal(
        await replacement.restoreConsent({ status: 'accepted_all' }),
        false,
    )
    const newest = replacement.consent({
        status: 'accepted_all',
        terms_accepted: true,
    })
    await settle()
    const writes = () =>
        page.requests.filter((request) => request.url.endsWith('/consent'))
    assert.equal(writes().length, 1)
    page.resolveConsentAt(0)
    await settle()
    assert.equal(await first, false)
    assert.equal(await discarded, false)
    assert.equal(writes().length, 2)
    assert.equal(JSON.parse(writes()[1].body).status, 'accepted_all')
    page.resolveConsentAt(1)
    await settle()
    assert.equal(await newest, true)
    assert.equal(
        await replacement.restoreConsent({ status: 'accepted_all' }),
        true,
    )
    assert.equal(writes().length, 2)
})

test('bfcache restoration waits for a fresh policy and re-reads withdrawn consent', async () => {
    const page = browser()
    page.resolve({ consent_required: true })
    await settle()
    assert.equal(await page.api.consent({ status: 'accepted_all' }), true)
    page.pagehide()
    const sent = page.events()
    page.replaceStoredConsent({
        status: 'rejected_non_essential',
        categories: ['essential'],
        policy_version: '1.0',
        decided_at: page.storedConsent().decided_at,
    })
    const resumed = page.pageshow()
    const lookups = page.requests.filter((r) =>
        r.url.endsWith('/consent-policy'),
    )
    assert.equal(lookups.length, 2)
    assert.equal(lookups[0].signal.aborted, true)
    resumed.track({ type: 'custom' })
    resumed.flush()
    assert.deepEqual(page.events(), sent)
    page.resolve({ consent_required: false })
    await settle()
    resumed.track({ type: 'custom' })
    resumed.flush()
    assert.deepEqual(page.events(), sent)
    const writes = page.requests.filter((r) => r.url.endsWith('/consent'))
    assert.equal(writes.length, 1)
})

test('bfcache restoration cannot reuse a permissive policy before a new strict policy resolves', async () => {
    const page = browser()
    page.resolve({ consent_required: false })
    await settle()
    page.pagehide()
    const sent = page.events()
    const resumed = page.pageshow()
    await settle()
    resumed.track({ type: 'custom' })
    resumed.flush()
    assert.deepEqual(page.events(), sent)
    page.resolve({ consent_required: true })
    await settle()
    resumed.track({ type: 'custom' })
    resumed.flush()
    assert.deepEqual(page.events(), sent)
    assert.equal(page.bannerVisible(), true)
})
