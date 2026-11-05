<?php

namespace Modules\GitHubIntegration\Application;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\GitHubIntegration\Infrastructure\InstallationRouter;
use Modules\Platform\Contracts\Tenancy\IntegrationPrincipal;
use Modules\Platform\Contracts\Tenancy\TenantAccessDenied;
use Modules\Platform\Contracts\Tenancy\TenantContext;

/**
 * Takes a webhook whose signature is already verified (G3, G-A, G-B).
 *
 * The organization comes only from an active linked installation, never from
 * the payload. Within the integration's tenant context the global delivery
 * register and the inbox row are written in one transaction, so a failure
 * leaves nothing behind and GitHub's redelivery is processed normally.
 * Anything not addressed to a selected repository of a linked installation
 * is registered as ignored and its payload is not stored anywhere.
 */
final class ReceiveWebhook
{
    /** Events about a repository: stored only for a selected one. */
    private const REPOSITORY_EVENTS = ['pull_request', 'push', 'check_run', 'check_suite', 'repository'];

    /** Events about the installation itself (repository list, suspension, removal). */
    private const INSTALLATION_EVENTS = ['installation', 'installation_repositories'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TenantContext $tenant,
        private readonly InstallationRouter $router,
    ) {}

    /** @param array<mixed> $payload decoded from $body */
    public function handle(string $deliveryId, string $event, string $body, array $payload): WebhookOutcome
    {
        $hash = hash('sha256', $body);
        $installation = $payload['installation'] ?? null;
        $installationExternalId = is_array($installation) && is_int($installation['id'] ?? null) && $installation['id'] > 0 ? $installation['id'] : null;
        $repository = $payload['repository'] ?? null;
        $repositoryExternalId = is_array($repository) && is_int($repository['id'] ?? null) ? $repository['id'] : null;
        $action = is_string($payload['action'] ?? null) && preg_match('/^[a-z_]{1,64}$/', $payload['action']) === 1 ? $payload['action'] : null;

        $repositoryScoped = in_array($event, self::REPOSITORY_EVENTS, true);
        $handled = ($repositoryScoped && $repositoryExternalId !== null) || in_array($event, self::INSTALLATION_EVENTS, true);
        $organization = $handled && $installationExternalId !== null ? $this->router->organizationFor($installationExternalId) : null;
        // jsonb rejects \u0000 although PHP accepts it: storing such a payload
        // would fail on every redelivery, so it is ignored up front.
        if ($organization === null || str_contains($body, '\\u0000')) {
            return $this->ignore($deliveryId, $hash, $event);
        }

        try {
            return $this->tenant->runForIntegration(
                IntegrationPrincipal::github($installationExternalId),
                $organization,
                function () use ($deliveryId, $hash, $event, $action, $body, $installationExternalId, $repositoryScoped, $repositoryExternalId): WebhookOutcome {
                    $target = $this->db->selectOne(
                        'select i.id::text as installation_id, r.id::text as repository_id
                           from integrations.github_installations i
                           left join integrations.github_repositories r
                             on r.organization_id = i.organization_id and r.installation_id = i.id
                            and r.repository_external_id = ? and r.selected_at is not null and r.available
                          where i.installation_external_id = ? and i.status = \'active\'',
                        [$repositoryExternalId ?? 0, $installationExternalId]
                    );
                    $accepted = $target !== null && (! $repositoryScoped || $target->repository_id !== null);
                    if (! $this->register($deliveryId, $hash, $event, $accepted ? WebhookOutcome::Accepted : WebhookOutcome::Ignored)) {
                        return WebhookOutcome::Duplicate;
                    }
                    if (! $accepted) {
                        return WebhookOutcome::Ignored;
                    }
                    $this->db->insert(
                        'insert into integrations.webhook_inbox (organization_id, installation_id, repository_id, delivery_id, event, action, payload)
                         values (platform.current_organization_id(), ?, ?, ?, ?, ?, ?::jsonb)',
                        [$target->installation_id, $repositoryScoped ? $target->repository_id : null, $deliveryId, $event, $action, $body]
                    );

                    return WebhookOutcome::Accepted;
                }
            );
        } catch (TenantAccessDenied) {
            // The installation stopped being active between routing and the transaction.
            return $this->ignore($deliveryId, $hash, $event);
        } catch (QueryException $exception) {
            throw WebhookIntakeFailed::sqlState((string) ($exception->errorInfo[0] ?? 'unknown'));
        }
    }

    private function ignore(string $deliveryId, string $hash, string $event): WebhookOutcome
    {
        try {
            $registered = $this->register($deliveryId, $hash, $event, WebhookOutcome::Ignored);
        } catch (QueryException $exception) {
            throw WebhookIntakeFailed::sqlState((string) ($exception->errorInfo[0] ?? 'unknown'));
        }

        return $registered
            ? WebhookOutcome::Ignored
            : WebhookOutcome::Duplicate;
    }

    /** False when the delivery id or the body was already registered. */
    private function register(string $deliveryId, string $hash, string $event, WebhookOutcome $outcome): bool
    {
        return $this->db->affectingStatement(
            "insert into integrations.webhook_deliveries (delivery_id, body_sha256, event, outcome)
             values (?, decode(?, 'hex'), ?, ?) on conflict do nothing",
            [$deliveryId, $hash, $event, $outcome->value]
        ) === 1;
    }
}
