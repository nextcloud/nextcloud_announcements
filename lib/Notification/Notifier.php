<?php

/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextcloudAnnouncements\Notification;

use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public const SUBJECT = 'announced';

	/** @var string */
	protected $appName;
	/** @var IFactory */
	protected $l10nFactory;
	/** @var IURLGenerator */
	protected $url;
	/** @var IConfig */
	protected $config;
	/** @var IGroupManager */
	protected $groupManager;

	public function __construct(string $appName,
		IFactory $l10nFactory,
		IURLGenerator $url,
		IConfig $config,
		IGroupManager $groupManager) {
		$this->appName = $appName;
		$this->l10nFactory = $l10nFactory;
		$this->url = $url;
		$this->config = $config;
		$this->groupManager = $groupManager;
	}

	/**
	 * Identifier of the notifier, only use [a-z0-9_]
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getID(): string {
		return $this->appName;
	}

	/**
	 * Human readable name describing the notifier
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getName(): string {
		return $this->l10nFactory->get($this->appName)->t('Nextcloud announcements');
	}

	/**
	 * @param INotification $notification
	 * @param string $languageCode The code of the language that should be used to prepare the notification
	 * @return INotification
	 * @throws UnknownNotificationException When the notification was not prepared by a notifier
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== $this->appName) {
			// Not my app => throw
			throw new UnknownNotificationException();
		}

		// Read the language from the notification
		$l = $this->l10nFactory->get($this->appName, $languageCode);

		switch ($notification->getSubject()) {
			case self::SUBJECT:
				$parameters = $notification->getSubjectParameters();
				$message = $parameters[0];
				$notification->setParsedSubject($l->t('Nextcloud announcement'))
					->setIcon($this->url->getAbsoluteURL($this->url->imagePath($this->appName, 'app-dark.svg')));

				$action = $notification->createAction();
				$action->setParsedLabel($l->t('Read more'))
					->setLink($notification->getLink(), IAction::TYPE_WEB)
					->setPrimary(true);
				$notification->addParsedAction($action);

				$isAdmin = $this->groupManager->isAdmin($notification->getUser());
				if ($isAdmin) {
					$groups = $this->config->getAppValue($this->appName, 'notification_groups', '');
					if ($groups === '') {
						$action = $notification->createAction();
						$action->setParsedLabel($l->t('Disable announcements'))
							->setLink($this->url->linkToOCSRouteAbsolute('provisioning_api.Apps.disable', ['app' => 'nextcloud_announcements']), IAction::TYPE_DELETE)
							->setPrimary(false);
						$notification->addParsedAction($action);

						$message .= "\n\n" . $l->t('(These announcements are only shown to administrators)');
					}
				}

				$notification->setParsedMessage($message);

				return $notification;

			default:
				// Unknown subject => Unknown notification => throw
				throw new UnknownNotificationException();
		}
	}
}
