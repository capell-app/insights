;(function () {
    'use strict'

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

    var defaultIgnoredSelectors = ['[data-capell-insights-ignore]']
    var sequence = 0
    var eventQueue = []
    var flushTimer = null
    var maxBatchSize = 25
    var visitStorageKey = 'capell_insights_visit_id'
    var visitCookieName = 'capell_insights_visit'
    var consentStorageKey = 'capell_insights_consent'
    var consentBannerSelector = '[data-capell-insights-consent-banner]'

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

    function currentConsentDecision() {
        var storedConsent = null

        try {
            storedConsent = window.localStorage.getItem(consentStorageKey)
        } catch (error) {
            storedConsent = null
        }

        if (!storedConsent) {
            return null
        }

        try {
            var consentDecision = JSON.parse(storedConsent)

            if (consentDecision.policy_version !== config.policyVersion) {
                return null
            }

            return consentDecision
        } catch (error) {
            return null
        }
    }

    function trackingAllowed() {
        // Regions that don't require prior consent keep the opt-out default.
        if (!config.consentRequired) {
            return true
        }

        // In consent-required regions (e.g. UK/EU) nothing is tracked until the
        // visitor has actively granted the analytics category.
        var consentDecision = currentConsentDecision()

        return Boolean(
            consentDecision &&
            consentIncludesInsights(consentDecision.categories),
        )
    }

    function storeConsentDecision(status, categories) {
        try {
            window.localStorage.setItem(
                consentStorageKey,
                JSON.stringify({
                    status: status,
                    categories: categories || [],
                    policy_version: config.policyVersion,
                    decided_at: new Date().toISOString(),
                }),
            )
        } catch (error) {
            // Storage may be unavailable in private browsing or strict environments.
        }
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
                    handleResponse &&
                    response.ok &&
                    contentType.indexOf('application/json') !== -1
                ) {
                    response
                        .json()
                        .then(handleResponse)
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

    function submitConsent(payload, afterConsent) {
        var status = payload.status
        var consentJson = JSON.stringify(
            Object.assign({ policy_version: config.policyVersion }, payload),
        )

        fetch(config.consentUrl, {
            method: 'POST',
            body: consentJson,
            headers: { 'Content-Type': 'application/json' },
            keepalive: true,
        })
            .then(function (response) {
                if (!response.ok) {
                    return
                }

                response
                    .json()
                    .then(function (response) {
                        storeVisitId(response.visit_id)
                        storeConsentDecision(
                            status,
                            response.enabled_categories,
                        )

                        if (afterConsent) {
                            afterConsent(response)
                        }
                    })
                    .catch(function () {})
            })
            .catch(function () {})
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

    function initializeConsentBanner() {
        var banner = document.querySelector(consentBannerSelector)

        if (!banner || currentConsentDecision()) {
            return
        }

        var choices = banner.querySelector(
            '[data-capell-insights-consent-choices]',
        )

        banner.hidden = false
        publishBannerHeight(banner)

        if (typeof ResizeObserver === 'function') {
            new ResizeObserver(function () {
                if (!banner.hidden) {
                    publishBannerHeight(banner)
                }
            }).observe(banner)
        } else {
            window.addEventListener('resize', function () {
                if (!banner.hidden) {
                    publishBannerHeight(banner)
                }
            })
        }

        banner.addEventListener('click', function (event) {
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

            var hadVisitId = Boolean(currentVisitId())

            submitConsent(consentPayloadForAction(action, banner), function () {
                var consentDecision = currentConsentDecision()

                if (
                    !hadVisitId &&
                    consentDecision &&
                    consentIncludesInsights(consentDecision.categories)
                ) {
                    queueEvent({ type: 'page_view' })
                    flushEvents()
                }

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
            !config.trackPageViews ||
            !trackingAllowed() ||
            ignoredBySelector(document.body)
        ) {
            return
        }

        queueEvent({ type: 'page_view' })
    }

    window.CapellInsights = {
        consent: function (payload) {
            submitConsent(payload)
        },
        track: queueEvent,
        flush: flushEvents,
    }

    document.addEventListener('click', trackClick, true)
    window.addEventListener('pagehide', flushEvents)
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            flushEvents()
        }
    })

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeConsentBanner, {
            once: true,
        })
        document.addEventListener('DOMContentLoaded', trackPageView, {
            once: true,
        })
    } else {
        initializeConsentBanner()
        trackPageView()
    }
})()
