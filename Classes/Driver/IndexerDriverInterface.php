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

use Flowpack\OpenSearch\Domain\Model\Document as OpenSearchDocument;
use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 * Indexer Driver Interface
 */
interface IndexerDriverInterface
{
    /**
     * Generate the query to index the document properties
     *
     * @param string $indexName
     * @param NodeInterface $node
     * @param OpenSearchDocument $document
     * @param array $documentData
     * @return array
     */
    public function document(string $indexName, NodeInterface $node, OpenSearchDocument $document, array $documentData): array;

    /**
     * Generate the query to index the fulltext of the document
     *
     * @param NodeInterface $node
     * @param array $fulltextIndexOfNode
     * @param string $targetWorkspaceName
     * @return array
     */
    public function fulltext(NodeInterface $node, array $fulltextIndexOfNode, string $targetWorkspaceName = null): array;
}
