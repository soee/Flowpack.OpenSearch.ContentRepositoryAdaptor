<?php
declare(strict_types=1);

namespace Flowpack\OpenSearch\ContentRepositoryAdaptor\Tests\Functional;

/*
 * This file is part of the Flowpack.OpenSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\OpenSearch\ContentRepositoryAdaptor\Command\NodeIndexCommandController;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Eel\OpenSearchQueryBuilder;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Eel\OpenSearchQueryResult;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\OpenSearchClient;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Tests\FunctionalTestCase;

abstract class BaseOpenSearchContentRepositoryAdapterTest extends FunctionalTestCase
{
    protected const TESTING_INDEX_PREFIX = 'neoscr_testing';
    protected static $testablePersistenceEnabled = true;
    protected NodeIndexCommandController $nodeIndexCommandController;
    protected OpenSearchClient $openSearchClient;
    /**
     * @var array<string, bool>
     */
    protected static $instantiatedIndexes = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->nodeIndexCommandController = $this->objectManager->get(NodeIndexCommandController::class);
        $this->openSearchClient = $this->objectManager->get(OpenSearchClient::class);
    }

    public function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->contextFactory) && $this->contextFactory instanceof ContextFactoryInterface) {
            $this->inject($this->contextFactory, 'contextInstances', []);
        }

        if (!$this->isIndexInitialized()) {
            // clean up any existing indices
            $this->openSearchClient->request('DELETE', '/' . self::TESTING_INDEX_PREFIX . '*');
        }
    }

    /**
     * @param string $method
     * @return string
     */
    protected function getLogMessagePrefix(string $method): string
    {
        return substr(strrchr($method, '\\'), 1);
    }

    protected function indexNodes(): void
    {
        if ($this->isIndexInitialized()) {
            return;
        }

        $this->nodeIndexCommandController->buildCommand(null, false, null, 'functionaltest');
        $this->setIndexInitialized();
    }

    /**
     * @return OpenSearchQueryBuilder
     */
    protected function getQueryBuilder(): OpenSearchQueryBuilder
    {
        try {
            /** @var OpenSearchQueryBuilder $openSearchQueryBuilder */
            $openSearchQueryBuilder = $this->objectManager->get(OpenSearchQueryBuilder::class);
            $this->inject($openSearchQueryBuilder, 'now', new \DateTimeImmutable('@1735685400')); // Dec. 31, 2024 23:50:00

            return $openSearchQueryBuilder;
        } catch (\Exception $exception) {
            static::fail('Setting up the QueryBuilder failed: ' . $exception->getMessage());
        }
    }

    protected function isIndexInitialized(): bool
    {
        return self::$instantiatedIndexes[get_class($this)] ?? false;
    }

    protected function setIndexInitialized(): void
    {
        self::$instantiatedIndexes[get_class($this)] = true;
    }
}
