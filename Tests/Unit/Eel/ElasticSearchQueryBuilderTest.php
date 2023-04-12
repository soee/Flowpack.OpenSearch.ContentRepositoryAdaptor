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

use Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\Version6\Query\FilteredQuery;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Eel\OpenSearchQueryResult;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\OpenSearchClient;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Exception\QueryBuildingException;
use Neos\Flow\Tests\UnitTestCase;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Service\Context;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Testcase for OpenSearchQueryResult
 */
class OpenSearchQueryResultTest extends UnitTestCase
{
    protected OpenSearchQueryResult $queryBuilder;

    public function setUp(): void
    {
        /** @var NodeInterface|MockObject $node */
        $node = $this->createMock(NodeInterface::class);
        $node->method('getPath')->willReturn('/foo/bar');

        $mockContext = $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock();
        $mockContext->method('getDimensions')->willReturn([]);
        $node->method('getContext')->willReturn($mockContext);

        $mockWorkspace = $this->getMockBuilder(Workspace::class)->disableOriginalConstructor()->getMock();
        $mockContext->method('getWorkspace')->willReturn($mockWorkspace);

        $mockWorkspace->method('getName')->willReturn('user-foo');

        $openSearchClient = $this->createMock(OpenSearchClient::class);
        $openSearchClient->method('getContextNode')->willReturn($node);

        $this->queryBuilder = new OpenSearchQueryResult();

        $request = [
            'query' => [
                'bool' => [
                    'must' => [
                        ['match_all' => []]
                    ],
                    'filter' => [
                        'bool' => [
                            'must' => [],
                            'should' => [],
                            'must_not' => [
                                [
                                    'term' => ['neos_hidden' => true]
                                ],
                                [
                                    'range' => [
                                        'neos_hidden_before_datetime' => [
                                            'gt' => 'now'
                                        ]
                                    ]
                                ],
                                [
                                    'range' => [
                                        'neos_hidden_after_datetime' => [
                                            'lt' => 'now'
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'fields' => ['neos_path']
        ];
        $unsupportedFieldsInCountRequest = ['fields', 'sort', 'from', 'size', 'highlight', 'aggs', 'aggregations'];

        $queryStringParameters = [
            'fields' => ['neos_fulltext.h1^2']
        ];

        $this->inject($this->queryBuilder, 'openSearchClient', $openSearchClient);
        $this->inject($this->queryBuilder, 'request', new FilteredQuery($request, $unsupportedFieldsInCountRequest, $queryStringParameters));

        $query = new FilteredQuery($this->queryBuilder->getRequest()->toArray(), [], []);
        $this->inject($this->queryBuilder, 'request', $query);
        $this->queryBuilder->query($node);
    }

    /**
     * @test
     */
    public function basicRequestStructureTakesContextNodeIntoAccount(): void
    {
        $expected = [
            'query' => [
                'bool' => [
                    'must' => [
                        ['match_all' => []]
                    ],
                    'filter' => [
                        'bool' => [
                            'must' => [
                                0 => [
                                    'bool' => [
                                        'should' => [
                                            0 => [
                                                'term' => [
                                                    'neos_parent_path' => '/foo/bar'
                                                ]
                                            ],
                                            1 => [
                                                'term' => [
                                                    'neos_path' => '/foo/bar'
                                                ]
                                            ]
                                        ]
                                    ]
                                ],
                                1 => [
                                    'terms' => [
                                        'neos_workspace' => ['live', 'user-foo']
                                    ]
                                ]
                            ],
                            'should' => [],
                            'must_not' => [
                                // Filter out all hidden elements
                                [
                                    'term' => ['neos_hidden' => true]
                                ],
                                // if now < hiddenBeforeDateTime: HIDE
                                // -> hiddenBeforeDateTime > now
                                [
                                    'range' => [
                                        'neos_hidden_before_datetime' => [
                                            'gt' => 'now'
                                        ]
                                    ]
                                ],
                                [
                                    'range' => [
                                        'neos_hidden_after_datetime' => [
                                            'lt' => 'now'
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'fields' => ['neos_path']
        ];
        $actual = $this->queryBuilder->getRequest()->toArray();
        self::assertEquals($expected, $actual);
    }

    /**
     * @test
     */
    public function queryFilterThrowsExceptionOnInvalidClauseType(): void
    {
        $this->expectException(QueryBuildingException::class);
        $this->queryBuilder->queryFilter('foo', [], 'unsupported');
    }

    /**
     * @test
     */
    public function nodeTypeFilterWorks(): void
    {
        $this->queryBuilder->nodeType('Foo.Bar:Baz');
        $expected = [
            'term' => [
                'neos_type_and_supertypes' => 'Foo.Bar:Baz'
            ]
        ];
        $actual = $this->queryBuilder->getRequest()->toArray();
        self::assertInArray($expected, $actual['query']['bool']['filter']['bool']['must']);
    }

    /**
     * @test
     */
    public function sortAscWorks(): void
    {
        $this->queryBuilder->sortAsc('fieldName');
        $expected = [
            [
                'fieldName' => ['order' => 'asc']
            ]
        ];
        $actual = $this->queryBuilder->getRequest()->toArray();
        self::assertSame($expected, $actual['sort']);
    }

    /**
     * @test
     */
    public function sortingIsAdditive(): void
    {
        $this->queryBuilder->sortAsc('fieldName')->sortDesc('field2')->sortAsc('field3');
        $expected = [
            [
                'fieldName' => ['order' => 'asc']
            ],
            [
                'field2' => ['order' => 'desc']
            ],
            [
                'field3' => ['order' => 'asc']
            ]
        ];
        $actual = $this->queryBuilder->getRequest()->toArray();
        self::assertSame($expected, $actual['sort']);
    }

    /**
     * @test
     */
    public function limitWorks(): void
    {
        $this->queryBuilder->limit(2);
        $actual = $this->queryBuilder->getRequest()->toArray();
        self::assertSame(2, $actual['size']);
    }

    /**
     * @test
     */
    public function sortDescWorks(): void
    {
        $this->queryBuilder->sortDesc('fieldName');
        $expected = [
            [
                'fieldName' => ['order' => 'desc']
            ]
        ];
        $actual = $this->queryBuilder->getRequest()->toArray();
        self::assertSame($expected, $actual['sort']);
    }

    /**
     * @return array
     */
    public function rangeConstraintExamples(): array
    {
        return [
            ['greaterThan', 'gt', 10],
            ['greaterThanOrEqual', 'gte', 20],
            ['lessThan', 'lt', 'now'],
            ['lessThanOrEqual', 'lte', 40]
        ];
    }

    /**
     * @test
     * @dataProvider rangeConstraintExamples
     * @param string $constraint
     * @param string $operator
     * @param mixed $value
     */
    public function rangeConstraintsWork(string $constraint, string $operator, $value): void
    {
        $this->queryBuilder->$constraint('fieldName', $value);
        $expected = [
            'range' => [
                'fieldName' => [$operator => $value]
            ]
        ];
        $actual = $this->queryBuilder->getRequest()->toArray();
        self::assertInArray($expected, $actual['query']['bool']['filter']['bool']['must']);
    }

    /**
     * @return array
     */
    public function simpleAggregationExamples(): array
    {
        return [
            ['min', 'foo', 'bar', 10],
            ['terms', 'foo', 'bar', 10],
            ['sum', 'foo', 'bar', 10],
            ['stats', 'foo', 'bar', 10],
            ['value_count', 'foo', 'bar', 20]
        ];
    }

    /**
     * @test
     * @dataProvider simpleAggregationExamples
     *
     * @param string $type
     * @param string $name
     * @param string $field
     * @param int $size
     * @throws QueryBuildingException
     */
    public function anSimpleAggregationCanBeAddedToTheRequest(string $type, string $name, string $field, int $size): void
    {
        $expected = [
            $name => [
                $type => [
                    'field' => $field,
                    'size' => $size
                ]
            ]
        ];

        $this->queryBuilder->fieldBasedAggregation($name, $field, $type, '', $size);
        $actual = $this->queryBuilder->getRequest()->toArray();

        self::assertInArray($expected, $actual);
    }

    /**
     * @test
     * @throws QueryBuildingException
     */
    public function anAggregationCanBeSubbedUnderAPath(): void
    {
        $this->queryBuilder->fieldBasedAggregation('foo', 'bar');
        $this->queryBuilder->fieldBasedAggregation('bar', 'bar', 'terms', 'foo', 22);
        $this->queryBuilder->fieldBasedAggregation('baz', 'bar', 'terms', 'foo.bar');

        $expected = [
            'foo' => [
                'terms' => [
                    'field' => 'bar',
                ],
                'aggregations' => [
                    'bar' => [
                        'terms' => [
                            'field' => 'bar',
                            'size' => 22
                        ],
                        'aggregations' => [
                            'baz' => [
                                'terms' => [
                                    'field' => 'bar',
                                ],
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $actual = $this->queryBuilder->getRequest()->toArray();
        self::assertInArray($expected, $actual);
    }

    /**
     * @test
     */
    public function ifTheParentPathDoesNotExistAnExceptionisThrown(): void
    {
        $this->expectException(QueryBuildingException::class);

        $this->queryBuilder->fieldBasedAggregation('foo', 'bar');
        $this->queryBuilder->fieldBasedAggregation('bar', 'bar', 'terms', 'doesNotExist');
    }

    /**
     * @test
     */
    public function ifSubbedParentPathDoesNotExistAnExceptionisThrown(): void
    {
        $this->expectException(QueryBuildingException::class);

        $this->queryBuilder->fieldBasedAggregation('foo', 'bar');
        $this->queryBuilder->fieldBasedAggregation('bar', 'bar', 'terms', 'foo.doesNotExist');
    }

    /**
     * @test
     */
    public function aCustomAggregationDefinitionCanBeApplied(): void
    {
        $expected = [
            'foo' => [
                'some' => ['field' => 'bar'],
                'custom' => ['field' => 'bar'],
                'arrays' => ['field' => 'bar']
            ]
        ];

        $this->queryBuilder->aggregation('foo', $expected['foo']);
        $actual = $this->queryBuilder->getRequest()->toArray();

        self::assertInArray($expected, $actual);
    }

    /**
     * @test
     */
    public function requestCanBeExtendedByArbitraryProperties(): void
    {
        $this->queryBuilder->request('foo.bar', ['field' => 'x']);
        $expected = [
            'bar' => ['field' => 'x']
        ];
        $actual = $this->queryBuilder->getRequest();
        self::assertEquals($expected, $actual['foo']);
    }

    /**
     * @test
     */
    public function existingRequestPropertiesCanBeOverridden(): void
    {
        $this->queryBuilder->limit(2);
        $this->queryBuilder->request('limit', 10);
        $expected = 10;
        $actual = $this->queryBuilder->getRequest();
        self::assertEquals($expected, $actual['limit']);
    }

    /**
     * @test
     */
    public function getTotalItemsReturnsZeroByDefault(): void
    {
        self::assertSame(0, $this->queryBuilder->getTotalItems());
    }

    /**
     * @test
     */
    public function getTotalItemsReturnsTotalHitsIfItExists(): void
    {
        $this->inject($this->queryBuilder, 'result', ['hits' => ['total' => ['value' => 123]]]);
        self::assertSame(123, $this->queryBuilder->getTotalItems());
    }

    /**
     * Test helper
     *
     * @param $expected
     * @param $actual
     * @return void
     */
    protected static function assertInArray($expected, $actual): void
    {
        foreach ($actual as $actualElement) {
            if ($actualElement === $expected) {
                self::assertTrue(true);

                return;
            }
        }

        // because $expected !== $actual ALWAYS, this will NEVER match but display a nice error message.
        self::assertSame($expected, $actual, 'The $expected array was not found inside $actual.');
    }
}
