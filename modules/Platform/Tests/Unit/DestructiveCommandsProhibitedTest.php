<?php

namespace Modules\Platform\Tests\Unit;

use Illuminate\Database\Console\DumpCommand;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Database\Console\WipeCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

/**
 * DB::prohibitDestructiveCommands() is active unless a human explicitly sets
 * DB_ALLOW_DESTRUCTIVE_COMMANDS=true (phpunit.xml forces false).
 * Checked by reflection so no destructive command is ever executed here.
 */
final class DestructiveCommandsProhibitedTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function destructiveCommands(): array
    {
        return [
            'migrate:fresh' => [FreshCommand::class],
            'migrate:refresh' => [RefreshCommand::class],
            'migrate:reset' => [ResetCommand::class],
            'migrate:rollback' => [RollbackCommand::class],
            'db:wipe' => [WipeCommand::class],
            'db:seed' => [SeedCommand::class],
            'schema:dump' => [DumpCommand::class],
        ];
    }

    /**
     * @param  class-string  $command
     */
    #[DataProvider('destructiveCommands')]
    public function test_destructive_command_is_prohibited(string $command): void
    {
        $this->assertFalse(config('database.allow_destructive_commands'));
        $this->assertTrue((new ReflectionProperty($command, 'prohibitedFromRunning'))->getValue());
    }
}
