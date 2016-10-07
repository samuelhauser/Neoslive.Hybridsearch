<?php
namespace Neoslive\Hybridsearch\Aspects\TYPO3\Fluid\View;

/*
 * This file is part of the TYPO3.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\ORM\Mapping as ORM;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Aop\JoinPointInterface;

/**
 * @Flow\Aspect
 */
class StandaloneViewAspect
{


    /**
     * @Flow\Around("method(TYPO3\Fluid\View\StandaloneView->getTemplatePathAndFilename())")
     * @return void
     */
    public function getTemplatePathAndFilename(JoinPointInterface $joinPoint)
    {

       $templatePathAndFilename = $joinPoint->getAdviceChain()->proceed($joinPoint);

        if (is_file($templatePathAndFilename) === false) {
            $templatePathAndFilename = 'resource://Neoslive.Hybridsearch/Private/Templates/Fallback.html';
            $standaloneView = $joinPoint->getProxy();
            \TYPO3\Flow\Reflection\ObjectAccess::setProperty($standaloneView, 'templatePathAndFilename', $templatePathAndFilename);
        }

        return $templatePathAndFilename;

    }


}