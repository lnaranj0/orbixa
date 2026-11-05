<?php

namespace Modules\GitHubIntegration\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\GitHubIntegration\Application\InstallationRejected;
use Modules\GitHubIntegration\Application\Ports\GitHubApi;
use Modules\GitHubIntegration\Application\Ports\GitHubApiFailure;
use Modules\GitHubIntegration\Application\Ports\GitHubInstallation;
use Modules\GitHubIntegration\Application\Ports\GitHubRepository;
use Modules\GitHubIntegration\Infrastructure\FakeGitHubApi;
use Modules\GitHubIntegration\Infrastructure\Setup\SetupState;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Application\RevokeMembership;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;

/**
 * 06 increment 6 (G-C): linking an installation and selecting repositories,
 * as the runtime role. Covers "Instalación autorizada": valid linking,
 * foreign or doubly claimed installation, and reused, expired or foreign state.
 */
final class InstallationSetupTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private FakeGitHubApi $github;

    private User $owner;

    private OrganizationId $organization;

    private int $installation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['github.app_slug' => 'orbixa-test', 'github.setup_per_minute' => 100]);
        $this->github = new FakeGitHubApi;
        $this->app->instance(GitHubApi::class, $this->github);
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Setup Inc');
        $this->installation = random_int(10_000_000, 99_999_999);
        $this->github->addInstallation(new GitHubInstallation($this->installation, 777, 'acme', 'Organization', false), [
            new GitHubRepository($this->installation + 1, 'acme/api', true),
            new GitHubRepository($this->installation + 2, 'acme/web', false),
        ]);
    }

    public function test_start_requires_manage_and_configuration(): void
    {
        $response = $this->actingAs($this->owner)->postJson($this->api('setup'))->assertOk();
        $this->assertMatchesRegularExpression('#^https://github\.com/apps/orbixa-test/installations/new\?state=[A-Za-z0-9_-]{43}$#', $response->json('data.redirect_url'));

        $this->actingAs($this->member('developer'))->postJson($this->api('setup'))->assertForbidden();
        $this->actingAs($this->user())->postJson($this->api('setup'))->assertNotFound();

        config(['github.app_slug' => null]);
        $this->actingAs($this->owner)->postJson($this->api('setup'))->assertStatus(503);
    }

    public function test_valid_installation_is_linked_with_unselected_repositories(): void
    {
        $this->github->grantUser('code-ok', [$this->installation]);
        $this->setupCallback($this->owner, $this->start($this->owner), 'code-ok')
            ->assertRedirect(route('organizations.show', ['organization' => $this->organization->value]))
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        $data = $this->actingAs($this->owner)->getJson($this->api(''))->assertOk()->json('data');
        $this->assertSame([[$this->installation, 'acme', 'active']], array_map(fn (array $i) => [$i['installation_external_id'], $i['account_login'], $i['status']], $data['installations']));
        $this->assertSame([['acme/api', true, false], ['acme/web', true, false]], array_map(fn (array $r) => [$r['full_name'], $r['available'], $r['selected']], $data['repositories']));
        $this->assertSame(['github.installation.connected'], $this->events($data['installations'][0]['id']));
    }

    public function test_state_is_single_use_bound_to_the_session_and_expires(): void
    {
        $this->github->grantUser('code-1', [$this->installation]);
        $this->github->grantUser('code-2', [$this->installation]);
        $state = $this->start($this->owner);

        $this->setupCallback($this->owner, 'forged-'.$state, 'code-1')->assertSessionHasErrors('github');
        // The forged attempt consumed the pending state: the real one is now useless too.
        $this->setupCallback($this->owner, $state, 'code-1')->assertSessionHasErrors('github');

        $state = $this->start($this->owner);
        $this->setupCallback($this->owner, $state, 'code-1')->assertSessionHasNoErrors();
        $this->setupCallback($this->owner, $state, 'code-2')->assertSessionHasErrors('github');

        // Expired.
        $state = $this->start($this->owner);
        $pending = session(SetupState::SESSION_KEY);
        $this->withSession([SetupState::SESSION_KEY => ['expires_at' => time() - 1] + $pending]);
        $this->setupCallback($this->owner, $state, 'code-2')->assertSessionHasErrors('github');

        // Another session (no pending state).
        $state = $this->start($this->owner);
        $this->flushSession();
        $this->setupCallback($this->owner, $state, 'code-2')->assertSessionHasErrors('github');
        // Only the one valid callback reached GitHub.
        $this->assertCount(1, array_filter($this->github->calls(), fn (string $call) => $call === 'oauth'));
    }

    public function test_installation_without_user_access_or_suspended_is_rejected(): void
    {
        $this->github->grantUser('no-access', [random_int(10_000_000, 99_999_999)]);
        $this->setupCallback($this->owner, $this->start($this->owner), 'no-access')->assertSessionHasErrors(['github' => InstallationRejected::MESSAGE]);

        $suspended = random_int(10_000_000, 99_999_999);
        $this->github->addInstallation(new GitHubInstallation($suspended, 777, 'acme', 'Organization', true));
        $this->github->grantUser('suspended', [$suspended]);
        $this->setupCallback($this->owner, $this->start($this->owner), 'suspended', $suspended)->assertSessionHasErrors('github');

        // Unknown to GitHub even though the URL names it.
        $unknown = random_int(10_000_000, 99_999_999);
        $this->github->grantUser('unknown', [$unknown]);
        $this->setupCallback($this->owner, $this->start($this->owner), 'unknown', $unknown)->assertSessionHasErrors('github');

        $this->assertSame([], $this->actingAs($this->owner)->getJson($this->api(''))->json('data.installations'));
    }

    public function test_repository_access_without_account_admin_cannot_link_the_installation(): void
    {
        // A collaborator with access to one repository sees the installation
        // in /user/installations, but does not administer the account.
        $this->github->grantUser('collaborator', [$this->installation], adminOf: []);
        $this->setupCallback($this->owner, $this->start($this->owner), 'collaborator')
            ->assertSessionHasErrors(['github' => InstallationRejected::MESSAGE]);

        $data = $this->actingAs($this->owner)->getJson($this->api(''))->json('data');
        $this->assertSame([[], []], [$data['installations'], $data['repositories']]);
        $this->assertNotContains('repositories:'.$this->installation.':1', $this->github->calls(), 'No repository is listed.');
    }

    public function test_an_installation_belongs_to_one_organization_and_the_error_is_generic(): void
    {
        $this->github->grantUser('first', [$this->installation]);
        $this->setupCallback($this->owner, $this->start($this->owner), 'first')->assertSessionHasNoErrors();

        $rival = $this->user();
        $rivalOrganization = $this->app->make(CreateOrganization::class)->handle($rival, 'Rival Inc');
        $this->github->grantUser('second', [$this->installation]);
        $state = $this->start($rival, $rivalOrganization);
        $this->setupCallback($rival, $state, 'second')
            ->assertRedirect(route('organizations.show', ['organization' => $rivalOrganization->value]))
            ->assertSessionHasErrors(['github' => InstallationRejected::MESSAGE]);

        $this->assertSame([], $this->actingAs($rival)->getJson($this->api('', $rivalOrganization))->json('data.installations'));
        $this->assertCount(1, $this->actingAs($this->owner)->getJson($this->api(''))->json('data.installations'));
    }

    public function test_member_without_manage_cannot_complete_and_github_is_not_called(): void
    {
        $developer = $this->member('developer');
        $this->github->grantUser('dev', [$this->installation]);
        $this->actingAs($developer)->withSession([SetupState::SESSION_KEY => [
            'organization' => $this->organization->value, 'user' => (string) $developer->getKey(), 'hash' => hash('sha256', 'crafted'), 'expires_at' => time() + 60,
        ]]);

        $this->setupCallback($developer, 'crafted', 'dev')->assertSessionHasErrors('github');
        $this->assertSame([], $this->github->calls());
    }

    public function test_reinstalling_refreshes_repositories_without_a_second_connection_event(): void
    {
        $this->github->grantUser('first', [$this->installation]);
        $this->setupCallback($this->owner, $this->start($this->owner), 'first');
        $this->github->addInstallation(new GitHubInstallation($this->installation, 777, 'acme-renamed', 'Organization', false), [
            new GitHubRepository($this->installation + 2, 'acme/web-renamed', false),
            new GitHubRepository($this->installation + 3, 'acme/new', true),
        ]);
        $this->github->grantUser('again', [$this->installation]);
        $this->setupCallback($this->owner, $this->start($this->owner), 'again', setupAction: 'update')->assertSessionHasNoErrors();

        $data = $this->actingAs($this->owner)->getJson($this->api(''))->json('data');
        $this->assertSame('acme-renamed', $data['installations'][0]['account_login']);
        $this->assertSame(
            [['acme/api', false], ['acme/new', true], ['acme/web-renamed', true]],
            array_map(fn (array $r) => [$r['full_name'], $r['available']], $data['repositories'])
        );
        $this->assertSame(['github.installation.connected'], $this->events($data['installations'][0]['id']));
    }

    public function test_pending_approval_on_github_links_nothing(): void
    {
        $state = $this->start($this->owner);
        $this->actingAs($this->owner)->get('/integrations/github/callback?'.http_build_query(['state' => $state, 'setup_action' => 'request']))
            ->assertSessionHas('status')->assertSessionHasNoErrors();
        $this->assertSame([], $this->github->calls());
    }

    public function test_repository_selection(): void
    {
        $this->github->grantUser('ok', [$this->installation]);
        $this->setupCallback($this->owner, $this->start($this->owner), 'ok');
        $repositories = $this->actingAs($this->owner)->getJson($this->api(''))->json('data.repositories');
        $api = $repositories[0]['id'];

        $developer = $this->member('developer');
        $this->actingAs($developer)->getJson($this->api(''))->assertForbidden();
        $this->actingAs($developer)->putJson($this->api("repositories/{$api}/selection"))->assertForbidden();

        $this->actingAs($this->owner)->putJson($this->api("repositories/{$api}/selection"))->assertNoContent();
        $this->actingAs($this->owner)->putJson($this->api("repositories/{$api}/selection"))->assertNoContent();
        $this->assertTrue($this->actingAs($this->owner)->getJson($this->api(''))->json('data.repositories.0.selected'));

        $this->actingAs($this->owner)->deleteJson($this->api("repositories/{$api}/selection"))->assertNoContent();
        $this->assertSame(['github.repository.selected', 'github.repository.deselected'], $this->events($api));

        // Unknown, malformed or another organization's repository: 404.
        $rival = $this->user();
        $rivalOrganization = $this->app->make(CreateOrganization::class)->handle($rival, 'Rival');
        $this->actingAs($rival)->putJson($this->api("repositories/{$api}/selection", $rivalOrganization))->assertNotFound();
        $this->actingAs($this->owner)->putJson($this->api('repositories/'.Str::uuid().'/selection'))->assertNotFound();
        $this->actingAs($this->owner)->putJson($this->api('repositories/not-a-uuid/selection'))->assertNotFound();

        // No longer available on the installation: 422.
        $this->tenant->runFor($this->owner, $this->organization, fn () => DB::update(
            'update integrations.github_repositories set available = false where id = ?', [$api]
        ));
        $this->actingAs($this->owner)->putJson($this->api("repositories/{$api}/selection"))->assertStatus(422);
    }

    public function test_guest_callback_goes_to_login_without_keeping_the_code(): void
    {
        $response = $this->get('/integrations/github/callback?'.http_build_query(['installation_id' => $this->installation, 'code' => 'guest-code', 'state' => 'x']));

        $response->assertRedirect(route('login'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertNull(session('url.intended'));
        $this->assertSame([], $this->github->calls());
    }

    public function test_state_of_another_user_in_the_same_session_is_rejected(): void
    {
        $this->github->grantUser('switch', [$this->installation]);
        $state = $this->start($this->owner);
        $admin = $this->member('org_admin');

        $this->setupCallback($admin, $state, 'switch')->assertSessionHasErrors('github');
        $this->assertSame([], $this->github->calls());
    }

    public function test_malformed_callback_parameters_are_rejected_before_github(): void
    {
        foreach (['0', '-5', 'abc', '1e3'] as $installation) {
            $state = $this->start($this->owner);
            $this->actingAs($this->owner)->get('/integrations/github/callback?'.http_build_query(['installation_id' => $installation, 'code' => 'c', 'state' => $state]))
                ->assertSessionHasErrors('github');
        }
        $state = $this->start($this->owner);
        $this->actingAs($this->owner)->get('/integrations/github/callback?'.http_build_query(['installation_id' => [$this->installation], 'code' => 'c', 'state' => $state]))
            ->assertSessionHasErrors('github');
        $this->start($this->owner);
        $this->actingAs($this->owner)->get('/integrations/github/callback?'.http_build_query(['installation_id' => $this->installation, 'code' => 'c', 'state' => ['a']]))
            ->assertRedirect(route('organizations.index'))->assertSessionHasErrors('github');
        $this->assertSame([], $this->github->calls());
    }

    public function test_github_must_answer_for_the_requested_installation(): void
    {
        $other = random_int(10_000_000, 99_999_999);
        $this->github->respondInstallation($this->installation, new GitHubInstallation($other, 777, 'evil', 'Organization', false));
        $this->github->grantUser('mismatch', [$this->installation]);

        $this->setupCallback($this->owner, $this->start($this->owner), 'mismatch')->assertSessionHasErrors('github');
        $this->assertSame([], $this->actingAs($this->owner)->getJson($this->api(''))->json('data.installations'));
    }

    public function test_permission_lost_while_talking_to_github_links_nothing(): void
    {
        $admin = $this->member('org_admin');
        $membership = $this->tenant->runFor($this->owner, $this->organization, fn () => (string) DB::selectOne(
            'select id::text as id from identity.organization_memberships where user_id = ?', [$admin->getKey()]
        )->id);
        $this->github->grantUser('revoked', [$this->installation]);
        $state = $this->start($admin);
        $this->github->beforeCall(function (string $call) use ($membership) {
            if (str_starts_with($call, 'repositories:')) {
                $this->tenant->runFor($this->owner, $this->organization, fn () => $this->app->make(RevokeMembership::class)->handle($this->owner, $membership));
            }
        });

        $this->setupCallback($admin, $state, 'revoked')->assertSessionHasErrors('github');
        $this->github->beforeCall(null);
        $this->assertSame([], $this->actingAs($this->owner)->getJson($this->api(''))->json('data.installations'));
    }

    public function test_github_failure_midway_links_nothing_and_logs_no_code(): void
    {
        $log = sys_get_temp_dir().'/orbixa-setup-'.bin2hex(random_bytes(4)).'.log';
        config(['logging.default' => 'single', 'logging.channels.single.path' => $log]);
        Log::forgetChannel('single');
        $code = 'code-'.bin2hex(random_bytes(8));
        $this->github->grantUser($code, [$this->installation]);
        $this->github->beforeCall(function (string $call) {
            if (str_starts_with($call, 'repositories:')) {
                throw GitHubApiFailure::status('repositories', 502);
            }
        });

        $this->setupCallback($this->owner, $this->start($this->owner), $code)->assertSessionHasErrors('github');
        $this->github->beforeCall(null);
        $this->assertSame([], $this->actingAs($this->owner)->getJson($this->api(''))->json('data.installations'));
        $written = is_file($log) ? (string) file_get_contents($log) : '';
        @unlink($log);
        $this->assertStringContainsString('GitHub call failed (repositories, HTTP 502)', $written);
        $this->assertStringNotContainsString($code, $written);
    }

    public function test_read_only_roles_and_foreign_organizations_are_denied(): void
    {
        $this->github->grantUser('ok', [$this->installation]);
        $this->setupCallback($this->owner, $this->start($this->owner), 'ok');
        $repository = $this->actingAs($this->owner)->getJson($this->api(''))->json('data.repositories.0.id');
        $this->actingAs($this->owner)->putJson($this->api("repositories/{$repository}/selection"))->assertNoContent();

        foreach (['viewer', 'reviewer', 'project_manager'] as $role) {
            $member = $this->member($role);
            $this->actingAs($member)->getJson($this->api(''))->assertForbidden();
            $this->actingAs($member)->postJson($this->api('setup'))->assertForbidden();
            $this->actingAs($member)->putJson($this->api("repositories/{$repository}/selection"))->assertForbidden();
            $this->actingAs($member)->deleteJson($this->api("repositories/{$repository}/selection"))->assertForbidden();
        }
        $rival = $this->user();
        $rivalOrganization = $this->app->make(CreateOrganization::class)->handle($rival, 'Rival');
        $this->actingAs($rival)->deleteJson($this->api("repositories/{$repository}/selection", $rivalOrganization))->assertNotFound();
        $this->assertTrue($this->actingAs($this->owner)->getJson($this->api(''))->json('data.repositories.0.selected'));
    }

    public function test_pending_approval_still_requires_manage(): void
    {
        $developer = $this->member('developer');
        $this->actingAs($developer)->withSession([SetupState::SESSION_KEY => [
            'organization' => $this->organization->value, 'user' => (string) $developer->getKey(), 'hash' => hash('sha256', 'crafted'), 'expires_at' => time() + 60,
        ]]);
        $this->actingAs($developer)->get('/integrations/github/callback?'.http_build_query(['state' => 'crafted', 'setup_action' => 'request']))
            ->assertSessionHasErrors('github')->assertSessionMissing('status');
    }

    public function test_setup_is_throttled(): void
    {
        config(['github.setup_per_minute' => 2]);
        $this->actingAs($this->owner)->postJson($this->api('setup'))->assertOk();
        $this->actingAs($this->owner)->postJson($this->api('setup'))->assertOk();
        $this->actingAs($this->owner)->postJson($this->api('setup'))->assertStatus(429);
        $this->actingAs($this->owner)->get('/integrations/github/callback')->assertStatus(429);
    }

    private function start(User $actor, ?OrganizationId $organization = null): string
    {
        $url = (string) $this->actingAs($actor)->postJson($this->api('setup', $organization))->assertOk()->json('data.redirect_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    private function setupCallback(User $actor, string $state, string $code, ?int $installation = null, string $setupAction = 'install'): TestResponse
    {
        return $this->actingAs($actor)->get('/integrations/github/callback?'.http_build_query([
            'installation_id' => $installation ?? $this->installation, 'setup_action' => $setupAction, 'code' => $code, 'state' => $state,
        ]));
    }

    private function api(string $path, ?OrganizationId $organization = null): string
    {
        return rtrim('/api/v1/organizations/'.($organization ?? $this->organization)->value.'/integrations/github/'.$path, '/');
    }

    /** @return list<string> */
    private function events(string $aggregateId): array
    {
        return $this->tenant->runFor($this->owner, $this->organization, fn () => array_column(DB::select(
            'select event_name from events.domain_outbox where aggregate_id = ? order by occurred_at, event_id', [$aggregateId]
        ), 'event_name'));
    }

    private function member(string $role): User
    {
        $user = $this->user();
        $ticket = $this->tenant->runFor($this->owner, $this->organization, fn () => $this->app->make(InviteMember::class)->handle($this->owner, $user->email, $role))->ticket;
        $this->app->make(AcceptInvitation::class)->handle($user, $this->organization, $ticket->reveal());

        return $user;
    }

    private function user(): User
    {
        return User::factory()->create(['email' => Str::uuid().'@example.test']);
    }
}
