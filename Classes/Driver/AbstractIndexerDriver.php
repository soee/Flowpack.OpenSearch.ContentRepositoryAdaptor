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

use Flowpack\OpenSearch\ContentRepositoryAdaptor\Service\DocumentIdentifier\DocumentIdentifierGeneratorInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;

/**
 * Abstract Fulltext Indexer Driver
 */
abstract class AbstractIndexerDriver extends AbstractDriver
{
    /**
     * @Flow\Inject
     * @var NodeTypeMappingBuilderInterface
     */
    protected $nodeTypeMappingBuilder;

    /**
     * @Flow\Inject
     * @var DocumentIdentifierGeneratorInterface
     */
    protected $documentIdentifierGenerator;

    /**
     * Whether the node is configured as fulltext root.
     *
     * @param NodeInterface $node
     * @return bool
     */
    protected function isFulltextRoot(NodeInterface $node): bool
    {
        if ($node->getNodeType()->hasConfiguration('search')) {
            $openSearchSettingsForNode = $node->getNodeType()->getConfiguration('search');
            if (isset($openSearchSettingsForNode['fulltext']['isRoot']) && $openSearchSettingsForNode['fulltext']['isRoot'] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param NodeInterface $node
     * @return NodeInterface|null
     */
    protected function findClosestFulltextRoot(NodeInterface $node): ?NodeInterface
    {
        $closestFulltextNode = $node;
        while (!$this->isFulltextRoot($closestFulltextNode)) {
            $closestFulltextNode = $closestFulltextNode->getParent();
            if ($closestFulltextNode === null) {
                // root of hierarchy, no fulltext root found anymore, abort silently...
                if ($node->getPath() !== '/' && $node->getPath() !== '/sites') {
                    $this->logger->warning(sprintf('NodeIndexer: No fulltext root found for node %s', (string)$node), LogEnvironment::fromMethodName(__METHOD__));
                }

                return null;
            }
        }

        return $closestFulltextNode;
    }
}
