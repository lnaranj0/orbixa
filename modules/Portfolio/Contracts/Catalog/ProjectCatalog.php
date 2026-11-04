<?php

namespace Modules\Portfolio\Contracts\Catalog;

/**
 * TRUSTED project inventory of the active organization, without an actor.
 * Only the Monitoring module may depend on it (deptrac layer
 * PortfolioCatalogContracts): it splits projects by confidentiality so
 * grants can apply their visibility level. Must run inside a tenant context.
 */
interface ProjectCatalog
{
    /**
     * @return array{standard: list<string>, restricted: list<string>}
     */
    public function idsByConfidentiality(): array;
}
