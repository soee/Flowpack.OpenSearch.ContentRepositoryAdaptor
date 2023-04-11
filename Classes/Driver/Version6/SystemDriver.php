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
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\SystemDriverInterface;
use Neos\Flow\Annotations as Flow;

/**
 * System driver for Elasticsearch version 6.x
 *
 * @Flow\Scope("singleton")
 */
class SystemDriver extends AbstractDriver implements SystemDriverInterface
{
    /**
     * @inheritDoc
     */
    public function status(): array
    {
        return $this->searchClient->request('GET', '/_stats')->getTreatedContent();
    }
}
