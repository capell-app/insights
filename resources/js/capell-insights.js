;(function initializeTracker() {
    'use strict'

    if (typeof window.CapellInsights?.dispose === 'function') {
        window.CapellInsights.dispose()
        delete window.CapellInsights
    }

    var configElement = document.querySelector('[data-capell-insights-tracker]')

    if (!configElement) {
        return
    }

    var config = {}

    try {
        config = JSON.parse(configElement.textContent || '{}')
    } catch (error) {
        return
    }

    function currentOriginUrl(url) {
        return new URL(url, window.location.origin).toString()
    }

    config.eventsUrl = currentOriginUrl(config.eventsUrl)
    config.consentUrl = currentOriginUrl(config.consentUrl)
    config.consentRequired = true

    var defaultIgnoredSelectors = ['[data-capell-insights-ignore]']
    var sequence = 0
    var eventQueue = []
    var flushTimer = null
    var maxBatchSize = 25
    var visitStorageKey = 'capell_insights_visit_id'
    var visitCookieName = 'capell_insights_visit'
    var consentStorageKey = 'capell_insights_consent'
    var consentBannerSelector = '[data-capell-insights-consent-banner]'
    var policyResolved = false
    var initialPageViewQueued = false
    var acknowledgedConsent = null
    var observedSharedConsent
    var observedSharedWithdrawals = new Set()
    var sharedWithdrawalRevision = 0
    var consentRequestSequence = 0
    var consentRequestPending = false
    // Keep the write queue across inline tracker replacements. Disposing a
    // client invalidates its acknowledgement, not an in-flight server write.
    var consentCoordinatorKey = Symbol.for('capell.insights.consentWrites')
    var consentWrites = window[consentCoordinatorKey]
    if (!consentWrites) {
        consentWrites = { tail: null }
        window[consentCoordinatorKey] = consentWrites
    }
    var restoredConsentReady = config.consentRestorationRequired !== true
    var disposed = false
    var cleanups = []

    function listen(target, name, callback, options) {
        target.addEventListener(name, callback, options)
        cleanups.push(function () {
            target.removeEventListener(name, callback, options)
        })
    }

    function dispose() {
        cleanups.splice(0).forEach(function (cleanup) {
            cleanup()
        })
        if (disposed) return
        disposed = true
        restoredConsentReady = false
        consentRequestSequence += 1
        discardQueuedEvents()
        clearBannerHeight()
    }

    function privacySignalEnabled() {
        if (!config.honorPrivacySignals) {
            return false
        }

        return (
            navigator.globalPrivacyControl === true ||
            navigator.doNotTrack === '1' ||
            window.doNotTrack === '1' ||
            navigator.msDoNotTrack === '1'
        )
    }

    if (privacySignalEnabled()) {
        return
    }

    function currentVisitId() {
        var storedVisitId = null

        try {
            storedVisitId = window.localStorage.getItem(visitStorageKey)
        } catch (error) {
            storedVisitId = null
        }

        return storedVisitId || currentVisitCookie()
    }

    function currentVisitCookie() {
        var cookiePrefix = visitCookieName + '='
        var cookies = document.cookie ? document.cookie.split(';') : []
        var matchingCookie = cookies.find(function (cookie) {
            return cookie.trim().indexOf(cookiePrefix) === 0
        })

        if (!matchingCookie) {
            return null
        }

        return decodeURIComponent(
            matchingCookie.trim().slice(cookiePrefix.length),
        )
    }

    function storeVisitId(visitId) {
        if (!visitId) {
            return
        }

        try {
            window.localStorage.setItem(visitStorageKey, visitId)
        } catch (error) {
            // Storage may be unavailable in private browsing or strict environments.
        }
    }

    function parseConsentDecision(storedConsent) {
        if (storedConsent) {
            try {
                return JSON.parse(storedConsent) || {}
            } catch (error) {
                return {}
            }
        }
        return null
    }

    function sharedConsentWithdrawn(decision) {
        return (
            !validConsentDecision(decision) ||
            !consentIncludesInsights(decision.categories)
        )
    }

    function rememberSharedWithdrawal(decision, storedConsent) {
        // Removals and malformed values have no decision identity; a later
        // identical value may be a new withdrawal and must remain conservative.
        if (
            typeof decision?.decided_at === 'string' &&
            Number.isFinite(Date.parse(decision.decided_at))
        ) {
            observedSharedWithdrawals.add(storedConsent)
        }
    }

    function withdrawSharedConsent(decision, storedConsent) {
        rememberSharedWithdrawal(decision, storedConsent)
        sharedWithdrawalRevision += 1
        // Keep a withdrawal latched even if another tab subsequently accepts.
        acknowledgedConsent =
            decision && !consentIncludesInsights(decision.categories)
                ? decision
                : {}
        discardQueuedEvents()
    }

    function readConsentDecision() {
        var storedConsent = null

        try {
            storedConsent = window.localStorage.getItem(consentStorageKey)
        } catch (error) {
            return acknowledgedConsent
        }

        var sharedDecision = parseConsentDecision(storedConsent)
        var sharedChanged =
            observedSharedConsent !== undefined &&
            storedConsent !== observedSharedConsent
        observedSharedConsent = storedConsent
        if (sharedConsentWithdrawn(sharedDecision)) {
            rememberSharedWithdrawal(sharedDecision, storedConsent)
            if (sharedChanged)
                withdrawSharedConsent(sharedDecision, storedConsent)
        }

        // A local rejection remains authoritative even if its storage write
        // failed. An unchanged shared value also preserves an acknowledged
        // grant whose write failed; only a shared change can withdraw it.
        if (
            acknowledgedConsent &&
            consentIncludesInsights(acknowledgedConsent.categories) &&
            !validConsentDecision(acknowledgedConsent)
        ) {
            acknowledgedConsent = {}
            discardQueuedEvents()
        }

        return acknowledgedConsent || sharedDecision
    }

    function currentConsentDecision() {
        var decision = readConsentDecision()
        return validConsentDecision(decision) ? decision : null
    }

    function validConsentDecision(decision) {
        if (
            !decision ||
            decision.policy_version !== config.policyVersion ||
            typeof decision.decided_at !== 'string'
        )
            return false
        var decidedAt = Date.parse(decision.decided_at)
        var age = Date.now() - decidedAt
        if (!Number.isFinite(decidedAt) || age < 0) return false
        var expiresDays = config.consentExpiresDays
        return (
            expiresDays === null ||
            (Number.isInteger(expiresDays) &&
                expiresDays >= 1 &&
                age <= expiresDays * 86400000)
        )
    }

    function trackingAllowed() {
        if (disposed || !policyResolved || !restoredConsentReady) {
            return false
        }

        var consentDecision = readConsentDecision()

        // An expired or obsolete choice still needs a fresh decision, even
        // when the current region would otherwise allow anonymous tracking.
        var allowed = consentDecision
            ? validConsentDecision(consentDecision) &&
              consentIncludesInsights(consentDecision.categories)
            : !config.consentRequired
        if (!allowed) discardQueuedEvents()
        return allowed
    }

    function applyConsentDecision(status, categories, decidedAt) {
        acknowledgedConsent = {
            status: status,
            categories: categories || [],
            policy_version: config.policyVersion,
            decided_at: decidedAt,
        }
    }

    function storeConsentDecision(status, categories, decidedAt) {
        applyConsentDecision(status, categories, decidedAt)

        try {
            var storedConsent = JSON.stringify(acknowledgedConsent)
            window.localStorage.setItem(consentStorageKey, storedConsent)
            observedSharedConsent = storedConsent
        } catch (error) {
            // Storage may be unavailable in private browsing or strict environments.
        }
    }

    function discardQueuedEvents() {
        if (flushTimer) {
            window.clearTimeout(flushTimer)
            flushTimer = null
        }

        eventQueue = []
    }

    function consentIncludesInsights(categories) {
        return (
            Array.isArray(categories) && categories.indexOf('insights') !== -1
        )
    }

    function sendJson(url, payload, handleResponse, forceFetch) {
        var json = JSON.stringify(payload)

        if (!forceFetch && navigator.sendBeacon) {
            var blob = new Blob([json], { type: 'application/json' })

            if (navigator.sendBeacon(url, blob)) {
                return
            }
        }

        fetch(url, {
            method: 'POST',
            body: json,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            keepalive: true,
        })
            .then(function (response) {
                var contentType = response.headers.get('content-type') || ''

                if (
                    !disposed &&
                    handleResponse &&
                    response.ok &&
                    contentType.indexOf('application/json') !== -1
                ) {
                    response
                        .json()
                        .then(function (response) {
                            if (!disposed) handleResponse(response)
                        })
                        .catch(function () {})
                }
            })
            .catch(function () {})
    }

    function consentPayloadForAction(action, banner) {
        if (action === 'accept') {
            return {
                status: 'accepted_all',
                terms_accepted: true,
            }
        }

        if (action === 'reject') {
            return {
                status: 'rejected_non_essential',
                terms_accepted: true,
            }
        }

        var categories = {}
        var checkboxes = banner.querySelectorAll(
            '[data-capell-insights-consent-category]',
        )

        checkboxes.forEach(function (checkbox) {
            categories[
                checkbox.getAttribute('data-capell-insights-consent-category')
            ] = checkbox.checked === true
        })

        return {
            status: 'granular',
            terms_accepted: true,
            categories: categories,
        }
    }

    function matchingConsentDecision(decision, payload) {
        if (
            !decision ||
            decision.status !== payload.status ||
            !Array.isArray(decision.categories)
        ) {
            return false
        }

        var expected = ['essential']
        var optional = ['insights', 'marketing', 'preferences']
        optional.forEach(function (category) {
            if (
                payload.status === 'accepted_all' ||
                (payload.status === 'granular' &&
                    payload.categories &&
                    payload.categories[category] === true)
            ) {
                expected.push(category)
            }
        })

        return (
            JSON.stringify(decision.categories.slice().sort()) ===
            JSON.stringify(expected.sort())
        )
    }

    function submitConsent(payload, afterConsent) {
        if (disposed) return Promise.resolve(false)
        // Include time waiting for earlier writes, transport and JSON parsing
        // in the lifetime of this choice; acknowledgement never renews it.
        var decidedAt = new Date().toISOString()
        var status = payload.status
        var currentDecision = currentConsentDecision()
        var withdrawalRevision = sharedWithdrawalRevision
        var acceptsInsights =
            status === 'accepted_all' ||
            (status === 'granular' && payload.categories?.insights === true)
        var canReuseDecision =
            !consentRequestPending &&
            !consentWrites.tail &&
            matchingConsentDecision(currentDecision, payload)
        var requestSequence = ++consentRequestSequence
        var consentJson = JSON.stringify(
            Object.assign({ policy_version: config.policyVersion }, payload),
        )

        if (
            status === 'rejected_non_essential' ||
            (status === 'granular' && payload.categories?.insights !== true)
        ) {
            if (!canReuseDecision) {
                restoredConsentReady = false
                storeConsentDecision(status, [], decidedAt)
            }
            discardQueuedEvents()
        }

        if (canReuseDecision) {
            restoredConsentReady = true
            trackPageView()
            flushEvents()
            if (afterConsent) {
                afterConsent({
                    visit_id: currentVisitId(),
                    enabled_categories: currentDecision.categories,
                })
            }
            return Promise.resolve(true)
        }

        consentRequestPending = true

        function requestStillCurrent() {
            // Reconcile both before reading JSON and after it resolves. A
            // notification may latch withdrawal while either stage is pending.
            readConsentDecision()
            return (
                requestSequence === consentRequestSequence &&
                (!acceptsInsights ||
                    withdrawalRevision === sharedWithdrawalRevision)
            )
        }

        // Persist choices in invocation order. A newer rejection takes effect
        // locally immediately and must also be the last decision at the server.
        var performRequest = function () {
            if (disposed) return Promise.resolve(false)
            return fetch(config.consentUrl, {
                method: 'POST',
                body: consentJson,
                headers: { 'Content-Type': 'application/json' },
                keepalive: true,
            })
                .then(function (response) {
                    if (!response.ok || !requestStillCurrent()) {
                        return false
                    }

                    return response.json().then(function (response) {
                        // A later choice remains authoritative even when an earlier
                        // request or JSON body finishes afterwards.
                        if (
                            !requestStillCurrent() ||
                            !response ||
                            typeof response.visit_id !== 'string' ||
                            response.visit_id === '' ||
                            !matchingConsentDecision(
                                {
                                    status: status,
                                    categories: response.enabled_categories,
                                },
                                payload,
                            )
                        ) {
                            return false
                        }

                        restoredConsentReady = true
                        storeVisitId(response.visit_id)
                        storeConsentDecision(
                            status,
                            response.enabled_categories,
                            decidedAt,
                        )
                        trackPageView()
                        flushEvents()

                        if (afterConsent) {
                            afterConsent(response)
                        }

                        return true
                    })
                })
                .catch(function () {
                    return false
                })
        }
        var request = consentWrites.tail
            ? consentWrites.tail.then(performRequest)
            : performRequest()
        consentWrites.tail = request
        return request.finally(function () {
            if (consentWrites.tail === request) consentWrites.tail = null
            if (requestSequence === consentRequestSequence) {
                consentRequestPending = false
            }
        })
    }

    function publishBannerHeight(banner) {
        // Expose the banner height so the theme can reserve space and keep the
        // footer from being covered by the fixed banner.
        document.documentElement.style.setProperty(
            '--capell-insights-banner-height',
            banner.offsetHeight + 'px',
        )
    }

    function clearBannerHeight() {
        document.documentElement.style.setProperty(
            '--capell-insights-banner-height',
            '0px',
        )
    }

    function reconcileConsentBanner() {
        var banner = document.querySelector(consentBannerSelector)

        if (
            !banner ||
            !policyResolved ||
            config.consentRequired ||
            readConsentDecision()
        ) {
            return
        }

        banner.hidden = true
        clearBannerHeight()
    }

    function initializeConsentBanner() {
        var banner = document.querySelector(consentBannerSelector)

        if (!banner) {
            return
        }

        if (
            currentConsentDecision() ||
            (policyResolved &&
                !config.consentRequired &&
                !readConsentDecision())
        ) {
            banner.hidden = true
            clearBannerHeight()

            return
        }

        var choices = banner.querySelector(
            '[data-capell-insights-consent-choices]',
        )

        banner.hidden = false
        publishBannerHeight(banner)

        if (typeof ResizeObserver === 'function') {
            var observer = new ResizeObserver(function () {
                if (!disposed && !banner.hidden) {
                    publishBannerHeight(banner)
                }
            })
            observer.observe(banner)
            cleanups.push(function () {
                observer.disconnect()
            })
        } else {
            listen(window, 'resize', function () {
                if (!disposed && !banner.hidden) {
                    publishBannerHeight(banner)
                }
            })
        }

        listen(banner, 'click', function (event) {
            if (!event.target || !event.target.closest) {
                return
            }

            var button = event.target.closest(
                '[data-capell-insights-consent-action]',
            )

            if (!button || !banner.contains(button)) {
                return
            }

            var action = button.getAttribute(
                'data-capell-insights-consent-action',
            )

            if (action === 'manage') {
                if (choices) {
                    choices.hidden = !choices.hidden
                    button.setAttribute(
                        'aria-expanded',
                        String(!choices.hidden),
                    )
                    publishBannerHeight(banner)
                }

                return
            }

            submitConsent(consentPayloadForAction(action, banner), function () {
                banner.hidden = true
                clearBannerHeight()
            })
        })
    }

    function flushEvents() {
        if (flushTimer) {
            window.clearTimeout(flushTimer)
            flushTimer = null
        }

        if (!trackingAllowed()) {
            discardQueuedEvents()
            return
        }

        if (!eventQueue.length) {
            return
        }

        var events = eventQueue.splice(0, maxBatchSize)

        var visitId = currentVisitId()

        sendJson(
            config.eventsUrl,
            {
                visit_id: visitId,
                events: events,
            },
            function (response) {
                storeVisitId(response.visit_id)
            },
            !visitId,
        )

        if (eventQueue.length) {
            scheduleFlush(0)
        }
    }

    function scheduleFlush(delay) {
        if (flushTimer) {
            return
        }

        flushTimer = window.setTimeout(flushEvents, delay)
    }

    function queueEvent(eventPayload) {
        if (!trackingAllowed()) {
            return
        }

        sequence += 1

        eventQueue.push(
            Object.assign(
                {
                    url: window.location.href,
                    title: document.title,
                    occurred_at: new Date().toISOString(),
                    sequence: sequence,
                },
                eventPayload,
            ),
        )

        if (eventQueue.length >= maxBatchSize) {
            flushEvents()
            return
        }

        scheduleFlush(150)
    }

    function trackedElementFromTarget(target) {
        if (!target) {
            return null
        }

        if (target.closest) {
            return target
        }

        return target.parentElement || null
    }

    function ignoredBySelector(element) {
        if (!element) {
            return false
        }

        var ignoredSelectors = defaultIgnoredSelectors.concat(
            Array.isArray(config.ignoredSelectors)
                ? config.ignoredSelectors
                : [],
        )

        return ignoredSelectors.some(function (selector) {
            try {
                return (
                    element.matches(selector) ||
                    Boolean(element.closest(selector))
                )
            } catch (error) {
                return false
            }
        })
    }

    function nearestLandmark(element) {
        var landmark = element.closest(
            'main, nav, header, footer, aside, section, article, [role]',
        )

        if (!landmark) {
            return null
        }

        return (
            landmark.getAttribute('aria-label') ||
            landmark.getAttribute('role') ||
            landmark.tagName.toLowerCase()
        )
    }

    function selectorFor(element) {
        var selector = element.tagName.toLowerCase()
        var trackingElement = element.closest('[data-capell-insights]')

        if (trackingElement === element) {
            selector += '[data-capell-insights]'
        }

        return selector
    }

    function explicitTrackingElement(element) {
        return element.closest('[data-capell-insights]')
    }

    function automaticTrackingElement(element) {
        if (!config.automaticClickTracking) {
            return null
        }

        return element.closest(
            'a[href], button, input[type="submit"], button[type="submit"]',
        )
    }

    function clickName(element) {
        var explicitName = element.getAttribute('data-capell-insights')

        if (explicitName) {
            return explicitName
        }

        if (element.matches('a[href]')) {
            return 'link_click'
        }

        if (element.matches('input[type="submit"], button[type="submit"]')) {
            return 'form_submit'
        }

        return 'button_click'
    }

    function clickLabel(element) {
        return (
            element.getAttribute('data-capell-insights-label') ||
            element.getAttribute('aria-label') ||
            element.textContent.trim().replace(/\s+/g, ' ').slice(0, 255) ||
            null
        )
    }

    function trackClick(event) {
        var clickedElement = trackedElementFromTarget(event.target)

        if (
            !config.trackClicks ||
            !trackingAllowed() ||
            ignoredBySelector(clickedElement)
        ) {
            return
        }

        var trackingElement =
            explicitTrackingElement(clickedElement) ||
            automaticTrackingElement(clickedElement)

        if (!trackingElement || ignoredBySelector(trackingElement)) {
            return
        }

        queueEvent({
            type: 'click',
            event_name: clickName(trackingElement),
            label: clickLabel(trackingElement),
            location: trackingElement.getAttribute(
                'data-capell-insights-location',
            ),
            target_selector: selectorFor(trackingElement),
            viewport_x: Math.round(event.clientX),
            viewport_y: Math.round(event.clientY),
            document_x: Math.round(event.pageX),
            document_y: Math.round(event.pageY),
            metadata: {
                nearest_landmark: nearestLandmark(trackingElement),
            },
        })
    }

    function trackPageView() {
        if (
            initialPageViewQueued ||
            !config.trackPageViews ||
            !trackingAllowed() ||
            ignoredBySelector(document.body)
        ) {
            return
        }

        initialPageViewQueued = true
        queueEvent({ type: 'page_view' })
    }

    function resolveConsentPolicy() {
        var completed = false
        var controller = new AbortController()
        var timeout = window.setTimeout(function () {
            complete(null)
            controller.abort()
        }, 5000)

        cleanups.push(function () {
            completed = true
            window.clearTimeout(timeout)
            controller.abort()
        })

        function complete(policy) {
            if (disposed || completed) {
                return
            }

            completed = true
            window.clearTimeout(timeout)
            config.consentRequired =
                !policy || policy.consent_required !== false
            policyResolved = true
            reconcileConsentBanner()
            trackPageView()
        }

        if (!config.consentPolicyUrl) {
            complete(null)
            return
        }

        fetch(currentOriginUrl(config.consentPolicyUrl), {
            method: 'GET',
            credentials: 'omit',
            cache: 'no-store',
            signal: controller.signal,
            headers: { Accept: 'application/json' },
        })
            .then(function (response) {
                return response.ok ? response.json() : null
            })
            .then(function (policy) {
                if (!policy || typeof policy.consent_required !== 'boolean') {
                    complete(null)
                    return
                }

                complete(policy)
            })
            .catch(function () {
                complete(null)
            })
    }

    var api = {
        dispose: dispose,
        // Restoration is read-only: an earlier choice never accepts a new
        // policy or creates another consent record without a fresh decision.
        restoreConsent: function (payload) {
            var restored =
                !disposed &&
                !consentRequestPending &&
                !consentWrites.tail &&
                matchingConsentDecision(currentConsentDecision(), payload)
            restoredConsentReady = restored
            if (!restored) discardQueuedEvents()
            else trackPageView()
            return Promise.resolve(restored)
        },
        consent: function (payload) {
            return submitConsent(payload)
        },
        track: queueEvent,
        flush: flushEvents,
    }

    window.CapellInsights = api
    listen(document, 'livewire:navigating', dispose)
    listen(window, 'storage', function (event) {
        if (
            (event.key === consentStorageKey || event.key === null) &&
            event.storageArea === window.localStorage
        ) {
            var sharedDecision = parseConsentDecision(event.newValue)
            if (
                (event.key === null ||
                    sharedConsentWithdrawn(sharedDecision)) &&
                !observedSharedWithdrawals.has(event.newValue)
            ) {
                // Repeated notifications for reconciled withdrawals predate any
                // fresh local choice. The stored value includes decided_at, so
                // a new withdrawal remains distinct even with the same choices.
                // The notification can precede a newer remote acceptance in
                // the event queue; its withdrawal must not be lost on reread.
                withdrawSharedConsent(sharedDecision, event.newValue)
            }
            readConsentDecision()
            if (!trackingAllowed()) discardQueuedEvents()
        }
    })
    listen(document, 'click', trackClick, true)
    listen(window, 'pagehide', function () {
        flushEvents()
        dispose()
        // A cached document resumes without executing its inline scripts again.
        listen(
            window,
            'pageshow',
            function (event) {
                if (event.persisted) initializeTracker()
            },
            { once: true },
        )
    })
    listen(document, 'visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            flushEvents()
        }
    })

    if (document.readyState === 'loading') {
        listen(document, 'DOMContentLoaded', initializeConsentBanner, {
            once: true,
        })
        listen(document, 'DOMContentLoaded', trackPageView, {
            once: true,
        })
    } else {
        initializeConsentBanner()
        trackPageView()
    }

    resolveConsentPolicy()
})()
