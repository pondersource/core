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

use OCA\Files_Sharing\Middleware\IRemoteOcsMiddleware;
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
	/** @var IRemoteOcsMiddleware */
	private $remoteOcsMiddleware;
	/** @var IClientService */
	private $clientService;
	/**
	 * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
	 */
	private $dispatcher;

	/**
	 * ExternalSharesController constructor.
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param IConfig $config
	 * @param IClientService $clientService
	 * @param EventDispatcherInterface $eventDispatcher
	 */
	public function __construct(
		$appName,
		IRequest $request,
		IConfig $config,
		IClientService $clientService,
		EventDispatcherInterface $eventDispatcher
	) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->clientService = $clientService;
		$this->dispatcher = $eventDispatcher;

		// Other applications like OpenCloudMesh provide their own version of RemoteOcsMiddleware.
		$remoteOcsMiddlewareClass = $this->config->getSystemValue('files_sharing.ocsMiddleware', 'OCA\Files_Sharing\Middleware\RemoteOcsMiddleware');
		$this->remoteOcsMiddleware = \OC::$server->query($remoteOcsMiddlewareClass);
	}

	/**
	 * @NoAdminRequired
	 * @NoOutgoingFederatedSharingRequired
	 *
	 * @return JSONResponse
	 */
	public function index() {
		return new JSONResponse($this->remoteOcsMiddleware->getOpenShares());
	}

	/**
	 * @NoAdminRequired
	 * @NoOutgoingFederatedSharingRequired
	 *
	 * @param int $id
	 * @return JSONResponse
	 */
	public function create($id, $share_type) {
		$manager = $this->remoteOcsMiddleware->getExternalManagerForShareType($share_type);
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
		$manager = $this->remoteOcsMiddleware->getExternalManagerForShareType($share_type);
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
