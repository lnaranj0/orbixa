<?php

namespace Modules\Platform\Contracts\Http;

/**
 * Opaque, tamper-proof keyset cursors for paginated APIs (D10). A cursor
 * only carries the last row's sort position; the visibility predicate and
 * filters are re-applied on every page, so a forged position cannot widen
 * what a caller sees, and the signature rejects edited cursors anyway.
 */
interface CursorCodec
{
    /**
     * @param  string  $resource  binds the cursor to one listing (and its filters)
     * @param  list<string>  $position  sort values of the last row
     */
    public function encode(string $resource, array $position): string;

    /**
     * @return list<string>
     *
     * @throws InvalidCursor
     */
    public function decode(string $resource, string $cursor): array;
}
