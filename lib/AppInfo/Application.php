<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\AppInfo;

use OCA\UserImapDomains\Backend\ImapUserBackend;
use OCA\UserImapDomains\Config\ConfigLexicon;
use OCA\UserImapDomains\SetupChecks\DomainConfigurationCheck;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUserManager;

class Application extends App implements IBootstrap {
	public const APP_ID = 'user_imap_domains';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerConfigLexicon(ConfigLexicon::class);
		$context->registerSetupCheck(DomainConfigurationCheck::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(static function (IUserManager $userManager, ImapUserBackend $backend): void {
			$userManager->registerBackend($backend);
		});
	}
}
