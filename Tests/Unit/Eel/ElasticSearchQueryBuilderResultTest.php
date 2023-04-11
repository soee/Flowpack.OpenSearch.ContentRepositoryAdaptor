<?php
declare(strict_types=1);

namespace Flowpack\OpenSearch\ContentRepositoryAdaptor\Tests\Unit\Eel;

/*
 * This file is part of the Flowpack.OpenSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\OpenSearch\ContentRepositoryAdaptor\Eel\OpenSearchQuery;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Eel\OpenSearchQueryResult;
use Neos\Flow\Tests\UnitTestCase;

/**
 * Testcase for OpenSearchQueryResult
 */
class OpenSearchQueryResultResultTest extends UnitTestCase
{
    /**
     * @test
     */
    public function ifNoAggregationsAreSetInTheQueyBuilderResultAnEmptyArrayWillBeReturnedIfYouFetchTheAggregations(): void
    {
        $resultArrayWithoutAggregations = [
            'nodes' => ['some', 'nodes']
        ];

        $queryBuilder = $this->getMockBuilder(OpenSearchQueryResult::class)->setMethods(['fetch'])->getMock();
        $queryBuilder->method('fetch')->willReturn($resultArrayWithoutAggregations);

        $esQuery = new OpenSearchQuery($queryBuilder);

        $queryResult = new OpenSearchQueryResult($esQuery);

        $actual = $queryResult->getAggregations();

        static::assertIsArray($actual);
        static::assertEmpty($actual);
    }
}
