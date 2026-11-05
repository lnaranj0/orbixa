<?php

namespace Modules\GitHubIntegration\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\GitHubIntegration\Tests\Unit\GitHubSecretsFixture;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;

/**
 * 06 increment 5 (G3, G-A, G-B): POST /webhooks/github as the runtime role.
 * Covers the "Verificación HMAC", "Idempotencia" and "Enrutamiento por
 * organización" scenarios.
 */
final class WebhookIntakeTest extends SecurityTestCase
{
    private GitHubSecretsFixture $secrets;

    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    private int $installation;

    private int $selectedRepository;

    private int $unselectedRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secrets = new GitHubSecretsFixture;
        config(['github.secrets' => $this->secrets->paths()]);
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = User::factory()->create(['email' => Str::uuid().'@example.test']);
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Webhook Inc');

        $this->installation = random_int(10_000_000, 99_999_999);
        $this->selectedRepository = random_int(10_000_000, 99_999_999);
        $this->unselectedRepository = $this->selectedRepository + 1;
        $this->tenant->runFor($this->owner, $this->organization, function () {
            $membership = (string) DB::selectOne('select id::text as id from identity.organization_memberships where user_id = ?', [$this->owner->getKey()])->id;
            $installation = (string) DB::selectOne(
                "insert into integrations.github_installations (organization_id, installation_external_id, account_login, account_type, linked_by_membership_id)
                 values (platform.current_organization_id(), ?, 'acme', 'Organization', ?) returning id::text as id",
                [$this->installation, $membership]
            )->id;
            DB::insert(
                "insert into integrations.github_repositories (organization_id, installation_id, repository_external_id, full_name, is_private, selected_at, selected_by_membership_id)
                 values (platform.current_organization_id(), ?, ?, 'acme/api', true, now(), ?),
                        (platform.current_organization_id(), ?, ?, 'acme/web', true, null, null)",
                [$installation, $this->selectedRepository, $membership, $installation, $this->unselectedRepository]
            );
        });
    }

    protected function tearDown(): void
    {
        $this->secrets->remove();
        parent::tearDown();
    }

    public function test_bad_signatures_are_rejected_before_parsing_and_leave_nothing(): void
    {
        $body = $this->payload($this->selectedRepository);
        $delivery = $this->deliveryId();

        foreach ([null, 'sha1=abc', 'sha256=zz', 'sha256='.hash_hmac('sha256', $body, 'wrong-secret'), 'sha256='.hash_hmac('sha256', $body.' ', $this->secrets->webhookSecret)] as $signature) {
            $this->deliver($body, $delivery, signature: $signature ?? false)->assertStatus(401)->assertContent('');
        }
        // Not JSON at all: still 401, so the body was never parsed.
        $this->deliver('{not json', $delivery, signature: 'sha256='.str_repeat('0', 64))->assertStatus(401);

        $this->assertDelivery($delivery, null);
        $this->assertInboxHas($delivery, false);
    }

    public function test_previous_secret_is_accepted_while_rotating(): void
    {
        $previous = 'previous-'.bin2hex(random_bytes(8));
        $this->secrets->write('github-webhook.previous', $previous);
        $body = $this->payload($this->selectedRepository);

        $this->deliver($body, $delivery = $this->deliveryId(), signature: 'sha256='.hash_hmac('sha256', $body, $previous))
            ->assertStatus(202)->assertExactJson(['status' => 'accepted']);
        $this->assertInboxHas($delivery, true);
    }

    public function test_oversized_bodies_get_413_unread(): void
    {
        config(['github.webhook_max_bytes' => 512]);
        $body = $this->payload($this->selectedRepository, ['padding' => str_repeat('x', 600)]);

        $this->deliver($body, $delivery = $this->deliveryId())->assertStatus(413);
        // Lying Content-Length: the bounded read still stops at the limit.
        $this->deliver($body, $delivery, headers: ['CONTENT_LENGTH' => '100'])->assertStatus(413);
        $this->deliver($body, $delivery, headers: ['CONTENT_LENGTH' => 'abc'])->assertStatus(413);
        // No Content-Length at all (chunked).
        $this->deliver($body, $delivery, headers: ['CONTENT_LENGTH' => null])->assertStatus(413);
        $this->assertDelivery($delivery, null);
    }

    public function test_selected_repository_event_is_stored_in_its_organization_only(): void
    {
        $response = $this->deliver($this->payload($this->selectedRepository), $delivery = $this->deliveryId());

        $response->assertStatus(202)->assertExactJson(['status' => 'accepted']);
        $this->assertFalse($response->headers->has('Set-Cookie'), 'No session is started.');
        $this->assertDelivery($delivery, 'accepted');
        $row = $this->tenant->runFor($this->owner, $this->organization, fn () => DB::selectOne(
            'select event, action, status, repository_id is not null as has_repository, payload->\'repository\'->>\'id\' as repository
               from integrations.webhook_inbox where delivery_id = ?',
            [$delivery]
        ));
        $this->assertSame(['pull_request', 'opened', 'pending', true, (string) $this->selectedRepository], [$row->event, $row->action, $row->status, $row->has_repository, $row->repository]);

        // Another organization never sees it.
        $stranger = User::factory()->create(['email' => Str::uuid().'@example.test']);
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other Inc');
        $this->assertSame(0, $this->tenant->runFor($stranger, $other, fn () => (int) DB::selectOne(
            'select count(*) as total from integrations.webhook_inbox where delivery_id = ?', [$delivery]
        )->total));
    }

    public function test_unrouted_or_unselected_events_are_ignored_without_payload(): void
    {
        $cases = [
            'unselected repository' => $this->payload($this->unselectedRepository),
            'unknown repository' => $this->payload(random_int(10_000_000, 99_999_999)),
            'unlinked installation' => $this->payload($this->selectedRepository, ['installation' => ['id' => random_int(10_000_000, 99_999_999)]]),
            'no installation' => $this->payload($this->selectedRepository, ['installation' => null]),
        ];
        foreach ($cases as $case => $body) {
            $this->deliver($body, $delivery = $this->deliveryId())->assertStatus(202)->assertExactJson(['status' => 'ignored']);
            $this->assertDelivery($delivery, 'ignored');
            $this->assertInboxHas($delivery, false, $case);
        }
        // jsonb cannot hold \u0000: ignored up front instead of failing on every redelivery.
        $this->deliver('{"action":"opened","installation":{"id":'.$this->installation.'},"repository":{"id":'.$this->selectedRepository.'},"title":"a\\u0000b"}', $delivery = $this->deliveryId())
            ->assertStatus(202)->assertExactJson(['status' => 'ignored']);
        $this->assertInboxHas($delivery, false, 'NUL escape');

        $this->deliver($this->payload($this->selectedRepository), $delivery = $this->deliveryId(), event: 'issues')
            ->assertExactJson(['status' => 'ignored']);
        $this->assertInboxHas($delivery, false, 'unhandled event');

        $this->tenant->runFor($this->owner, $this->organization, fn () => DB::update(
            "update integrations.github_installations set status = 'revoked' where installation_external_id = ?", [$this->installation]
        ));
        $this->deliver($this->payload($this->selectedRepository), $delivery = $this->deliveryId())->assertExactJson(['status' => 'ignored']);
        $this->assertInboxHas($delivery, false, 'revoked installation');
    }

    public function test_organization_comes_from_the_installation_never_the_payload(): void
    {
        $stranger = User::factory()->create(['email' => Str::uuid().'@example.test']);
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Target Inc');
        $body = $this->payload($this->selectedRepository, ['organization_id' => $other->value, 'organization' => ['id' => $other->value]]);

        $this->deliver($body, $delivery = $this->deliveryId())->assertExactJson(['status' => 'accepted']);
        $this->assertInboxHas($delivery, true);
        $this->assertSame(0, $this->tenant->runFor($stranger, $other, fn () => (int) DB::selectOne(
            'select count(*) as total from integrations.webhook_inbox where delivery_id = ?', [$delivery]
        )->total));
    }

    public function test_ignored_payloads_do_not_reach_the_logs(): void
    {
        $log = $this->secrets->directory.'/laravel.log';
        config(['logging.default' => 'single', 'logging.channels.single.path' => $log]);
        Log::forgetChannel('single');
        $body = $this->payload($this->unselectedRepository);

        $this->deliver($body, $this->deliveryId())->assertExactJson(['status' => 'ignored']);
        $this->deliver('{"bad":', $this->deliveryId())->assertStatus(400);
        Log::info('marker');

        $this->assertStringNotContainsString(json_decode($body, true)['nonce'], (string) file_get_contents($log));
    }

    public function test_installation_events_are_stored_without_a_repository(): void
    {
        $body = $this->payload(null, ['action' => 'suspend']);
        $this->deliver($body, $delivery = $this->deliveryId(), event: 'installation')->assertStatus(202)->assertExactJson(['status' => 'accepted']);
        $row = $this->tenant->runFor($this->owner, $this->organization, fn () => DB::selectOne(
            'select repository_id from integrations.webhook_inbox where delivery_id = ?', [$delivery]
        ));
        $this->assertNotNull($row);
        $this->assertNull($row->repository_id);
    }

    public function test_repeated_deliveries_have_no_second_effect(): void
    {
        $body = $this->payload($this->selectedRepository);
        $delivery = $this->deliveryId();

        $this->deliver($body, $delivery)->assertStatus(202);
        $this->deliver($body, $delivery)->assertStatus(200)->assertExactJson(['status' => 'duplicate']);
        // Same body redelivered under a new id.
        $this->deliver($body, $redelivery = $this->deliveryId())->assertStatus(200)->assertExactJson(['status' => 'duplicate']);
        $this->assertInboxHas($redelivery, false);

        $count = $this->tenant->runFor($this->owner, $this->organization, fn () => (int) DB::selectOne(
            "select count(*) as total from integrations.webhook_inbox where payload->>'nonce' = ?", [json_decode($body, true)['nonce']]
        )->total);
        $this->assertSame(1, $count);

        // An ignored delivery is a duplicate the second time too.
        $ignored = $this->payload($this->unselectedRepository);
        $this->deliver($ignored, $id = $this->deliveryId())->assertExactJson(['status' => 'ignored']);
        $this->deliver($ignored, $id)->assertExactJson(['status' => 'duplicate']);
    }

    public function test_malformed_headers_or_body_get_400_and_missing_secret_503(): void
    {
        $this->deliver($this->payload($this->selectedRepository), 'bad id!')->assertStatus(400);
        $this->deliver($this->payload($this->selectedRepository), $this->deliveryId(), event: 'Pull-Request')->assertStatus(400);
        $this->deliver($this->payload($this->selectedRepository), $this->deliveryId(), event: str_repeat('a', 65))->assertStatus(400);
        $this->deliver('[1,2]', $this->deliveryId())->assertStatus(400);
        $this->deliver('{"a":', $this->deliveryId())->assertStatus(400);

        unlink($this->secrets->directory.'/github-webhook');
        $this->deliver($this->payload($this->selectedRepository), $this->deliveryId())->assertStatus(503)->assertContent('');
    }

    public function test_deliveries_are_throttled_per_source_address(): void
    {
        config(['github.webhook_rate_per_minute' => 2]);
        $this->deliver('{}', $this->deliveryId(), signature: false)->assertStatus(401);
        $this->deliver('{}', $this->deliveryId(), signature: false)->assertStatus(401);
        $this->deliver('{}', $this->deliveryId(), signature: false)->assertStatus(429);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function payload(?int $repository, array $overrides = []): string
    {
        $payload = array_merge([
            'action' => 'opened',
            'nonce' => bin2hex(random_bytes(8)),
            'installation' => ['id' => $this->installation],
            'repository' => $repository === null ? null : ['id' => $repository, 'full_name' => 'acme/api'],
            'pull_request' => ['number' => 1, 'title' => 'ACME-1 First'],
        ], $overrides);

        return (string) json_encode(array_filter($payload, fn ($value) => $value !== null));
    }

    /**
     * @param  string|false|null  $signature  null signs with the current secret; false sends none
     * @param  array<string, string|null>  $headers  null removes the header
     */
    private function deliver(string $body, string $delivery, string $event = 'pull_request', string|false|null $signature = null, array $headers = []): TestResponse
    {
        $server = array_merge([
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => (string) strlen($body),
            'HTTP_X_GITHUB_DELIVERY' => $delivery,
            'HTTP_X_GITHUB_EVENT' => $event,
        ], $headers);
        $server = array_filter($server, fn (?string $value) => $value !== null);
        if ($signature !== false) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $signature ?? 'sha256='.hash_hmac('sha256', $body, $this->secrets->webhookSecret);
        }

        return $this->call('POST', '/webhooks/github', [], [], [], $server, $body);
    }

    private function deliveryId(): string
    {
        return (string) Str::uuid();
    }

    private function assertDelivery(string $delivery, ?string $outcome): void
    {
        // The runtime cannot read the register (INSERT only): the owner checks it.
        $row = DB::connection((string) config('database.migrator_connection'))
            ->selectOne('select outcome from integrations.webhook_deliveries where delivery_id = ?', [$delivery]);
        $this->assertSame($outcome, $row?->outcome);
    }

    private function assertInboxHas(string $delivery, bool $expected, string $case = ''): void
    {
        $count = $this->tenant->runFor($this->owner, $this->organization, fn () => (int) DB::selectOne(
            'select count(*) as total from integrations.webhook_inbox where delivery_id = ?', [$delivery]
        )->total);
        $this->assertSame($expected ? 1 : 0, $count, $case);
    }
}
