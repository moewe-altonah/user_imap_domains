<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\Settings;

use OCA\UserImapDomains\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Own section in the admin settings navigation
 */
class AdminSection implements IIconSection {
	public function __construct(
		private readonly IL10N $l,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('IMAP Multi-Domain Login');
	}

	/**
	 * Position in the settings navigation, lower values are shown first
	 */
	public function getPriority(): int {
		return 80;
	}

	/**
	 * Dark icon: the settings navigation inverts icons in the dark theme
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
	}
}
