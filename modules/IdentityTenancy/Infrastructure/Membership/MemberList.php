<?php

namespace Modules\IdentityTenancy\Infrastructure\Membership;

use Illuminate\Database\DatabaseManager;

/**
 * Members of the ACTIVE organization (RLS scopes the memberships; users are
 * only reached through those memberships, never listed globally).
 */
final class MemberList
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @return list<array{id: string, name: string, email: string, joined_at: string}>
     */
    public function active(): array
    {
        $members = [];
        foreach ($this->db->connection()->select(
            "select m.id::text as id, u.name, u.email, m.joined_at::text as joined_at
               from identity.organization_memberships m
               join identity.users u on u.id = m.user_id
              where m.status = 'active'
              order by u.name, m.id"
        ) as $row) {
            $columns = (array) $row;
            $members[] = [
                'id' => (string) $columns['id'],
                'name' => (string) $columns['name'],
                'email' => (string) $columns['email'],
                'joined_at' => (string) $columns['joined_at'],
            ];
        }

        return $members;
    }

    public function organizationName(): ?string
    {
        $row = $this->db->connection()->selectOne('select name from identity.organizations limit 1');

        return $row === null ? null : (string) ((array) $row)['name'];
    }
}
