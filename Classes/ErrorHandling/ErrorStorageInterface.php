<?php

declare(strict_types=1);

namespace Flowpack\OpenSearch\ContentRepositoryAdaptor\ErrorHandling;

/*
 * This file is part of the Flowpack.OpenSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

interface ErrorStorageInterface
{
    /**
     * Write the error message and return a short info for the log
     *
     * @param array $errorResult
     * @return string Information about the logged OpenSearch Error
     */
    public function logErrorResult(array $errorResult): string;
}
