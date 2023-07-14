<?php
/**
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files_Sharing\Controllers;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IRequest;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Class ExternalSharesController
 *
 * @package OCA\Files_Sharing\Controllers
 */
class ExternalSharesController extends Controller {
	/** @var \OCA\Files_Sharing\External\Manager */
	private $externalManager;
	/** @var \OCA\Files_Sharing\External\Manager */
	private $groupExternalManager = null;
	/** @var IClientService */
	private $clientService;
	/**
	 * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
	 */
	private $dispatcher;

	/** @var IConfig $config */
	private $config;

	public const group_share_type = "group";
	/**
	 * ExternalSharesController constructor.
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param \OCA\Files_Sharing\External\Manager $externalManager
	 * @param IClientService $clientService
	 * @param EventDispatcherInterface $eventDispatcher
	 * @param IConfig $config
	 */
	public function __construct(
		$appName,
		IRequest $request,
		\OCA\Files_Sharing\External\Manager $externalManager,
		IClientService $clientService,
		EventDispatcherInterface $eventDispatcher,
		IConfig $config
	) {
		parent::__construct($appName, $request);
		$this->externalManager = $externalManager;
		$this->clientService = $clientService;
		$this->dispatcher = $eventDispatcher;
		$this->config = $config;
		// Allow other apps to add an external manager for user-to-group shares
		$managerClass = $this->config->getSystemValue('sharing.groupExternalManager');
		if ($managerClass !== '') {
			$this->groupExternalManager = \OC::$server->query($managerClass);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoOutgoingFederatedSharingRequired
	 *
	 * @return JSONResponse
	 */
	public function index() {
		$federatedGroupResult = [];
		$groupExternalManager = $this->initGroupManager();
		if ($groupExternalManager !== null) {
			$federatedGroupResult = $groupExternalManager->getOpenShares();
		}
		$result = array_merge($federatedGroupResult, $this->externalManager->getOpenShares());
		return new JSONResponse($result);
	}

	/**
	 * @NoAdminRequired
	 * @NoOutgoingFederatedSharingRequired
	 *
	 * @param int $id
	 * @param string $share_type
	 * @return JSONResponse
	 */
	public function create($id, $share_type) {
		$manager = $this->getManagerForShareType($share_type);
		$shareInfo = $manager->getShare($id);
		
		if ($shareInfo !== false) {
			$mountPoint = $manager->getShareRecipientMountPoint($shareInfo);
			$fileId = $manager->getShareFileId($shareInfo, $mountPoint);

			$event = new GenericEvent(
				null,
				[
					'shareAcceptedFrom' => $shareInfo['owner'],
					'sharedAcceptedBy' => $shareInfo['user'],
					'sharedItem' => $shareInfo['name'],
					'remoteUrl' => $shareInfo['remote'],
					'shareId' => $id,
					'fileId' => $fileId,
					'shareRecipient' => $shareInfo['user'],
				]
			);
			$this->dispatcher->dispatch($event, 'remoteshare.accepted');
			$manager->acceptShare($id);
		}
		return new JSONResponse();
	}

	/**
	 * @NoAdminRequired
	 * @NoOutgoingFederatedSharingRequired
	 *
	 * @param integer $id
	 * @return JSONResponse
	 */
	public function destroy($id, $share_type) {
		$manager = $this->getManagerForShareType($share_type);
		$shareInfo = $manager->getShare($id);
		if ($shareInfo !== false) {
			$event = new GenericEvent(
				null,
				[
					'shareAcceptedFrom' => $shareInfo['owner'],
					'sharedAcceptedBy' => $shareInfo['user'],
					'sharedItem' => $shareInfo['name'],
					'remoteUrl' => $shareInfo['remote']
				]
			);
			$this->dispatcher->dispatch($event, 'remoteshare.declined');
			$manager->declineShare($id);
		}
		return new JSONResponse();
	}

	private function initGroupManager() {
		// Allow other apps to add an external manager for user-to-group shares
		$managerClass = $this->config->getSystemValue('sharing.groupExternalManager');
		if ($managerClass !== '') {
			return \OC::$server->query($managerClass);
		}
		return null;
	}
	private function getManagerForShareType($share_type) {
		$groupExternalManager = $this->initGroupManager();
		if ($share_type === self::group_share_type && $groupExternalManager !== null) {
			$manager = $groupExternalManager;
		} else {
			$manager = $this->externalManager;
		}
		return $manager;
	}

	/**
	 * Test whether the specified remote is accessible
	 *
	 * @param string $remote
	 * @param bool $checkVersion
	 * @return bool
	 */
	protected function testUrl($remote, $checkVersion = false) {
		try {
			$client = $this->clientService->newClient();
			$response = \json_decode($client->get(
				$remote,
				[
					'timeout' => 3,
					'connect_timeout' => 3,
				]
			)->getBody());

			if ($checkVersion) {
				return !empty($response->version) && \version_compare($response->version, '7.0.0', '>=');
			} else {
				return \is_object($response);
			}
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * @PublicPage
	 * @NoOutgoingFederatedSharingRequired
	 * @NoIncomingFederatedSharingRequired
	 *
	 * @param string $remote
	 * @return DataResponse
	 */
	public function testRemote($remote) {
		// cut query and|or anchor part off
		$remote = \strtok($remote, '?#');
		if (
			$this->testUrl('https://' . $remote . '/ocs-provider/') ||
			$this->testUrl('https://' . $remote . '/ocs-provider/index.php') ||
			$this->testUrl('https://' . $remote . '/status.php', true)
		) {
			return new DataResponse('https');
		} elseif (
			$this->testUrl('http://' . $remote . '/ocs-provider/') ||
			$this->testUrl('http://' . $remote . '/ocs-provider/index.php') ||
			$this->testUrl('http://' . $remote . '/status.php', true)
		) {
			return new DataResponse('http');
		} else {
			return new DataResponse(false);
		}
	}
}
