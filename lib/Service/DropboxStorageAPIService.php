<?php
/**
 * Nextcloud - dropbox
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Dropbox\Service;

use OCP\IL10N;
use Psr\Log\LoggerInterface;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\Files\IRootFolder;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\ForbiddenException;
use OCP\Lock\LockedException;

use OCP\BackgroundJob\IJobList;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

use OCA\Dropbox\AppInfo\Application;
use OCA\Dropbox\BackgroundJob\ImportDropboxJob;

require_once __DIR__ . '/../constants.php';

class DropboxStorageAPIService {

	private $l10n;
	private $logger;

	/**
	 * Service to make requests to Dropbox API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IRootFolder $root,
								IConfig $config,
								IJobList $jobList,
								ITempManager $tempManager,
								DropboxAPIService $dropboxApiService) {
		$this->appName = $appName;
		$this->l10n = $l10n;
		$this->logger = $logger;
		$this->config = $config;
		$this->root = $root;
		$this->jobList = $jobList;
		$this->tempManager = $tempManager;
		$this->dropboxApiService = $dropboxApiService;
	}

	/**
	 * @param string $accessToken
	 * @param string $refreshToken
	 * @param string $clientID
	 * @param string $clientSecret
	 * @param string $userId
	 * @return array
	 */
	public function getStorageSize(string $accessToken, string $refreshToken, string $clientID, string $clientSecret, string $userId): array {
		// dropbox storage size
		$params = [];
		$result = $this->dropboxApiService->request($accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'users/get_space_usage', $params, 'POST');
		if (isset($result['error']) || !isset($result['used'])) {
			return $result;
		}
		return [
			'usageInStorage' => $result['used'],
		];
	}

	/**
	 * @param string $accessToken
	 * @param string $userId
	 * @return array
	 */
	public function startImportDropbox(string $accessToken, string $userId): array {
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'output_dir', '/Dropbox import');
		$targetPath = $targetPath ?: '/Dropbox import';
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$folder = $userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
				return ['error' => 'Impossible to create Dropbox folder'];
			}
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'importing_dropbox', '1');
		$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		$this->config->setUserValue($userId, Application::APP_ID, 'last_dropbox_import_timestamp', '0');

		$this->jobList->add(ImportDropboxJob::class, ['user_id' => $userId]);
		return ['targetPath' => $targetPath];
	}

	/**
	 * @param string $userId
	 * @return array
	 */
	public function importDropboxJob(string $userId): void {
		$this->logger->error('Importing dropbox files for ' . $userId);
		$importingDropbox = $this->config->getUserValue($userId, Application::APP_ID, 'importing_dropbox', '0') === '1';
		$jobRunning = $this->config->getUserValue($userId, Application::APP_ID, 'dropbox_import_running', '0') === '1';
		if (!$importingDropbox || $jobRunning) {
			return;
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'dropbox_import_running', '1');

		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token', '');
		$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token', '');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', DEFAULT_DROPBOX_CLIENT_ID);
		$clientID = $clientID ?: DEFAULT_DROPBOX_CLIENT_ID;
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', DEFAULT_DROPBOX_CLIENT_SECRET);
		$clientSecret = $clientSecret ?: DEFAULT_DROPBOX_CLIENT_SECRET;
		// import batch of files
		$targetPath = $this->config->getUserValue($userId, Application::APP_ID, 'output_dir', '/Dropbox import');
		$targetPath = $targetPath ?: '/Dropbox import';
		// import by batch of 500 Mo
		$alreadyImported = $this->config->getUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
		$alreadyImported = (int) $alreadyImported;
		$result = $this->importFiles($accessToken, $refreshToken, $clientID, $clientSecret, $userId, $targetPath, 500000000, $alreadyImported);
		if (isset($result['error']) || (isset($result['finished']) && $result['finished'])) {
			$this->config->setUserValue($userId, Application::APP_ID, 'importing_dropbox', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', '0');
			$this->config->setUserValue($userId, Application::APP_ID, 'last_dropbox_import_timestamp', '0');
			if (isset($result['finished']) && $result['finished']) {
				$this->dropboxApiService->sendNCNotification($userId, 'import_dropbox_finished', [
					'nbImported' => $result['totalSeen'],
					'targetPath' => $targetPath,
				]);
			}
		} else {
			$ts = (new \Datetime())->getTimestamp();
			$this->config->setUserValue($userId, Application::APP_ID, 'last_dropbox_import_timestamp', $ts);
			$this->jobList->add(ImportDropboxJob::class, ['user_id' => $userId]);
		}
		$this->config->setUserValue($userId, Application::APP_ID, 'dropbox_import_running', '0');
	}

	/**
	 * @param string $accessToken
	 * @param string $refreshToken
	 * @param string $clientID
	 * @param string $clientSecret
	 * @param string $userId
	 * @param string $targetPath
	 * @param ?int $maxDownloadSize
	 * @param int $alreadyImported
	 * @return array
	 */
	public function importFiles(string $accessToken, string $refreshToken, string $clientID, string $clientSecret,
								string $userId, string $targetPath,
								?int $maxDownloadSize = null, int $alreadyImported): array {
		// create root folder
		$userFolder = $this->root->getUserFolder($userId);
		if (!$userFolder->nodeExists($targetPath)) {
			$folder = $userFolder->newFolder($targetPath);
		} else {
			$folder = $userFolder->get($targetPath);
			if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
				return ['error' => 'Impossible to create ' . $targetPath . ' folder'];
			}
		}

		$downloadedSize = 0;
		$nbDownloaded = 0;
		$totalSeenNumber = 0;

		$params = [
			'limit' => 2000,
			'path' => '',
			'recursive' => true,
			'include_deleted' => false,
			'include_has_explicit_shared_members' => false,
			'include_mounted_folders' => true,
			'include_non_downloadable_files' => false,
		];
		do {
			$suffix = isset($params['cursor']) ? '/continue' : '';
			$result = $this->dropboxApiService->request(
				$accessToken, $refreshToken, $clientID, $clientSecret, $userId, 'files/list_folder' . $suffix, $params, 'POST'
			);
			if (isset($result['error'])) {
				return $result;
			}
			if (isset($result['entries']) && is_array($result['entries'])) {
				foreach ($result['entries'] as $entry) {
					if (isset($entry['.tag']) && $entry['.tag'] === 'file') {
						$totalSeenNumber++;
						$size = $this->getFile($accessToken, $refreshToken, $clientID, $clientSecret, $userId, $entry, $folder);
						if (!is_null($size)) {
							$nbDownloaded++;
							$this->config->setUserValue($userId, Application::APP_ID, 'nb_imported_files', $alreadyImported + $nbDownloaded);
							$downloadedSize += $size;
							if ($maxDownloadSize && $downloadedSize > $maxDownloadSize) {
								return [
									'nbDownloaded' => $nbDownloaded,
									'targetPath' => $targetPath,
									'finished' => false,
									'totalSeen' => $totalSeenNumber,
								];
							}
						}
					}
				}
			}
			$params = [
				'cursor' => $result['cursor'] ?? '',
			];
		} while (isset($result['has_more'], $result['cursor']) && $result['has_more']);

		return [
			'nbDownloaded' => $nbDownloaded,
			'targetPath' => $targetPath,
			'finished' => true,
			'totalSeen' => $totalSeenNumber,
		];
	}

	/**
	 * @param string $accessToken
	 * @param string $refreshToken
	 * @param string $clientID
	 * @param string $clientSecret
	 * @param string $userId
	 * @param array $fileItem
	 * @param Node $topFolder
	 * @return ?int downloaded size, null if already existing or network error
	 */
	private function getFile(string $accessToken, string $refreshToken, string $clientID, string $clientSecret,
							string $userId, array $fileItem, Node $topFolder): ?float {
		$fileName = $fileItem['name'];
		$path = preg_replace('/^\//', '', $fileItem['path_display'] ?? '.');
		$pathParts = pathinfo($path);
		$dirName = $pathParts['dirname'];
		if ($dirName === '.') {
			$saveFolder = $topFolder;
		} else {
			$saveFolder = $this->createAndGetFolder($dirName, $topFolder);
		}
		try {
			$fileExists = $saveFolder->nodeExists($fileName);
		} catch (ForbiddenException $e) {
			return null;
		}
		if (!is_null($saveFolder) && !$fileExists) {
			try {
				$savedFile = $saveFolder->newFile($fileName);
				$resource = $savedFile->fopen('w');
			} catch (NotFoundException $e) {
				$this->logger->warning(
					'Dropbox error, can\'t create file "' . $fileName . '" in "' . $saveFolder->getPath() . '"',
					['app' => $this->appName]
				);
				return null;
			}
			$res = $this->dropboxApiService->downloadFile(
				$accessToken, $refreshToken, $clientID, $clientSecret, $userId, $resource, $fileItem['id']
			);
			if (!isset($res['error'])) {
				if (isset($fileItem['server_modified'])) {
					$d = new \Datetime($fileItem['server_modified']);
					$ts = $d->getTimestamp();
					$savedFile->touch($ts);
				} else {
					$savedFile->touch();
				}
				$stat = $savedFile->stat();
				return $stat['size'] ?? 0;
			} else {
				$this->logger->warning('Dropbox error downloading file ' . $fileName . ' : ' . $res['error'], ['app' => $this->appName]);
				try {
					if ($savedFile->isDeletable()) {
						$savedFile->delete();
					}
				} catch (LockedException $e) {
					$this->logger->warning('Dropbox error deleting file ' . $fileName, ['app' => $this->appName]);
				}
			}
		}
		return null;
	}

	/**
	 * @param string $dirName
	 * @param Node $topFolder
	 * @return ?Node
	 */
	private function createAndGetFolder(string $dirName, Node $topFolder): ?Node {
		$dirs = explode('/', $dirName);
		$seenDirs = [];
		$dirNode = $topFolder;
		foreach ($dirs as $dir) {
			if (!$dirNode->nodeExists($dir)) {
				$dirNode = $dirNode->newFolder($dir);
			} else {
				$dirNode = $dirNode->get($dir);
				if ($dirNode->getType() !== FileInfo::TYPE_FOLDER) {
					return null;
				}
			}
		}
		return $dirNode;
	}
}
