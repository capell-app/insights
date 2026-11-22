<?php

declare(strict_types=1);

return [
    'advanced' => 'Erweitert',
    'collection' => 'Erfassung',
    'consent_retention' => 'Einwilligung und Aufbewahrung',
    'developer' => 'Entwickler: Hashing und Ausschlüsse',
    'recommended_privacy' => 'Empfohlen: Verlangen Sie in jeder Region eine Einwilligung und lassen Sie das Hashing von Besucherdaten aktiviert. Bestehende Einstellungen bleiben erhalten, bis Sie speichern.',
    'automatic_click_tracking' => 'Automatische Klickerfassung',
    'default_consent_region' => 'Standardregion für Einwilligungen',
    'default_consent_region_helper' => 'Ausweichregion für Einwilligungen, die in den Insights-Einstellungen gespeichert wird und verwendet wird, wenn die automatische Regionserkennung nicht verfügbar ist. Lassen Sie das Feld leer, um capell-insights.default_consent_region zu verwenden. Ist auch dieser Konfigurationswert nicht gesetzt, ist die Ausweichregion unbekannt.',
    'event_types' => [
        'page_view' => 'Seitenaufruf',
        'click' => 'Klick',
        'form' => 'Formularübermittlung',
        'custom' => 'Benutzerdefiniertes Ereignis',
        'consent' => 'Einwilligung',
    ],
    'enabled' => 'Insights aktivieren',
    'fieldset' => 'Insights',
    'hash_visitor_data' => 'Besucherdaten hashen',
    'hash_visitor_data_helper' => 'Besucherkennungen werden nur als Einweg-Hashes gespeichert. Ist diese Option deaktiviert, wird überhaupt keine Besucherkennung gespeichert. Die Änderung betrifft nur Besuche und Einwilligungen, die nach dem Speichern erfasst werden.',
    'ignored_paths' => 'Ignorierte Pfade',
    'ignored_paths_helper' => 'Ein Pfadmuster pro Zeile, zum Beispiel /account*. Passende Seiten laden keinen Tracker und erfassen weder Besuche noch Ereignisse.',
    'ignored_selectors' => 'Ignorierte Selektoren',
    'ignored_selectors_helper' => 'Ein CSS-Selektor pro Zeile. Klicks auf passende Elemente oder innerhalb davon werden nicht erfasst.',
    'policy_version' => 'Richtlinienversion',
    'policy_version_helper' => 'Ändern Sie diesen Wert, wenn sich Ihre Einwilligungsrichtlinie ändert. Besucher, die einer früheren Version zugestimmt haben, werden erneut gefragt, bevor etwas erfasst wird.',
    'require_consent_for_all_regions' => 'Einwilligung für alle Regionen verlangen',
    'retention_days' => 'Aufbewahrungsdauer',
    'retention_days_helper' => 'Die monatliche Bereinigung entfernt Ereignisse, Einwilligungen und Zusammenfassungen, die älter als diese Aufbewahrungsdauer sind, sowie inaktive Besuche ohne verbleibende Ereignisse.',
    'track_clicks' => 'Klicks erfassen',
    'track_page_views' => 'Seitenaufrufe erfassen',
];
