<?php

declare(strict_types=1);

namespace Flowpack\OpenSearch\ContentRepositoryAdaptor\Service;

/*
 * This file is part of the Flowpack.OpenSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;

/**
 * @TODO: Unused? Can be removed?
 * Index Workspace Trait
 */
trait IndexWorkspaceTrait
{
    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Factory\NodeFactory
     */
    protected $nodeFactory;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Service\ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Service\ContentDimensionCombinator
     */
    protected $contentDimensionCombinator;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Search\Indexer\NodeIndexingManager
     */
    protected $nodeIndexingManager;

    /**
     * @param string $workspaceName
     * @param int $limit
     * @param callable $callback
     * @return int Count of nodes indexed
     */
    protected function indexWorkspace(string $workspaceName, ?int $limit = null, callable $callback = null): int
    {
        $count = 0;
        $combinations = $this->contentDimensionCombinator->getAllAllowedCombinations();
        if ($combinations === []) {
            $count += $this->indexWorkspaceWithDimensions($workspaceName, [], $limit, $callback);
        } else {
            foreach ($combinations as $combination) {
                $count += $this->indexWorkspaceWithDimensions($workspaceName, $combination, $limit, $callback);
            }
        }

        return $count;
    }

    /**
     * @param string $workspaceName
     * @param array $dimensions
     * @param int $limit
     * @param callable $callback
     * @return int
     */
    protected function indexWorkspaceWithDimensions(string $workspaceName, array $dimensions = [], ?int $limit = null, callable $callback = null): int
    {
        $context = $this->contextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => $dimensions,
            'invisibleContentShown' => true
        ]);
        $rootNode = $context->getRootNode();
        $indexedNodes = 0;

        $traverseNodes = function (NodeInterface $currentNode, &$indexedNodes) use ($limit, &$traverseNodes) {
            if ($limit !== null && $indexedNodes > $limit) {
                return;
            }
            $this->nodeIndexingManager->indexNode($currentNode);
            $indexedNodes++;
            array_map(function (NodeInterface $childNode) use ($traverseNodes, &$indexedNodes) {
                $traverseNodes($childNode, $indexedNodes);
            }, $currentNode->getChildNodes());
        };

        $traverseNodes($rootNode, $indexedNodes);

        $this->nodeFactory->reset();
        $context->getFirstLevelNodeCache()->flush();

        if ($callback !== null) {
            $callback($workspaceName, $indexedNodes, $dimensions);
        }

        return $indexedNodes;
    }
}
