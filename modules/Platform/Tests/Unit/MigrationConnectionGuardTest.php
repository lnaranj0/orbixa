<?php

namespace Modules\Platform\Tests\Unit;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Modules\Platform\Infrastructure\Database\MigrationConnectionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Rejection paths only: they must fail before any database connection is
 * opened. The positive path (real migrator role) is exercised when the test
 * database is migrated.
 */
final class MigrationConnectionGuardTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function refusedInvocations(): array
    {
        return [
            'migrate without --database' => ['migrate', []],
            'migrate on runtime connection' => ['migrate', ['--database=pgsql']],
            'migrate with separate value' => ['migrate', ['--database', 'pgsql']],
            'status on default connection' => ['migrate:status', []],
            'rollback on runtime connection' => ['migrate:rollback', ['--database=pgsql']],
            'fresh without --database' => ['migrate:fresh', []],
            'seed without --database' => ['db:seed', []],
            'wipe without --database' => ['db:wipe', []],
        ];
    }

    /**
     * @param  list<string>  $arguments
     */
    #[DataProvider('refusedInvocations')]
    public function test_schema_commands_must_target_the_migrator_connection(string $command, array $arguments): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--database=pgsql_migrator');

        $this->guard()->handle($this->event($command, $arguments));
    }

    /**
     * Regression: APP_ENV=testing must not bypass the guard. Runs through the
     * real console kernel, not by calling handle() directly.
     *
     * @return array<string, array{string}>
     */
    public static function guardedCommandsThroughKernel(): array
    {
        return [
            'migrate' => ['migrate'],
            'migrate:status' => ['migrate:status'],
            'migrate:install' => ['migrate:install'],
        ];
    }

    #[DataProvider('guardedCommandsThroughKernel')]
    public function test_guard_runs_for_real_artisan_calls_in_testing_environment(string $command): void
    {
        $this->assertTrue($this->app->environment('testing'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--database=pgsql_migrator');

        Artisan::call($command);
    }

    public function test_unrelated_commands_are_not_guarded(): void
    {
        $this->expectNotToPerformAssertions();

        $this->guard()->handle($this->event('about', []));
        $this->guard()->handle($this->event('test', ['--testsuite=Security']));
    }

    private function guard(): MigrationConnectionGuard
    {
        return $this->app->make(MigrationConnectionGuard::class);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function event(string $command, array $arguments): CommandStarting
    {
        return new CommandStarting($command, new ArgvInput(['artisan', $command, ...$arguments]), new NullOutput);
    }
}
