<?php
declare(strict_types=1);

namespace Flowpack\OpenSearch\ContentRepositoryAdaptor\Tests\Functional\Eel;

/*
 * This file is part of the Flowpack.OpenSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\OpenSearch\ContentRepositoryAdaptor\Tests\Functional\BaseOpenSearchContentRepositoryAdapterTest;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Tests\Functional\Traits\Assertions;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Tests\Functional\Traits\ContentRepositoryMultiDimensionNodeCreationTrait;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Tests\Functional\Traits\ContentRepositorySetupTrait;
use Neos\ContentRepository\Domain\Model\NodeInterface;

class OpenSearchMultiDimensionQueryTest extends BaseOpenSearchContentRepositoryAdapterTest
{
    use ContentRepositorySetupTrait, ContentRepositoryMultiDimensionNodeCreationTrait, Assertions;

    /**
     * @var NodeInterface
     */
    protected $siteNodeDefault;

    /**
     * @var NodeInterface
     */
    protected $siteNodeDe;

    /**
     * @var NodeInterface
     */
    protected $siteNodeDk;

    public function setUp(): void
    {
        parent::setUp();

        $this->setupContentRepository();
        $this->createNodesForNodeSearchTest();
        $this->indexNodes();
    }

    /**
     * @test
     */
    public function countDefaultDimensionNodesTest(): void
    {
        $resultDefault = $this->getQueryBuilder()
            ->query($this->siteNodeDefault)
            ->log($this->getLogMessagePrefix(__METHOD__))
            ->nodeType('Flowpack.OpenSearch.ContentRepositoryAdaptor:Document')
            ->sortDesc('title')
            ->execute();

        static::assertCount(3, $resultDefault->toArray());
        static::assertNodeNames(['root', 'document1', 'document-untranslated'], $resultDefault);
    }

    /**
     * @test
     */
    public function countDeDimensionNodesTest(): void
    {
        $resultDe = $this->getQueryBuilder()
            ->query($this->siteNodeDe)
            ->log($this->getLogMessagePrefix(__METHOD__))
            ->nodeType('Flowpack.OpenSearch.ContentRepositoryAdaptor:Document')
            ->sortDesc('title')
            ->execute();

        // expecting: root, document1, document2, document3, document4, untranslated (fallback from en_us) = 6
        static::assertCount(6, $resultDe->toArray(), 'Found nodes: ' . implode(',', self::extractNodeNames($resultDe)));
        static::assertNodeNames(['root', 'document1', 'document2-de', 'document3-de', 'document4-de', 'document-untranslated'], $resultDe);
    }

    /**
     * @test
     */
    public function countDkDimensionNodesTest(): void
    {
        $resultDk = $this->getQueryBuilder()
            ->query($this->siteNodeDk)
            ->log($this->getLogMessagePrefix(__METHOD__))
            ->nodeType('Flowpack.OpenSearch.ContentRepositoryAdaptor:Document')
            ->sortDesc('title')
            ->execute();

        // expecting: root, document1, document2, untranslated (fallback from en_us) = 4
        static::assertCount(4, $resultDk->toArray());
        static::assertNodeNames(['root', 'document1', 'document2-dk', 'document-untranslated'], $resultDk);
    }
}
