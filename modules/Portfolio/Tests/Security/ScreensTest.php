<?php

namespace Modules\Portfolio\Tests\Security;

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
use Modules\Teams\Application\AssignTeamMember;
use Modules\Teams\Application\CreateTeam;

/**
 * 02 increment 7b: minimal screens (D18) apply the same visibility rules as
 * the API.
 */
final class ScreensTest extends SecurityTestCase
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
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Screens Inc');
    }

    public function test_project_screens_show_only_visible_projects(): void
    {
        $developer = $this->member('developer');
        [$visible, $hidden] = $this->as($this->owner, function () use ($developer) {
            $visible = $this->app->make(CreateProject::class)->handle($this->owner, 'VIS', 'Visible project');
            $hidden = $this->app->make(CreateProject::class)->handle($this->owner, 'HID', 'Hidden project', 'general', 'restricted');
            $this->app->make(AddProjectMember::class)->handle($this->owner, $visible, $this->membershipId($developer));

            return [$visible, $hidden];
        });

        $this->actingAs($developer)->get($this->url('projects'))
            ->assertOk()->assertSee('Visible project')->assertDontSee('Hidden project')->assertDontSee('Nuevo proyecto');
        $this->get($this->url("projects/{$visible}"))->assertOk()->assertSee('VIS');
        $this->get($this->url("projects/{$hidden}"))->assertNotFound();
        $this->get($this->url('projects?cursor=forged.cursor'))->assertStatus(422);

        $this->actingAs($this->owner)->get($this->url('projects'))->assertOk()->assertSee('Hidden project')->assertSee('Nuevo proyecto');
    }

    public function test_creating_a_project_from_the_form(): void
    {
        $this->actingAs($this->owner)
            ->post($this->url('projects'), ['key' => 'FRM', 'name' => 'From form', 'type' => 'software', 'confidentiality' => 'standard'])
            ->assertRedirect();
        $this->get($this->url('projects'))->assertSee('From form');

        $this->from($this->url('projects'))
            ->post($this->url('projects'), ['key' => 'FRM', 'name' => 'Again', 'type' => 'general', 'confidentiality' => 'standard'])
            ->assertRedirect($this->url('projects'))->assertSessionHasErrors(['key' => 'This project key cannot be used.']);

        $developer = $this->member('developer');
        $this->actingAs($developer)->post($this->url('projects'), ['key' => 'DEV', 'name' => 'Rogue', 'type' => 'general', 'confidentiality' => 'standard'])->assertForbidden();
    }

    public function test_team_screen_follows_d15(): void
    {
        $developer = $this->member('developer');
        $this->as($this->owner, function () use ($developer) {
            $mine = $this->app->make(CreateTeam::class)->handle($this->owner, 'Mine');
            $this->app->make(CreateTeam::class)->handle($this->owner, 'Not mine');
            $this->app->make(AssignTeamMember::class)->handle($this->owner, $mine, $this->membershipId($developer));
        });

        $this->actingAs($developer)->get($this->url('teams'))->assertOk()->assertSee('Mine')->assertDontSee('Not mine')->assertDontSee('Nuevo equipo');
        $this->post($this->url('teams'), ['name' => 'Rogue'])->assertForbidden();

        $this->actingAs($this->owner)->get($this->url('teams'))->assertSee('Not mine')->assertSee('Nuevo equipo');
        $this->post($this->url('teams'), ['name' => 'From form'])->assertRedirect($this->url('teams'));
        $this->get($this->url('teams'))->assertSee('From form');
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
