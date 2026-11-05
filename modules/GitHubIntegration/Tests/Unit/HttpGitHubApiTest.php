<?php

namespace Modules\GitHubIntegration\Tests\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\GitHubIntegration\Application\Ports\GitHubApi;
use Modules\GitHubIntegration\Application\Ports\GitHubApiFailure;
use Modules\GitHubIntegration\Infrastructure\Http\HttpGitHubApi;
use Modules\GitHubIntegration\Infrastructure\Http\InstallationTokenCache;
use Tests\TestCase;

/**
 * 06 increment 4 (G9, G10): the real client against faked HTTP. Read-only
 * besides token issuance, tokens cached encrypted, paging pinned to the API
 * host, and no credential in any failure, log or cache entry.
 */
final class HttpGitHubApiTest extends TestCase
{
    private const INSTALLATION = 4242;

    private GitHubSecretsFixture $fixture;

    private string $installationToken;

    private string $userToken;

    private int $tokenIssuances = 0;

    private string $logPath;

    /** @var array<string, \Closure(Request): mixed> */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new GitHubSecretsFixture;
        $this->installationToken = 'ghs_'.bin2hex(random_bytes(18));
        $this->userToken = 'ghu_'.bin2hex(random_bytes(18));
        $this->logPath = $this->fixture->directory.'/laravel.log';
        config([
            'github.driver' => 'http',
            'github.client_id' => 'Iv1.testclient',
            'github.secrets' => $this->fixture->paths(),
            'github.token_store' => 'array',
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['single'],
            'logging.channels.single.path' => $this->logPath,
        ]);
        Log::forgetChannel('stack');
        Log::forgetChannel('single');
        $this->app->forgetInstance(GitHubApi::class);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            foreach ($this->routes as $pattern => $respond) {
                if (preg_match($pattern, $request->method().' '.$request->url()) === 1) {
                    return $respond($request);
                }
            }

            return Http::response(['message' => 'Not Found'], 404);
        });
        $this->route('#^POST https://api\.github\.com/app/installations/\d+/access_tokens$#', function () {
            $this->tokenIssuances++;

            return Http::response(['token' => $this->installationToken, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)], 201);
        });
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function test_installation_is_read_with_a_short_lived_app_jwt(): void
    {
        $this->route('#^GET https://api\.github\.com/app/installations/4242$#', fn () => Http::response([
            'id' => self::INSTALLATION, 'account' => ['login' => 'acme', 'type' => 'Organization'], 'suspended_at' => null,
        ]));

        $installation = $this->api()->installation(self::INSTALLATION);
        $this->assertNotNull($installation);
        $this->assertSame(['acme', 'Organization', false], [$installation->accountLogin, $installation->accountType, $installation->suspended]);
        $this->assertNull($this->api()->installation(7), 'Unknown installation.');

        Http::assertSent(function (Request $request) {
            [$header, $payload, $signature] = explode('.', substr($request->header('Authorization')[0], strlen('Bearer ')));
            $claims = json_decode(self::base64UrlDecode($payload), true);
            $valid = openssl_verify("{$header}.{$payload}", self::base64UrlDecode($signature), $this->fixture->publicKey, OPENSSL_ALGO_SHA256);

            return $valid === 1 && $claims['iss'] === 'Iv1.testclient' && $claims['exp'] - $claims['iat'] <= 600;
        });
    }

    public function test_installation_token_is_issued_once_and_cached_encrypted(): void
    {
        $this->route('#^GET https://api\.github\.com/installation/repositories#', fn (Request $request) => Http::response(
            ['repositories' => [['id' => 42, 'full_name' => 'acme/api', 'private' => true]]],
            200,
            ($request->data()['page'] ?? 1) == 1 ? ['Link' => '<https://api.github.com/installation/repositories?per_page=100&page=2>; rel="next"'] : [],
        ));

        $first = $this->api()->installationRepositories(self::INSTALLATION);
        $this->assertSame(42, $first->repositories[0]->id);
        $this->assertSame(2, $first->nextPage);
        $this->assertNull($this->api()->installationRepositories(self::INSTALLATION, 2)->nextPage);
        $this->assertSame(1, $this->tokenIssuances, 'The second call reuses the cached token.');

        $stored = Cache::store('array')->get(InstallationTokenCache::key(self::INSTALLATION));
        $this->assertIsString($stored);
        $this->assertStringNotContainsString($this->installationToken, $stored);
        $this->assertStringNotContainsString($this->installationToken, base64_decode($stored));

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer '.$this->installationToken));
    }

    public function test_a_token_about_to_expire_is_not_cached(): void
    {
        $this->route('#^POST https://api\.github\.com/app/installations/\d+/access_tokens$#', function () {
            $this->tokenIssuances++;

            return Http::response(['token' => $this->installationToken, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 120)], 201);
        }, prepend: true);
        $this->route('#^GET https://api\.github\.com/installation/repositories#', fn () => Http::response(['repositories' => []]));

        $this->api()->installationRepositories(self::INSTALLATION);
        $this->api()->installationRepositories(self::INSTALLATION);
        $this->assertSame(2, $this->tokenIssuances);
    }

    public function test_paging_links_must_stay_on_the_api_endpoint(): void
    {
        foreach ([
            '<https://evil.example/installation/repositories?page=2>; rel="next"',
            '<http://api.github.com/installation/repositories?page=2>; rel="next"',
            '<https://api.github.com/user/repos?page=2>; rel="next"',
            '<https://api.github.com:8443/installation/repositories?page=2>; rel="next"',
            '<https://api.github.com/installation/repositories?page=0>; rel="next"',
        ] as $link) {
            $this->route('#^GET https://api\.github\.com/installation/repositories#', fn () => Http::response(['repositories' => []], 200, ['Link' => $link]), prepend: true);
            try {
                $this->api()->installationRepositories(self::INSTALLATION);
                $this->fail("Accepted paging link {$link}");
            } catch (GitHubApiFailure $failure) {
                $this->assertSame('repositories', $failure->operation);
            }
        }
    }

    public function test_oauth_code_exchange_and_user_installations(): void
    {
        $this->route('#^POST https://github\.com/login/oauth/access_token$#', fn (Request $request) => $request->data()['code'] === 'good-code'
            ? Http::response(['access_token' => $this->userToken, 'token_type' => 'bearer'])
            : Http::response(['error' => 'bad_verification_code']));
        $this->route('#^GET https://api\.github\.com/user/installations#', fn () => Http::response(['installations' => [['id' => self::INSTALLATION], ['id' => 7]]]));

        $token = $this->api()->exchangeOAuthCode('good-code');
        $this->assertSame([self::INSTALLATION, 7], $this->api()->userInstallationIds($token));
        $this->expectException(GitHubApiFailure::class);
        $this->api()->exchangeOAuthCode('reused-code');
    }

    public function test_only_reads_and_token_issuance_reach_github(): void
    {
        $this->route('#^GET https://api\.github\.com/app/installations/#', fn () => Http::response(['id' => self::INSTALLATION, 'account' => ['login' => 'acme', 'type' => 'User']]));
        $this->route('#^GET https://api\.github\.com/#', fn () => Http::response(['repositories' => [], 'installations' => []]));
        $this->route('#^POST https://github\.com/login/oauth/access_token$#', fn () => Http::response(['access_token' => $this->userToken]));

        $api = $this->api();
        $api->installation(self::INSTALLATION);
        $api->installationRepositories(self::INSTALLATION);
        $api->userInstallationIds($api->exchangeOAuthCode('code'));

        Http::assertSentCount(5);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET' && preg_match(
            '#^(https://api\.github\.com/app/installations/\d+/access_tokens|https://github\.com/login/oauth/access_token)$#',
            $request->url()
        ) !== 1);

        $send = new \ReflectionMethod(HttpGitHubApi::class, 'send');
        foreach ([['PATCH', 'https://api.github.com/repos/acme/api/issues/1'], ['POST', 'https://api.github.com/repos/acme/api/issues'], ['DELETE', 'https://api.github.com/app/installations/1']] as [$method, $url]) {
            try {
                $send->invoke($api, $method, $url, 'write', null);
                $this->fail("{$method} {$url} was allowed.");
            } catch (GitHubApiFailure $failure) {
                $this->assertSame('write', $failure->operation);
            }
        }
        Http::assertSentCount(5);
    }

    public function test_no_secret_reaches_failures_logs_or_cache(): void
    {
        // GitHub echoing credentials back in an error body must not matter.
        $echo = fn (Request $request) => Http::response(['message' => 'boom '.implode(' ', $request->header('Authorization'))], 500);
        $this->route('#^GET https://api\.github\.com/app/installations/#', $echo);
        $this->route('#^GET https://api\.github\.com/installation/repositories#', $echo);
        $this->route('#^POST https://github\.com/login/oauth/access_token$#', fn () => Http::response(['access_token' => $this->userToken]));
        $this->route('#^GET https://api\.github\.com/user/installations#', fn () => Http::failedConnection());

        $failures = [];
        foreach ([
            fn (GitHubApi $api) => $api->installation(self::INSTALLATION),
            fn (GitHubApi $api) => $api->installationRepositories(self::INSTALLATION),
            fn (GitHubApi $api) => $api->userInstallationIds($api->exchangeOAuthCode('code')),
        ] as $call) {
            try {
                $call($this->api());
                $this->fail('Expected a failure.');
            } catch (GitHubApiFailure $failure) {
                $this->assertNull($failure->getPrevious());
                $failures[] = $failure;
                report($failure);
            }
        }

        // A careless log line is still masked by the channel tap.
        Log::error('GitHub call failed', [
            'headers' => ['Authorization' => 'Bearer '.$this->installationToken],
            'detail' => "token {$this->userToken} with key ".$this->fixture->privateKey,
        ]);
        Log::warning('Retrying with Authorization: Bearer '.$this->installationToken);

        $haystacks = [
            'log' => (string) file_get_contents($this->logPath),
            'failures' => implode("\n", array_map(fn (GitHubApiFailure $failure) => (string) $failure, $failures)),
            'cache' => (string) Cache::store('array')->get(InstallationTokenCache::key(self::INSTALLATION)),
            'config' => (string) json_encode(config()->all()),
        ];
        $this->assertStringContainsString('GitHub call failed', $haystacks['log']);
        foreach ($this->sentinels() as $label => $secret) {
            foreach ($haystacks as $where => $text) {
                $this->assertStringNotContainsString($secret, $text, "The {$label} leaked into the {$where}.");
            }
        }
    }

    /** @return array<string, string> */
    private function sentinels(): array
    {
        $keyLines = array_filter(explode("\n", $this->fixture->privateKey), fn (string $line) => strlen($line) > 40);

        return [
            'installation token' => $this->installationToken,
            'user token' => $this->userToken,
            'webhook secret' => $this->fixture->webhookSecret,
            'OAuth client secret' => $this->fixture->oauthClientSecret,
            'private key' => (string) reset($keyLines),
        ];
    }

    private function route(string $pattern, \Closure $respond, bool $prepend = false): void
    {
        $this->routes = $prepend ? [$pattern => $respond] + $this->routes : $this->routes + [$pattern => $respond];
    }

    private function api(): GitHubApi
    {
        return $this->app->make(GitHubApi::class);
    }

    private static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'));
    }
}
