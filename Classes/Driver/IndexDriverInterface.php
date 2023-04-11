<?php
declare(strict_types=1);

namespace Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver;

/*
 * This file is part of the Flowpack.OpenSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

/**
 * OpenSearch Index Driver Interface
 */
interface IndexDriverInterface
{

    /**
     * Get the list of Indexes attached to the given alias
     *
     * @param string $alias
     * @return array
     */
    public function getIndexNamesByAlias(string $alias): array;

    /**
     * Get the list of Indexes attached to the given alias prefix
     *
     * @param string $prefix
     * @return array
     */
    public function getIndexNamesByPrefix(string $prefix): array;

    /**
     * Remove alias by name
     *
     * @param string $index
     * @return void
     */
    public function deleteIndex(string $index): void;

    /**
     * Execute batch aliases actions
     *
     * @param array $actions
     * @return mixed
     */
    public function aliasActions(array $actions);
}
