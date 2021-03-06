<?php
namespace JBartels\BeAcl\ViewHelpers;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3\CMS\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3\CMS\Fluid\Core\ViewHelper\Facets\CompilableInterface;
use TYPO3\CMS\Beuser\ViewHelpers\PermissionsViewHelper as BasePermissionsViewHelper;

/**
 * Render permission icon group (user / group / others) of the "Access" module.
 *
 * Most of that could be done in fluid directly, but this view helper
 * is much better performance wise.
 */
class PermissionsViewHelper extends BasePermissionsViewHelper implements CompilableInterface
{

    /**
     * Implementing CompilableInterface suppresses object instantiation of this view helper
     *
     * @param array $arguments
     * @param \Closure $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     * @return string
     * @throws \TYPO3\CMS\Fluid\Core\ViewHelper\Exception
     */
    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext
    ) {
        $masks = [1, 16, 2, 4, 8];

        if (empty(static::$permissionLabels)) {
            foreach ($masks as $mask) {
                static::$permissionLabels[$mask] = LocalizationUtility::translate(
                    'LLL:EXT:beuser/Resources/Private/Language/locallang_mod_permission.xlf:' . $mask,
                    'be_user'
                );
            }
        }

        $icon = '';
        foreach ($masks as $mask) {
            if ($arguments['permission'] & $mask) {
                $permissionClass = 'fa-check text-success';
                $mode = 'delete';
            } else {
                $permissionClass = 'fa-times text-danger';
                $mode = 'add';
            }

            $label = htmlspecialchars(static::$permissionLabels[$mask]);
            $icon .= '<span'
                . ' title="' . $label . '"'
                . ' data-toggle="tooltip"'
                . ' class="t3-icon fa ' . $permissionClass . '"></span>';
        }

        return '<span id="' . $arguments['pageId'] . '_' . $arguments['scope'] . '">' . $icon . '</span>';
    }
}
