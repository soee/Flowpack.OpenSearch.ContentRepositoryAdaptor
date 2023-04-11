<?php

declare(strict_types=1);

namespace Flowpack\OpenSearch\ContentRepositoryAdaptor\Factory;

/*
 * This file is part of the Flowpack.OpenSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\QueryInterface;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Exception\ConfigurationException;
use Neos\Flow\Annotations as Flow;

/**
 * A factory for creating the OpenSearch Query
 *
 * @Flow\Scope("singleton")
 */
class QueryFactory extends AbstractDriverSpecificObjectFactory
{
    /**
     * @return QueryInterface
     * @throws ConfigurationException
     */
    public function createQuery(): QueryInterface
    {
        return $this->resolve('query');
    }
}
