<?php

declare(strict_types=1);

namespace Flowpack\OpenSearch\ContentRepositoryAdaptor\Eel;

/*
 * This file is part of the Flowpack.OpenSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\QueryInterface;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Dto\SearchResult;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Exception\ConfigurationException;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\OpenSearchClient;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Exception;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Exception\QueryBuildingException;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Persistence\QueryResultInterface;
use Psr\Log\LoggerInterface;
use Flowpack\OpenSearch\Transfer\Exception\ApiException;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Search\Search\QueryBuilderInterface;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Utility\Now;
use Neos\Utility\Arrays;

/**
 * Query Builder for OpenSearch Queries
 */
class OpenSearchQueryBuilder implements QueryBuilderInterface, ProtectedContextAwareInterface
{
    /**
     * @Flow\Inject
     * @var OpenSearchClient
     */
    protected $openSearchClient;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var ThrowableStorageInterface
     */
    protected $throwableStorage;

    /**
     * @var boolean
     */
    protected $logThisQuery = false;

    /**
     * @var string
     */
    protected $logMessage;

    /**
     * @var integer
     */
    protected $limit;

    /**
     * @var integer
     */
    protected $from;

    /**
     * @Flow\Inject(lazy=false)
     * @var Now
     */
    protected $now;

    /**
     * This (internal) array stores, for the last search request, a mapping from Node Identifiers
     * to the full OpenSearch Hit which was returned.
     *
     * This is needed to e.g. use result highlighting.
     *
     * @var array
     */
    protected $openSearchHitsIndexedByNodeFromLastRequest;

    /**
     * The OpenSearch request, as it is being built up.
     *
     * @var QueryInterface
     * @Flow\Inject
     */
    protected $request;

    /**
     * @var array
     */
    protected $result = [];

    /**
     * HIGH-LEVEL API
     */

    /**
     * Filter by node type, taking inheritance into account.
     *
     * @param string $nodeType the node type to filter for
     * @return OpenSearchQueryResult
     * @throws QueryBuildingException
     * @api
     */
    public function nodeType(string $nodeType): QueryBuilderInterface
    {
        // on indexing, neos_type_and_supertypes contains the typename itself and all supertypes, so that's why we can
        // use a simple term filter here.

        // https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-term-filter.html
        return $this->queryFilter('term', ['neos_type_and_supertypes' => $nodeType]);
    }

    /**
     * Sort descending by $propertyName
     *
     * @param string $propertyName the property name to sort by
     * @return OpenSearchQueryResult
     * @api
     */
    public function sortDesc(string $propertyName): QueryBuilderInterface
    {
        $configuration = [
            $propertyName => ['order' => 'desc']
        ];

        $this->sort($configuration);

        return $this;
    }

    /**
     * Sort ascending by $propertyName
     *
     * @param string $propertyName the property name to sort by
     * @return OpenSearchQueryResult
     * @api
     */
    public function sortAsc(string $propertyName): QueryBuilderInterface
    {
        $configuration = [
            $propertyName => ['order' => 'asc']
        ];

        $this->sort($configuration);

        return $this;
    }

    /**
     * Add a $configuration sort filter to the request
     *
     * @param array $configuration
     * @return OpenSearchQueryResult
     * @api
     */
    public function sort($configuration): OpenSearchQueryResult
    {
        $this->request->addSortFilter($configuration);

        return $this;
    }

    /**
     * output only $limit records
     *
     * Internally, we fetch $limit*$workspaceNestingLevel records, because we fetch the *conjunction* of all workspaces;
     * and then we filter after execution when we have found the right number of results.
     *
     * This algorithm can be re-checked when https://github.com/elasticsearch/elasticsearch/issues/3300 is merged.
     *
     * @param integer $limit
     * @return OpenSearchQueryResult
     * @throws IllegalObjectTypeException
     * @api
     */
    public function limit($limit): QueryBuilderInterface
    {
        if ($limit === null) {
            return $this;
        }

        $currentWorkspaceNestingLevel = 1;
        $workspace = $this->openSearchClient->getContextNode()->getContext()->getWorkspace();
        while ($workspace->getBaseWorkspace() !== null) {
            $currentWorkspaceNestingLevel++;
            $workspace = $workspace->getBaseWorkspace();
        }

        $this->limit = $limit;

        // https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-from-size.html
        $this->request->size($limit * $currentWorkspaceNestingLevel);

        return $this;
    }

    /**
     * output records starting at $from
     *
     * @param integer $from
     * @return OpenSearchQueryResult
     * @api
     */
    public function from($from): QueryBuilderInterface
    {
        if (!$from) {
            return $this;
        }

        $this->from = $from;
        $this->request->from($from);

        return $this;
    }

    /**
     * add an exact-match query for a given property
     *
     * @param string $propertyName Name of the property
     * @param mixed $value Value for comparison
     * @return QueryBuilderInterface
     * @throws QueryBuildingException
     * @api
     */
    public function exactMatch(string $propertyName, $value): QueryBuilderInterface
    {
        return $this->queryFilter('term', [$propertyName => $this->convertValue($value)]);
    }

    /**
     * @param string $propertyName
     * @param mixed $value
     * @return QueryBuilderInterface
     * @throws QueryBuildingException
     */
    public function exclude(string $propertyName, $value): QueryBuilderInterface
    {
        return $this->queryFilter('term', [$propertyName => $this->convertValue($value)], 'must_not');
    }

    /**
     * add a range filter (gt) for the given property
     *
     * @param string $propertyName Name of the property
     * @param mixed $value Value for comparison
     * @param string $clauseType one of must, should, must_not
     * @return QueryBuilderInterface
     * @throws QueryBuildingException
     * @api
     */
    public function greaterThan(string $propertyName, $value, string $clauseType = 'must'): OpenSearchQueryBuilder
    {
        return $this->queryFilter('range', [$propertyName => ['gt' => $this->convertValue($value)]], $clauseType);
    }

    /**
     * add a range filter (gte) for the given property
     *
     * @param string $propertyName Name of the property
     * @param mixed $value Value for comparison
     * @param string $clauseType one of must, should, must_not
     * @return QueryBuilderInterface
     * @throws QueryBuildingException
     * @api
     */
    public function greaterThanOrEqual(string $propertyName, $value, string $clauseType = 'must'): OpenSearchQueryBuilder
    {
        return $this->queryFilter('range', [$propertyName => ['gte' => $this->convertValue($value)]], $clauseType);
    }

    /**
     * add a range filter (lt) for the given property
     *
     * @param string $propertyName Name of the property
     * @param mixed $value Value for comparison
     * @param string $clauseType one of must, should, must_not
     * @return QueryBuilderInterface
     * @throws QueryBuildingException
     * @api
     */
    public function lessThan(string $propertyName, $value, string $clauseType = 'must'): OpenSearchQueryBuilder
    {
        return $this->queryFilter('range', [$propertyName => ['lt' => $this->convertValue($value)]], $clauseType);
    }

    /**
     * add a range filter (lte) for the given property
     *
     * @param string $propertyName Name of the property
     * @param mixed $value Value for comparison
     * @param string $clauseType one of must, should, must_not
     * @return QueryBuilderInterface
     * @throws QueryBuildingException
     * @api
     */
    public function lessThanOrEqual(string $propertyName, $value, string $clauseType = 'must'): OpenSearchQueryBuilder
    {
        return $this->queryFilter('range', [$propertyName => ['lte' => $this->convertValue($value)]], $clauseType);
    }

    /**
     * LOW-LEVEL API
     */

    /**
     * Add a filter to query.filtered.filter
     *
     * @param string $filterType
     * @param mixed $filterOptions
     * @param string $clauseType one of must, should, must_not
     * @return QueryBuilderInterface
     * @throws QueryBuildingException
     * @api
     */
    public function queryFilter(string $filterType, $filterOptions, string $clauseType = 'must'): OpenSearchQueryBuilder
    {
        $this->request->queryFilter($filterType, $filterOptions, $clauseType);

        return $this;
    }

    /**
     * Append $data to the given array at $path inside $this->request.
     *
     * Low-level method to manipulate the Elasticsearch Query
     *
     * @param string $path
     * @param array $data
     * @return QueryBuilderInterface
     * @throws QueryBuildingException
     */
    public function appendAtPath(string $path, array $data): OpenSearchQueryBuilder
    {
        $this->request->appendAtPath($path, $data);

        return $this;
    }

    /**
     * Add multiple filters to query.filtered.filter
     *
     * Example Usage:
     *
     *   searchFilter = Neos.Fusion:RawArray {
     *      author = 'Max'
     *      tags = Neos.Fusion:RawArray {
     *        0 = 'a'
     *        1 = 'b'
     *      }
     *   }
     *
     *   searchQuery = ${Search.queryFilterMultiple(this.searchFilter)}
     *
     * @param array $data An associative array of keys as variable names and values as variable values
     * @param string $clauseType one of must, should, must_not
     * @return QueryBuilderInterface
     * @throws QueryBuildingException
     * @api
     */
    public function queryFilterMultiple(array $data, $clauseType = 'must'): OpenSearchQueryBuilder
    {
        foreach ($data as $key => $value) {
            if ($value !== null) {
                if (is_array($value)) {
                    $this->queryFilter('terms', [$key => array_values($value)], $clauseType);
                } else {
                    $this->queryFilter('term', [$key => $value], $clauseType);
                }
            }
        }

        return $this;
    }

    /**
     * This method adds a field based aggregation configuration. This can be used for simple
     * aggregations like terms
     *
     * Example Usage to create a terms aggregation for a property color:
     * nodes = ${Search....fieldBasedAggregation("colors", "color").execute()}
     *
     * Access all aggregation data with {nodes.aggregations} in your fluid template
     *
     * @param string $name The name to identify the resulting aggregation
     * @param string $field The field to aggregate by
     * @param string $type Aggregation type
     * @param string $parentPath
     * @param int|null $size The amount of buckets to return or null if not applicable to the aggregation
     * @return QueryBuilderInterface
     * @throws QueryBuildingException
     */
    public function fieldBasedAggregation(string $name, string $field, string $type = 'terms', string $parentPath = '', ?int $size = null): OpenSearchQueryBuilder
    {
        $aggregationDefinition = [
            $type => [
                'field' => $field
            ]
        ];

        if ($size !== null) {
            $aggregationDefinition[$type]['size'] = $size;
        }

        $this->aggregation($name, $aggregationDefinition, $parentPath);

        return $this;
    }

    /**
     * This method is used to create any kind of aggregation.
     *
     * Example Usage to create a terms aggregation for a property color:
     *
     * aggregationDefinition = Neos.Fusion:DataStructure {
     *   terms {
     *     field = "color"
     *   }
     * }
     *
     * nodes = ${Search....aggregation("color", this.aggregationDefinition).execute()}
     *
     * Access all aggregation data with {nodes.aggregations} in your fluid template
     *
     * @param string $name
     * @param array $aggregationDefinition
     * @param string $parentPath
     * @return QueryBuilderInterface
     * @throws QueryBuildingException
     */
    public function aggregation(string $name, array $aggregationDefinition, string $parentPath = ''): OpenSearchQueryBuilder
    {
        $this->request->aggregation($name, $aggregationDefinition, $parentPath);

        return $this;
    }

    /**
     * This method is used to create a simple term suggestion.
     *
     * Example Usage of a term suggestion
     *
     * nodes = ${Search....termSuggestions("aTerm")}
     *
     * Access all suggestions data with ${Search....getSuggestions()}
     *
     * @param string $text
     * @param string $field
     * @param string $name
     * @return QueryBuilderInterface
     */
    public function termSuggestions(string $text, string $field = 'neos_fulltext.text', string $name = 'suggestions'): OpenSearchQueryBuilder
    {
        $suggestionDefinition = [
            'text' => $text,
            'term' => [
                'field' => $field
            ]
        ];

        $this->suggestions($name, $suggestionDefinition);

        return $this;
    }

    /**
     * This method is used to create any kind of suggestion.
     *
     * Example Usage of a term suggestion for the fulltext search
     *
     * suggestionDefinition = Neos.Fusion:RawArray {
     *     text = "some text"
     *     terms = Neos.Fusion:RawArray {
     *         field = "body"
     *     }
     * }
     *
     * nodes = ${Search....suggestion("my-suggestions", this.suggestionDefinition).execute()}
     *
     * Access all suggestions data with {nodes.suggestions} in your fluid template
     *
     * @param string $name
     * @param array $suggestionDefinition
     * @return QueryBuilderInterface
     */
    public function suggestions(string $name, array $suggestionDefinition): OpenSearchQueryBuilder
    {
        $this->request->suggestions($name, $suggestionDefinition);

        return $this;
    }

    /**
     * Get the OpenSearch request as we need it
     *
     * @return QueryInterface
     */
    public function getRequest(): QueryInterface
    {
        return $this->request;
    }

    /**
     * Log the current request to the OpenSearch log for debugging after it has been executed.
     *
     * @param string $message an optional message to identify the log entry
     * @return QueryBuilderInterface
     * @api
     */
    public function log($message = null): OpenSearchQueryBuilder
    {
        $this->logThisQuery = true;
        $this->logMessage = $message;

        return $this;
    }

    /**
     * @return int
     */
    public function getTotalItems(): int
    {
        return $this->evaluateResult($this->result)->getTotal();
    }

    /**
     * @return int
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * @return int
     */
    public function getFrom(): int
    {
        return $this->from;
    }

    /**
     * This low-level method can be used to look up the full OpenSearch hit given a certain node.
     *
     * @param NodeInterface $node
     * @return array the OpenSearch hit for the node as array, or NULL if it does not exist.
     */
    public function getFullOpenSearchHitForNode(NodeInterface $node): ?array
    {
        return $this->openSearchHitsIndexedByNodeFromLastRequest[$node->getIdentifier()] ?? null;
    }

    /**
     * Execute the query and return the list of nodes as result.
     *
     * This method is rather internal; just to be called from the OpenSearchQueryResult. For the public API, please use execute()
     *
     * @return array<\Neos\ContentRepository\Domain\Model\NodeInterface>
     * @throws Exception
     * @throws \Flowpack\OpenSearch\Exception
     * @throws \Neos\Flow\Http\Exception
     */
    public function fetch(): array
    {
        try {
            $timeBefore = microtime(true);
            $request = $this->request->getRequestAsJson();
            $response = $this->openSearchClient->getIndex()->request('GET', '/_search', [], $request);
            $timeAfterwards = microtime(true);

            $this->result = $response->getTreatedContent();
            $searchResult = $this->evaluateResult($this->result);

            $this->result['nodes'] = [];

            $this->logThisQuery && $this->logger->debug(sprintf('Query Log (%s): Indexname: %s %s -- execution time: %s ms -- Limit: %s -- Number of results returned: %s -- Total Results: %s', $this->logMessage, $this->getIndexName(), $request, (($timeAfterwards - $timeBefore) * 1000), $this->limit, count($searchResult->getHits()), $searchResult->getTotal()), LogEnvironment::fromMethodName(__METHOD__));

            if (count($searchResult->getHits()) > 0) {
                $this->result['nodes'] = $this->convertHitsToNodes($searchResult->getHits());
            }
        } catch (ApiException $exception) {
            $message = $this->throwableStorage->logThrowable($exception);
            $this->logger->error(sprintf('Request failed with %s', $message), LogEnvironment::fromMethodName(__METHOD__));
            $this->result['nodes'] = [];
        }

        return $this->result;
    }

    /**
     * @param array $result
     * @return SearchResult
     */
    protected function evaluateResult(array $result): SearchResult
    {
        return new SearchResult(
            $hits = $result['hits']['hits'] ?? [],
            $total = $result['hits']['total']['value'] ?? 0
        );
    }

    /**
     * Get a query result object for lazy execution of the query
     *
     * @param bool $cacheResult
     * @return OpenSearchQueryResult
     * @throws Exception
     * @throws ConfigurationException
     * @throws \JsonException
     * @api
     */
    public function execute(bool $cacheResult = true): \Traversable
    {
        return (new OpenSearchQuery($this))->execute($cacheResult);
    }

    /**
     * Get a uncached query result object for lazy execution of the query
     *
     * @return OpenSearchQueryResult
     * @throws \JsonException
     * @api
     */
    public function executeUncached(): OpenSearchQueryResult
    {
        return (new OpenSearchQuery($this))->execute();
    }

    /**
     * Return the total number of hits for the query.
     *
     * @return integer
     * @throws Exception
     * @throws \Flowpack\OpenSearch\Exception
     * @throws \Neos\Flow\Http\Exception
     * @api
     */
    public function count(): int
    {
        $timeBefore = microtime(true);
        $request = $this->getRequest()->getCountRequestAsJson();

        $response = $this->openSearchClient->getIndex()->request('GET', '/_count', [], $request);
        $timeAfterwards = microtime(true);

        $treatedContent = $response->getTreatedContent();
        $count = (int)$treatedContent['count'];

        $this->logThisQuery && $this->logger->debug('Count Query Log (' . $this->logMessage . '): Indexname: ' . $this->getIndexName() . ' ' . $request . ' -- execution time: ' . (($timeAfterwards - $timeBefore) * 1000) . ' ms -- Total Results: ' . $count, LogEnvironment::fromMethodName(__METHOD__));

        return $count;
    }

    /**
     * Match the searchword against the fulltext index.
     *
     * NOTE: Please use {@see simpleQueryStringFulltext} instead, as it is more robust.
     *
     * @param string $searchWord
     * @param array $options Options to configure the query_string, see https://www.elastic.co/guide/en/elasticsearch/reference/7.6/query-dsl-query-string-query.html
     * @return QueryBuilderInterface
     * @throws \JsonException
     * @api
     */
    public function fulltext(string $searchWord, array $options = []): QueryBuilderInterface
    {
        // We automatically enable result highlighting when doing fulltext searches. It is up to the user to use this information or not use it.
        $this->request->fulltext(trim(json_encode($searchWord, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), '"'), $options);
        $this->request->highlight(150, 2);

        return $this;
    }

    /**
     * Match the searchword against the fulltext index using the elasticsearch
     * [simple query string query](https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-simple-query-string-query.html).
     *
     * This method has two main benefits over {@see fulltext()}:
     * - Supports phrase searches like `"Neos and Flow"`, where
     *   quotes mean "I want this exact phrase" (similar to many search engines).
     * - do not crash if the user does not enter a fully syntactically valid query
     *   (invalid query parts are ignored).
     *
     * This is exactly what we want for a good search field behavior.
     *
     * @param string $searchWord
     * @param array $options Options to configure the query_string, see https://www.elastic.co/guide/en/elasticsearch/reference/7.6/query-dsl-query-string-query.html
     * @return QueryBuilderInterface
     * @api
     */
    public function simpleQueryStringFulltext(string $searchWord, array $options = []): QueryBuilderInterface
    {
        // We automatically enable result highlighting when doing fulltext searches. It is up to the user to use this information or not use it.
        $this->request->simpleQueryStringFulltext($searchWord, $options);
        $this->request->highlight(150, 2);

        return $this;
    }

    /**
     * Adds a prefix filter to the query
     * See: https://www.elastic.co/guide/en/elasticsearch/reference/7.6/query-dsl-prefix-query.html
     *
     * @param string $propertyName
     * @param string $prefix
     * @param string $clauseType one of must, should, must_not
     * @return $this|QueryBuilderInterface
     * @throws QueryBuildingException
     */
    public function prefix(string $propertyName, string $prefix, string $clauseType = 'must'): QueryBuilderInterface
    {
        $this->request->queryFilter('prefix', [$propertyName => $prefix], $clauseType);

        return $this;
    }

    /**
     * Filters documents that include only hits that exists within a specific distance from a geo point.
     *
     * @param string $propertyName
     * @param string|array $geoPoint Either ['lon' => x.x, 'lat' => y.y], [lon, lat], 'lat,lon', or GeoHash
     * @param string $distance Distance with unit. See: https://www.elastic.co/guide/en/elasticsearch/reference/7.6/common-options.html#distance-units
     * @param string $clauseType one of must, should, must_not
     * @return QueryBuilderInterface
     * @throws QueryBuildingException
     */
    public function geoDistance(string $propertyName, $geoPoint, string $distance, string $clauseType = 'must'): QueryBuilderInterface
    {
        $this->queryFilter('geo_distance', [
            'distance' => $distance,
            $propertyName => $geoPoint,
        ], $clauseType);

        return $this;
    }

    /**
     * Configure Result Highlighting. Only makes sense in combination with fulltext(). By default, highlighting is enabled.
     * It can be disabled by calling "highlight(FALSE)".
     *
     * @param int|bool $fragmentSize The result fragment size for highlight snippets. If this parameter is FALSE, highlighting will be disabled.
     * @param int|null $fragmentCount The number of highlight fragments to show.
     * @param int $noMatchSize
     * @param string $field
     * @return OpenSearchQueryResult
     * @api
     */
    public function highlight($fragmentSize, int $fragmentCount = null, int $noMatchSize = 150, string $field = 'neos_fulltext.*'): OpenSearchQueryResult
    {
        $this->request->highlight($fragmentSize, $fragmentCount, $noMatchSize, $field);

        return $this;
    }

    /**
     * This method is used to define a more like this query.
     * The More Like This Query (MLT Query) finds documents that are "like" a given set of documents.
     * See: https://www.elastic.co/guide/en/elasticsearch/reference/5.6/query-dsl-mlt-query.html
     *
     * @param array $like An array of strings or documents
     * @param array $fields Fields to compare other docs with
     * @param array $options Additional options for the more_like_this quey
     * @return OpenSearchQueryResult
     */
    public function moreLikeThis(array $like, array $fields = [], array $options = []): OpenSearchQueryResult
    {
        $like = is_array($like) ? $like : [$like];

        $getDocumentDefinitionByNode = function (QueryInterface $request, NodeInterface $node): array {
            $request->queryFilter('term', ['neos_node_identifier' => $node->getIdentifier()]);
            $response = $this->openSearchClient->getIndex()->request('GET', '/_search', [], $request->toArray())->getTreatedContent();
            $respondedDocuments = Arrays::getValueByPath($response, 'hits.hits');
            if (count($respondedDocuments) === 0) {
                $this->logger->info(sprintf('The node with identifier %s was not found in the OpenSearch index.', $node->getIdentifier()), LogEnvironment::fromMethodName(__METHOD__));
                return [];
            }

            $respondedDocument = current($respondedDocuments);
            return [
                '_id' => $respondedDocument['_id'],
                '_index' => $respondedDocument['_index'],
            ];
        };

        $processedLike = [];

        foreach ($like as $key => $likeElement) {
            if ($likeElement instanceof NodeInterface) {
                $documentDefinition = $getDocumentDefinitionByNode(clone $this->request, $likeElement);
                if (!empty($documentDefinition)) {
                    $processedLike[] = $documentDefinition;
                }
            } else {
                $processedLike[] = $likeElement;
            }
        }

        $processedLike = array_filter($processedLike);

        if (!empty($processedLike)) {
            $this->request->moreLikeThis($processedLike, $fields, $options);
        }

        return $this;
    }

    /**
     * Sets the starting point for this query. Search result should only contain nodes that
     * match the context of the given node and have it as parent node in their rootline.
     *
     * @param NodeInterface $contextNode
     * @return OpenSearchQueryResult
     * @throws QueryBuildingException
     * @throws IllegalObjectTypeException
     * @api
     */
    public function query(NodeInterface $contextNode): QueryBuilderInterface
    {
        $this->openSearchClient->setContextNode($contextNode);

        // on indexing, the neos_parent_path is tokenized to contain ALL parent path parts,
        // e.g. /foo, /foo/bar/, /foo/bar/baz; to speed up matching.. That's why we use a simple "term" filter here.
        // https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-term-filter.html
        // another term filter against the path allows the context node itself to be found
        $this->queryFilter('bool', [
            'should' => [
                [
                    'term' => ['neos_parent_path' => $contextNode->getPath()]
                ],
                [
                    'term' => ['neos_path' => $contextNode->getPath()]
                ]
            ]
        ]);

        $workspaces = array_merge(
            [$contextNode->getContext()->getWorkspace()->getName()],
            array_keys($contextNode->getContext()->getWorkspace()->getBaseWorkspaces())
        );
        //
        // https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-terms-filter.html
        $this->queryFilter('terms', ['neos_workspace' => $workspaces]);

        return $this;
    }

    /**
     * Modify a part of the Elasticsearch Request denoted by $path, merging together
     * the existing values and the passed-in values.
     *
     * @param string $path
     * @param mixed $requestPart
     * @return OpenSearchQueryBuilder
     */
    public function request(string $path, $requestPart): OpenSearchQueryBuilder
    {
        $this->request->setByPath($path, $requestPart);

        return $this;
    }

    /**
     * All methods are considered safe
     *
     * @param string $methodName
     * @return bool
     */
    public function allowsCallOfMethod($methodName)
    {
        return true;
    }

    /**
     * @param array $hits
     * @return array Array of Node objects
     */
    protected function convertHitsToNodes(array $hits): array
    {
        $nodes = [];
        $openSearchHitPerNode = [];
        $notConvertedNodePaths = [];

        /**
         * TODO: This code below is not fully correct yet:
         *
         * We always fetch $limit * (numberOfWorkspaces) records; so that we find a node:
         * - *once* if it is only in live workspace and matches the query
         * - *once* if it is only in user workspace and matches the query
         * - *twice* if it is in both workspaces and matches the query *both times*. In this case we filter the duplicate record.
         * - *once* if it is in the live workspace and has been DELETED in the user workspace (STILL WRONG)
         * - *once* if it is in the live workspace and has been MODIFIED to NOT MATCH THE QUERY ANYMORE in user workspace (STILL WRONG)
         *
         * If we want to fix this cleanly, we'd need to do an *additional query* in order to filter all nodes from a non-user workspace
         * which *do exist in the user workspace but do NOT match the current query*. This has to be done somehow "recursively"; and later
         * we might be able to use https://github.com/elasticsearch/elasticsearch/issues/3300 as soon as it is merged.
         */
        foreach ($hits as $hit) {
            $nodePath = $hit[isset($hit['fields']['neos_path']) ? 'fields' : '_source']['neos_path'];
            if (is_array($nodePath)) {
                $nodePath = current($nodePath);
            }

            $node = $this->openSearchClient->getContextNode()->getNode($nodePath);

            if (!$node instanceof NodeInterface) {
                $notConvertedNodePaths[] = $nodePath;
                continue;
            }

            if (isset($nodes[$node->getIdentifier()])) {
                continue;
            }

            $nodes[$node->getIdentifier()] = $node;
            $openSearchHitPerNode[$node->getIdentifier()] = $hit;
            if ($this->limit > 0 && count($nodes) >= $this->limit) {
                break;
            }
        }

        $this->logThisQuery && $this->logger->debug(sprintf('[%s] Returned %s nodes.', $this->logMessage, count($nodes)), LogEnvironment::fromMethodName(__METHOD__));

        if (!empty($notConvertedNodePaths)) {
            $this->logger->warning(sprintf('[%s] Search resulted in %s hits but only %s hits could be converted to nodes. Nodes with paths "%s" could not have been converted.', $this->logMessage, count($hits), count($nodes), implode(', ', $notConvertedNodePaths)), LogEnvironment::fromMethodName(__METHOD__));
        }

        $this->openSearchHitsIndexedByNodeFromLastRequest = $openSearchHitPerNode;

        return array_values($nodes);
    }

    /**
     * This method will get the minimum of all allowed cache lifetimes for the
     * nodes that would result from the current defined query. This means it will evaluate to the nearest future value of the
     * hiddenBeforeDateTime or hiddenAfterDateTime properties of all nodes in the result.
     *
     * @return int
     * @throws Exception
     * @throws QueryBuildingException
     * @throws \Flowpack\OpenSearch\Exception
     * @throws \Neos\Flow\Http\Exception
     */
    public function cacheLifetime(): int
    {
        $minTimestamps = array_filter([
            $this->getNearestFutureDate('neos_hidden_before_datetime'),
            $this->getNearestFutureDate('neos_hidden_after_datetime')
        ], static function ($value) {
            return $value !== 0;
        });

        if (empty($minTimestamps)) {
            return 0;
        }

        $minTimestamp = min($minTimestamps);

        return $minTimestamp - $this->now->getTimestamp();
    }

    /**
     * @param string $dateField
     * @return int
     * @throws Exception
     * @throws QueryBuildingException
     * @throws \Flowpack\OpenSearch\Exception
     * @throws \Neos\Flow\Http\Exception
     */
    protected function getNearestFutureDate(string $dateField): int
    {
        $request = clone $this->request;

        $convertDateResultToTimestamp = static function (array $dateResult): int {
            if (!isset($dateResult['value_as_string'])) {
                return 0;
            }
            return (new \DateTime($dateResult['value_as_string']))->getTimestamp();
        };

        $request->queryFilter('range', [$dateField => ['gt' => 'now']], 'must');
        $request->aggregation('minTime', [
            'min' => [
                'field' => $dateField
            ]
        ]);

        $request->size(0);

        $requestArray = $request->toArray();

        $mustNot = Arrays::getValueByPath($requestArray, 'query.bool.filter.bool.must_not');

        /* Remove exclusion of not yet visible nodes
        - range:
          neos_hidden_before_datetime:
            gt: now
        */
        unset($mustNot[1]);

        $requestArray = Arrays::setValueByPath($requestArray, 'query.bool.filter.bool.must_not', array_values($mustNot));

        $result = $this->openSearchClient->getIndex()->request('GET', '/_search', [], $requestArray)->getTreatedContent();

        return $convertDateResultToTimestamp(Arrays::getValueByPath($result, 'aggregations.minTime'));
    }

    /**
     * Proxy method to access the public method of the Request object
     *
     * This is used to call a method of a custom Request type where no corresponding wrapper method exist in the QueryBuilder.
     *
     * @param string $method
     * @param array $arguments
     * @return OpenSearchQueryBuilder
     * @throws Exception
     */
    public function __call(string $method, array $arguments): OpenSearchQueryBuilder
    {
        if (!method_exists($this->request, $method)) {
            throw new Exception(sprintf('Method "%s" does not exist in the current Request object "%s"', $method, get_class($this->request)), 1486763515);
        }
        call_user_func_array([$this->request, $method], $arguments);

        return $this;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected function convertValue($value)
    {
        if ($value instanceof NodeInterface) {
            return $value->getIdentifier();
        }

        if ($value instanceof \DateTime) {
            return $value->format('Y-m-d\TH:i:sP');
        }

        return $value;
    }

    /**
     * Retrieve the indexName
     *
     * @return string
     * @throws Exception
     * @throws Exception\ConfigurationException
     */
    public function getIndexName(): string
    {
        return $this->openSearchClient->getIndexName();
    }
}
