<?php

namespace Modules\GitHubIntegration\Infrastructure\Setup;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Modules\Platform\Contracts\Tenancy\OrganizationId;

/**
 * The `state` of an App installation (G-C): 32 random bytes, bound to the
 * session, the user and the organization, valid ten minutes, single use.
 * Only its hash is kept, in the session; the callback pulls it whatever the
 * outcome, so a state can never be replayed. A new setup replaces a pending
 * one, and so does any request to the callback (accepted: it cannot link
 * anything, it can only cancel a setup in progress).
 */
final class SetupState
{
    public const SESSION_KEY = 'orbixa.github_setup';

    private const TTL_SECONDS = 600;

    public function issue(Session $session, Authenticatable $user, OrganizationId $organization): string
    {
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session->put(self::SESSION_KEY, [
            'organization' => $organization->value,
            'user' => (string) $user->getAuthIdentifier(),
            'hash' => hash('sha256', $state),
            'expires_at' => time() + self::TTL_SECONDS,
        ]);

        return $state;
    }

    /** The organization the state was issued for, or null if it is missing, foreign, expired or reused. */
    public function consume(Session $session, ?Authenticatable $user, mixed $state): ?OrganizationId
    {
        $pending = $session->pull(self::SESSION_KEY);
        if ($user === null || ! is_string($state) || $state === '' || ! is_array($pending)
            || ($pending['user'] ?? null) !== (string) $user->getAuthIdentifier()
            || ! is_string($pending['hash'] ?? null) || ! is_string($pending['organization'] ?? null)
            || ! is_int($pending['expires_at'] ?? null) || $pending['expires_at'] < time()
            || ! hash_equals($pending['hash'], hash('sha256', $state))) {
            return null;
        }

        try {
            return OrganizationId::fromString($pending['organization']);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
