<?php
/**
 * Nextcloud - dropbox
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Dropbox\Controller;

use OCP\App\IAppManager;
use OCP\Files\IAppData;
use OCP\AppFramework\Http\DataDisplayResponse;

use OCP\IURLGenerator;
use OCP\IConfig;
use OCP\IServerContainer;
use OCP\IL10N;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;

use OCP\AppFramework\Http\ContentSecurityPolicy;

use Psr\Log\LoggerInterface;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\Dropbox\Service\DropboxStorageAPIService;
use OCA\Dropbox\AppInfo\Application;

require_once __DIR__ . '/../constants.php';

class DropboxAPIController extends Controller {


	private $userId;
	private $config;
	private $dbconnection;
	private $dbtype;

	public function __construct(string $AppName,
								IRequest $request,
								IServerContainer $serverContainer,
								IConfig $config,
								IL10N $l10n,
								IAppManager $appManager,
								IAppData $appData,
								LoggerInterface $logger,
								DropboxStorageAPIService $dropboxStorageApiService,
								?string $userId) {
		parent::__construct($AppName, $request);
		$this->userId = $userId;
		$this->l10n = $l10n;
		$this->appData = $appData;
		$this->serverContainer = $serverContainer;
		$this->config = $config;
		$this->logger = $logger;
		$this->dropboxStorageApiService = $dropboxStorageApiService;
		$this->accessToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'token', '');
		$this->refreshToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'refresh_token', '');
		$this->clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', DEFAULT_DROPBOX_CLIENT_ID);
		$this->clientID = $this->clientID ?: DEFAULT_DROPBOX_CLIENT_ID;
		$this->clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret', DEFAULT_DROPBOX_CLIENT_SECRET);
		$this->clientSecret = $this->clientSecret ?: DEFAULT_DROPBOX_CLIENT_SECRET;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getStorageSize(): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse(null, 400);
		}
		$result = $this->dropboxStorageApiService->getStorageSize(
			$this->accessToken, $this->refreshToken, $this->clientID, $this->clientSecret, $this->userId
		);
		if (isset($result['error'])) {
			$response = new DataResponse($result['error'], 401);
		} else {
			$response = new DataResponse($result);
		}
		return $response;
	}

	/**
     * @NoAdminRequired
     *
     * @return DataResponse
     */
    public function importDropbox(): DataResponse {
        if ($this->accessToken === '') {
            return new DataResponse(null, 400);
        }
        $result = $this->dropboxStorageApiService->startImportDropbox($this->accessToken, $this->userId);
        if (isset($result['error'])) {
            $response = new DataResponse($result['error'], 401);
        } else {
            $response = new DataResponse($result);
        }
        return $response;
	}

	/**
     * @NoAdminRequired
     *
     * @return DataResponse
     */
    public function getImportDropboxInformation(): DataResponse {
        if ($this->accessToken === '') {
            return new DataResponse(null, 400);
        }
        $response = new DataResponse([
            'importing_dropbox' => $this->config->getUserValue($this->userId, Application::APP_ID, 'importing_dropbox', '') === '1',
            'last_dropbox_import_timestamp' => (int) $this->config->getUserValue($this->userId, Application::APP_ID, 'last_dropbox_import_timestamp', '0'),
            'nb_imported_files' => (int) $this->config->getUserValue($this->userId, Application::APP_ID, 'nb_imported_files', '0'),
        ]);
        return $response;
    }
}
