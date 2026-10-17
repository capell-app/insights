import assert from 'node:assert/strict'
import test from 'node:test'

import { browser, settle } from './browser.mjs'

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

for (const consentPersistence of ['failed', 'non-ok', 'malformed']) {
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

test('acknowledges the public consent request only after persistence', async () => {
    const page = browser({ consentPersistence: 'delayed' })
    const acknowledgement = page.api.consent({ status: 'accepted_all' })
    assert.equal(typeof acknowledgement?.then, 'function')
    page.resolveConsent()
    assert.equal(await acknowledgement, true)
})

for (const consentPersistence of ['failed', 'non-ok', 'malformed']) {
    test(`reports unsuccessful public consent persistence: ${consentPersistence}`, async () => {
        const page = browser({ consentPersistence })
        assert.equal(await page.api.consent({ status: 'accepted_all' }), false)
    })
}

test('an older accepted response cannot replace a newer rejection', async () => {
    const page = browser({ consentPersistence: 'delayed' })
    page.resolve({ consent_required: true })
    await settle()
    const acceptance = page.api.consent({ status: 'accepted_all' })
    const rejection = page.api.consent({ status: 'rejected_non_essential' })
    assert.equal(page.storedConsent().status, 'rejected_non_essential')
    assert.equal(
        page.requests.filter((request) => request.url.endsWith('/consent'))
            .length,
        1,
    )
    page.resolveConsentAt(0)
    await acceptance
    await settle()
    assert.equal(page.storedConsent().status, 'rejected_non_essential')
    page.resolveConsentAt(1)
    await rejection
    await settle()
    assert.equal(page.storedConsent().status, 'rejected_non_essential')
    assert.equal(await acceptance, false)
    assert.equal(await rejection, true)
    await page.runTimers()
    assert.deepEqual(page.events(), [])
})

test('reuses an acknowledged granular choice without another consent record', async () => {
    const page = browser()
    const choice = {
        status: 'granular',
        terms_accepted: true,
        categories: { insights: true, marketing: false, preferences: false },
    }
    assert.equal(await page.api.consent(choice), true)
    assert.equal(await page.api.consent(choice), true)
    assert.equal(
        page.requests.filter((request) => request.url.endsWith('/consent'))
            .length,
        1,
    )
})

test('a remembered rejection still supersedes an in-flight acceptance', async () => {
    const page = browser({
        consentPersistence: 'delayed',
        consent: {
            status: 'rejected_non_essential',
            categories: ['essential'],
            policy_version: '1.0',
        },
    })
    const acceptance = page.api.consent({ status: 'accepted_all' })
    const rejection = page.api.consent({ status: 'rejected_non_essential' })
    assert.equal(
        page.requests.filter((request) => request.url.endsWith('/consent'))
            .length,
        1,
    )
    page.resolveConsentAt(0)
    assert.equal(await acceptance, false)
    await settle()
    assert.equal(
        page.requests.filter((request) => request.url.endsWith('/consent'))
            .length,
        2,
    )
    page.resolveConsentAt(1)
    assert.equal(await rejection, true)
    assert.equal(page.storedConsent().status, 'rejected_non_essential')
})

test('repeated rejection reuses the same acknowledged essential category', async () => {
    const page = browser()
    for (let i = 0; i < 3; i++)
        assert.equal(
            await page.api.consent({ status: 'rejected_non_essential' }),
            true,
        )
    assert.equal(
        page.requests.filter((request) => request.url.endsWith('/consent'))
            .length,
        1,
    )
    assert.deepEqual(Array.from(page.storedConsent().categories), ['essential'])
})

test('granular analytics revocation stops queued and new events before failed persistence', async () => {
    const page = browser({
        consentPersistence: 'delayed',
        consent: {
            status: 'granular',
            categories: ['essential', 'insights'],
            policy_version: '1.0',
        },
    })
    page.resolve({ consent_required: true })
    await settle()
    const alreadySent = page.events()
    page.api.track({ type: 'click', event_name: 'queued-before-revocation' })
    const decision = page.api.consent({
        status: 'granular',
        terms_accepted: true,
        categories: { insights: false, marketing: true, preferences: false },
    })
    page.api.track({ type: 'click', event_name: 'after-revocation' })
    page.api.flush()
    assert.deepEqual(page.events(), alreadySent)
    page.rejectConsent()
    assert.equal(await decision, false)
    page.api.track({ type: 'click', event_name: 'after-failure' })
    page.api.flush()
    assert.deepEqual(page.events(), alreadySent)
})

test('read-only restoration never accepts stale policy or creates another consent record', async () => {
    const choice = {
        status: 'granular',
        terms_accepted: true,
        categories: { insights: true, marketing: false, preferences: false },
    }
    for (const policy_version of ['0.9', '1.0']) {
        const page = browser({
            consent: {
                status: 'granular',
                categories: ['essential', 'insights'],
                policy_version,
            },
        })
        assert.equal(
            await page.api.restoreConsent(choice),
            policy_version === '1.0',
        )
        assert.equal(
            page.requests.filter((request) => request.url.endsWith('/consent'))
                .length,
            0,
        )
    }
})

test('failed rejection cannot be reused as acknowledged consent', async () => {
    const page = browser({ consentPersistence: 'failed' })
    const rejection = { status: 'rejected_non_essential' }
    assert.equal(await page.api.consent(rejection), false)
    assert.equal(await page.api.restoreConsent(rejection), false)
    assert.equal(await page.api.consent(rejection), false)
    assert.equal(
        page.requests.filter((request) => request.url.endsWith('/consent'))
            .length,
        2,
    )
})

test('accepted all replay matches the actual controller category set', async () => {
    const page = browser()
    const acceptance = { status: 'accepted_all' }
    assert.equal(await page.api.consent(acceptance), true)
    assert.equal(await page.api.restoreConsent(acceptance), true)
    assert.equal(await page.api.consent(acceptance), true)
    assert.equal(
        page.requests.filter((request) => request.url.endsWith('/consent'))
            .length,
        1,
    )
    assert.deepEqual(
        Array.from(page.storedConsent().categories).sort(),
        ['essential', 'insights', 'marketing', 'preferences'].sort(),
    )
})

for (const consentCategories of [
    [true],
    ['unknown'],
    [],
    ['essential', 'insights'],
    ['essential', 'essential'],
]) {
    test(`rejects malformed or contradictory rejection acknowledgement ${JSON.stringify(consentCategories)}`, async () => {
        const page = browser({ consentCategories })
        assert.equal(
            await page.api.consent({ status: 'rejected_non_essential' }),
            false,
        )
        assert.equal(
            await page.api.restoreConsent({ status: 'rejected_non_essential' }),
            false,
        )
        await page.runTimers()
        assert.deepEqual(page.events(), [])
    })
}

test('expired same-policy choices require a fresh decision rather than silent renewal', async () => {
    const choice = { status: 'accepted_all' }
    const page = browser({
        consent: {
            status: 'accepted_all',
            categories: ['essential', 'insights', 'marketing', 'preferences'],
            policy_version: '1.0',
            decided_at: new Date(Date.now() - 181 * 86400000).toISOString(),
        },
    })
    const prior = page.storedConsent()
    assert.equal(await page.api.restoreConsent(choice), false)
    assert.deepEqual(page.storedConsent(), prior)
    assert.equal(
        page.requests.filter((r) => r.url.endsWith('/consent')).length,
        0,
    )
    assert.equal(await page.api.consent(choice), true)
    assert.equal(
        page.requests.filter((r) => r.url.endsWith('/consent')).length,
        1,
    )
    assert.ok(
        Date.parse(page.storedConsent().decided_at) >
            Date.parse(prior.decided_at),
    )
})

for (const decided_at of [
    null,
    '',
    'not-a-date',
    new Date(Date.now() + 86400000).toISOString(),
]) {
    test(`invalid or future decision time cannot restore or deduplicate: ${decided_at}`, async () => {
        const page = browser({
            consent: {
                status: 'rejected_non_essential',
                categories: ['essential'],
                policy_version: '1.0',
                decided_at,
            },
        })
        assert.equal(
            await page.api.restoreConsent({ status: 'rejected_non_essential' }),
            false,
        )
        assert.equal(
            await page.api.consent({ status: 'rejected_non_essential' }),
            true,
        )
        assert.equal(
            page.requests.filter((r) => r.url.endsWith('/consent')).length,
            1,
        )
    })
}

test('failed read-only restoration leaves acknowledged timestamps and categories intact', async () => {
    const page = browser({
        consent: {
            status: 'accepted_all',
            categories: ['essential', 'insights', 'marketing', 'preferences'],
            policy_version: '1.0',
        },
    })
    const prior = page.storedConsent()
    assert.equal(
        await page.api.restoreConsent({ status: 'rejected_non_essential' }),
        false,
    )
    page.resolve({ consent_required: true })
    await settle()
    assert.deepEqual(page.events(), [])
    assert.deepEqual(page.storedConsent(), prior)
    assert.equal(
        await page.api.restoreConsent({ status: 'accepted_all' }),
        true,
    )
    assert.deepEqual(page.storedConsent(), prior)
    assert.equal(
        page.requests.filter((r) => r.url.endsWith('/consent')).length,
        0,
    )
})

test('disabled expiry retains an old acknowledged choice without creating a new record', async () => {
    const page = browser({
        consentExpiresDays: null,
        consent: {
            status: 'rejected_non_essential',
            categories: ['essential'],
            policy_version: '1.0',
            decided_at: new Date(Date.now() - 365 * 86400000).toISOString(),
        },
    })
    assert.equal(
        await page.api.restoreConsent({ status: 'rejected_non_essential' }),
        true,
    )
    assert.equal(
        await page.api.consent({ status: 'rejected_non_essential' }),
        true,
    )
    assert.equal(
        page.requests.filter((r) => r.url.endsWith('/consent')).length,
        0,
    )
})

for (const consentRequired of [true, false]) {
    for (const status of ['accepted_all', 'rejected_non_essential']) {
        test(`expiry blocks queued and new events for ${status} with required policy ${consentRequired}`, async () => {
            const page = browser({ consentExpiresDays: 1 })
            page.resolve({ consent_required: consentRequired })
            await settle()
            assert.equal(await page.api.consent({ status }), true)
            const sent = page.events()
            page.api.track({
                type: 'custom',
                event_name: 'queued-before-expiry',
            })
            page.advanceTime(86400001)
            page.api.track({ type: 'custom', event_name: 'after-expiry' })
            page.api.flush()
            await page.runTimers()
            assert.deepEqual(page.events(), sent)
            assert.equal(await page.api.restoreConsent({ status }), false)
        })
    }
}

test('a pending acceptance cannot send events before its acknowledgement', async () => {
    const page = browser({ consentPersistence: 'delayed' })
    page.resolve({ consent_required: true })
    await settle()
    const acceptance = page.api.consent({ status: 'accepted_all' })
    page.api.track({ type: 'custom' })
    page.api.flush()
    await page.runTimers()
    assert.deepEqual(page.events(), [])
    page.resolveConsent()
    assert.equal(await acceptance, true)
    await page.runTimers()
    assert.deepEqual(
        page.events().map((event) => event.type),
        ['page_view'],
    )
})
