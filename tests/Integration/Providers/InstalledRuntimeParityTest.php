<?php

declare(strict_types=1);

use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Actions\RuntimeRefresh\RefreshInstalledPackageRuntimeAction;
use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Capell\Tests\Support\InstalledRuntimeParity;
use Capell\Tests\Support\InstalledRuntimeState;
use Capell\Tests\Support\InstalledRuntimeTestCase;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Symfony\Component\Process\Process;

it('matches fresh installed boot after in-process installation and repeated refresh', function (): void {
    InstalledRuntimeParity::assertPackage('insights');
});

it('keeps maintenance schedules exactly once in the public role', function (bool $installed): void {
    InstalledRuntimeState::run(static function () use ($installed): void {
        $app = InstalledRuntimeTestCase::boot(InstalledRuntimeParity::manifest('insights'), $installed, static function (Application $app): void {
            $app->instance(RuntimeRoleResolver::class, new RuntimeRoleResolver(RuntimeRoleSelectionData::fromConfiguredValue('public')));
        }, sourceRoot: dirname(__DIR__, 3));
        expect($app->make(RuntimeRoleResolver::class)->role())->toBe(RuntimeRole::Public);
        $schedule = $app->make(Schedule::class);
        $commands = ['insights:purge', 'insights:rollups:rebuild'];
        $assertSchedules = static function (int $count) use ($schedule, $commands): void {
            foreach ($commands as $command) {
                expect(array_filter($schedule->events(), static fn (ScheduledEvent $event): bool => is_string($event->command) && str_contains($event->command, $command)))->toHaveCount($count);
            }
        };
        $assertSchedules($installed ? 1 : 0);
        $package = CapellCore::getPackage('capell-app/insights');
        if (! $installed) {
            InstalledRuntimeTestCase::prepareLateInstallMigrations($app);
            InstallPackageAction::run($package);
        }
        $assertSchedules(1);
        RefreshInstalledPackageRuntimeAction::run($package);
        RefreshInstalledPackageRuntimeAction::run($package);
        $assertSchedules(1);
    });
})->with([true, false]);

it('keeps maintenance schedules when a public web request resolves the scheduler', function (): void {
    $root = dirname(__DIR__, 5);
    $process = new Process([
        PHP_BINARY, 'vendor/bin/pest',
        'packages/insights/tests/Integration/Providers/InstalledRuntimeParityTest.php',
        '--configuration=phpunit.xml', '--colors=never',
        '--filter=keeps maintenance schedules exactly once in the public role',
    ], $root);
    $process->setTimeout(120);
    $process->setEnv(['APP_RUNNING_IN_CONSOLE' => 'false']);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput());
});
