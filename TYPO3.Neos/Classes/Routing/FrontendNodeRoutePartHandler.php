<?php
namespace TYPO3\TYPO3\Routing;

/*                                                                        *
 * This script belongs to the FLOW3 package "TYPO3.TYPO3".                *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use \TYPO3\TYPO3\Domain\Service\ContentContext;
use \TYPO3\TYPO3CR\Domain\Model\NodeInterface;

use TYPO3\FLOW3\Annotations as FLOW3;

/**
 * A route part handler for finding nodes specifically in the website's frontend.
 *
 * @FLOW3\Scope("singleton")
 */
class FrontendNodeRoutePartHandler extends \TYPO3\FLOW3\Mvc\Routing\DynamicRoutePart {

	const MATCHRESULT_FOUND = TRUE;
	const MATCHRESULT_NOWORKSPACE = -1;
	const MATCHRESULT_NOSITE = -2;
	const MATCHRESULT_NOSITENODE = -3;
	const MATCHRESULT_NOSUCHNODE = -4;
	const MATCHRESULT_NOSUCHCONTENT = -5;
	const MATCHRESULT_INVALIDPATH = -6;

	/**
	 * @var \TYPO3\TYPO3CR\Domain\Repository\NodeRepository
	 */
	protected $nodeRepository;

	/**
	 * @param \TYPO3\TYPO3CR\Domain\Repository\NodeRepository $nodeRepository
	 * @return void
	 */
	public function injectNodeRepository(\TYPO3\TYPO3CR\Domain\Repository\NodeRepository $nodeRepository) {
		$this->nodeRepository = $nodeRepository;
	}

	/**
	 * Matches a frontend URI pointing to a node (for example a page).
	 *
	 * This function tries to find a matching node by the given relative context node path. If one was found, its
	 * absolute context node path is returned in $this->value.
	 *
	 * Note that this matcher does not check if access to the resolved workspace or node is allowed because at the point
	 * in time the route part handler is invoked, the security framework is not yet fully initialized.
	 *
	 * @param string $value The relative context node path (without leading "/", relative to the current Site Node)
	 * @return mixed One of the MATCHRESULT_* constants
	 */
	protected function matchValue($value) {
		$relativeContextNodePath = $value;

		if ($relativeContextNodePath !== '') {
			preg_match(NodeInterface::MATCH_PATTERN_CONTEXTPATH, $relativeContextNodePath, $matches);
			if (!isset($matches['NodePath'])) {
				return self::MATCHRESULT_INVALIDPATH;
			}
			$relativeNodePath = $matches['NodePath'];
		} else {
			$relativeNodePath = '';
		}

		if ($this->nodeRepository->getContext() === NULL) {
			$workspaceName = (isset($matches['WorkspaceName']) ? $matches['WorkspaceName'] : 'live');
			$contentContext = new ContentContext($workspaceName);
			$contentContext->setInvisibleContentShown(TRUE);
			$this->nodeRepository->setContext($contentContext);
		} else {
			$contentContext = $this->nodeRepository->getContext();
		}

		$workspace = $contentContext->getWorkspace(FALSE);
		if (!$workspace) {
			return self::MATCHRESULT_NOWORKSPACE;
		}

		$site = $contentContext->getCurrentSite();
		if (!$site) {
			return self::MATCHRESULT_NOSITE;
		}

		$siteNode = $contentContext->getCurrentSiteNode();
		if (!$siteNode) {
			return self::MATCHRESULT_NOSITENODE;
		}
		$node = ($relativeNodePath === '') ? $siteNode->getPrimaryChildNode() : $siteNode->getNode($relativeNodePath);
		if (!$node) {
			return self::MATCHRESULT_NOSUCHNODE;
		}
		$this->value = $node->getContextPath();
		return self::MATCHRESULT_FOUND;
	}

	/**
	 * Extracts the node path from the request path.
	 *
	 * @param string $requestPath The request path to be matched
	 * @return string value to match, or an empty string if $requestPath is empty or split string was not found
	 */
	protected function findValueToMatch($requestPath) {
		if ($this->splitString !== '') {
			$splitStringPosition = strpos($requestPath, $this->splitString);
			if ($splitStringPosition !== FALSE) {
				$requestPath = substr($requestPath, 0, $splitStringPosition);
			}
		}
		if (strpos($requestPath, '.') === FALSE) {
			return $requestPath;
		} else {
			$splitRequestPath = explode('/', $requestPath);
			$lastPart = array_pop($splitRequestPath);
			$dotPosition = strpos($lastPart, '.');
			if ($dotPosition !== FALSE) {
				$lastPart = substr($lastPart, 0, $dotPosition);
			}
			array_push($splitRequestPath, $lastPart);
			return implode('/', $splitRequestPath);
		}
	}

	/**
	 * Checks, whether given value is a Node object and if so, sets $this->value to the respective node context path.
	 *
	 * In order to render a suitable frontend URI, this function strips off the path to the site node and only keeps
	 * the actual node path relative to that site node. In practice this function would set $this->value as follows:
	 *
	 * absolute node path: /sites/footypo3org/homepage/about
	 * $this->value:       homepage/about
	 *
	 * absolute node path: /sites/footypo3org/homepage/about@user-admin
	 * $this->value:       homepage/about@user-admin

	 *
	 * @param mixed $value Either a Node object or an absolute context node path
	 * @return boolean TRUE if value could be resolved successfully, otherwise FALSE.
	 */
	protected function resolveValue($value) {
		if (!$value instanceof NodeInterface && !is_string($value)) {
			return FALSE;
		}

		if (is_string($value)) {
			preg_match(NodeInterface::MATCH_PATTERN_CONTEXTPATH, $value, $matches);
			if (!isset($matches['NodePath'])) {
				return FALSE;
			}

			$workspaceName = (isset($matches['WorkspaceName']) ? $matches['WorkspaceName'] : 'live');
			$contentContext = $this->nodeRepository->getContext();
			if ($contentContext->getWorkspace(FALSE) === NULL) {
				return FALSE;
			}

			$node = $contentContext->getCurrentSiteNode()->getNode($matches['NodePath']);
		} else {
			$node = $value;
			$contentContext = $this->nodeRepository->getContext();
		}

		if ($node instanceof NodeInterface) {
			while ($node->getContentType() === 'TYPO3.TYPO3:Shortcut') {
				$childNodes = $node->getChildNodes('TYPO3.TYPO3:Page,TYPO3.TYPO3:Shortcut');
				$node = current($childNodes);
			}

			$nodeContextPath = $node->getContextPath();
			$siteNodePath = $contentContext->getCurrentSiteNode()->getPath();
		} else {
			return FALSE;
		}

		if (substr($nodeContextPath, 0, strlen($siteNodePath)) !== $siteNodePath) {
			return FALSE;
		}

		$this->value = substr($nodeContextPath, strlen($siteNodePath) + 1);
		return TRUE;
	}

}
?>