<?php

namespace Modules\GitHubIntegration\Tests\Unit;

use Modules\GitHubIntegration\Application\Ports\GitHubApi;
use Modules\GitHubIntegration\Application\Ports\GitHubApiFailure;
use Modules\GitHubIntegration\Domain\Secret;
use Modules\GitHubIntegration\Infrastructure\DisabledGitHubApi;
use Modules\GitHubIntegration\Infrastructure\FakeGitHubApi;
use Modules\GitHubIntegration\Infrastructure\Secrets\GitHubSecrets;
use Tests\TestCase;

/** 06 increment 4 (G10): where secrets come from and how they behave in memory. */
final class GitHubSecretsTest extends TestCase
{
    private GitHubSecretsFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new GitHubSecretsFixture;
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function test_secrets_are_read_from_owner_only_files(): void
    {
        $secrets = new GitHubSecrets($this->fixture->paths());
        $this->assertSame($this->fixture->webhookSecret, $secrets->webhookSecrets()[0]->reveal());
        $this->assertCount(1, $secrets->webhookSecrets(), 'The previous secret is optional.');
        $this->assertSame($this->fixture->oauthClientSecret, $secrets->oauthClientSecret()->reveal());

        $this->fixture->write('github-webhook.previous', 'previous-'.bin2hex(random_bytes(8)));
        $this->assertCount(2, $secrets->webhookSecrets(), 'Current and previous while rotating.');

        $this->fixture->write('github-oauth-client', $this->fixture->oauthClientSecret, 0o644);
        $this->assertNotConfigured(fn () => $secrets->oauthClientSecret());

        $missing = new GitHubSecrets(['private_key' => $this->fixture->directory.'/absent.pem']);
        $this->assertNotConfigured(fn () => $missing->privateKey());
        $this->assertNotConfigured(fn () => $missing->webhookSecrets());
    }

    public function test_a_secret_never_prints_or_serializes(): void
    {
        $secret = new Secret('sentinel-'.bin2hex(random_bytes(8)));

        $this->assertStringNotContainsString($secret->reveal(), print_r($secret, true));
        $this->assertSame('{}', json_encode($secret));
        ob_start();
        var_dump($secret);
        $this->assertStringNotContainsString($secret->reveal(), (string) ob_get_clean());

        $this->expectException(\LogicException::class);
        serialize($secret);
    }

    public function test_config_holds_paths_only_and_drivers_fail_closed(): void
    {
        $this->assertStringNotContainsString('PRIVATE KEY', (string) json_encode(config('github')));

        config(['github.driver' => null]);
        $this->app->forgetInstance(GitHubApi::class);
        $this->assertInstanceOf(DisabledGitHubApi::class, $this->app->make(GitHubApi::class));
        $this->assertNotConfigured(fn () => $this->app->make(GitHubApi::class)->installation(1));

        config(['github.driver' => 'fake']);
        $this->app->forgetInstance(GitHubApi::class);
        $this->assertInstanceOf(FakeGitHubApi::class, $this->app->make(GitHubApi::class));

        $this->app['env'] = 'production';
        $this->app->forgetInstance(GitHubApi::class);
        $this->assertNotConfigured(fn () => $this->app->make(GitHubApi::class));
    }

    private function assertNotConfigured(\Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Expected GitHubApiFailure.');
        } catch (GitHubApiFailure $failure) {
            $this->assertSame('configuration', $failure->operation);
        }
    }
}
