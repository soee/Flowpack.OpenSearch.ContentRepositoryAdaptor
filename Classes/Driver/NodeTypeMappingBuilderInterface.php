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

use Flowpack\OpenSearch\Domain\Model\Index;
use Flowpack\OpenSearch\Mapping\MappingCollection;
use Neos\Error\Messages\Result;

/**
 * NodeTypeMappingBuilder Interface
 */
interface NodeTypeMappingBuilderInterface
{
    /**
     * Builds a Mapping Collection from the configured node types
     *
     * @param Index $index
     * @return MappingCollection<\Flowpack\OpenSearch\Domain\Model\Mapping>
     */
    public function buildMappingInformation(Index $index): MappingCollection;

    /**
     * @return Result
     */
    public function getLastMappingErrors(): Result;
}
