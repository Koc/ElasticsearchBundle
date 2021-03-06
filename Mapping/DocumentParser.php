<?php

/*
 * This file is part of the ONGR package.
 *
 * (c) NFQ Technologies UAB <info@nfq.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ONGR\ElasticsearchBundle\Mapping;

use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\Cache;
use ONGR\ElasticsearchBundle\Annotation\AbstractAnnotation;
use ONGR\ElasticsearchBundle\Annotation\Embedded;
use ONGR\ElasticsearchBundle\Annotation\Index;
use ONGR\ElasticsearchBundle\Annotation\NestedType;
use ONGR\ElasticsearchBundle\Annotation\ObjectType;
use ONGR\ElasticsearchBundle\Annotation\PropertiesAwareInterface;
use ONGR\ElasticsearchBundle\Annotation\Property;
use ONGR\ElasticsearchBundle\DependencyInjection\Configuration;

/**
 * Document parser used for reading document annotations.
 */
class DocumentParser
{
    const OBJ_CACHED_FIELDS = 'ongr.obj_fields';
    const EMBEDDED_CACHED_FIELDS = 'ongr.embedded_fields';
    const ARRAY_CACHED_FIELDS = 'ongr.array_fields';

    private $reader;
    private $properties = [];
    private $analysisConfig = [];
    private $cache;

    public function __construct(Reader $reader, Cache $cache, array $analysisConfig = [])
    {
        $this->reader = $reader;
        $this->cache = $cache;
        $this->analysisConfig = $analysisConfig;

        #Fix for annotations loader until doctrine/annotations 2.0 will be released with the full autoload support.
        AnnotationRegistry::registerLoader('class_exists');
    }

    public function getIndexAliasName(\ReflectionClass $class): string
    {
        /** @var Index $document */
        $document = $this->reader->getClassAnnotation($class, Index::class);

        return $document->alias ?? Caser::snake($class->getShortName());
    }

    public function isDefaultIndex(\ReflectionClass $class): bool
    {
        /** @var Index $document */
        $document = $this->reader->getClassAnnotation($class, Index::class);

        return $document->default;
    }

    public function getIndexAnnotation(\ReflectionClass $class)
    {
        /** @var Index $document */
        $document = $this->reader->getClassAnnotation($class, Index::class);

        return $document;
    }

    /**
     * @deprecated will be deleted in v7. Types are deleted from elasticsearch.
     */
    public function getTypeName(\ReflectionClass $class): string
    {
        /** @var Index $document */
        $document = $this->reader->getClassAnnotation($class, Index::class);

        return $document->typeName ?? '_doc';
    }

    public function getIndexMetadata(\ReflectionClass $class): array
    {
        if ($class->isTrait()) {
            return [];
        }

        /** @var Index $document */
        $document = $this->reader->getClassAnnotation($class, Index::class);

        if ($document === null) {
            return [];
        }

        $settings = $document->getSettings();
        $settings['analysis'] = $this->getAnalysisConfig($class);

        return array_filter(array_map('array_filter', [
            'settings' => $settings,
            'mappings' => [
                $this->getTypeName($class) => [
                    'properties' => array_filter($this->getClassMetadata($class))
                ]
            ]
        ]));
    }

    public function getDocumentNamespace(string $indexAlias): ?string
    {
        if ($this->cache->contains(Configuration::ONGR_INDEXES)) {
            $indexes = $this->cache->fetch(Configuration::ONGR_INDEXES);

            if (isset($indexes[$indexAlias])) {
                return $indexes[$indexAlias];
            }
        }

        return null;
    }

    public function getParsedDocument(\ReflectionClass $class): Index
    {
        /** @var Index $document */
        $document = $this->reader->getClassAnnotation($class, Index::class);

        return $document;
    }

    private function getClassMetadata(\ReflectionClass $class): array
    {
        $mapping = [];
        $objFields = null;
        $arrayFields = null;
        $embeddedFields = null;

        /** @var \ReflectionProperty $property */
        foreach ($this->getDocumentPropertiesReflection($class) as $name => $property) {
            $annotations = $this->reader->getPropertyAnnotations($property);

            /** @var AbstractAnnotation $annotation */
            foreach ($annotations as $annotation) {
                if (!$annotation instanceof PropertiesAwareInterface) {
                    continue;
                }

                $fieldMapping = $annotation->getSettings();

                if ($annotation instanceof Property) {
                    $fieldMapping['type'] = $annotation->type;
                    $fieldMapping['analyzer'] = $annotation->analyzer;
                    $fieldMapping['search_analyzer'] = $annotation->searchAnalyzer;
                    $fieldMapping['search_quote_analyzer'] = $annotation->searchQuoteAnalyzer;
                }

                if ($annotation instanceof Embedded) {
                    $embeddedClass = new \ReflectionClass($annotation->class);
                    $fieldMapping['type'] = $this->getObjectMappingType($embeddedClass);
                    $fieldMapping['properties'] = $this->getClassMetadata($embeddedClass);
                    $embeddedFields[$name] = $annotation->class;
                }

                $mapping[$annotation->getName() ?? Caser::snake($name)] = array_filter($fieldMapping);
                $objFields[$name] = $annotation->getName() ?? Caser::snake($name);
                $arrayFields[$annotation->getName() ?? Caser::snake($name)] = $name;
            }
        }

        //Embeded fields are option compared to the array or object mapping.
        if ($embeddedFields) {
            $cacheItem = $this->cache->fetch(self::EMBEDDED_CACHED_FIELDS) ?? [];
            $cacheItem[$class->getName()] = $embeddedFields;
            $t = $this->cache->save(self::EMBEDDED_CACHED_FIELDS, $cacheItem);
        }

        $cacheItem = $this->cache->fetch(self::ARRAY_CACHED_FIELDS) ?? [];
        $cacheItem[$class->getName()] = $arrayFields;
        $this->cache->save(self::ARRAY_CACHED_FIELDS, $cacheItem);

        $cacheItem = $this->cache->fetch(self::OBJ_CACHED_FIELDS) ?? [];
        $cacheItem[$class->getName()] = $objFields;
        $this->cache->save(self::OBJ_CACHED_FIELDS, $cacheItem);

        return $mapping;
    }

    public function getAnalysisConfig(\ReflectionClass $class): array
    {
        $config = [];
        $mapping = $this->getClassMetadata($class);

        //Think how to remove these array merge
        $analyzers = $this->getListFromArrayByKey('analyzer', $mapping);
        $analyzers = array_merge($analyzers, $this->getListFromArrayByKey('search_analyzer', $mapping));
        $analyzers = array_merge($analyzers, $this->getListFromArrayByKey('search_quote_analyzer', $mapping));

        foreach ($analyzers as $analyzer) {
            if (isset($this->analysisConfig['analyzer'][$analyzer])) {
                $config['analyzer'][$analyzer] = $this->analysisConfig['analyzer'][$analyzer];
            }
        }

        foreach (['tokenizer', 'filter', 'normalizer', 'char_filter'] as $type) {
            $list = $this->getListFromArrayByKey($type, $config);

            foreach ($list as $listItem) {
                if (isset($this->analysisConfig[$type][$listItem])) {
                    $config[$type][$listItem] = $this->analysisConfig[$type][$listItem];
                }
            }
        }

        return $config;
    }

    private function getListFromArrayByKey(string $searchKey, array $array): array
    {
        $list = [];

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveArrayIterator($array),
            \RecursiveIteratorIterator::SELF_FIRST
        ) as $key => $value) {
            if ($key === $searchKey) {
                if (is_array($value)) {
                    $list = array_merge($list, $value);
                } else {
                    $list[] = $value;
                }
            }
        }

        return array_unique($list);
    }

    private function getObjectMappingType(\ReflectionClass $class): string
    {
        switch (true) {
            case $this->reader->getClassAnnotation($class, ObjectType::class):
                $type = ObjectType::TYPE;
                break;
            case $this->reader->getClassAnnotation($class, NestedType::class):
                $type = NestedType::TYPE;
                break;
            default:
                throw new \LogicException(
                    sprintf(
                        '%s must be used @ObjectType or @NestedType as embeddable object.',
                        $class->getName()
                    )
                );
        }

        return $type;
    }

    private function getDocumentPropertiesReflection(\ReflectionClass $class): array
    {
        if (in_array($class->getName(), $this->properties)) {
            return $this->properties[$class->getName()];
        }

        $properties = [];

        foreach ($class->getProperties() as $property) {
            if (!in_array($property->getName(), $properties)) {
                $properties[$property->getName()] = $property;
            }
        }

        $parentReflection = $class->getParentClass();
        if ($parentReflection !== false) {
            $properties = array_merge(
                $properties,
                array_diff_key($this->getDocumentPropertiesReflection($parentReflection), $properties)
            );
        }

        $this->properties[$class->getName()] = $properties;

        return $properties;
    }
}
