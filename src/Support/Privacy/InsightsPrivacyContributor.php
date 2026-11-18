<?php

declare(strict_types=1);

namespace Capell\Insights\Support\Privacy;

use Capell\Core\Contracts\Privacy\PrivacySubjectEraser;
use Capell\Core\Contracts\Privacy\PrivacySubjectExporter;
use Capell\Core\Data\Privacy\PrivacyErasureReceiptData;
use Capell\Core\Data\Privacy\PrivacyExportSectionData;
use Capell\Core\Data\Privacy\PrivacySubject;
use Capell\Core\Enums\PrivacyErasureMode;
use Capell\Insights\Actions\AnonymizeInsightsVisitAction;
use Capell\Insights\Actions\BuildInsightsVisitPrivacyExportAction;
use Capell\Insights\Models\InsightsVisit;
use Override;

/**
 * Exports a visit's consents and events and anonymises them in place: the keyed hashes, the legacy session id and the
 * visit's URLs are cleared and the rows are kept.
 */
final class InsightsPrivacyContributor implements PrivacySubjectEraser, PrivacySubjectExporter
{
    public const string KEY = 'insights';

    #[Override]
    public function contributor(): string
    {
        return self::KEY;
    }

    #[Override]
    public function supports(PrivacySubject $subject): bool
    {
        return $subject->model instanceof InsightsVisit;
    }

    #[Override]
    public function export(PrivacySubject $subject): PrivacyExportSectionData
    {
        return new PrivacyExportSectionData($subject->model instanceof InsightsVisit ? BuildInsightsVisitPrivacyExportAction::run($subject->model) : []);
    }

    #[Override]
    public function erase(PrivacySubject $subject, PrivacyErasureMode $mode): PrivacyErasureReceiptData
    {
        // Records are kept with their personal fields cleared, whichever mode is asked for.
        $affectedRecords = 0;

        if ($subject->model instanceof InsightsVisit) {
            AnonymizeInsightsVisitAction::run($subject->model);
            $affectedRecords = 1;
        }

        return new PrivacyErasureReceiptData(self::KEY, PrivacyErasureMode::Anonymize, $affectedRecords);
    }

    #[Override]
    public function retainsSuppressionMarker(): bool
    {
        return false;
    }
}
