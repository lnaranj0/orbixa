<?php

namespace Modules\Work\Contracts\Workflow;

/** 05 P3: Workflow asks Work whether a status still holds items before archiving it. */
interface StatusUsage
{
    public function hasItems(string $statusId): bool;
}
