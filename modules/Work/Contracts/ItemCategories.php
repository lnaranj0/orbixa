<?php

namespace Modules\Work\Contracts;

/**
 * Current status category of items another module already references
 * (05 P9: Planning closing a cycle). Ids only in, categories only out;
 * unknown or foreign ids are simply absent (RLS).
 */
interface ItemCategories
{
    /**
     * @param  list<string>  $itemIds
     * @return array<string, string> item id => category
     */
    public function of(array $itemIds): array;
}
