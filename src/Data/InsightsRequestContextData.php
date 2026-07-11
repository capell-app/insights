<?php

declare(strict_types=1);

namespace Capell\Insights\Data;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

final class InsightsRequestContextData extends Data
{
    public function __construct(
        public ?int $siteId,
        public ?int $languageId,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            siteId: self::modelKey($request->attributes->get('site'), Site::class),
            languageId: self::modelKey($request->attributes->get('language'), Language::class),
        );
    }

    public function toRequest(): Request
    {
        $request = Request::create('/', 'POST');
        $site = $this->siteId !== null ? Site::query()->find($this->siteId) : null;
        $language = $this->languageId !== null ? Language::query()->find($this->languageId) : null;

        if ($site instanceof Site) {
            $request->attributes->set('site', $site);
        }

        if ($language instanceof Language) {
            $request->attributes->set('language', $language);
        }

        return $request;
    }

    /** @param class-string<Model> $expectedClass */
    private static function modelKey(mixed $model, string $expectedClass): ?int
    {
        if (! $model instanceof $expectedClass) {
            return null;
        }

        $key = $model->getKey();

        return is_numeric($key) ? (int) $key : null;
    }
}
