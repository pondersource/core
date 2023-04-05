<?php
/**
 * @author Yashar PourMohamad <yasharpm@gmail.com>
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

namespace OCA\Files_Sharing\Middleware;

use OCA\Files_Sharing\External\AbstractManager;

/**
 * Wraps the external manager needed by RemoteOcsController and ExternalSharesController.
 *
 * @package OCA\Files_Sharing\Middleware
 */
interface IRemoteOcsMiddleware {
	/**
	 * return a list of shares which are accepted by the user
	 *
	 * @return array list of accepted server-to-server shares
	 */
	public function getAcceptedShares();

	/**
	 * return a list of shares which are not yet accepted by the user
	 *
	 * @return array list of open server-to-server shares
	 */
	public function getOpenShares();

	/**
	 * return an external manager for the given share type
	 *
	 * @param string $shareType
	 * @return AbstractManager|null
	 * 
	 * @throws Exception
	 */
	public function getExternalManagerForShareType($shareType);
}
