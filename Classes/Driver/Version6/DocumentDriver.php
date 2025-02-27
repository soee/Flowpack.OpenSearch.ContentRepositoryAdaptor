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

use Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\AbstractDriver;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\DocumentDriverInterface;
use Flowpack\OpenSearch\Domain\Model\Index;
use Flowpack\OpenSearch\Domain\Model\Mapping;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;

/**
 * Document driver for Elasticsearch version 6.x
 *
 * @Flow\Scope("singleton")
 */
class DocumentDriver extends AbstractDriver implements DocumentDriverInterface
{
    /**
     * {@inheritdoc}
     */
    public function delete(NodeInterface $node, string $identifier): array
    {
        return [
            [
                'delete' => [
                    '_type' =>'_doc',
                    '_id' => $identifier
                ]
            ]
        ];
    }
}
