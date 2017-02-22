<?php
namespace Neoslive\Hybridsearch\Aspects\Neos\Domain\Repository;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neoslive\Hybridsearch\Factory\SearchIndexFactory;
use Neos\ContentRepository\Domain\Model\NodeData;


/**
 * @Flow\Aspect
 */
class NodeDataRepositoryAspect
{


    /**
     * @Flow\Inject
     * @var SearchIndexFactory
     */
    protected $searchIndexFactory;

    /**
     * @var array
     */
    protected $nodesupdated;


    /**
     * @Flow\AfterReturning("within(Neos\Flow\Persistence\PersistenceManagerInterface) && method(public .+->(add|update)())")
     * @param JoinPointInterface $joinPoint
     * @return string
     */
    public function updateObjectToIndex(JoinPointInterface $joinPoint)
    {
        $arguments = $joinPoint->getMethodArguments();
        $object = reset($arguments);

        if ($object instanceof NodeData && $object->getWorkspace()->getName() == 'live' && $object->getNodeType()->hasConfiguration('properties.neoslivehybridsearchrealtime')) {
           $GLOBALS['neoslivehybridsearchrealtimequeue'][$object->getWorkspace()->getName()][$object->getIdentifier()] = 1;
        }

    }

    /**
     * @Flow\AfterReturning("within(Neos\Flow\Persistence\PersistenceManagerInterface) && method(public .+->(persistAll)())")
     * @param JoinPointInterface $joinPoint
     * @return string
     */
    public function persistAllObjectToIndex(JoinPointInterface $joinPoint)
    {
        $this->searchIndexFactory->executeRealtimeSync();
    }


    /**
     * @Flow\AfterReturning("within(Neos\Flow\Persistence\PersistenceManagerInterface) && method(public .+->(remove)())")
     * @param JoinPointInterface $joinPoint
     * @return string
     */
    public function removeObjectToIndex(JoinPointInterface $joinPoint)
    {
        $arguments = $joinPoint->getMethodArguments();
        $object = reset($arguments);

        if ($object instanceof NodeData && $object->getWorkspace()->getName() == 'live' && $object->getNodeType()->hasConfiguration('properties.neoslivehybridsearchrealtime')) {
            if (isset($GLOBALS['neoslivehybridsearch'.$object->getIdentifier()]) == false) {
                $this->searchIndexFactory->checkIndexRealtimeForRemovingNodeData($object);
            }
        }

    }


}
