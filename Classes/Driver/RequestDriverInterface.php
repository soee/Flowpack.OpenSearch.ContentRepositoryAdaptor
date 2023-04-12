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

use Flowpack\OpenSearch\Domain\Model\Index;

/**
 * OpenSearch Request Driver Interface
 */
interface RequestDriverInterface
{
    /**
     * Execute a bulk request
     *
     * @param Index $index
     * @param array|string $request an array or a raw JSON request payload
     * @return array An array of respones per batch entry.
     */
    public function bulk(Index $index, $request): array;
}
