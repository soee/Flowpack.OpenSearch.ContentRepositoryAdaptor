<?php

declare(strict_types=1);

namespace Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\Version6\Mapping;

/*
 * This file is part of the Flowpack.OpenSearch.ContentRepositoryAdaptor package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\OpenSearch\ContentRepositoryAdaptor\Service\NodeTypeIndexingConfiguration;
use Flowpack\OpenSearch\ContentRepositoryAdaptor\Driver\AbstractNodeTypeMappingBuilder;
use Flowpack\OpenSearch\Domain\Model\Index;
use Flowpack\OpenSearch\Domain\Model\Mapping;
use Flowpack\OpenSearch\Mapping\MappingCollection;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\Error\Messages\Result;
use Neos\Error\Messages\Warning;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class NodeTypeMappingBuilder extends AbstractNodeTypeMappingBuilder
{
    /**
     * @var NodeTypeIndexingConfiguration
     * @Flow\Inject
     */
    protected $nodeTypeIndexingConfiguration;


    /**
     * Builds a Mapping Collection from the configured node types
     *
     * @param Index $index
     * @return MappingCollection<\Flowpack\OpenSearch\Domain\Model\Mapping>
     * @throws \Flowpack\OpenSearch\ContentRepositoryAdaptor\Exception
     */
    public function buildMappingInformation(Index $index): MappingCollection
    {
        $this->lastMappingErrors = new Result();

        $mappings = new MappingCollection(MappingCollection::TYPE_ENTITY);

        /** @var NodeType $nodeType */
        foreach ($this->nodeTypeManager->getNodeTypes() as $nodeTypeName => $nodeType) {
            if ($nodeTypeName === 'unstructured' || $nodeType->isAbstract()) {
                continue;
            }

            if ($this->nodeTypeIndexingConfiguration->isIndexable($nodeType) === false) {
                continue;
            }

            $mapping = new Mapping($index->findType($nodeTypeName));
            $fullConfiguration = $nodeType->getFullConfiguration();
            if (isset($fullConfiguration['search']['openSearchMapping'])) {
                $fullMapping = $fullConfiguration['search']['openSearchMapping'];
                $mapping->setFullMapping($fullMapping);
            }

            foreach ($nodeType->getProperties() as $propertyName => $propertyConfiguration) {
                // This property is configured to not be index, so do not add a mapping for it
                if (isset($propertyConfiguration['search']) && array_key_exists('indexing', $propertyConfiguration['search']) && $propertyConfiguration['search']['indexing'] === false) {
                    continue;
                }

                if (isset($propertyConfiguration['search']['openSearchMapping'])) {
                    if (is_array($propertyConfiguration['search']['openSearchMapping'])) {
                        $propertyMapping = array_filter($propertyConfiguration['search']['openSearchMapping'], static function ($value) {
                            return $value !== null;
                        });
                        $mapping->setPropertyByPath($propertyName, $propertyMapping);
                    }
                } elseif (isset($propertyConfiguration['type'], $this->defaultConfigurationPerType[$propertyConfiguration['type']]['openSearchMapping'])) {
                    if (is_array($this->defaultConfigurationPerType[$propertyConfiguration['type']]['openSearchMapping'])) {
                        $mapping->setPropertyByPath($propertyName, $this->defaultConfigurationPerType[$propertyConfiguration['type']]['openSearchMapping']);
                    }
                } else {
                    $this->lastMappingErrors->addWarning(new Warning('Node Type "' . $nodeTypeName . '" - property "' . $propertyName . '": No ElasticSearch Mapping found.'));
                }
            }

            $mappings->add($mapping);
        }

        return $mappings;
    }
}
