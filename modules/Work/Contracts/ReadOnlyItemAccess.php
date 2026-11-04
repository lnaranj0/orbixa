<?php

namespace Modules\Work\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Read-only access to work items granted outside project visibility
 * (04 M2: monitoring grants). Work asks it only on WRITE paths, for an item
 * the actor cannot see through its project: a provider that covers the item
 * turns the 404 into a 403 and records the refused attempt. It never grants
 * reads or writes inside Work.
 */
interface ReadOnlyItemAccess
{
    public const TAG = 'orbixa.work.read-only-item-access';

    /** True when the actor may only read this item; the refused write is audited by the provider. */
    public function refuseWrite(Authenticatable $actor, string $itemId): bool;
}
