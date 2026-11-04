<?php

namespace Modules\Work\Tests\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\AcceptInvitation;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Application\InviteMember;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Contracts\Tenancy\TenantContext;
use Modules\Platform\Tests\Security\SecurityTestCase;
use Modules\Portfolio\Application\AddProjectMember;
use Modules\Portfolio\Application\CreateProject;
use Modules\Work\Application\AssignWorkItem;
use Modules\Work\Application\CreateWorkItem;

/**
 * 03 increment 7: minimal work screens (W11) with the API's rules; the
 * acceptance path "create and finish a real task" through the UI.
 */
final class WorkScreensTest extends SecurityTestCase
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
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Screens Work');
    }

    public function test_a_developer_creates_and_finishes_a_task_from_the_screens(): void
    {
        $developer = $this->member('developer');
        $project = $this->as($this->owner, function () use ($developer) {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'UIX', 'Interface');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($developer), 'manager');

            return $project;
        });
        $this->actingAs($developer);

        $this->get($this->url("projects/{$project}/backlog"))->assertOk()->assertSee('Nuevo elemento');
        $response = $this->post($this->url("projects/{$project}/backlog"), ['type' => 'task', 'title' => 'Screen task', 'priority' => 'high']);
        $response->assertRedirect();
        $itemUrl = (string) $response->headers->get('Location');
        $this->get($itemUrl)->assertOk()->assertSee('UIX-1')->assertSee('Screen task')->assertSee('Cambiar estado');

        $item = (string) $this->as($this->owner, fn () => DB::selectOne("select id::text as id from work.items where key = 'UIX-1'")->id);
        $this->from($itemUrl)->post($this->url("items/{$item}/transitions"), ['expected_version' => 0, 'to' => 'done'])
            ->assertRedirect($itemUrl)->assertSessionHasErrors('to');
        foreach (['ready', 'active', 'review', 'done'] as $version => $status) {
            $this->post($this->url("items/{$item}/transitions"), ['expected_version' => $version, 'to' => $status])->assertRedirect();
        }
        $this->get($itemUrl)->assertOk()->assertSee('Hecho')->assertSee('review → done');
        $this->get($this->url("projects/{$project}/backlog"))->assertSee('UIX-1');
    }

    public function test_screens_hide_what_the_user_cannot_see_or_do(): void
    {
        $viewer = $this->member('viewer');
        $developer = $this->member('developer');
        [$project, $hidden, $mine] = $this->as($this->owner, function () use ($viewer, $developer) {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'VIS', 'Visible');
            $hidden = $this->app->make(CreateProject::class)->handle($this->owner, 'HID', 'Hidden');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($viewer));
            $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($developer));
            $mine = $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => 'Assigned to dev']);
            $this->app->make(AssignWorkItem::class)->assign($this->owner, $mine, $this->membershipId($developer), 'owner');
            $hiddenItem = $this->app->make(CreateWorkItem::class)->handle($this->owner, $hidden, ['type' => 'task', 'title' => 'Hidden task']);

            return [$project, $hiddenItem, $mine];
        });

        $this->actingAs($viewer)->get($this->url("projects/{$project}/backlog"))->assertOk()->assertSee('Assigned to dev')->assertDontSee('Nuevo elemento');
        $this->get($this->url("items/{$mine}"))->assertOk()->assertDontSee('Cambiar estado');
        $this->get($this->url("items/{$hidden}"))->assertNotFound();
        $this->post($this->url("projects/{$project}/backlog"), ['type' => 'task', 'title' => 'Nope', 'priority' => 'none'])->assertForbidden();

        $this->actingAs($developer)->get($this->url('my-work'))->assertOk()->assertSee('Assigned to dev')->assertDontSee('Hidden task');
    }

    private function url(string $path): string
    {
        return "/o/{$this->organization->value}/{$path}";
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
