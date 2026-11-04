<?php

namespace Modules\IdentityTenancy\Tests\Security;

use Illuminate\Support\Str;
use Modules\IdentityTenancy\Application\CreateOrganization;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;
use Modules\Platform\Contracts\Tenancy\OrganizationId;
use Modules\Platform\Tests\Security\SecurityTestCase;

/**
 * 01 increment 6, end to end over HTTP on real PostgreSQL: minimal login,
 * active organization, uniform 404, 403, invitations and revocation.
 */
final class HttpFlowsTest extends SecurityTestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_are_sent_to_login_and_can_sign_in(): void
    {
        $user = $this->user();

        $this->get('/organizations')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Iniciar sesión');

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertRedirect('/organizations');
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_fails_and_login_is_throttled(): void
    {
        $user = $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_creating_an_organization_opens_it(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->post('/organizations', ['name' => 'Acme HTTP']);

        $response->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Acme HTTP');
    }

    public function test_foreign_unknown_and_malformed_organizations_get_the_same_404(): void
    {
        $owner = $this->user();
        $foreign = $this->organizationOf($owner, 'Foreign');
        $outsider = $this->user();

        $foreignResponse = $this->actingAs($outsider)->get("/o/{$foreign->value}");
        $unknownResponse = $this->actingAs($outsider)->get('/o/'.Str::uuid());
        $malformedResponse = $this->actingAs($outsider)->get('/o/not-a-uuid');

        $foreignResponse->assertNotFound();
        $unknownResponse->assertNotFound();
        $malformedResponse->assertNotFound();
        $this->assertSame($unknownResponse->getContent(), $foreignResponse->getContent());
        $this->assertStringNotContainsString('Foreign', (string) $foreignResponse->getContent());
    }

    public function test_switching_organization_regenerates_the_session(): void
    {
        $user = $this->user();
        $organization = $this->organizationOf($user, 'Switchable');
        $this->actingAs($user)->get('/organizations')->assertOk();
        $before = session()->getId();

        $this->post('/organizations/switch', ['organization' => $organization->value])
            ->assertRedirect("/o/{$organization->value}");

        $this->assertNotSame($before, session()->getId());
        $this->assertSame($organization->value, session(ActiveOrganization::SESSION_KEY));
    }

    public function test_member_without_permission_gets_403_on_members_page(): void
    {
        $owner = $this->user();
        $organization = $this->organizationOf($owner, 'Perms');
        $developer = $this->joinViaHttp($owner, $organization, 'developer');

        $this->actingAs($developer)->get("/o/{$organization->value}")->assertOk();
        $this->actingAs($developer)->get("/o/{$organization->value}/members")->assertForbidden();
        $this->actingAs($developer)->post("/o/{$organization->value}/members/invitations", ['email' => 'x@example.test', 'role' => 'viewer'])->assertForbidden();
    }

    public function test_invitation_link_token_is_moved_out_of_the_url(): void
    {
        $owner = $this->user();
        $organization = $this->organizationOf($owner, 'Links');
        $invitee = $this->user();
        $link = $this->inviteViaHttp($owner, $organization, $invitee->email, 'viewer');

        $response = $this->actingAs($invitee)->get($link);

        $response->assertRedirect("/invitations/{$organization->value}/accept");
        $this->assertStringNotContainsString('token=', (string) $response->headers->get('Location'));
    }

    public function test_full_invitation_flow_and_revocation_over_http(): void
    {
        $owner = $this->user();
        $organization = $this->organizationOf($owner, 'Flow');
        $member = $this->joinViaHttp($owner, $organization, 'viewer');

        $this->actingAs($member)->get("/o/{$organization->value}")->assertOk()->assertSee('Flow');

        $membershipId = $this->membershipIdOnPage($owner, $organization, $member);
        $this->actingAs($owner)->post("/o/{$organization->value}/members/{$membershipId}/revoke")->assertRedirect();

        $this->actingAs($member)->withSession([ActiveOrganization::SESSION_KEY => $organization->value])
            ->get("/o/{$organization->value}")->assertNotFound();
        $this->assertNull(session(ActiveOrganization::SESSION_KEY), 'The revoked organization is dropped from the session.');
    }

    public function test_rejected_invitation_shows_one_generic_message(): void
    {
        $owner = $this->user();
        $organization = $this->organizationOf($owner, 'Generic');
        $link = $this->inviteViaHttp($owner, $organization, 'someone-else-'.Str::uuid().'@example.test', 'viewer');
        $intruder = $this->user();

        $this->actingAs($intruder)->get($link);
        $this->post("/invitations/{$organization->value}/accept")
            ->assertRedirect('/organizations')
            ->assertSessionHasErrors(['invitation' => 'This invitation is not valid.']);
    }

    private function user(): User
    {
        return User::factory()->create(['email' => Str::uuid().'@example.test', 'password' => self::PASSWORD]);
    }

    private function organizationOf(User $owner, string $name): OrganizationId
    {
        return $this->app->make(CreateOrganization::class)->handle($owner, $name);
    }

    private function inviteViaHttp(User $owner, OrganizationId $organization, string $email, string $role): string
    {
        $this->actingAs($owner)
            ->post("/o/{$organization->value}/members/invitations", ['email' => $email, 'role' => $role])
            ->assertSessionHas('invitation_link');

        return (string) session('invitation_link');
    }

    private function joinViaHttp(User $owner, OrganizationId $organization, string $role): User
    {
        $member = $this->user();
        $link = $this->inviteViaHttp($owner, $organization, $member->email, $role);

        $this->actingAs($member)->get($link)->assertRedirect();
        $this->get("/invitations/{$organization->value}/accept")->assertOk();
        $this->post("/invitations/{$organization->value}/accept")->assertRedirect("/o/{$organization->value}");

        return $member;
    }

    private function membershipIdOnPage(User $owner, OrganizationId $organization, User $member): string
    {
        $html = (string) $this->actingAs($owner)->get("/o/{$organization->value}/members")->assertOk()->assertSee($member->email)->getContent();
        preg_match_all('#/members/([0-9a-f-]{36})/revoke#', $html, $matches);

        foreach ($matches[1] as $candidate) {
            if (str_contains($html, $member->email)) {
                $row = substr($html, (int) strpos($html, $member->email));
                if (str_contains(substr($row, 0, 2000), $candidate)) {
                    return $candidate;
                }
            }
        }

        $this->fail('Revoke action for the member not found on the page.');
    }
}
