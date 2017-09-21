<?php

namespace Neos\ContentGraph\DoctrineDbalAdapter\Domain\Repository;

/*
 * This file is part of the Neos.ContentGraph.DoctrineDbalAdapter package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */
use Doctrine\DBAL\Connection;
use Neos\ContentGraph\DoctrineDbalAdapter\Infrastructure\Dto\HierarchyEdge;
use Neos\ContentGraph\DoctrineDbalAdapter\Infrastructure\Service\DbalClient;
use Neos\ContentGraph\Infrastructure\Dto\Node;
use Neos\ContentRepository\Domain\ValueObject\NodeIdentifier;
use Neos\ContentRepository\Domain\ValueObject\SubgraphIdentifier;
use Neos\Flow\Annotations as Flow;

/**
 * The read only content graph for projection support
 *
 * @Flow\Scope("singleton")
 */
class ProjectionContentGraph
{
    /**
     * @Flow\Inject
     * @var DbalClient
     */
    protected $client;


    public function isEmpty(): bool
    {
        return (int)$this->getDatabaseConnection()
                ->executeQuery('SELECT count(*) FROM neos_contentgraph_node')
                ->fetch()['count'] > 0
            && (int)$this->getDatabaseConnection()
                ->executeQuery('SELECT count(*) FROM neos_contentgraph_hierarchyrelation')
                ->fetch()['count'] > 0;
    }

    public function getNode(string $nodeIdentifier, string $subgraphIdentityHash): Node
    {
        $nodeData = $this->getDatabaseConnection()->executeQuery(
            'SELECT n.* FROM neos_contentgraph_node n
 WHERE nodeidentifier = :nodeIdentifier
 AND subgraphidentityhash = :subgraphIdentityHash',
            [
                'nodeIdentifier' => $nodeIdentifier,
                'subgraphIdentityHash' => $subgraphIdentityHash
            ]
        )->fetch();

        return $this->mapRawDataToNode($nodeData);
    }

    public function getEdgePosition(NodeIdentifier $parentIdentifier, NodeIdentifier $precedingSiblingIdentifier = null, SubgraphIdentifier $subgraphIdentifier)
    {
        if ($precedingSiblingIdentifier) {
            $precedingSiblingPosition = (int)$this->getDatabaseConnection()->executeQuery(
                'SELECT h.position FROM neos_contentgraph_hierarchyrelation h
                          WHERE h.childnodeidentifier = :precedingSiblingIdentifier
                          AND subgraphIdentityHash = :subgraphIdentityHash',
                [
                    'precedingSiblingIdentifier' => (string)$precedingSiblingIdentifier,
                    'subgraphIdentityHash' => $subgraphIdentifier->getHash()
                ]
            )->fetch()['position'];

            $youngerSiblingEdge = $this->getDatabaseConnection()->executeQuery(
                'SELECT MIN(h.position) AS `position` FROM neos_contentgraph_hierarchyrelation h
 WHERE h.parentnodeidentifier = :parentNodeIdentifier
 AND h.subgraphidentityhash = :subgraphIdentityHash
 AND h.`position` > :position',
                [
                    'parentNodeIdentifier' => (string)$parentIdentifier,
                    'subgraphIdentityHash' => $subgraphIdentifier->getHash(),
                    'position' => $precedingSiblingPosition
                ]
            )->fetch();

            if (!is_null($youngerSiblingEdge['position'])) {
                $position = ($precedingSiblingPosition + (int)$youngerSiblingEdge['position']) / 2;
            } else {
                $position = $precedingSiblingPosition + 128;
            }
        } else {
            $leftmostPrecedingSiblingEdge = $this->getDatabaseConnection()->executeQuery(
                'SELECT MIN(h.position) AS `position` FROM neos_contentgraph_hierarchyrelation h
 WHERE h.parentnodeidentifier = :parentNodeIdentifier
 AND h.subgraphidentityhash = :subgraphIdentityHash
 ORDER BY h.`position` ASC',
                [
                    'parentNodeIdentifier' => (string)$parentIdentifier,
                    'subgraphIdentityHash' => $subgraphIdentifier->getHash(),
                ]
            )->fetch();

            if ($leftmostPrecedingSiblingEdge) {
                $position = ((int)$leftmostPrecedingSiblingEdge['position']) - 128;
            } else {
                $position = 0;
            }
        }

        return $position;
    }

    /**
     * @param string $parentNodesIdentifierInGraph
     * @param string $subgraphIdentityHash
     * @return array|HierarchyEdge[]
     */
    public function getOutboundHierarchyEdgesForNodeAndSubgraph(string $parentNodesIdentifierInGraph, string $subgraphIdentityHash): array
    {
        $edges = [];
        foreach ($this->getDatabaseConnection()->executeQuery(
            'SELECT h.* FROM neos_contentgraph_hierarchyrelation h
 WHERE parentnodesidentifieringraph = :parentNodesIdentifierInGraph
 AND subgraphIdentityHash = :subgraphIdentityHash',
            [
                'parentNodesIdentifierInGraph' => $parentNodesIdentifierInGraph,
                'subgraphIdentityHash' => $subgraphIdentityHash
            ]
        )->fetchAll() as $edgeData) {
            $edges[] = $this->mapRawDataToHierarchyEdge($edgeData);
        }

        return $edges;
    }


    /**
     * @param string $childNodesIdentifierInGraph
     * @param array $subgraphIdentityHashs
     * @return array|HierarchyEdge[]
     */
    public function findInboundHierarchyEdgesForNodeAndSubgraphs(string $childNodesIdentifierInGraph, array $subgraphIdentityHashs): array
    {
        $edges = [];
        foreach ($this->getDatabaseConnection()->executeQuery(
            'SELECT h.* FROM neos_contentgraph_hierarchyrelation h
 WHERE childnodesidentifieringraph = :childNodesIdentifierInGraph
 AND subgraphIdentityHash IN (:subgraphIdentityHashs)',
            [
                'childNodesIdentifierInGraph' => $childNodesIdentifierInGraph,
                'subgraphIdentityHashs' => $subgraphIdentityHashs
            ],
            [
                'subgraphIdentityHashs' => Connection::PARAM_STR_ARRAY
            ]
        )->fetchAll() as $edgeData) {
            $edges[] = $this->mapRawDataToHierarchyEdge($edgeData);
        }

        return $edges;
    }

    /**
     * @param string $parentNodesIdentifierInGraph
     * @param array $subgraphIdentityHashs
     * @return array|HierarchyEdge[]
     */
    public function findOutboundHierarchyEdgesForNodeAndSubgraphs(string $parentNodesIdentifierInGraph, array $subgraphIdentityHashs): array
    {
        $edges = [];
        foreach ($this->getDatabaseConnection()->executeQuery(
            'SELECT h.* FROM neos_contentgraph_hierarchyrelation h
 WHERE parentnodesidentifieringraph = :parentNodesIdentifierInGraph
 AND subgraphIdentityHash IN (:subgraphIdentityHashs)',
            [
                'parentNodesIdentifierInGraph' => $parentNodesIdentifierInGraph,
                'subgraphIdentityHashs' => $subgraphIdentityHashs
            ],
            [
                'subgraphIdentityHashs' => Connection::PARAM_STR_ARRAY
            ]
        )->fetchAll() as $edgeData) {
            $edges[] = $this->mapRawDataToHierarchyEdge($edgeData);
        }

        return $edges;
    }

    protected function mapRawDataToHierarchyEdge(array $rawData): HierarchyEdge
    {
        return new HierarchyEdge(
            $rawData['parentnodesidentifieringraph'],
            $rawData['childnodesidentifieringraph'],
            $rawData['name'],
            $rawData['subgraphidentityhash'],
            $rawData['position']
        );
    }

    protected function mapRawDataToNode(array $rawData): Node
    {
        return new Node(
            $rawData['nodeidentifier'],
            $rawData['nodeaggregateidentifier'],
            $rawData['subgraphidentifier'],
            $rawData['subgraphidentityhash'],
            json_decode($rawData['properties'], true),
            $rawData['nodetypename']
        );
    }

    protected function getDatabaseConnection(): Connection
    {
        return $this->client->getConnection();
    }
}