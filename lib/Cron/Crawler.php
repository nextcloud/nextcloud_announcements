<?php
/**
 * @copyright Copyright (c) 2016 Joas Schilling <coding@schilljs.com>
 *
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

namespace OCA\NextcloudAnnouncements\Cron;


use OC\BackgroundJob\TimedJob;
use OCA\NextcloudAnnouncements\Notification\Notifier;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;

class Crawler extends TimedJob  {

	const FEED_URL = 'https://nextcloud.com/blogfeed/';

	/** @var string */
	protected $appName;
	/** @var IConfig */
	protected $config;
	/** @var IGroupManager */
	protected $groupManager;
	/** @var INotificationManager */
	protected $notificationManager;
	/** @var IClientService */
	protected $clientService;

	/** @var string[] */
	protected $notifyUsers = [];

	/**
	 * @param string $appName
	 * @param IConfig $config
	 * @param IGroupManager $groupManager
	 * @param INotificationManager $notificationManager
	 * @param IClientService $clientService
	 */
	public function __construct($appName, IConfig $config, IGroupManager $groupManager, INotificationManager $notificationManager, IClientService $clientService) {
		$this->appName = $appName;
		$this->config = $config;
		$this->groupManager = $groupManager;
		$this->notificationManager = $notificationManager;
		$this->clientService = $clientService;

		// Run once per day
		$this->setInterval(1); // FIXME 24 * 60 * 60);
	}


	protected function run($argument) {
		$client = $this->clientService->newClient();
		$response = $client->get(self::FEED_URL);

		if ($response->getStatusCode() !== Http::STATUS_OK) {
			return;
		}

		$rss = simplexml_load_string($response->getBody());

		/**
		 * TODO: https://github.com/contribook/main/issues/8
		if ($rss->channel->pubDate === $this->config->getAppValue($this->appName, 'pub_date', '')) {
			return;
		}
		 */

		foreach ($rss->channel->item as $item) {
			$id = md5((string) $item->guid);
			if ($this->config->getAppValue($this->appName, $id, '') === 'published') {
				continue;
			}

			$notification = $this->notificationManager->createNotification();
			$notification->setApp($this->appName)
				->setDateTime(new \DateTime((string) $item->pubDate))
				->setObject($this->appName, $id)
				->setSubject(Notifier::SUBJECT, [(string) $item->author, (string) $item->title])
				->setLink((string) $item->link);

			foreach ($this->getUsersToNotify() as $uid) {
				$notification->setUser($uid);
				$this->notificationManager->notify($notification);
			}

			$this->config->getAppValue($this->appName, $id, 'published');
		}

		$this->config->setAppValue($this->appName, 'pub_date', $rss->channel->pubDate);
	}

	/**
	 * Get the list of users to notify
	 * @return string[]
	 */
	protected function getUsersToNotify() {
		if (!empty($this->notifyUsers)) {
			return array_keys($this->notifyUsers);
		}

		$groups = $this->config->getAppValue($this->appName, 'notification_groups', '["admin"]');
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
