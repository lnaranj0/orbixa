<?php

namespace Modules\GitHubIntegration\Tests\Security;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Portfolio\Application\CreateProject;

/**
 * 06 increment 2: integrations schema as the runtime role (G1, G3, G-C, G-D).
 */
final class IntegrationsSchemaTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Integrations Schema');
    }

    public function test_installation_belongs_to_a_single_organization_and_is_isolated(): void
    {
        $external = random_int(1_000_000, 9_999_999);
        $this->inOrg($this->owner, $this->organization, fn () => $this->installation(DB::connection(), $external));

        $stranger = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other');
        $this->inOrg($stranger, $other, function () use ($external) {
            $this->assertSame(0, (int) DB::selectOne('select count(*) as total from integrations.github_installations')->total);
            // The same GitHub installation cannot be claimed by a second organization.
            $this->assertSqlState('23505', fn (Connection $db) => $this->installation($db, $external));
        });
    }

    public function test_repositories_links_and_inbox_invariants(): void
    {
        $this->inOrg($this->owner, $this->organization, function () {
            $installation = $this->installation(DB::connection(), random_int(1_000_000, 9_999_999));
            $owner = $this->ownerMembership();
            $repository = (string) DB::selectOne(
                "insert into integrations.github_repositories (organization_id, installation_id, repository_external_id, full_name, is_private)
                 values (platform.current_organization_id(), ?, 42, 'acme/api', true) returning id::text as id",
                [$installation]
            )->id;
            $this->assertSqlState('23505', fn (Connection $db) => $db->insert(
                "insert into integrations.github_repositories (organization_id, installation_id, repository_external_id, full_name, is_private)
                 values (platform.current_organization_id(), ?, 42, 'acme/renamed', true)",
                [$installation]
            ));
            $this->assertSqlState('23514', fn (Connection $db) => $db->update('update integrations.github_repositories set selected_at = now() where id = ?', [$repository]));

            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'GHI', 'GitHub');
            $link = fn (Connection $db) => $db->insert(
                'insert into integrations.repository_project_links (organization_id, repository_id, project_id, linked_by_membership_id)
                 values (platform.current_organization_id(), ?, ?, ?)',
                [$repository, $project, $owner]
            );
            $link(DB::connection());
            $this->assertSqlState('23505', $link);

            // A link is unlinked once and never reopened or rewritten.
            $linkId = (string) DB::selectOne('select id::text as id from integrations.repository_project_links where repository_id = ? and project_id = ?', [$repository, $project])->id;
            $this->assertSqlState('23514', fn (Connection $db) => $db->update(
                "update integrations.repository_project_links set unlinked_at = linked_at - interval '1 day', unlinked_by_membership_id = ? where id = ?", [$owner, $linkId]
            ));
            DB::update('update integrations.repository_project_links set unlinked_at = now(), unlinked_by_membership_id = ? where id = ?', [$owner, $linkId]);
            $this->assertSqlState('23514', fn (Connection $db) => $db->update(
                'update integrations.repository_project_links set unlinked_at = null, unlinked_by_membership_id = null where id = ?', [$linkId]
            ));
            $this->assertSqlState('23514', fn (Connection $db) => $db->update(
                'update integrations.repository_project_links set unlinked_at = now() + interval \'1 hour\' where id = ?', [$linkId]
            ));
            // Once unlinked, the pair can be linked again as a new row.
            $link(DB::connection());
            $this->assertSqlState('23503', fn (Connection $db) => $db->insert(
                'insert into integrations.repository_project_links (organization_id, repository_id, project_id, linked_by_membership_id)
                 values (platform.current_organization_id(), ?, ?, ?)',
                [$repository, (string) Str::uuid(), $owner]
            ));

            $inbox = fn (Connection $db, string $delivery, string $payload = '{"action":"opened"}') => $db->insert(
                "insert into integrations.webhook_inbox (organization_id, installation_id, repository_id, delivery_id, event, action, payload)
                 values (platform.current_organization_id(), ?, ?, ?, 'pull_request', 'opened', ?::jsonb)",
                [$installation, $repository, $delivery, $payload]
            );
            $inbox(DB::connection(), 'd-1');
            $this->assertSqlState('23505', fn (Connection $db) => $inbox($db, 'd-1'));
            $this->assertSqlState('23514', fn (Connection $db) => $inbox($db, 'bad id!'));
            $this->assertSqlState('23514', fn (Connection $db) => $inbox($db, 'd-2', '[]'));
            $this->assertSqlState('23514', fn (Connection $db) => $db->update(
                "update integrations.webhook_inbox set payload_purged_at = now() where delivery_id = 'd-1'"
            ));
            DB::update("update integrations.webhook_inbox set payload = '{}'::jsonb, payload_purged_at = now() where delivery_id = 'd-1'");

            foreach (['github_installations', 'github_repositories', 'repository_project_links', 'webhook_inbox'] as $table) {
                $this->assertSqlState('42501', fn (Connection $db) => $db->delete("delete from integrations.{$table}"));
            }
            $this->assertSqlState('42501', fn (Connection $db) => $db->update('update integrations.github_installations set installation_external_id = 1'));
            $this->assertSqlState('42501', fn (Connection $db) => $db->update('update integrations.github_repositories set repository_external_id = 1'));
            $this->assertSqlState('42501', fn (Connection $db) => $db->update("update integrations.webhook_inbox set delivery_id = 'x'"));
        });
    }

    public function test_global_delivery_register_holds_no_tenant_data_and_is_append_only(): void
    {
        $columns = array_column(DB::select(
            "select column_name from information_schema.columns where table_schema = 'integrations' and table_name = 'webhook_deliveries' order by ordinal_position"
        ), 'column_name');
        // No installation id: it maps 1:1 to an organization (review finding 3).
        $this->assertSame(['delivery_id', 'body_sha256', 'event', 'outcome', 'received_at'], $columns);

        $insert = fn (Connection $db, string $delivery, string $body) => $db->insert(
            "insert into integrations.webhook_deliveries (delivery_id, body_sha256, event, outcome) values (?, decode(?, 'hex'), 'ping', 'accepted')",
            [$delivery, hash('sha256', $body)]
        );
        $insert(DB::connection(), 'g-'.Str::random(8), 'body-a-'.Str::random(8));
        $body = 'body-b-'.Str::random(8);
        $insert(DB::connection(), 'g-'.Str::random(8), $body);
        $this->assertSqlState('23505', fn (Connection $db) => $insert($db, 'g-'.Str::random(8), $body)); // same body, new delivery id
        $this->assertSqlState('42501', fn (Connection $db) => $db->select('select delivery_id from integrations.webhook_deliveries limit 1'));
        $this->assertSqlState('42501', fn (Connection $db) => $db->update("update integrations.webhook_deliveries set outcome = 'ignored'"));
        $this->assertSqlState('42501', fn (Connection $db) => $db->delete('delete from integrations.webhook_deliveries'));
    }

    private function installation(Connection $db, int $external): string
    {
        return (string) $db->selectOne(
            "insert into integrations.github_installations (organization_id, installation_external_id, account_login, account_type, linked_by_membership_id)
             values (platform.current_organization_id(), ?, 'acme', 'Organization',
                     (select id from identity.organization_memberships limit 1)) returning id::text as id",
            [$external]
        )->id;
    }

    private function ownerMembership(): string
    {
        return (string) DB::selectOne('select id::text as id from identity.organization_memberships where user_id = ?', [$this->owner->getKey()])->id;
    }

    private function inOrg(User $actor, OrganizationId $organization, \Closure $callback): mixed
    {
        return $this->tenant->runFor($actor, $organization, $callback);
    }

    private function user(): User
    {
        return User::factory()->create(['email' => Str::uuid().'@example.test']);
    }
}
