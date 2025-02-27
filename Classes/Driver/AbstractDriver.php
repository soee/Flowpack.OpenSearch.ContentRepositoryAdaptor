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

use Flowpack\OpenSearch\ContentRepositoryAdaptor\OpenSearchClient;
use Psr\Log\LoggerInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Abstract Opensearch driver
 */
abstract class AbstractDriver
{
    /**
     * @Flow\Inject
     * @var OpenSearchClient
     */
    protected $searchClient;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;
}
