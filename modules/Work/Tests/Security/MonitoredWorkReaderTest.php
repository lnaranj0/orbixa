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
use Modules\Portfolio\Contracts\Catalog\ProjectCatalog;
use Modules\Teams\Application\CreateTeam;
use Modules\Work\Application\AssignWorkItem;
use Modules\Work\Application\CreateWorkItem;
use Modules\Work\Application\LinkItemTeam;
use Modules\Work\Contracts\Monitoring\ItemSelector;
use Modules\Work\Contracts\Monitoring\MonitoredItem;
use Modules\Work\Contracts\Monitoring\MonitoredWorkReader;

/**
 * 04 increment 5: the trusted monitoring reader returns exactly what its
 * selector covers (M6, M7), and the project catalog splits by
 * confidentiality.
 */
final class MonitoredWorkReaderTest extends SecurityTestCase
{
    private TenantContext $tenant;

    private User $owner;

    private OrganizationId $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->app->make(TenantContext::class);
        $this->owner = $this->user();
        $this->organization = $this->app->make(CreateOrganization::class)->handle($this->owner, 'Reader Inc');
    }

    public function test_selector_clauses_and_their_union(): void
    {
        $observed = $this->member('developer');

        $this->as($this->owner, function () use ($observed) {
            $standard = $this->app->make(CreateProject::class)->handle($this->owner, 'STD', 'Standard');
            $restricted = $this->app->make(CreateProject::class)->handle($this->owner, 'RST', 'Restricted', 'general', 'restricted');
            foreach ([$standard, $restricted] as $project) {
                $this->app->make(AddProjectMember::class)->handle($this->owner, $project, $this->membershipId($observed));
            }
            $team = $this->app->make(CreateTeam::class)->handle($this->owner, 'Observed');
            $create = fn (string $project, string $title) => $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => $title, 'description' => "About {$title}"]);
            $assigned = $create($standard, 'Assigned');
            $assignedRestricted = $create($restricted, 'Assigned restricted');
            $teamItem = $create($standard, 'Team item');
            $create($standard, 'Unrelated');
            $this->app->make(AssignWorkItem::class)->assign($this->owner, $assigned, $this->membershipId($observed), 'owner');
            $this->app->make(AssignWorkItem::class)->assign($this->owner, $assignedRestricted, $this->membershipId($observed));
            $this->app->make(LinkItemTeam::class)->link($this->owner, $teamItem, $team);

            $catalog = $this->app->make(ProjectCatalog::class)->idsByConfidentiality();
            $this->assertSame([[$standard], [$restricted]], [$catalog['standard'], $catalog['restricted']]);

            $reader = $this->app->make(MonitoredWorkReader::class);
            $keys = fn (ItemSelector $s) => $this->keys($reader->page($s, [], null, 50));

            $this->assertSame([], $keys(ItemSelector::nothing()));
            $this->assertSame(0, $reader->counts(ItemSelector::nothing())['total']);
            $this->assertSame(['STD-1', 'STD-2', 'STD-3'], $keys(ItemSelector::nothing()->with(ItemSelector::ALL, [$standard])));
            $this->assertSame(['STD-1'], $keys(ItemSelector::nothing()->with(ItemSelector::ASSIGNEES, [$standard], [$this->membershipId($observed)])));
            $this->assertSame(['RST-1', 'STD-1'], $keys(ItemSelector::nothing()->with(ItemSelector::ASSIGNEES, [$standard, $restricted], [$this->membershipId($observed)])));
            $this->assertSame(['STD-2'], $keys(ItemSelector::nothing()->with(ItemSelector::TEAMS, [$standard], [$team])));
            $union = ItemSelector::nothing()
                ->with(ItemSelector::ASSIGNEES, [$standard], [$this->membershipId($observed)])
                ->with(ItemSelector::TEAMS, [$standard], [$team]);
            $this->assertSame(['STD-1', 'STD-2'], $keys($union));
            // A clause without targets or projects selects nothing.
            $this->assertTrue(ItemSelector::nothing()->with(ItemSelector::TEAMS, [$standard], [])->isEmpty());
            $this->assertTrue(ItemSelector::nothing()->with(ItemSelector::ALL, [])->isEmpty());

            $counts = $reader->counts($union);
            $this->assertSame(2, $counts['total']);
            $this->assertSame(['backlog' => 2], $counts['by_status']);

            $this->assertSame('About Assigned', $reader->find($union, $assigned)?->description);
            $this->assertNull($reader->find($union, $assignedRestricted));
            $this->assertTrue($reader->covers($union, $teamItem));
            $this->assertFalse($reader->covers($union, $assignedRestricted));
            $this->assertNull($reader->page($union, [], null, 50)[0]->description, 'Pages never carry descriptions.');

            $this->assertSame($union->fingerprint(), ItemSelector::nothing()
                ->with(ItemSelector::TEAMS, [$standard], [$team])
                ->with(ItemSelector::ASSIGNEES, [$standard], [$this->membershipId($observed)])->fingerprint());
        });
    }

    public function test_foreign_ids_in_a_selector_return_nothing(): void
    {
        [$project, $item] = $this->as($this->owner, function () {
            $project = $this->app->make(CreateProject::class)->handle($this->owner, 'OWN', 'Own');

            return [$project, $this->app->make(CreateWorkItem::class)->handle($this->owner, $project, ['type' => 'task', 'title' => 'Private'])];
        });

        $stranger = $this->user();
        $other = $this->app->make(CreateOrganization::class)->handle($stranger, 'Other Inc');
        $this->tenant->runFor($stranger, $other, function () use ($project, $item) {
            $selector = ItemSelector::nothing()->with(ItemSelector::ALL, [$project, (string) Str::uuid()]);
            $reader = $this->app->make(MonitoredWorkReader::class);
            $this->assertSame([], $reader->page($selector, [], null, 10));
            $this->assertSame(0, $reader->counts($selector)['total']);
            $this->assertNull($reader->find($selector, $item));
            $this->assertSame(['standard' => [], 'restricted' => []], $this->app->make(ProjectCatalog::class)->idsByConfidentiality());
        });
    }

    /**
     * @param  list<MonitoredItem>  $items
     * @return list<string>
     */
    private function keys(array $items): array
    {
        $keys = array_map(fn (MonitoredItem $i) => $i->key, $items);
        sort($keys);

        return $keys;
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
        return (string) DB::selectOne('select id::text as id from identity.organization_memberships where user_id = ?', [$user->getKey()])->id;
    }

    private function events(string $name, string $aggregateId): int
    {
        return (int) DB::selectOne('select count(*) as total from events.domain_outbox where event_name = ? and aggregate_id = ?', [$name, $aggregateId])->total;
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    private function assertFailsWith(string $expected, \Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail("Expected {$expected}.");
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($expected, $exception, $exception->getMessage());
        }
    }
}
