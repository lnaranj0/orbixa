<?php

namespace Modules\Platform\Tests\Security\Code;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Static scan: only TenantContext may write the tenant GUC, and no
 * application code may persist session-level settings or role defaults.
 * Runs without a database.
 */
final class TenantGucUsageTest extends TestCase
{
    private const SCANNED_DIRECTORIES = ['app', 'config', 'database/migrations', 'modules', 'routes'];

    private const ALLOWED_FILES = ['modules/Platform/Infrastructure/Tenancy/PostgresTenantContext.php'];

    private const FORBIDDEN_PATTERNS = [
        'set_config call' => '/set_config\s*\(/i',
        'SET app.* (session or local)' => '/\bset\s+(local\s+|session\s+)?app\./i',
        'RESET app.*' => '/\breset\s+app\./i',
        'ALTER ROLE' => '/\balter\s+role\b/i',
    ];

    public function test_tenant_guc_is_only_written_by_tenant_context(): void
    {
        $root = dirname(__DIR__, 5);
        $violations = [];

        foreach (self::SCANNED_DIRECTORIES as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($root) + 1);

                // Tests manipulate the GUC on purpose to prove RLS fails closed.
                if (in_array($relative, self::ALLOWED_FILES, true) || str_contains($relative, '/Tests/')) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                foreach (self::FORBIDDEN_PATTERNS as $label => $pattern) {
                    if (preg_match($pattern, $contents) === 1) {
                        $violations[] = "{$relative}: {$label}";
                    }
                }
            }
        }

        $this->assertSame([], $violations);
    }
}
