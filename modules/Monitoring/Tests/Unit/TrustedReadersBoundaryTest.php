<?php

namespace Modules\Monitoring\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 04 review B8: deptrac only sees layered classes. This scans every PHP
 * file (routes, App, container lookups) so the trusted, actor-less readers
 * are referenced only by Monitoring, by their own implementations and
 * bindings, and by tests.
 */
final class TrustedReadersBoundaryTest extends TestCase
{
    private const TRUSTED = ['Work\\Contracts\\Monitoring\\', 'Portfolio\\Contracts\\Catalog\\'];

    private const ALLOWED = [
        'modules/Monitoring/',
        'modules/Work/Contracts/Monitoring/',
        'modules/Work/Infrastructure/PostgresMonitoredWorkReader.php',
        'modules/Work/Infrastructure/WorkServiceProvider.php',
        'modules/Portfolio/Contracts/Catalog/',
        'modules/Portfolio/Infrastructure/PostgresProjectCatalog.php',
        'modules/Portfolio/Infrastructure/PortfolioServiceProvider.php',
    ];

    public function test_trusted_readers_are_used_only_by_monitoring(): void
    {
        $root = dirname(__DIR__, 4);
        $offenders = [];

        foreach (['app', 'bootstrap', 'routes', 'modules'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = substr((string) $file, strlen($root) + 1);
                if (! str_ends_with($path, '.php') || str_contains($path, '/Tests/') || $this->allowed($path)) {
                    continue;
                }
                $source = (string) file_get_contents((string) $file);
                foreach (self::TRUSTED as $namespace) {
                    if (str_contains($source, $namespace)) {
                        $offenders[] = $path;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'Only Monitoring may use the trusted monitoring readers.');
    }

    private function allowed(string $path): bool
    {
        foreach (self::ALLOWED as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
