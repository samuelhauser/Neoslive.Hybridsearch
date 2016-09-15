<?php
namespace Neoslive\Hybridsearch\Factory;

/*
 * This file is part of the Neoslive.Hybridsearch package.
 *
 * (c) Contributors to the package
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */


use TYPO3\Flow\Annotations as Flow;
use TYPO3\Neos\Domain\Repository\SiteRepository;
use TYPO3\Neos\Domain\Service\ContentContextFactory;
use TYPO3\TYPO3CR\Domain\Model\Node;
use TYPO3\Neos\Domain\Model\Site;
use TYPO3\TYPO3CR\Domain\Model\Workspace;
use TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository;
use TYPO3\TYPO3CR\Domain\Repository\WorkspaceRepository;
use TYPO3\TYPO3CR\Domain\Service\ContentDimensionCombinator;
use TYPO3\Eel\FlowQuery\FlowQuery;
use \Org\Heigl\Hyphenator as h;
use \ForceUTF8\Encoding;
use Firebase\FirebaseLib;
use TYPO3\Flow\Utility\Algorithms;
use TYPO3\Flow\Core\Booting\Scripts;

class SearchIndexFactory
{

    /**
     * @Flow\InjectConfiguration(package="TYPO3.Flow")
     * @var array
     */
    protected $flowSettings;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;


    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @var \TYPO3\Flow\Utility\Environment
     */
    protected $environment;

    /**
     * @Flow\Inject
     * @var ContentContextFactory
     */
    protected $contentContextFactory;


    /**
     * @Flow\Inject
     * @var ContentDimensionCombinator
     */
    protected $contentDimensionCombinator;


    /**
     * @var mixed
     */
    protected $hyphenator;


    /**
     * @var \stdClass
     */
    protected $index;


    /**
     * @var \stdClass
     */
    protected $keywords;


    /**
     * @var array
     */
    protected $settings;


    /**
     * @var string
     */
    protected $basepath;


    /**
     * @var string
     */
    protected $temporaryDirectory;


    /**
     * @var FirebaseLib
     */
    protected $firebase;


    /**
     * @var boolean
     */
    protected $creatingFullIndex = false;



    /**
     * Inject the settings
     *
     * @param array $settings
     * @return void
     */
    public function injectSettings(array $settings)
    {
        $this->settings = $settings;

        $this->index = new \stdClass();
        $this->keywords = new \stdClass();

    }

    /**
     * Injects the Environment object
     *
     * @param \TYPO3\Flow\Utility\Environment $environment
     * @return void
     */
    public function injectEnvironment(\TYPO3\Flow\Utility\Environment $environment)
    {

        $this->firebase = new FirebaseLib($this->settings['Firebase']['endpoint'], $this->settings['Firebase']['token']);
        $this->firebase->setTimeOut(0);

        $this->environment = $environment;

        $temporaryDirectory = $this->environment->getPathToTemporaryDirectory() . 'NeosliveHybridsearch/';

        if (!is_writable($temporaryDirectory)) {
            try {
                \TYPO3\Flow\Utility\Files::createDirectoryRecursively($temporaryDirectory);
            } catch (\TYPO3\Flow\Utility\Exception $exception) {
                throw new Exception('The temporary directory "' . $temporaryDirectory . '" could not be created.', 1264426237);
            }
        }
        if (!is_dir($temporaryDirectory) && !is_link($temporaryDirectory)) {
            throw new Exception('The temporary directory "' . $temporaryDirectory . '" does not exist.', 1203965199);
        }
        if (!is_writable($temporaryDirectory)) {
            throw new Exception('The temporary directory "' . $temporaryDirectory . '" is not writable.', 1203965200);
        }

        $this->temporaryDirectory = $temporaryDirectory;


    }


    /**
     * Create full search index for given node path
     * @param string $path path of the root node name
     * @param Site $site
     * @param string $workspacename
     * @return void
     */
    public function createFullIndex($path, $site, $workspacename)
    {


        foreach ($this->workspaceRepository->findAll() as $workspace) {

            /** @var Workspace $workspace */
            if ($workspacename === null || $workspacename === $workspace->getName()) {
                $this->deleteWorkspace($workspace);
                $this->createIndex($path, $workspace, $site);
                $this->save();
            }

        }


    }


    /**
     * Update index for given node and target workspace
     * @param Node $node
     * @param Workspace $workspace
     */
    public function updateIndex($node, $workspace)
    {

        \TYPO3\Flow\var_dump($node);
        $this->generateSingleIndex($node, $this->getWorkspaceHash($workspace), $node->getNodeData()->getDimensionsHash());
        $this->save();

    }

    /**
     * Update index for given node and target workspace
     * @param Node $node
     * @param Workspace $workspace
     */
    public function removeIndex($node, $workspace)
    {
        $this->removeSingleIndex($node, $this->getWorkspaceHash($workspace), $node->getNodeData()->getDimensionsHash());
    }

    /**
     * Create search index for given root node name, workspace and site
     *
     *
     * @param string $path node identified by path used as entry point for creating search index
     * @param Workspace $workspace workspace creating search index for
     * @param Site $site neos site
     * @param boolean $includingSelf If specified, indexing self node otherwise only children
     * @return void
     */
    public function createIndex($path, $workspace, $site, $includingSelf = false)
    {


        // TODO: Performance could be improved by a search for all child node data instead of looping over all contexts
        foreach ($this->contentDimensionCombinator->getAllAllowedCombinations() as $dimensionConfiguration) {


            $context = $this->createContext($workspace->getName(), $dimensionConfiguration, $site);

            /** @var Node $node */
            $node = new Node(
                $this->nodeDataRepository->findOneByPath($path, $workspace),
                $context
            );

            $this->generateIndex($node, $workspace, $dimensionConfiguration, '', $includingSelf);


        }


    }


    /**
     * Generates recursive search index for given root node
     *
     * @param Node $node node used as entry point for creating search index
     * @param Workspace $workspace for generating index
     * @param array $dimensionConfiguration dimension configuration array
     * @param string $nodeTypeFilter If specified, only nodes with that node type are considered
     * @param boolean $includingSelf If specified, indexing self node otherwise only children
     * @return void
     */
    private function generateIndex($node, $workspace, $dimensionConfiguration, $nodeTypeFilter = '', $includingSelf = false)
    {


        if ($nodeTypeFilter === '') {
            if (isset($this->settings['Filter']['NodeTypeFilter'])) {
                $nodeTypeFilter = $this->settings['Filter']['NodeTypeFilter'];
            } else {
                $nodeTypeFilter = '[instanceof TYPO3.Neos:Content]';
            }
        }


        $workspaceHash = $this->getWorkspaceHash($workspace);
        $dimensionConfigurationHash = $this->getDimensionConfiugurationHash($dimensionConfiguration);


        $flowQuery = new FlowQuery(array($node));


        if ($includingSelf) {
            $this->generateSingleIndex($node, $workspaceHash, $dimensionConfigurationHash);
        }


        foreach ($flowQuery->find($nodeTypeFilter) as $children) {

            /** @var Node $children */
            $this->generateSingleIndex($children, $workspaceHash, $dimensionConfigurationHash);

        }


    }


    /**
     * Remove single index for given node
     *
     * @param Node $node
     * @param String $workspaceHash
     * @param string $dimensionConfigurationHash
     * @param array $skipKeywords
     * @return void
     */
    private function removeSingleIndex($node, $workspaceHash, $dimensionConfigurationHash, $skipKeywords = array())
    {

        if ($this->creatingFullIndex === false) {

            $basepath = $this->getBasePath();

            foreach ($this->getIndexByNode($node, $workspaceHash, $dimensionConfigurationHash, $skipKeywords) as $keyword => $val) {
                $this->firebaseDelete($basepath . "/index/$workspaceHash/$dimensionConfigurationHash" . "/" . urlencode($keyword) . "/" . urlencode($node->getIdentifier()));
            }

            $this->firebaseDelete($basepath . "/keywords/$workspaceHash/$dimensionConfigurationHash" . "/" . urlencode($node->getIdentifier()));

        }


    }

    /**
     * Generates single index for given node
     *
     * @param Node $node
     * @param String $workspaceHash
     * @param string $dimensionConfigurationHash
     * @return void
     */
    private function generateSingleIndex($node, $workspaceHash, $dimensionConfigurationHash)
    {


        if ($node->isHidden() || $node->isRemoved()) {

            // skipp node
        } else {


            if (isset($this->keywords->keywords->$workspaceHash->$dimensionConfigurationHash->keywords) === false) {
                $this->keywords->keywords = new \stdClass();
                $this->keywords->keywords->$workspaceHash = new \stdClass();
                $this->keywords->keywords->$workspaceHash->$dimensionConfigurationHash = new \stdClass();
                $this->keywords->keywords->$workspaceHash->$dimensionConfigurationHash->keywords = new \stdClass();
            }


            if (isset($this->index->$workspaceHash) === false) {
                $this->index->$workspaceHash = new \stdClass();
            }

            if (isset($this->index->$workspaceHash->$dimensionConfigurationHash) === false) {
                $this->index->$workspaceHash->$dimensionConfigurationHash = new \stdClass();
            }


            $indexData = $this->convertNodeToSearchIndexResult($node);
            $identifier = $indexData->identifier;

            $this->keywords->keywords->$workspaceHash->$dimensionConfigurationHash->$identifier = new \stdClass();

            $keywords = $this->generateSearchIndexFromProperties($indexData->properties);
            $this->removeSingleIndex($node, $workspaceHash, $dimensionConfigurationHash, $keywords);

            foreach ($keywords as $keyword => $frequency) {

                if (isset($this->index->$workspaceHash->$dimensionConfigurationHash->$keyword) === false) {
                    $this->index->$workspaceHash->$dimensionConfigurationHash->$keyword = new \stdClass();
                }
                $this->index->$workspaceHash->$dimensionConfigurationHash->$keyword->$identifier = $indexData;
                $this->keywords->keywords->$workspaceHash->$dimensionConfigurationHash->$identifier->$keyword = true;


            }


        }


    }

    /**
     * Generate search index words from properties array
     *
     * @param array $properties
     * @return void
     */
    protected function generateSearchIndexFromProperties($properties)
    {


        if (count($properties) === 0) {

            return $properties;
        }

        $keywords = array();

        $text = "";


        foreach ($properties as $property => $value) {

            if (gettype($value) !== 'string') {

                $value = json_encode($value);
            }

            $text .= preg_replace("/[^A-z0-9öäüÖÄÜ ]/", "", mb_strtolower(strip_tags(preg_replace("/[^A-z0-9öäüÖÄÜ]/", " ", $value)))) . " ";

        }

        $words = explode(" ", $text);


        $hypenated = $this->getHyphenator()->hyphenate($text);
        if (is_string($hypenated)) {
            $hwords = explode(" ", $hypenated);
            foreach ($hwords as $key => $v) {
                if (strlen($v) > 2) {
                    $words[] = $v;
                }
            }
        }

        foreach ($words as $w) {
            if (strlen($w) > 1) {
                $w = Encoding::UTF8FixWin1252Chars($w);
                $keywords[$w] = isset($keywords[$w]) ? $keywords[$w] + 1 : 1;
            }
        }

        return $keywords;

    }


    /**
     * @param Node $node
     * @param string $grandParentNodeFilter
     * @param string $parentNodeFilter
     * @return \stdClass
     */
    private function convertNodeToSearchIndexResult($node, $grandParentNodeFilter = '', $parentNodeFilter = '')
    {

        if ($grandParentNodeFilter === '') {
            if (isset($this->settings['Filter']['GrantParentNodeTypeFilter'])) {
                $grandParentNodeFilter = $this->settings['Filter']['GrantParentNodeTypeFilter'];
            } else {
                $grandParentNodeFilter = '[instanceof TYPO3.Neos:Content]';
            }
        }

        if ($parentNodeFilter === '') {
            if (isset($this->settings['Filter']['ParentNodeTypeFilter'])) {
                $parentNodeFilter = $this->settings['Filter']['ParentNodeTypeFilter'];
            } else {
                $parentNodeFilter = '[instanceof TYPO3.Neos:Content]';
            }
        }


        $properties = new \stdClass();
        foreach ($node->getProperties() as $key => $val) {


            if (gettype($val) === 'string') {
                $k = mb_strtolower(preg_replace("/[^A-z0-9]/", "-", $node->getNodeType()->getName() . ":" . $key));
                if (is_string($val)) {

                    $properties->$k = (Encoding::UTF8FixWin1252Chars($val));


                }
            }
        }


        $flowQuery = new FlowQuery(array($node));

        $parentNode = $flowQuery->parent()->closest($parentNodeFilter)->get(0);
        $grandParentNode = $flowQuery->closest($grandParentNodeFilter)->get(0);


        $parentProperties = new \stdClass();
        $parentPropertiesText = '';
        if ($parentNode) {
            foreach ($parentNode->getProperties() as $key => $val) {
                if (gettype($val) === 'string') {
                    $k = mb_strtolower(preg_replace("/[^A-z]/", "-", $parentNode->getNodeType()->getName() . ":" . $key));
                    $parentProperties->$k = (Encoding::UTF8FixWin1252Chars($val));
                    $parentPropertiesText .= (Encoding::UTF8FixWin1252Chars($val)) . " ";
                }
            }

            $properties->parent = (Encoding::UTF8FixWin1252Chars($parentPropertiesText));
        }

        $grandParentProperties = new \stdClass();
        $grandParentPropertiesText = '';
        if ($grandParentNode) {
            foreach ($grandParentNode->getProperties() as $key => $val) {
                if (gettype($val) === 'string') {

                    $k = mb_strtolower(preg_replace("/[^A-z]/", "-", $grandParentNode->getNodeType()->getName() . ":" . $key));
                    $grandParentProperties->$k = (Encoding::UTF8FixWin1252Chars($val));
                    $grandParentPropertiesText .= (Encoding::UTF8FixWin1252Chars($val)) . " ";
                }
            }

            $properties->grandparent = (Encoding::UTF8FixWin1252Chars($grandParentPropertiesText));
        }

        $data = new \stdClass();


        $data->identifier = $node->getNodeData()->getIdentifier();
        $data->properties = $properties;
        $data->nodeType = $node->getNodeType()->getName();
        $data->isHidden = $node->isHidden();
        $data->isRemoved = $node->isRemoved();


        $data->grandParentNode = new \stdClass();
        $data->grandParentNode->identifier = $grandParentNode ? $grandParentNode->getIdentifier() : null;
        $data->grandParentNode->properties = $grandParentProperties;
        $data->grandParentNode->nodeType = $grandParentNode ? $grandParentNode->getNodeType()->getName() : '';

        if ($parentNode) {
            $data->parentNode = new \stdClass();
            $data->parentNode->identifier = $parentNode->getIdentifier();
            $data->parentNode->properties = $parentProperties;
            $data->parentNode->nodeType = $parentNode->getNodeType()->getName();
        }


        return $data;


    }


    /**
     * Get dimension confiuguration hash (replace critical strings)
     * @param array $dimensionConfiguration
     * @return string
     */
    private function getDimensionConfiugurationHash($dimensionConfiguration)
    {

        return \TYPO3\TYPO3CR\Utility::sortDimensionValueArrayAndReturnDimensionsHash($dimensionConfiguration);

    }


    /**
     * Get workspace hash (replace critical strings) for given workspace
     * @param Workspace $workspace
     * @return string
     */
    private function getWorkspaceHash($workspace)
    {

        return preg_replace("/^A-z0-9/", "-", $workspace->getName());

    }


    /**
     * @param string $path
     * @param mixed $data
     * @return void
     */
    public function firebaseUpdate($path, $data)
    {


        $this->addToQueue($path, $data, 'update');

    }

    /**
     * @param string $path
     * @param mixed $data
     * @return void
     */
    public function firebaseSet($path, $data)
    {

        $this->addToQueue($path, $data, 'set');


    }


    /**
     * @param string $path
     * @return void
     */
    public function firebaseDelete($path)
    {

        $this->addToQueue($path, null, 'delete');


    }


    /**
     * @param string $path
     * @param mixed $data
     * @param string $method
     * @return void
     */
    protected function addToQueue($path, $data = null, $method = 'update')
    {

        $filename = $this->temporaryDirectory . "/queued_" . time() . "_" . Algorithms::generateUUID() . ".json";
        $fp = fopen($filename, 'w');
        fwrite($fp, json_encode(
            array(
                'path' => $path,
                'data' => $data,
                'method' => $method,
            )
        ));
        fclose($fp);

    }


    /**
     * @return void
     */
    public function proceedQueue()
    {


        $lockedfilename = $this->temporaryDirectory . "/locked.txt";

        if (is_file($lockedfilename) === true) {

            sleep(3);
            $this->proceedQueue();

        } else {

            $fp = fopen($lockedfilename, 'w');
            fwrite($fp, time());
            fclose($fp);

            $files = array();

            $fp = opendir($this->temporaryDirectory);
            while (false !== ($entry = readdir($fp))) {

                if (substr($entry, 0, 6) === 'queued' && rename($this->temporaryDirectory . "/" . $entry, $this->temporaryDirectory . "/locked." . $entry)) {
                    $files[] = $this->temporaryDirectory . "/locked." . $entry;
                }

            }

            unlink($lockedfilename);
            sort($files);


            foreach ($files as $file) {

                $content = json_decode(file_get_contents($file));

                if ($content) {

                    switch ($content->method) {
                        case 'update':
                            $this->firebase->update($content->path, $content->data);
                            break;

                        case 'delete':
                            $this->firebase->delete($content->path);
                            break;

                        case 'set':
                            $this->firebase->set($content->path, $content->data);
                            break;
                    }

                }


                if (substr($file, 0, strlen($this->temporaryDirectory)) === $this->temporaryDirectory) {
                    unlink($file);
                }


            }


        }


    }


    /**
     * Save generated search index as tempory json file for persisting later
     * @param string $target keywords|index
     * @param mixed index
     * @param mixed keywords
     * @return void
     */
    protected function save($target = 'all', $index = false, $keywords = false)
    {

        if ($index === false) {
            $index = $this->index;
        }

        if ($keywords === false) {
            $keywords = $this->keywords;
        }

        $basepath = $this->getBasePath();


        if ($this->creatingFullIndex) {

            if ($target === 'all' || $target == 'index') {
                // patch index data all in one request
                foreach ($index as $workspace => $workspaceData) {
                    foreach ($workspaceData as $dimension => $dimensionData) {
                        $this->firebaseSet($basepath . "/index/" . $workspace . "/" . $dimension, $dimensionData);
                    }
                }
            }

            if ($target === 'all' || $target == 'keywords') {
                // patch keywords data all in one request
                $this->firebaseUpdate($basepath, $keywords);
            }


        } else {

            if ($target === 'all' || $target == 'index') {
                // put index data node by node for keep old records existing
                foreach ($index as $workspace => $workspaceData) {
                    foreach ($workspaceData as $dimension => $dimensionData) {
                        foreach ($dimensionData as $keyword => $keywordData) {
                            foreach ($keywordData as $node => $nodeData) {
                                $this->firebaseUpdate($basepath . "/index/" . $workspace . "/" . $dimension . "/" . urlencode($keyword) . "/" . urlencode($node), $nodeData);
                            }
                        }
                    }
                }
            }
            if ($target === 'all' || $target == 'keywords') {
                // patch keywords by node for keep old records existing
                foreach ($keywords as $path => $pathData) {
                    foreach ($pathData as $workspace => $workspaceData) {
                        foreach ($workspaceData as $dimension => $dimensionData) {
                            foreach ($dimensionData as $node => $nodeData) {
                                $this->firebaseSet($basepath . "/keywords/" . $workspace . "/" . $dimension . "/" . urlencode($node), $nodeData);
                            }
                        }
                    }
                }
            }


        }


        if ($target === 'all' || $target == 'index') {
            $this->index = new \stdClass();
        }

        if ($target === 'all' || $target == 'keywords') {
            $this->keywords = new \stdClass();
        }

        Scripts::executeCommandAsync(' hybridsearch:proceed', $this->flowSettings,array());




    }


    /**
     * Get Firebase index by node
     * @param Node $node
     * @param String $workspaceHash
     * @param string $dimensionConfigurationHash
     * @param array $skipKeywords
     * @return array
     */
    public function getIndexByNode($node, $workspaceHash, $dimensionConfigurationHash, $skipKeywords = array())
    {

        $path = $this->getBasePath() . "/keywords/" . $workspaceHash . "/" . $dimensionConfigurationHash . "/" . $node->getIdentifier();
        $result = $this->firebase->get($path);

        if ($result != 'null') {
            $result = json_decode($result);
        } else {
            $result = new \stdClass();
        }

        if (count($skipKeywords)) {
            foreach (get_object_vars($result) as $keyword => $val) {
                if (isset($skipKeywords[$keyword])) {
                    unset($result->$keyword);
                }
            }
        }

        return $result;


    }


    /**
     * Delete index for given workspace
     * Do firebase delete request
     * @param Workspace $workspace
     * @return mixed
     */
    protected
    function deleteWorkspace($workspace)
    {

        $this->creatingFullIndex = true;

        $this->firebase->delete($this->getBasePath() . 'index/' . $workspace->getName());
        $this->firebase->delete($this->getBasePath() . 'keywords/' . $workspace->getName());

    }

    /**
     * Get firebase base path
     * @return string
     */
    protected
    function getBasePath()
    {


        return $this->settings['Firebase']['path']."/";


    }


    /**
     * Get Hyphenator instance
     *
     * @return h\Hyphenator
     */
    protected
    function getHyphenator()
    {


        if ($this->hyphenator) {
            return $this->hyphenator;
        }

        $o = new h\Options();
        $o->setHyphen(' ')
            ->setDefaultLocale('de_DE')
            ->setRightMin(4)
            ->setLeftMin(4)
            ->setWordMin(4)
            ->setQuality(100)
            ->setMinWordLength(10)
            ->setFilters('Simple')
            ->setTokenizers('Whitespace', 'Punctuation');
        $this->hyphenator = new h\Hyphenator();
        $this->hyphenator->setOptions($o);

        return $this->hyphenator;


    }

    /**
     * Creates a content context for given workspace
     *
     * @param string $workspaceName
     * @param array $dimensions
     * @param Site $currentSite
     * @return \TYPO3\TYPO3CR\Domain\Service\Context
     */
    protected
    function createContext($workspaceName, $dimensions, $currentSite)
    {


        return $this->contentContextFactory->create(array(
            'workspaceName' => $workspaceName,
            'currentSite' => $currentSite,
            'dimensions' => $dimensions,
            'invisibleContentShown' => false,
            'inaccessibleContentShown' => false,
            'removedContentShown' => false
        ));
    }


}
