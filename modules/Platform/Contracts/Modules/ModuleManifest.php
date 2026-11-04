<?php

namespace Modules\Platform\Contracts\Modules;

/**
 * Declares the database resources a module owns (spec "Ownership por
 * dominio"). Every table in the database must be claimed by exactly one
 * manifest; other modules reach those tables only through contracts.
 */
interface ModuleManifest
{
    public const TAG = 'orbixa.module-manifests';

    public function module(): string;

    /**
     * @return list<string> schema-qualified table names, e.g. "platform.tenant_probe"
     */
    public function ownedTables(): array;
}
