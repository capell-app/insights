import assert from 'node:assert/strict'
import test from 'node:test'
import { browser, settle } from './browser.mjs'

// Exercise the same shared storage used by two tabs, including delivery before
// the browser has dispatched the asynchronous storage notification.
for (const transport of ['fetch', 'beacon']) {
    for (const reconcileAt of ['enqueue', 'flush', 'storage']) {
        test(`cross-tab withdrawal discards queued and new events at ${reconcileAt} with ${transport}`, async () => {
            const sharedStorage = new Map()
            const beacons = []
            const tabA = browser({
                sharedStorage,
                sendBeacon:
                    transport === 'beacon'
                        ? (url, body) => {
                              beacons.push({ url, body })
                              return true
                          }
                        : null,
            })
            const tabB = browser({ sharedStorage, initialTime: tabA.now() })
            tabA.resolve({ consent_required: true })
            tabB.resolve({ consent_required: true })
            await settle()
            assert.equal(
                await tabA.api.consent({ status: 'accepted_all' }),
                true,
            )
            const sent = tabA.events()
            const beaconCount = beacons.length
            assert.equal(sent.length + beaconCount, 1)
            tabA.api.track({
                type: 'custom',
                event_name: 'queued-before-withdrawal-in-other-tab',
            })
            assert.equal(
                await tabB.api.consent({ status: 'rejected_non_essential' }),
                true,
            )

            if (reconcileAt === 'storage') {
                tabA.storageChanged()
                assert.equal(tabA.timerCount(), 0)
            }
            if (reconcileAt === 'flush') tabA.api.flush()
            tabA.api.track({
                type: 'custom',
                event_name: 'after-withdrawal-in-other-tab',
            })
            if (reconcileAt === 'enqueue') assert.equal(tabA.timerCount(), 0)
            tabA.api.flush()
            await tabA.runTimers()
            assert.deepEqual(tabA.events(), sent)
            assert.equal(beacons.length, beaconCount)

            // Another tab cannot undo this instance's observed withdrawal.
            assert.equal(
                await tabB.api.consent({ status: 'accepted_all' }),
                true,
            )
            tabA.storageChanged()
            tabA.api.track({
                type: 'custom',
                event_name: 'after-remote-reacceptance',
            })
            tabA.api.flush()
            assert.deepEqual(tabA.events(), sent)
            assert.equal(beacons.length, beaconCount)
        })
    }
}

for (const changedDecision of ['expired', 'obsolete', 'removed', 'malformed']) {
    test(`shared ${changedDecision} consent invalidates the cached grant even in a permissive region`, async () => {
        const sharedStorage = new Map()
        const page = browser({ sharedStorage, consentExpiresDays: 1 })
        page.resolve({ consent_required: false })
        await settle()
        assert.equal(await page.api.consent({ status: 'accepted_all' }), true)
        const sent = page.events()
        page.api.track({ type: 'custom', event_name: 'queued' })
        if (changedDecision === 'removed') sharedStorage.clear()
        else if (changedDecision === 'malformed')
            sharedStorage.set('capell_insights_consent', '{')
        else
            page.replaceStoredConsent({
                ...page.storedConsent(),
                ...(changedDecision === 'expired'
                    ? {
                          decided_at: new Date(
                              page.now() - 2 * 86400000,
                          ).toISOString(),
                      }
                    : { policy_version: 'obsolete' }),
            })
        page.storageChanged(
            changedDecision === 'removed' ? null : 'capell_insights_consent',
        )
        page.api.track({ type: 'custom', event_name: 'after-change' })
        page.api.flush()
        assert.deepEqual(page.events(), sent)
    })
}

test('remote acceptance still requires this tab to restore its own consent', async () => {
    const sharedStorage = new Map()
    const tabA = browser({ sharedStorage, consentRestorationRequired: true })
    const tabB = browser({ sharedStorage, initialTime: tabA.now() })
    tabA.resolve({ consent_required: true })
    tabB.resolve({ consent_required: true })
    await settle()
    assert.equal(await tabB.api.consent({ status: 'accepted_all' }), true)
    tabA.storageChanged()
    tabA.api.track({ type: 'custom' })
    tabA.api.flush()
    assert.deepEqual(tabA.events(), [])
    assert.equal(
        await tabA.api.restoreConsent({ status: 'accepted_all' }),
        true,
    )
    tabA.api.flush()
    assert.deepEqual(
        tabA.events().map((event) => event.type),
        ['page_view'],
    )
})

test('local rejection blocks synchronously when its storage write fails', async () => {
    const page = browser({
        storageWriteUnavailable: true,
        consentPersistence: 'delayed',
        consent: {
            status: 'accepted_all',
            categories: ['essential', 'insights', 'marketing', 'preferences'],
            policy_version: '1.0',
        },
    })
    page.resolve({ consent_required: false })
    await settle()
    page.api.track({ type: 'custom' })
    const rejection = page.api.consent({ status: 'rejected_non_essential' })
    assert.equal(page.storedConsent().status, 'accepted_all')
    page.storageChanged()
    page.api.track({ type: 'custom' })
    page.api.flush()
    assert.deepEqual(page.events(), [])
    page.rejectConsent()
    assert.equal(await rejection, false)
    page.api.track({ type: 'custom' })
    page.api.flush()
    assert.deepEqual(page.events(), [])
})

test('storage notifications ignore unrelated keys and are removed on disposal', async () => {
    const page = browser()
    page.resolve({ consent_required: true })
    await settle()
    assert.equal(await page.api.consent({ status: 'accepted_all' }), true)
    page.api.track({ type: 'custom', event_name: 'queued' })
    page.storageChanged('unrelated')
    page.api.flush()
    assert.equal(page.events().at(-1).event_name, 'queued')
    assert.equal(page.windowListenerCount('storage'), 1)
    page.api.dispose()
    assert.equal(page.windowListenerCount('storage'), 0)
})

for (const consentPersistence of ['delayed', 'delayed-json']) {
    test(`a two-day ${consentPersistence} acknowledgement cannot restart one-day consent expiry`, async () => {
        const page = browser({ consentExpiresDays: 1, consentPersistence })
        page.resolve({ consent_required: true })
        await settle()
        const submittedAt = page.now()
        const acceptance = page.api.consent({
            status: 'accepted_all',
            terms_accepted: true,
        })
        assert.ok(
            page.requests.some((request) => request.url.endsWith('/consent')),
        )
        await settle()
        page.advanceTime(2 * 86400000)
        if (consentPersistence === 'delayed') page.resolveConsentAt(0)
        else await page.resolveConsentJson()
        assert.equal(await acceptance, true)
        page.api.track({ type: 'custom' })
        page.api.flush()
        await page.runTimers()
        assert.deepEqual(page.events(), [])
        assert.equal(Date.parse(page.storedConsent().decided_at), submittedAt)
        assert.equal(
            await page.api.restoreConsent({ status: 'accepted_all' }),
            false,
        )
    })
}

for (const consentPersistence of ['delayed', 'delayed-json']) {
    for (const notification of [false, true]) {
        for (const withdrawal of ['rejected', 'removed']) {
            test(`a ${consentPersistence} acceptance cannot overwrite shared ${withdrawal} consent with notification ${notification}`, async () => {
                const sharedStorage = new Map()
                const tabA = browser({
                    sharedStorage,
                    consentPersistence,
                    // A removal must change the shared value even without a notification.
                    consent:
                        withdrawal === 'removed'
                            ? {
                                  status: 'rejected_non_essential',
                                  categories: ['essential'],
                                  policy_version: '1.0',
                              }
                            : null,
                })
                const tabB = browser({ sharedStorage, initialTime: tabA.now() })
                tabA.resolve({ consent_required: true })
                tabB.resolve({ consent_required: true })
                await settle()
                const acceptance = tabA.api.consent({ status: 'accepted_all' })
                await settle()
                if (withdrawal === 'removed') sharedStorage.clear()
                else
                    await tabB.api.consent({ status: 'rejected_non_essential' })
                const withdrawn = sharedStorage.get('capell_insights_consent')
                if (notification)
                    tabA.storageChanged(
                        withdrawal === 'removed'
                            ? null
                            : 'capell_insights_consent',
                    )
                if (consentPersistence === 'delayed') tabA.resolveConsentAt(0)
                else await tabA.resolveConsentJson()
                assert.equal(await acceptance, false)
                assert.equal(
                    sharedStorage.get('capell_insights_consent'),
                    withdrawn,
                )
                tabA.api.track({
                    type: 'custom',
                    event_name: 'after-withdrawal',
                })
                tabA.api.flush()
                await tabA.runTimers()
                assert.deepEqual(tabA.events(), [])
                assert.equal(tabA.timerCount(), 0)
            })
        }
    }
}

for (const withdrawal of ['rejected', 'removed', 'cleared']) {
    for (const consentRequired of [false, true]) {
        test(`queued ${withdrawal} notification latches withdrawal after remote reacceptance with required policy ${consentRequired}`, async () => {
            const sharedStorage = new Map()
            const tabA = browser({ sharedStorage })
            const tabB = browser({ sharedStorage, initialTime: tabA.now() })
            tabA.resolve({ consent_required: consentRequired })
            tabB.resolve({ consent_required: consentRequired })
            await settle()
            assert.equal(
                await tabA.api.consent({ status: 'accepted_all' }),
                true,
            )
            const sent = tabA.events()
            tabA.api.track({
                type: 'custom',
                event_name: 'queued-before-withdrawal',
            })
            let withdrawn = null
            if (withdrawal === 'rejected') {
                await tabB.api.consent({ status: 'rejected_non_essential' })
                withdrawn = sharedStorage.get('capell_insights_consent')
            } else sharedStorage.clear()
            await tabB.api.consent({ status: 'accepted_all' })
            tabA.storageChanged(
                withdrawal === 'cleared' ? null : 'capell_insights_consent',
                withdrawn,
            )
            assert.equal(tabA.timerCount(), 0)
            // Delivery of the later acceptance must not restore this tab's grant.
            tabA.storageChanged()
            assert.equal(
                await tabA.api.restoreConsent({ status: 'accepted_all' }),
                false,
            )
            tabA.api.track({ type: 'custom', event_name: 'after-withdrawal' })
            tabA.api.flush()
            await tabA.runTimers()
            assert.deepEqual(tabA.events(), sent)
            // A fresh decision in this instance can grant consent again.
            assert.equal(
                await tabA.api.consent({ status: 'accepted_all' }),
                true,
            )
            tabA.api.track({
                type: 'custom',
                event_name: 'after-local-reacceptance',
            })
            tabA.api.flush()
            assert.equal(
                tabA.events().at(-1).event_name,
                'after-local-reacceptance',
            )
        })
    }
}

for (const consentPersistence of ['delayed', 'delayed-json']) {
    test(`a queued withdrawal invalidates a ${consentPersistence} acceptance even after remote reacceptance`, async () => {
        const sharedStorage = new Map()
        const tabA = browser({ sharedStorage, consentPersistence })
        const tabB = browser({ sharedStorage, initialTime: tabA.now() })
        tabA.resolve({ consent_required: true })
        tabB.resolve({ consent_required: true })
        await settle()
        const acceptance = tabA.api.consent({ status: 'accepted_all' })
        await settle()
        await tabB.api.consent({ status: 'rejected_non_essential' })
        const withdrawn = sharedStorage.get('capell_insights_consent')
        await tabB.api.consent({ status: 'accepted_all' })
        const latest = sharedStorage.get('capell_insights_consent')
        tabA.storageChanged('capell_insights_consent', withdrawn)
        tabA.storageChanged()
        if (consentPersistence === 'delayed') tabA.resolveConsentAt(0)
        else await tabA.resolveConsentJson()
        assert.equal(await acceptance, false)
        assert.equal(sharedStorage.get('capell_insights_consent'), latest)
        tabA.api.track({ type: 'custom' })
        tabA.api.flush()
        assert.deepEqual(tabA.events(), [])
    })
}

for (const notificationAt of [
    'pending-transport',
    'pending-json',
    'after-acknowledgement',
]) {
    test(`an earlier remote withdrawal must not invalidate a fresh local acceptance (${notificationAt})`, async () => {
        const sharedStorage = new Map()
        const tabA = browser({
            sharedStorage,
            consentPersistence:
                notificationAt === 'pending-json' ? 'delayed-json' : 'delayed',
        })
        const tabB = browser({ sharedStorage, initialTime: tabA.now() })
        tabA.resolve({ consent_required: true })
        tabB.resolve({ consent_required: true })
        await settle()
        // The withdrawal's notification remains queued while A observes its value.
        await tabB.api.consent({
            status: 'rejected_non_essential',
            terms_accepted: true,
        })
        const olderWithdrawal = sharedStorage.get('capell_insights_consent')
        tabA.advanceTime(1000)
        const acceptance = tabA.api.consent({
            status: 'accepted_all',
            terms_accepted: true,
        })
        await settle()
        if (notificationAt !== 'after-acknowledgement')
            tabA.storageChanged('capell_insights_consent', olderWithdrawal)
        if (notificationAt === 'pending-json') await tabA.resolveConsentJson()
        else tabA.resolveConsentAt(0)
        const acknowledged = await acceptance
        if (notificationAt === 'after-acknowledgement')
            tabA.storageChanged('capell_insights_consent', olderWithdrawal)
        tabA.api.track({
            type: 'custom',
            event_name: 'after-fresh-local-acceptance',
        })
        tabA.api.flush()
        assert.equal(
            acknowledged,
            true,
            'a newer explicit local acceptance is still current',
        )
        assert.equal(
            tabA.events().at(-1)?.event_name,
            'after-fresh-local-acceptance',
            'the earlier withdrawal must not revoke a later explicit acceptance',
        )
    })
}

for (const remoteReacceptance of [false, true]) {
    test(`a new withdrawal still revokes a fresh local acceptance after an observed withdrawal, remote reacceptance ${remoteReacceptance}`, async () => {
        const sharedStorage = new Map()
        const tabA = browser({ sharedStorage })
        const tabB = browser({ sharedStorage, initialTime: tabA.now() })
        tabA.resolve({ consent_required: true })
        tabB.resolve({ consent_required: true })
        await settle()
        await tabB.api.consent({ status: 'rejected_non_essential' })
        const olderWithdrawal = sharedStorage.get('capell_insights_consent')
        tabA.advanceTime(1000)
        assert.equal(await tabA.api.consent({ status: 'accepted_all' }), true)
        tabA.storageChanged('capell_insights_consent', olderWithdrawal)
        const sent = tabA.events()
        tabA.api.track({
            type: 'custom',
            event_name: 'queued-before-withdrawal',
        })
        assert.equal(tabA.timerCount(), 1)

        // B must make a fresh choice too; its previously acknowledged rejection
        // would otherwise be reused without writing a new withdrawal.
        assert.equal(await tabB.api.consent({ status: 'accepted_all' }), true)
        tabB.advanceTime(2000)
        assert.equal(
            await tabB.api.consent({ status: 'rejected_non_essential' }),
            true,
        )
        const newerWithdrawal = sharedStorage.get('capell_insights_consent')
        assert.notEqual(newerWithdrawal, olderWithdrawal)
        if (remoteReacceptance)
            await tabB.api.consent({ status: 'accepted_all' })
        tabA.advanceTime(1000)
        tabA.storageChanged('capell_insights_consent', newerWithdrawal)
        assert.equal(tabA.timerCount(), 0)
        tabA.api.track({ type: 'custom', event_name: 'after-new-withdrawal' })
        tabA.api.flush()
        await tabA.runTimers()
        assert.deepEqual(tabA.events(), sent)
    })
}

for (const withdrawn of [null, '{']) {
    test(`an identical withdrawal without a decision identity still revokes after remote reacceptance (${withdrawn})`, async () => {
        const sharedStorage = new Map()
        const page = browser({ sharedStorage })
        page.resolve({ consent_required: true })
        await settle()
        assert.equal(await page.api.consent({ status: 'accepted_all' }), true)
        if (withdrawn === null) sharedStorage.clear()
        else sharedStorage.set('capell_insights_consent', withdrawn)
        page.storageChanged('capell_insights_consent', withdrawn)
        assert.equal(await page.api.consent({ status: 'accepted_all' }), true)
        const sent = page.events()
        page.api.track({
            type: 'custom',
            event_name: 'queued-before-withdrawal',
        })
        assert.equal(page.timerCount(), 1)
        // Another removal or malformed write followed by remote reacceptance
        // leaves an indistinguishable withdrawal notification in the task queue.
        page.storageChanged('capell_insights_consent', withdrawn)
        assert.equal(page.timerCount(), 0)
        page.api.track({ type: 'custom', event_name: 'after-withdrawal' })
        page.api.flush()
        await page.runTimers()
        assert.deepEqual(page.events(), sent)
    })
}

test('a withdrawal observed on the initial storage read predates a fresh local acceptance', async () => {
    const page = browser({
        consent: {
            status: 'rejected_non_essential',
            categories: ['essential'],
            policy_version: '1.0',
        },
    })
    const withdrawn = JSON.stringify(page.storedConsent())
    page.resolve({ consent_required: true })
    await settle()
    page.advanceTime(1000)
    assert.equal(await page.api.consent({ status: 'accepted_all' }), true)
    page.storageChanged('capell_insights_consent', withdrawn)
    page.api.track({
        type: 'custom',
        event_name: 'after-fresh-local-acceptance',
    })
    page.api.flush()
    assert.equal(
        page.events().at(-1)?.event_name,
        'after-fresh-local-acceptance',
    )
})

for (const initialDecision of ['missing', 'rejected']) {
    test(`acknowledged acceptance tracks after a failed storage write with unchanged ${initialDecision} storage`, async () => {
        const page = browser({
            storageWriteUnavailable: true,
            consent:
                initialDecision === 'rejected'
                    ? {
                          status: 'rejected_non_essential',
                          categories: ['essential'],
                          policy_version: '1.0',
                      }
                    : null,
        })
        page.resolve({ consent_required: true })
        await settle()
        const initialSharedDecision = page.storedConsent()
        assert.equal(await page.api.consent({ status: 'accepted_all' }), true)
        assert.deepEqual(page.storedConsent(), initialSharedDecision)
        assert.deepEqual(
            page.events().map((event) => event.type),
            ['page_view'],
        )
        page.api.track({ type: 'custom', event_name: 'after-acceptance' })
        page.api.flush()
        assert.equal(page.events().at(-1).event_name, 'after-acceptance')
        const sent = page.events()
        page.api.track({
            type: 'custom',
            event_name: 'queued-before-shared-change',
        })
        page.replaceStoredConsent({
            status: 'rejected_non_essential',
            categories: ['essential'],
            policy_version: '1.0',
            decided_at: new Date(page.now() - 1).toISOString(),
        })
        page.api.track({ type: 'custom', event_name: 'after-shared-change' })
        page.api.flush()
        assert.deepEqual(page.events(), sent)
        assert.equal(page.timerCount(), 0)
    })
}
