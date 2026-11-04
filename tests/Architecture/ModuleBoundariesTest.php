<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Spec "Módulos desacoplados": importing another module's infrastructure
 * fails the architecture check, and the real codebase passes it.
 */
final class ModuleBoundariesTest extends TestCase
{
    public function test_codebase_respects_module_boundaries(): void
    {
        $process = $this->deptrac('deptrac.yaml');

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
    }

    public function test_importing_another_modules_internals_fails_the_check(): void
    {
        $root = dirname(__DIR__, 2);

        // Same layers and ruleset as production; only the analysed paths change.
        $config = preg_replace(
            '/  paths:\n    - \.\/app\n    - \.\/modules\n/',
            "  paths:\n    - {$root}/tests/Architecture/Fixtures\n    - {$root}/modules\n",
            (string) file_get_contents("{$root}/deptrac.yaml"),
            count: $replaced,
        );
        $this->assertSame(1, $replaced, 'deptrac.yaml paths block changed; update this test.');

        $base = (string) tempnam(sys_get_temp_dir(), 'deptrac-violation-');
        $file = $base.'.yaml';
        file_put_contents($file, $config);

        try {
            $process = $this->deptrac($file);
        } finally {
            @unlink($file);
            @unlink($base);
        }

        $this->assertNotSame(0, $process->getExitCode(), 'deptrac accepted a cross-module internal dependency.');
        $this->assertStringContainsString('LeakyAuditRecorder', $process->getOutput());
    }

    private function deptrac(string $config): Process
    {
        $root = dirname(__DIR__, 2);
        $process = new Process(
            [PHP_BINARY, 'vendor/bin/deptrac', 'analyse', '--config-file='.$config, '--no-progress', '--no-cache'],
            $root,
        );
        $process->setTimeout(120);
        $process->run();

        return $process;
    }
}
