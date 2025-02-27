<?php

declare(strict_types=1);

namespace Flowpack\OpenSearch\ContentRepositoryAdaptor;

/*
 * This file is part of the Flowpack.OpenSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\OpenSearch\ContentRepositoryAdaptor\Exception\ConfigurationException;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Service\DimensionsService;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Service\IndexNameStrategyInterface;
use Flowpack\OpenSearch\Domain\Model\Client;
use Flowpack\OpenSearch\Domain\Model\Index;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;

/**
 * The OpenSearch client to be used by the content repository adapter.
 *
 * Used to:
 *
 * - make the OpenSearch Client globally available
 * - allow to access the index to be used for reading/writing in a global way
 *
 * @Flow\Scope("singleton")
 */
class OpenSearchClient extends Client
{
    /**
     * @var IndexNameStrategyInterface
     * @Flow\Inject
     */
    protected $indexNameStrategy;

    /**
     * @var DimensionsService
     * @Flow\Inject
     */
    protected $dimensionsService;

    /**
     * @var string
     */
    protected $dimensionsHash;

    /**
     * @var NodeInterface
     */
    protected $contextNode;

    /**
     * @return NodeInterface
     */
    public function getContextNode(): NodeInterface
    {
        return $this->contextNode;
    }

    /**
     * @param NodeInterface $contextNode
     */
    public function setContextNode(NodeInterface $contextNode): void
    {
        $this->setDimensions($contextNode->getContext()->getTargetDimensions());
        $this->contextNode = $contextNode;
    }

    /**
     * @param array $dimensionValues
     */
    public function setDimensions(array $dimensionValues = []): void
    {
        $this->dimensionsHash = $this->dimensionsService->hash($dimensionValues);
    }

    /**
     * @return string
     */
    public function getDimensionsHash(): string
    {
        return $this->dimensionsHash;
    }

    /**
     * @param \Closure $closure
     * @param array $dimensionValues
     * @throws \Exception
     */
    public function withDimensions(\Closure $closure, array $dimensionValues = []): void
    {
        $previousDimensionHash = $this->dimensionsHash;
        try {
            $this->setDimensions($dimensionValues);
            $closure();
        } finally {
            $this->dimensionsHash = $previousDimensionHash;
        }
    }

    /**
     * Get the index name to be used
     *
     * @return string
     * @throws ConfigurationException
     * @todo Add a constraints, if the system use content dimensions, the dimensionsHash MUST be set
     */
    public function getIndexName(): string
    {
        $name = $this->getIndexNamePrefix();
        if ($this->dimensionsHash !== null) {
            $name .= '-' . $this->dimensionsHash;
        }
        return $name;
    }

    /**
     * @return string
     * @throws ConfigurationException
     */
    public function getIndexNamePrefix(): string
    {
        $name = trim($this->indexNameStrategy->get());
        if ($name === '') {
            throw new ConfigurationException('IndexNameStrategy ' . get_class($this->indexNameStrategy) . ' returned an empty index name', 1582538800);
        }

        return $name;
    }

    /**
     * Retrieve the index to be used for querying or on-the-fly indexing.
     * In OpenSearch, this index is an *alias* to the currently used index.
     *
     * @return \Flowpack\OpenSearch\Domain\Model\Index
     * @throws Exception
     * @throws \Flowpack\OpenSearch\Exception
     * @throws ConfigurationException
     */
    public function getIndex(): Index
    {
        return $this->findIndex($this->getIndexName());
    }
}
