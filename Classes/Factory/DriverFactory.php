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

use Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\DocumentDriverInterface;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\IndexDriverInterface;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\IndexerDriverInterface;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\RequestDriverInterface;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\SystemDriverInterface;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Exception\ConfigurationException;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class DriverFactory extends AbstractDriverSpecificObjectFactory
{
    /**
     * @return DocumentDriverInterface
     * @throws ConfigurationException
     */
    public function createDocumentDriver(): DocumentDriverInterface
    {
        return $this->resolve('document');
    }

    /**
     * @return IndexerDriverInterface
     * @throws ConfigurationException
     */
    public function createIndexerDriver(): IndexerDriverInterface
    {
        return $this->resolve('indexer');
    }

    /**
     * @return IndexDriverInterface
     * @throws ConfigurationException
     */
    public function createIndexManagementDriver(): IndexDriverInterface
    {
        return $this->resolve('indexManagement');
    }

    /**
     * @return RequestDriverInterface
     * @throws ConfigurationException
     */
    public function createRequestDriver(): RequestDriverInterface
    {
        return $this->resolve('request');
    }

    /**
     * @return SystemDriverInterface
     * @throws ConfigurationException
     */
    public function createSystemDriver(): SystemDriverInterface
    {
        return $this->resolve('system');
    }
}
