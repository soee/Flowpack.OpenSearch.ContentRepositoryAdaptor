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

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Persistence\QueryInterface;
use Neos\Flow\Persistence\QueryResultInterface;

class OpenSearchQueryResult implements QueryResultInterface, ProtectedContextAwareInterface
{
    /**
     * @var OpenSearchQuery
     */
    protected $openSearchQuery;

    /**
     * @var array
     */
    protected $result;

    /**
     * @var array
     */
    protected $nodes;

    /**
     * @var integer
     */
    protected $count;

    public function __construct(OpenSearchQuery $openSearchQuery)
    {
        $this->openSearchQuery = $openSearchQuery;
    }

    /**
     * Initialize the results by really executing the query
     *
     * @return void
     * @throws \Flowpack\OpenSearch\ContentRepositoryAdaptor\Exception
     * @throws \Flowpack\OpenSearch\Exception
     * @throws \Neos\Flow\Http\Exception
     */
    protected function initialize(): void
    {
        if ($this->result === null) {
            $queryBuilder = $this->openSearchQuery->getQueryBuilder();
            $this->result = $queryBuilder->fetch();
            $this->nodes = $this->result['nodes'];
            $this->count = $queryBuilder->getTotalItems();
        }
    }

    /**
     * @return OpenSearchQuery
     */
    public function getQuery(): QueryInterface
    {
        return clone $this->openSearchQuery;
    }

    /**
     * {@inheritdoc}
     */
    public function current()
    {
        $this->initialize();

        return current($this->nodes);
    }

    /**
     * {@inheritdoc}
     */
    public function next()
    {
        $this->initialize();

        return next($this->nodes);
    }

    /**
     * {@inheritdoc}
     */
    public function key()
    {
        $this->initialize();

        return key($this->nodes);
    }

    /**
     * {@inheritdoc}
     */
    public function valid()
    {
        $this->initialize();

        return current($this->nodes) !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function rewind()
    {
        $this->initialize();
        reset($this->nodes);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetExists($offset)
    {
        $this->initialize();

        return isset($this->nodes[$offset]);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetGet($offset)
    {
        $this->initialize();

        return $this->nodes[$offset];
    }

    /**
     * {@inheritdoc}
     */
    public function offsetSet($offset, $value)
    {
        $this->initialize();
        $this->nodes[$offset] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function offsetUnset($offset)
    {
        $this->initialize();
        unset($this->nodes[$offset]);
    }

    /**
     * {@inheritdoc}
     */
    public function getFirst()
    {
        $this->initialize();
        if (count($this->nodes) > 0) {
            return array_values($this->nodes)[0];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        $this->initialize();

        return $this->nodes;
    }

    /**
     * {@inheritdoc}
     * @throws \Flowpack\OpenSearch\Exception
     */
    public function count()
    {
        if ($this->count === null) {
            $this->count = $this->openSearchQuery->getQueryBuilder()->count();
        }

        return $this->count;
    }

    /**
     * @return int the current number of results which can be iterated upon
     * @api
     */
    public function getAccessibleCount(): int
    {
        $this->initialize();

        return count($this->nodes);
    }

    /**
     * @return array
     */
    public function getAggregations(): array
    {
        $this->initialize();
        if (array_key_exists('aggregations', $this->result)) {
            return $this->result['aggregations'];
        }

        return [];
    }

    /**
     * Returns an array of type
     * [
     *     <suggestionName> => [
     *         'text' => <term>
     *         'options' => [
     *              [
     *               'text' => <suggestionText>
     *               'score' => <score>
     *              ],
     *              [
     *              ...
     *              ]
     *         ]
     *     ]
     * ]
     *
     * @return array
     */
    public function getSuggestions(): array
    {
        $this->initialize();
        if (array_key_exists('suggest', $this->result)) {
            return $this->result['suggest'];
        }

        return [];
    }

    /**
     * Returns the OpenSearch "hit" (e.g. the raw content being transferred back from OpenSearch)
     * for the given node.
     *
     * Can be used for example to access highlighting information.
     *
     * @param NodeInterface $node
     * @return array the OpenSearch hit, or NULL if it does not exist.
     * @api
     */
    public function searchHitForNode(NodeInterface $node): ?array
    {
        return $this->openSearchQuery->getQueryBuilder()->getFullOpenSearchHitForNode($node);
    }

    /**
     * Returns the array with all sort values for a given node. The values are fetched from the raw content
     * OpenSearch returns within the hit data
     *
     * @param NodeInterface $node
     * @return array
     */
    public function getSortValuesForNode(NodeInterface $node): array
    {
        $hit = $this->searchHitForNode($node);
        if (is_array($hit) && array_key_exists('sort', $hit)) {
            return $hit['sort'];
        }

        return [];
    }

    /**
     * @param string $methodName
     * @return boolean
     */
    public function allowsCallOfMethod($methodName)
    {
        return true;
    }
}
