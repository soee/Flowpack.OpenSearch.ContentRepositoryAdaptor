<?php

declare(strict_types=1);

namespace Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\Version6;

/*
 * This file is part of the Flowpack.OpenSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Indexer\NodeIndexer;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\AbstractIndexerDriver;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\IndexerDriverInterface;
use Flowpack\OpenSearch\Domain\Model\Document as OpenSearchDocument;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Log\Utility\LogEnvironment;

/**
 * Indexer driver for Elasticsearch version 6.x
 *
 * @Flow\Scope("singleton")
 */
class IndexerDriver extends AbstractIndexerDriver implements IndexerDriverInterface
{

    /**
     * {@inheritdoc}
     */
    public function document(string $indexName, NodeInterface $node, OpenSearchDocument $document, array $documentData): array
    {
        if ($this->isFulltextRoot($node)) {
            // for fulltext root documents, we need to preserve the "neos_fulltext" field. That's why we use the
            // "update" API instead of the "index" API, with a custom script internally; as we
            // shall not delete the "neos_fulltext" part of the document if it has any.
            return [
                [
                    'update' => [
                        '_id' => $document->getId(),
                        '_index' => $indexName,
                        'retry_on_conflict' => 3
                    ]
                ],
                // https://www.elastic.co/guide/en/elasticsearch/reference/5.0/docs-update.html
                [
                    'script' => [
                        'lang' => 'painless',
                        'source' => '
                            HashMap fulltext = (ctx._source.containsKey("neos_fulltext") && ctx._source.neos_fulltext instanceof Map ? ctx._source.neos_fulltext : new HashMap());
                            HashMap fulltextParts = (ctx._source.containsKey("neos_fulltext_parts") && ctx._source.neos_fulltext_parts instanceof Map ? ctx._source.neos_fulltext_parts : new HashMap());
                            ctx._source = params.newData;
                            ctx._source.neos_fulltext = fulltext;
                            ctx._source.neos_fulltext_parts = fulltextParts',
                        'params' => [
                            'newData' => $documentData
                        ]
                    ],
                    'upsert' => $documentData
                ]
            ];
        }

        // non-fulltext-root documents can be indexed as-they-are
        return [
            [
                'index' => [
                    '_id' => $document->getId(),
                    '_index' => $indexName,
                ]
            ],
            $documentData
        ];
    }

    /**
     * {@inheritdoc}
     * @param NodeInterface $node
     * @param array $fulltextIndexOfNode
     * @param string|null $targetWorkspaceName
     * @return array
     */
    public function fulltext(NodeInterface $node, array $fulltextIndexOfNode, string $targetWorkspaceName = null): array
    {
        $closestFulltextNode = $this->findClosestFulltextRoot($node);
        if ($closestFulltextNode === null) {
            return [];
        }

        $closestFulltextNodeDocumentIdentifier = $this->documentIdentifierGenerator->generate($closestFulltextNode, $targetWorkspaceName);

        if ($closestFulltextNode->isRemoved()) {
            // fulltext root is removed, abort silently...
            $this->logger->debug(sprintf('NodeIndexer (%s): Fulltext root found for %s (%s) not updated, it is removed', $closestFulltextNodeDocumentIdentifier, $node->getPath(), $node->getIdentifier()), LogEnvironment::fromMethodName(__METHOD__));

            return [];
        }

        $upsertFulltextParts = [];
        if (!empty($fulltextIndexOfNode)) {
            $upsertFulltextParts[$node->getIdentifier()] = $fulltextIndexOfNode;
        }

        return [
            [
                'update' => [
                    '_id' => $closestFulltextNodeDocumentIdentifier
                ]
            ],
            // https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-update.html
            [
                // first, update the neos_fulltext_parts, then re-generate the neos_fulltext from all neos_fulltext_parts
                'script' => [
                    'lang' => 'painless',
                    'source' => '
                        ctx._source.neos_fulltext = new HashMap();
                        if (!ctx._source.containsKey("neos_fulltext_parts") || !(ctx._source.neos_fulltext_parts instanceof Map)) {
                            ctx._source.neos_fulltext_parts = new HashMap();
                        }

                        if (params.nodeIsRemoved || params.nodeIsHidden || params.fulltext.size() == 0) {
                            if (ctx._source.neos_fulltext_parts.containsKey(params.identifier)) {
                                ctx._source.neos_fulltext_parts.remove(params.identifier);
                            }
                        } else {
                            ctx._source.neos_fulltext_parts.put(params.identifier, params.fulltext);
                        }

                        for (fulltextPart in ctx._source.neos_fulltext_parts.entrySet()) {
                            for (entry in fulltextPart.getValue().entrySet()) {
                                def value = "";
                                if (ctx._source.neos_fulltext.containsKey(entry.getKey())) {
                                    value = ctx._source.neos_fulltext[entry.getKey()] + " " + entry.getValue().trim();
                                } else {
                                    value = entry.getValue().trim();
                                }
                                ctx._source.neos_fulltext[entry.getKey()] = value;
                            }
                        }',
                    'params' => [
                        'identifier' => $node->getIdentifier(),
                        'nodeIsRemoved' => $node->isRemoved(),
                        'nodeIsHidden' => $node->isHidden(),
                        'fulltext' => $fulltextIndexOfNode
                    ],
                ],
                'upsert' => [
                    'neos_fulltext' => $fulltextIndexOfNode,
                    'neos_fulltext_parts' => $upsertFulltextParts
                ]
            ]
        ];
    }
}
