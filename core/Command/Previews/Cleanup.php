<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2022, ownCloud GmbH
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
namespace OC\Core\Command\Previews;

use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use OC\Core\Command\Base;
use OCP\Files\Folder;
use OCP\IDBConnection;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Cleanup extends Base {

	/**
	 * @var IDBConnection
	 */
	private $connection;

	public function __construct(IDBConnection $connection) {
		parent::__construct();
		$this->connection = $connection;
	}

	protected function configure() {
		parent::configure();

		$this
			->setName('previews:cleanup')
			->setDescription('Remove unreferenced previews')
			->addOption('all')
			->addArgument('chunk_size', InputArgument::OPTIONAL, '', 1000)
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$all = $input->hasOption('all');
		$chunk_size = $input->getArgument('chunk_size');
		$count = $this->process($all, $chunk_size, static function ($userId, $name, $action) use ($output) {
			$output->writeln("$name - $userId: $action");
		});

		$output->writeln("$count orphaned previews deleted");
		return 1;
	}

	public function process(bool $all = false, int $chunkSize = 1000, \Closure $progress = null): int {
		if ($progress === null) {
			$progress = static function () {
			};
		}
		$root = \OC::$server->getLazyRootFolder();
		$count = 0;

		$lastFileId = 0;
		while (true) {
			$rows = $this->queryPreviewsToDelete($lastFileId, $chunkSize);
			foreach ($rows as $row) {
				$name = $row['name'];
				$userId = $row['user_id'];
				$lastFileId = $row['fileid'];

				$userFiles = $root->getUserFolder($userId);
				if ($userFiles->getParent()->nodeExists('thumbnails')) {
					/** @var Folder $thumbnailsFolder */
					$thumbnailsFolder = $userFiles->getParent()->get('thumbnails');
					if ($thumbnailsFolder instanceof Folder && $thumbnailsFolder->nodeExists($name)) {
						$notExistingPreview = $thumbnailsFolder->get($name);
						$notExistingPreview->delete();
						$progress($userId, $name, 'deleted');
					} else {
						# cleanup cache
						$this->cleanFileCache($name);
						$progress($userId, $name, 'cache cleared');
					}
				}
			}
			$count += \count($rows);
			if (!$all || empty($rows)) {
				break;
			}
		}

		return $count;
	}

	private function queryPreviewsToDelete(int $startFileId = 0, int $chunkSize = 1000): array {
		$isMysql = ($this->connection->getDatabasePlatform() instanceof MySqlPlatform);
		$isOracle = ($this->connection->getDatabasePlatform() instanceof OraclePlatform);
		$intDataType = $isMysql ? 'UNSIGNED' : 'INT';

		$sql = "select `fileid`, `name`, `user_id` from `*PREFIX*filecache` `fc`
join `*PREFIX*mounts` on `storage` = `storage_id`
where `parent` in (select `fileid` from `*PREFIX*filecache` where `storage` in (select `numeric_id` from `oc_storages` where `id` like 'home::%' or `id` like 'object::user:%') and `path` = 'thumbnails')
  and not exists(select `fileid` from `*PREFIX*filecache` where CAST(`fc`.`name` as $intDataType) = `*PREFIX*filecache`.`fileid`)
  and `fc`.`fileid` > ?
  order by `user_id`, `fileid`";

		if ($isOracle) {
			$sql = "select * from ($sql) where ROWNUM <= $chunkSize";

			# Oracle might have issues with new lines ......
			$sql = trim(preg_replace('/\s+/', ' ', $sql));
		} else {
			$sql .= " limit $chunkSize";
		}

		return $this->connection->executeQuery($sql, [$startFileId])->fetchAll(\PDO::FETCH_ASSOC);
	}

	private function cleanFileCache($name) {
		$sql = "delete from `*PREFIX*filecache` where path like 'thumbnails/$name/%' or path = 'thumbnails/$name'";
		return $this->connection->executeQuery($sql)->rowCount();
	}
}
