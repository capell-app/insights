<?php

declare(strict_types=1);

namespace Capell\Insights\Http\Controllers;

use Capell\Insights\Actions\IngestInsightsBeaconAction;
use Capell\Insights\Actions\ValidateInsightsBeaconRequestAction;
use Capell\Insights\Data\InsightsBeaconData;
use Capell\Insights\Enums\InsightsEventType;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;

class InsightsBeaconController
{
    public function __invoke(Request $request): JsonResponse|HttpResponse
    {
        if (ValidateInsightsBeaconRequestAction::run($request) === false) {
            return response()->noContent();
        }

        $visitUuid = IngestInsightsBeaconAction::run(
            InsightsBeaconData::fromValidated($request->validate($this->rules())),
            $request,
        );

        if ($visitUuid !== null) {
            return response()->json([
                'visit_id' => $visitUuid,
            ]);
        }

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'visit_id' => ['nullable', 'string', 'max:80'],
            'events' => ['required', 'array', 'max:25'],
            'events.*.type' => ['required', Rule::enum(InsightsEventType::class)],
            'events.*.url' => ['required', 'url', 'max:512', $this->pathMaxRule()],
            'events.*.title' => ['nullable', 'string', 'max:255'],
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.event_name' => ['nullable', 'string', 'max:100'],
            'events.*.label' => ['nullable', 'string', 'max:255'],
            'events.*.location' => ['nullable', 'string', 'max:255'],
            'events.*.target_selector' => ['nullable', 'string', 'max:500'],
            'events.*.viewport_x' => ['nullable', 'integer'],
            'events.*.viewport_y' => ['nullable', 'integer'],
            'events.*.document_x' => ['nullable', 'integer'],
            'events.*.document_y' => ['nullable', 'integer'],
            'events.*.metadata' => ['nullable', 'array:nearest_landmark,source_package,conversion_value,conversion_currency'],
            'events.*.metadata.nearest_landmark' => ['nullable', 'string', 'max:255'],
            'events.*.metadata.source_package' => ['nullable', 'string', 'max:120'],
            'events.*.metadata.conversion_value' => ['nullable', 'numeric'],
            'events.*.metadata.conversion_currency' => ['nullable', 'string', 'size:3'],
        ];
    }

    private function pathMaxRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $path = parse_url($value, PHP_URL_PATH);

            if (is_string($path) && mb_strlen($path) > 512) {
                $fail(sprintf('The %s path must not be greater than 512 characters.', $attribute));
            }
        };
    }
}
