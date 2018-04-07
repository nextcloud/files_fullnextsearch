<?php
/**
 * Files_FullTextSearch - Index the content of your files
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Files_FullTextSearch\Service;


use Exception;
use OC\App\AppManager;
use OCA\Files_FullTextSearch\Exceptions\FileIsNotIndexableException;
use OCA\Files_FullTextSearch\Exceptions\KnownFileSourceException;
use OCA\Files_FullTextSearch\Model\FilesDocument;
use OCA\Files_FullTextSearch\Model\MountPoint;
use OCA\GroupFolders\Folder\FolderManager;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\Share\IManager;

class GroupFoldersService {


	const DOCUMENT_SOURCE = 'files_group_folders';

	/** @var IManager */
	private $shareManager;

	/** @var FolderManager */
	private $folderManager;

	/** @var LocalFilesService */
	private $localFilesService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/** @var MountPoint[] */
	private $groupFolders = [];


	/**
	 * ExternalFilesService constructor.
	 *
	 * @param $userId
	 * @param IDBConnection $dbConnection
	 * @param AppManager $appManager
	 * @param IManager $shareManager
	 * @param LocalFilesService $localFilesService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		$userId, IDBConnection $dbConnection, AppManager $appManager, IManager $shareManager,
		LocalFilesService $localFilesService, ConfigService $configService,
		MiscService $miscService
	) {

		if ($appManager->isEnabledForUser('groupfolders', $userId)) {
			try {
				$this->folderManager = new FolderManager($dbConnection);
			} catch (Exception $e) {
				return;
			}
		}

		$this->shareManager = $shareManager;
		$this->localFilesService = $localFilesService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $userId
	 */
	public function initGroupSharesForUser($userId) {
		if ($this->folderManager === null) {
			return;
		}

		$this->groupFolders = [];
		if ($this->configService->getAppValue(ConfigService::FILES_GROUP_FOLDERS) !== '1') {
			return;
		}

		$this->groupFolders = $this->getMountPoints($userId);
	}


	/**
	 * @param Node $file
	 *
	 * @param string $source
	 *
	 * @throws KnownFileSourceException
	 */
	public function getFileSource(Node $file, &$source) {
		if ($this->folderManager === null) {
			return;
		}

		if (!$this->configService->optionIsSelected(ConfigService::FILES_GROUP_FOLDERS)) {
			return;
		}

		try {
			$this->getMountPoint($file);
		} catch (FileIsNotIndexableException $e) {
			return;
		}

		$source = self::DOCUMENT_SOURCE;
		throw new KnownFileSourceException();
	}


	/**
	 * @param FilesDocument $document
	 * @param Node $file
	 */
	public function updateDocumentAccess(FilesDocument &$document, Node $file) {

		if ($document->getSource() !== self::DOCUMENT_SOURCE) {
			return;
		}

		try {
			$mount = $this->getMountPoint($file);
		} catch (FileIsNotIndexableException $e) {
			return;
		}

		$access = $document->getAccess();
		$access->addGroups($mount->getGroups());

		$document->setAccess($access);
	}


	/**
	 * @param FilesDocument $document
	 * @param array $users
	 */
	public function getShareUsers(FilesDocument $document, &$users) {

		if ($document->getSource() !== self::DOCUMENT_SOURCE) {
			return;
		}

		$this->localFilesService->getSharedUsersFromAccess($document->getAccess(), $users);
	}


	/**
	 * @param Node $file
	 *
	 * @return MountPoint
	 * @throws FileIsNotIndexableException
	 */
	private function getMountPoint(Node $file) {

		foreach ($this->groupFolders as $mount) {
			if (strpos($file->getPath(), $mount->getPath()) === 0) {
				return $mount;
			}
		}

		throw new FileIsNotIndexableException();

	}


	/**
	 * @param string $userId
	 *
	 * @return MountPoint[]
	 */
	private function getMountPoints($userId) {

		$mountPoints = [];
		$mounts = $this->folderManager->getAllFolders();

		foreach ($mounts as $path => $mount) {
			$mountPoint = new MountPoint();
			$mountPoint->setId($mount['id'])
					   ->setPath('/' . $userId . '/files/' . $mount['mount_point'])
					   ->setGroups(array_keys($mount['groups']));
			$mountPoints[] = $mountPoint;
		}

		return $mountPoints;
	}


}