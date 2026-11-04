<?php

namespace Modules\Portfolio\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Contracts\Events\ActorReference;
use Modules\Platform\Contracts\Events\DomainEvent;
use Modules\Platform\Contracts\Events\DomainEventRecorder;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Portfolio\Infrastructure\ProjectStore;

/**
 * Creates a project in the active organization; the creator becomes its
 * manager. A taken key fails with the same generic error whether or not the
 * existing project is visible to the actor (D11; throttled at the route).
 */
final class CreateProject
{
    public const KEY_PATTERN = '/^[A-Z][A-Z0-9]{1,9}$/';

    public const TYPES = ['general', 'software', 'hybrid'];

    public const CONFIDENTIALITY = ['standard', 'restricted'];

    public function __construct(
        private readonly ProjectGuard $guard,
        private readonly ProjectStore $projects,
        private readonly DomainEventRecorder $events,
    ) {}

    public function handle(
        Authenticatable $actor,
        string $key,
        string $name,
        string $type = 'general',
        string $confidentiality = 'standard',
        ?string $description = null,
    ): string {
        $actorMembershipId = $this->guard->context($actor);
        $this->guard->requireCreate($actor);

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw InputRejected::because('Project key must be 2-10 uppercase letters or digits, starting with a letter.');
        }
        $name = ProjectInput::name($name);
        ProjectInput::oneOf('type', $type, self::TYPES);
        ProjectInput::oneOf('confidentiality', $confidentiality, self::CONFIDENTIALITY);
        $description = ProjectInput::description($description);

        $projectId = $this->projects->create($key, $name, $type, $confidentiality, $description, $actorMembershipId);
        $this->projects->addMember($projectId, $actorMembershipId, 'manager', $actorMembershipId);

        $this->events->record(
            new DomainEvent('project.created', 1, 'project', $projectId, [
                'type' => $type,
                'confidentiality' => $confidentiality,
            ]),
            ActorReference::human((string) $actor->getAuthIdentifier()),
        );

        return $projectId;
    }
}
