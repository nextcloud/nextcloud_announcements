<?php

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextcloudAnnouncements\Cron;

use OCA\NextcloudAnnouncements\Notification\Notifier;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use phpseclib\File\X509;
use Psr\Log\LoggerInterface;

class Crawler extends TimedJob {
	public const FEED_URL = 'https://pushfeed.nextcloud.com/feed';


	/** @var array<array-key, bool> */
	protected $notifyUsers = [];

	public function __construct(
		ITimeFactory $time,
		protected string $appName,
		protected IConfig $config,
		protected IGroupManager $groupManager,
		protected INotificationManager $notificationManager,
		protected IClientService $clientService,
		protected LoggerInterface $logger,
	) {
		parent::__construct($time);

		// Run once per day
		$interval = 24 * 60 * 60;
		// Add random interval to spread load on server
		$interval += random_int(0, 60) * 60;

		$this->setInterval($interval);
		// this should not run during maintenance to not overload the target server
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}


	protected function run(mixed $argument): void {
		if ($this->config->getSystemValueBool('has_internet_connection', true) === false) {
			$this->logger->info('This instance does not have Internet connection to access the Nextcloud feed platform.', ['app' => $this->appName]);
			return;
		}
		try {
			$feedBody = $this->loadFeed();
			$rss = simplexml_load_string($feedBody);
			if ($rss === false) {
				throw new \Exception('Invalid XML feed');
			}
		} catch (\Exception $e) {
			// Something is wrong 🙊
			return;
		}

		$rssPubDate = (string)$rss->channel->pubDate;

		$lastPubDate = $this->config->getAppValue($this->appName, 'pub_date', 'now');
		if ($lastPubDate === 'now1') {
			// First call, don't spam the user with old stuff...
			$this->config->setAppValue($this->appName, 'pub_date', $rssPubDate);
			return;
		}

		if ($rssPubDate === $lastPubDate) {
			// Nothing new here...
			return;
		}

		$lastPubDateTime = new \DateTime($lastPubDate);

		foreach ($rss->channel->item as $item) {
			$id = md5((string)$item->guid);
			if ($this->config->getAppValue($this->appName, $id, '') === 'published') {
				continue;
			}
			$pubDate = new \DateTime((string)$item->pubDate);

			if ($pubDate <= $lastPubDateTime) {
				continue;
			}

			$notification = $this->notificationManager->createNotification();
			$notification->setApp($this->appName)
				->setDateTime($pubDate)
				->setObject($this->appName, $id)
				->setSubject(Notifier::SUBJECT, [(string)$item->title])
				->setLink((string)$item->link);

			foreach ($this->getUsersToNotify() as $uid) {
				$notification->setUser($uid);
				$this->notificationManager->notify($notification);
			}

			$this->config->getAppValue($this->appName, $id, 'published');
		}

		$this->config->setAppValue($this->appName, 'pub_date', $rssPubDate);
	}

	/**
	 * @return string
	 * @throws \Exception
	 */
	protected function loadFeed() {
		$signature = $this->readFile('.signature');
		if ($signature === '' || $signature === null) {
			throw new \Exception('Invalid signature fetched from the server');
		}

		$certificate = new X509();
		$certificate->loadCA(file_get_contents(\OC::$SERVERROOT . '/resources/codesigning/root.crt'));
		$loadedCertificate = $certificate->loadX509(file_get_contents(__DIR__ . '/../../appinfo/certificate.crt'));

		// Verify if the certificate has been revoked
		$crl = new X509();
		$crl->loadCA(file_get_contents(\OC::$SERVERROOT . '/resources/codesigning/root.crt'));
		$crl->loadCRL(file_get_contents(\OC::$SERVERROOT . '/resources/codesigning/root.crl'));
		if ($crl->validateSignature() !== true) {
			throw new \Exception('Could not validate CRL signature');
		}
		$csn = $loadedCertificate['tbsCertificate']['serialNumber']->toString();
		$revoked = $crl->getRevoked($csn);
		if ($revoked !== false) {
			throw new \Exception('Certificate has been revoked');
		}

		// Verify if the certificate has been issued by the Nextcloud Code Authority CA
		if ($certificate->validateSignature() !== true) {
			throw new \Exception('App with id nextcloud_announcements has a certificate not issued by a trusted Code Signing Authority');
		}

		// Verify if the certificate is issued for the requested app id
		$certInfo = openssl_x509_parse(file_get_contents(__DIR__ . '/../../appinfo/certificate.crt'));
		if (!isset($certInfo['subject']['CN'])) {
			throw new \Exception('App with id nextcloud_announcements has a cert with no CN');
		}
		if ($certInfo['subject']['CN'] !== 'nextcloud_announcements') {
			throw new \Exception(sprintf('App with id nextcloud_announcements has a cert issued to %s', $certInfo['subject']['CN']));
		}

		$feedBody = $this->readFile('.rss');
		if ($feedBody === null) {
			throw new \Exception('Feed body is empty');
		}

		// Check if the signature actually matches the downloaded content
		$certificate = openssl_get_publickey(file_get_contents(__DIR__ . '/../../appinfo/certificate.crt'));
		$verified = openssl_verify($feedBody, base64_decode($signature), $certificate, OPENSSL_ALGO_SHA512) === 1;

		// PHP 8 automatically frees the key instance and deprecates the function
		if (PHP_VERSION_ID < 80000) {
			openssl_free_key($certificate);
		}

		if (!$verified) {
			// Signature does not match
			throw new \Exception('Feed has an invalid signature');
		}

		return $feedBody;
	}

	/**
	 * @throws \Exception
	 */
	protected function readFile(string $file): ?string {
		$client = $this->clientService->newClient();
		$response = $client->get(self::FEED_URL . $file);
		if ($response->getStatusCode() !== Http::STATUS_OK) {
			throw new \Exception('Could not load file');
		}
		$body = $response->getBody();
		if (is_resource($body)) {
			$content = stream_get_contents($body);
			return $content !== false ? $content : null;
		}
		return $body;
	}

	/**
	 * Get the list of users to notify
	 * @return string[]
	 */
	protected function getUsersToNotify(): array {
		if (!empty($this->notifyUsers)) {
			return array_keys($this->notifyUsers);
		}

		$groups = $this->config->getAppValue($this->appName, 'notification_groups', '');
		if ($groups === '') {
			// Fallback to the update notifications when not explicitly configured back in the days when this app had a UI
			$groups = $this->config->getAppValue('updatenotification', 'notify_groups', '["admin"]');
		}
		$groups = json_decode($groups, true);

		if ($groups === null) {
			return [];
		}

		foreach ($groups as $gid) {
			$group = $this->groupManager->get($gid);
			if (!($group instanceof IGroup)) {
				continue;
			}

			/** @var IUser[] $users */
			$users = $group->getUsers();
			foreach ($users as $user) {
				$uid = $user->getUID();
				if (isset($this->notifyUsers[$uid])) {
					continue;
				}

				$this->notifyUsers[$uid] = true;
			}
		}

		return array_keys($this->notifyUsers);
	}
}
