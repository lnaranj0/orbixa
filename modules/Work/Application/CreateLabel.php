<?php

namespace Modules\Work\Application;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Platform\Contracts\Validation\InputRejected;
use Modules\Work\Infrastructure\ItemStore;

/** Labels belong to one project and are managed by whoever edits its work (W4). */
final class CreateLabel
{
    public function __construct(
        private readonly WorkGuard $guard,
        private readonly ItemStore $items,
    ) {}

    public function handle(Authenticatable $actor, string $projectId, string $name, string $color = '#64748b'): string
    {
        $actorMembershipId = $this->guard->context($actor);
        $project = $this->guard->project($actor, $projectId);
        $this->guard->requireProjectEdit($actor, $project);

        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 50) {
            throw InputRejected::because('Label name must be 1-50 characters.');
        }
        if (preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
            throw InputRejected::because('Label color must be #rrggbb in lowercase.');
        }

        return $this->items->createLabel($project->id, $name, $color, $actorMembershipId);
    }
}
