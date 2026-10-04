<?php

declare(strict_types=1);

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\Lang;
use Illuminate\Translation\Translator;

it('translates every insights enum label in English', function (HasLabel $case): void {
    app()->setLocale('en');
    $translator = resolve(Translator::class);
    Lang::partialMock()->shouldReceive('get')->once()->andReturnUsing(static function (string $key) use ($translator): string|array {
        expect($translator->hasForLocale($key, 'en'))->toBeTrue();

        return $translator->get($key);
    });
    $label = $case->getLabel();

    expect($label)->toBeString()->not->toBeEmpty();
    assert(is_string($label));
    expect($label)->not->toContain('capell-insights::');
})->with(function (): array {
    $cases = [];
    foreach (glob(dirname(__DIR__, 2) . '/src/Enums/*.php') ?: [] as $file) {
        $class = 'Capell\\Insights\\Enums\\' . basename($file, '.php');
        if (! enum_exists($class) || ! is_subclass_of($class, HasLabel::class)) {
            continue;
        }

        foreach ($class::cases() as $case) {
            $cases[$class . '::' . $case->name] = [$case];
        }
    }

    return $cases;
});
