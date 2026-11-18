import assert from 'node:assert/strict'
import test from 'node:test'

import { browser, settle } from './browser.mjs'

function privacy(initial) {
    const state = { analytics: initial }

    return {
        state,
        api: {
            allows: (category) =>
                category === 'analytics' ? state.analytics : null,
            consent: () => null,
            choose: () => Promise.resolve(true),
            open: () => {},
        },
    }
}

const consentRequests = (page) =>
    page.requests.filter((request) => request.url.endsWith('/consent'))

test('with Privacy Centre present Insights shows no banner and tracks nothing while analytics is undecided', async () => {
    const shared = privacy(null)
    const page = browser({ capellPrivacy: shared.api })
    page.resolve({ consent_required: false })
    await settle()
    await page.runTimers()
    page.api.track({ type: 'click', event_name: 'early' })
    page.api.flush()

    assert.equal(page.bannerVisible(), false)
    assert.deepEqual(page.events(), [])
    assert.deepEqual(consentRequests(page), [])
})

test('with Privacy Centre present a refusal keeps Insights silent', async () => {
    const shared = privacy(false)
    const page = browser({ capellPrivacy: shared.api })
    page.resolve({ consent_required: false })
    await settle()
    await page.runTimers()
    page.api.track({ type: 'click', event_name: 'refused' })
    page.api.flush()

    assert.equal(page.bannerVisible(), false)
    assert.deepEqual(page.events(), [])
    assert.deepEqual(consentRequests(page), [])
})

test('with Privacy Centre present an analytics grant records the decision and tracks the first page view', async () => {
    const shared = privacy(null)
    const page = browser({ capellPrivacy: shared.api })
    page.resolve({ consent_required: true })
    await settle()

    shared.state.analytics = true
    page.dispatchPrivacyChanged()
    await settle()
    await page.runTimers()

    const [request] = consentRequests(page)
    const body = JSON.parse(request.body)
    assert.equal(body.status, 'granular')
    assert.deepEqual(body.categories, {
        insights: true,
        marketing: false,
        preferences: false,
    })
    assert.equal(body.source, 'privacy-center')
    assert.equal(page.bannerVisible(), false)
    assert.deepEqual(
        page.events().map((event) => event.type),
        ['page_view'],
    )
})

test('with Privacy Centre present a granted visitor is tracked on load', async () => {
    const shared = privacy(true)
    const page = browser({ capellPrivacy: shared.api })
    page.resolve({ consent_required: true })
    await settle()
    await page.runTimers()

    assert.deepEqual(
        page.events().map((event) => event.type),
        ['page_view'],
    )
})

test('with Privacy Centre present a later withdrawal stops tracking and records the refusal', async () => {
    const shared = privacy(true)
    const page = browser({ capellPrivacy: shared.api })
    page.resolve({ consent_required: true })
    await settle()
    await page.runTimers()

    shared.state.analytics = false
    page.dispatchPrivacyChanged()
    await settle()
    page.api.track({ type: 'click', event_name: 'after-withdrawal' })
    page.api.flush()

    const bodies = consentRequests(page).map((request) =>
        JSON.parse(request.body),
    )
    assert.equal(bodies.at(-1).status, 'rejected_non_essential')
    assert.equal(bodies.at(-1).source, 'privacy-center')
    assert.equal(
        page.events().filter((event) => event.event_name === 'after-withdrawal')
            .length,
        0,
    )
})

test('without Privacy Centre the fallback banner still appears when consent is required', async () => {
    const page = browser()
    page.resolve({ consent_required: true })
    await settle()

    assert.equal(page.bannerVisible(), true)
})

test('without Privacy Centre a provider refusal hides the fallback banner and blocks tracking', async () => {
    const page = browser()
    page.resolve({ consent_required: false, analytics_decision: 'denied' })
    await settle()
    await page.runTimers()
    page.api.track({ type: 'click', event_name: 'gpc' })
    page.api.flush()
    page.api.consent({ status: 'accepted_all' })
    await settle()
    page.api.flush()

    assert.equal(page.bannerVisible(), false)
    assert.deepEqual(page.events(), [])
})
