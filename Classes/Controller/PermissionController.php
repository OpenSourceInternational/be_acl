<?php
namespace JBartels\BeAcl\Controller;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2005 Sebastian Kurfuerst (sebastian@garbage-group.de)
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Utility\IconUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Beuser\Controller\PermissionController as BaseController;
use JBartels\BeAcl\View\BackendTemplateView;

/**
 * Backend ACL - Replacement for "web->Access"
 *
 * @author  Sebastian Kurfuerst <sebastian@garbage-group.de>
 *
 * Bugfixes applied:
 * #25942, #25835, #13019, #13176, #13175 Jan Bartels
 */
class PermissionController extends BaseController
{

    protected $defaultViewObjectName = BackendTemplateView::class;

    protected $aclList = [];

    protected $currentAction;

    protected $aclTypes = [0, 1];

    /*****************************
     *
     * Listing and Form rendering
     *
     *****************************/

    /**
     * Initialize action
     *
     * @return void
     */
    protected function initializeAction()
    {
        parent::initializeAction();

        if(empty($this->returnUrl)) {
            $this->returnUrl = $this->uriBuilder->reset()->setArguments([
                'action' => 'index',
                'id' => $this->id
                ])->buildBackendUri();
        }
    }

    /**
     * Initializes view
     *
     * @param ViewInterface $view The view to be initialized
     * @return void
     */
    protected function initializeView(ViewInterface $view)
    {
        parent::initializeView($view);
        // Add custom JS for Acl permissions
        if ($view instanceof BackendTemplateView) {
            $view->getModuleTemplate()->getPageRenderer()->loadRequireJsModule('TYPO3/CMS/BeAcl/AclPermissions');
        }
    }

    /**
     * Index action
     *
     * @return void
     */
    public function indexAction()
    {
        parent::indexAction();

        // Get ACL configuration
        $beAclConfig = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['be_acl'];

        $disableOldPermissionSystem = $beAclConfig['disableOldPermissionSystem'] ? 1 : 0;
        $enableFilterSelector = $beAclConfig['enableFilterSelector'] ? 1 : 0;

        $this->view->assign('disableOldPermissionSystem', $disableOldPermissionSystem);
        $this->view->assign('enableFilterSelector', $enableFilterSelector);

        $GLOBALS['LANG']->includeLLFile('EXT:be_acl/Resources/Private/Languages/locallang_perm.xlf');

        /*
         *  User ACLs
         */
        $userAcls = $this->aclObjects(0, $beAclConfig);
        // If filter is enabled, filter user ACLs according to selection
        if ($enableFilterSelector) {
            $usersSelected = array_filter($userAcls, function ($var) {
                return !empty($var['selected']);
            });
        }
         // No filter enabled, so show all user ACLs
        else {
            $usersSelected = $userAcls;
        }
        $this->view->assign('userSelectedAcls', $usersSelected);

        // Options for user filter
        $this->view->assign('userFilterOptions', [
            'options' => $userAcls,
            'title' => $GLOBALS['LANG']->getLL('aclUsers'),
            'id' => 'userAclFilter'
        ]);

        /*
         *  Group ACLs
         */
        $groupAcls = $this->aclObjects(1, $beAclConfig);
        // If filter is enabled, filter group ACLs according to selection
        if ($enableFilterSelector) {
            $groupsSelected = array_filter($groupAcls, function ($var) {
                return !empty($var['selected']);
            });
        }
        // No filter enabled, so show all group ACLs
        else {
            $groupsSelected = $groupAcls;
        }
        $this->view->assign('groupSelectedAcls', $groupsSelected);

        // Options for group filter
        $this->view->assign('groupFilterOptions', [
            'options' => $groupAcls,
            'title' => $GLOBALS['LANG']->getLL('aclGroups'),
            'id' => 'groupAclFilter'
        ]);

        /*
         *  ACL Tree
         */
        $this->buildACLtree(array_keys($userAcls), array_keys($groupAcls));
        $this->view->assign('aclList', $this->aclList);
    }

    /**
     * Edit action
     *
     * @return void
     */
    public function editAction()
    {
        parent::editAction();

        // Get ACL configuration
        $beAclConfig = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['be_acl'];

        $disableOldPermissionSystem = $beAclConfig['disableOldPermissionSystem'] ? 1 : 0;

        $this->view->assign('disableOldPermissionSystem', $disableOldPermissionSystem);

        $GLOBALS['LANG']->includeLLFile('EXT:be_acl/Resources/Private/Languages/locallang_perm.xlf');

        // ACL CODE
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_beacl_acl');
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('*')
            ->from('tx_beacl_acl')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($this->id, \PDO::PARAM_INT)
                )
            );

        $result = $queryBuilder->execute()->fetchAll();
        $pageAcls = [];

        foreach ($result as $resultItem) {
            $pageAcls[] = $resultItem;
        }

        $userGroupSelectorOptions = [];
        foreach ([1 => 'Group', 0 => 'User'] as $type => $label) {
            $option = new \stdClass();
            $option->key = $type;
            $option->value = LocalizationUtility::translate('LLL:EXT:be_acl/Resources/Private/Languages/locallang_perm.xlf:acl' . $label,
                'be_acl');
            $userGroupSelectorOptions[] = $option;
        }
        $this->view->assign('userGroupSelectorOptions', $userGroupSelectorOptions);
        $this->view->assign('pageAcls', $pageAcls);
    }

    /**
     * Update action
     *
     * @param array $data
     * @param array $mirror
     * @return void
     */
    protected function updateAction(array $data, array $mirror)
    {
        // Process data map
        $tce = GeneralUtility::makeInstance(DataHandler::class);
        $tce->stripslashes_values = 0;
        $tce->start($data, []);
        $tce->process_datamap();

        parent::updateAction($data, $mirror);
    }

    /*****************************
     *
     * Helper functions
     *
     *****************************/

    /**
     * @param $type
     * @return mixed
     */
    protected function aclObjectQuery($type)
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_beacl_acl');
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('*')
            ->from('tx_beacl_acl')
            ->where(
                $queryBuilder->expr()->eq(
                    'type',
                    $queryBuilder->createNamedParameter($type, \PDO::PARAM_INT)
                )
            );

        $result = $queryBuilder->execute()->fetchAll();
        return $result;
    }

    protected function getCurrentAction()
    {
        if (is_null($this->currentAction)) {
            $this->currentAction = $this->request->getControllerActionName();
        }
        return $this->currentAction;
    }

    /**
     *
     * @global array $BE_USER
     * @param int $type
     * @param array $conf
     * @return array
     */
    protected function aclObjects($type, $conf)
    {
        global $BE_USER;
        $aclObjects = [];
        $currentSelection = [];

        // Run query
        $result = $this->aclObjectQuery($type);

        // Process results
        foreach ($result as $resultItem) {
            $aclObjects[$resultItem['object_id']] = $resultItem;
        }
        // Check results
        if (empty($aclObjects)) {
            return $aclObjects;
        }

        // If filter selector is enabled, then determine currently selected items
        if ($conf['enableFilterSelector']) {
            // get current selection from UC, merge data, write it back to UC
            $currentSelection = is_array($BE_USER->uc['moduleData']['txbeacl_aclSelector'][$type]) ? $BE_USER->uc['moduleData']['txbeacl_aclSelector'][$type] : [];

            $currentSelectionOverride_raw = GeneralUtility::_GP('tx_beacl_objsel');
            $currentSelectionOverride = [];
            if (is_array($currentSelectionOverride_raw[$type])) {
                foreach ($currentSelectionOverride_raw[$type] as $tmp) {
                    $currentSelectionOverride[$tmp] = $tmp;
                }
            }
            if ($currentSelectionOverride) {
                $currentSelection = $currentSelectionOverride;
            }

            $BE_USER->uc['moduleData']['txbeacl_aclSelector'][$type] = $currentSelection;
            $BE_USER->writeUC($BE_USER->uc);
        }

        // create option data
        foreach ($aclObjects as $k => &$v) {
            $v['selected'] = (in_array($k, $currentSelection)) ? 1 : 0;
        }

        return $aclObjects;
    }

    /**
     * Creates an ACL tree which correlates with tree for current page
     * Datastructure: pageid - userId / groupId - permissions
     * eg. $this->aclList[pageId][type][object_id] = [
     *      'permissions' => 31
     *      'recursive' => 1,
     *      'pid' => 10
     * ];
     *
     * @param array $users - user ID list
     * @param array $groups - group ID list
     */
    protected function buildACLtree($users, $groups)
    {
        $startPerms = [
            0 => [],
            1 => []
        ];

        // get permissions in the starting point for users and groups
        $rootLine = BackendUtility::BEgetRootLine($this->id);
        $currentPage = array_shift($rootLine); // needed as a starting point

        // Iterate rootline, looking for recursive ACLs that may apply to the current page
        foreach ($rootLine as $level => $values) {

            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('tx_beacl_acl');
            $queryBuilder = $connection->createQueryBuilder();
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder->select('*')
                ->from('tx_beacl_acl')
                ->where(
                    $queryBuilder->expr()->eq(
                        'recursive',
                        $queryBuilder->createNamedParameter(1, \PDO::PARAM_INT)
                    ),
                    $queryBuilder->expr()->eq(
                        'pid',
                        $queryBuilder->createNamedParameter($values['uid'], \PDO::PARAM_INT)
                    )
                );

            $result = $queryBuilder->execute()->fetchAll();

            foreach ($result as $resultItem) {
                // User type ACLs
                if ($resultItem['type'] == 0
                    && in_array($resultItem['object_id'], $users)
                    && !array_key_exists($resultItem['object_id'], $startPerms[0])
                ) {
                    $startPerms[0][$resultItem['object_id']] = [
                        'uid' => $resultItem['uid'],
                        'permissions' => $resultItem['permissions'],
                        'recursive' => $resultItem['recursive'],
                        'pid' => $resultItem['pid']
                    ];
                }
                // Group type ACLs
                elseif ($resultItem['type'] == 1
                    && in_array($resultItem['object_id'], $groups)
                    && !array_key_exists($resultItem['object_id'], $startPerms[1])
                ) {
                    $startPerms[1][$resultItem['object_id']] = [
                        'uid' => $resultItem['uid'],
                        'permissions' => $resultItem['permissions'],
                        'recursive' => $resultItem['recursive'],
                        'pid' => $resultItem['pid']
                    ];
                }
            }
        }

        $this->traversePageTree_acl($startPerms, $currentPage['uid']);
    }

    /**
     * @return array
     */
    protected function getDefaultAclMetaData()
    {
        return array_fill_keys($this->aclTypes, [
            'acls' => 0,
            'inherited' => 0
        ]);
    }

    /**
     * Adds count meta data to the page ACL list
     * @param array $pageData
     */
    protected function addAclMetaData(&$pageData)
    {
        if (!array_key_exists('meta', $pageData)) {
            $pageData['meta'] = $this->getDefaultAclMetaData();
        }

        foreach ($this->aclTypes as $type) {
            $pageData['meta'][$type]['inherited'] = (isset($pageData[$type]) && is_array($pageData[$type])) ? count($pageData[$type]) : 0;
        }
    }

    /**
     * Finds ACL permissions for specified page and its children recursively, given
     * the parent ACLs.
     * @param array $parentACLs
     * @param int $pageId
     */
    protected function traversePageTree_acl($parentACLs, $pageId)
    {


        // Fetch ACLs aasigned to given page
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_beacl_acl');
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('*')
            ->from('tx_beacl_acl')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT)
                )
            );
        $result = $queryBuilder->execute()->fetchAll();
        $hasNoRecursive = [];
        $this->aclList[$pageId] = $parentACLs;

        $this->addAclMetaData($this->aclList[$pageId]);

        foreach ($result as $resultItem) {
            $aclData = [
                'uid' => $resultItem['uid'],
                'permissions' => $resultItem['permissions'],
                'recursive' => $resultItem['recursive'],
                'pid' => $resultItem['pid']
            ];

            // Non-recursive ACL
            if ($resultItem['recursive'] == 0) {
                $this->aclList[$pageId][$resultItem['type']][$resultItem['object_id']] = $aclData;
                $hasNoRecursive[$pageId][$resultItem['type']][$resultItem['object_id']] = $aclData;
            }
            else {
                // Recursive ACL
                // Add to parent ACLs for sub-pages
                $parentACLs[$resultItem['type']][$resultItem['object_id']] = $aclData;
                // If there also is a non-recursive ACL for this object_id, that takes precedence
                // for this page. Otherwise, add it to the ACL list.
                if (is_array($hasNoRecursive[$pageId][$resultItem['type']][$resultItem['object_id']])) {
                    $this->aclList[$pageId][$resultItem['type']][$resultItem['object_id']] = $hasNoRecursive[$pageId][$resultItem['type']][$resultItem['object_id']];
                } else {
                    $this->aclList[$pageId][$resultItem['type']][$resultItem['object_id']] = $aclData;
                }
            }

            // Increment ACL count
            $this->aclList[$pageId]['meta'][$resultItem['type']]['acls'] += 1;
        }

        // Find child pages and their ACL permissions
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages');
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $queryBuilder->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'deleted',
                    $queryBuilder->createNamedParameter(false, \PDO::PARAM_BOOL)
                )
            );

        $result = $queryBuilder->execute()->fetchAll();

        if(!empty($result)){
            foreach ($result as $resultItem) {
                $this->traversePageTree_acl($parentACLs, $resultItem['uid']);
            }
        }
    }

}

