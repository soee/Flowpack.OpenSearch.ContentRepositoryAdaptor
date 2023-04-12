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
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\RequestDriverInterface;
use Flowpack\OpenSearch\Domain\Model\Index;
use Neos\Flow\Annotations as Flow;

/**
 * Request driver for Elasticsearch version 6.x
 *
 * @Flow\Scope("singleton")
 */
class RequestDriver extends AbstractDriver implements RequestDriverInterface
{
    /**
     * {@inheritdoc}
     * @throws \Flowpack\OpenSearch\Exception
     * @throws \Neos\Flow\Http\Exception
     */
    public function bulk(Index $index, $request): array
    {
        if (is_array($request)) {
            $request = json_encode($request);
        }

        // Bulk request MUST end with line return
        $request = trim($request) . "\n";
        return $index->request('POST', '/_bulk', [], $request)->getTreatedContent();
    }
}
