<?php

namespace Modules\Reporting\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Portfolio\Application\CreateProject;
use Modules\Work\Application\CreateWorkItem;

/**
 * 05 increment 11: workflow, planning and metrics screens.
 */
final class WorkflowPlanningScreensTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Screens Flow');
    }

    public function test_screens_render_inside_visibility(): void
    {
        $outsider = $this->member('developer');
        $project = $this->as($this->owner, function () {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'SCR', 'Screens');
            $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => 'First']);

            return $project;
        });
        $base = "/o/{$this->organization->value}";

        $this->actingAs($this->owner);
        $this->get("{$base}/projects/{$project}/workflow")->assertOk()->assertSee('En revisión')->assertSee('Requiere aprobación');
        $this->get("{$base}/projects/{$project}/planning")->assertOk()->assertSee('Flujo continuo');
        $this->get("{$base}/reports/flow?project_id={$project}")->assertOk()->assertSee('Throughput');
        $this->get("{$base}/projects/{$project}")->assertOk()->assertSee('Workflow')->assertSee('Planificación');

        $this->actingAs($outsider);
        $this->get("{$base}/projects/{$project}/workflow")->assertNotFound();
        $this->get("{$base}/projects/{$project}/planning")->assertNotFound();
        $this->get("{$base}/reports/flow?project_id={$project}")->assertNotFound();
        $this->get("{$base}/reports/flow")->assertOk()->assertSee('Throughput');
        $this->assertSame(1, $this->as($this->owner, fn () => (int) DB::selectOne('select count(*) as total from work.items')->total));
    }

    private function as(User $actor, \Closure $callback): mixed
    {
        return $this->tenant->runFor($actor, $this->organization, $callback);
    }

    private function user(): User
    {
        return User::factory()->create(['email' => Str::uuid().'@example.test']);
    }

    private function member(string $role): User
    {
        $user = $this->user();
        $ticket = $this->as($this->owner, fn () => $this->app->make(InviteMember::class)->handle($this->owner, $user->email, $role))->ticket;
        $this->app->make(AcceptInvitation::class)->handle($user, $this->organization, $ticket->reveal());

        return $user;
    }

    private function membershipId(User $user): string
    {
        return $this->as($this->owner, fn () => (string) DB::selectOne(
            'select id::text as id from identity.organization_memberships where user_id = ?',
            [$user->getKey()]
        )->id);
    }
}
