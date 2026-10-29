<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\SetupChecks;

use OCA\UserImapDomains\Db\UserStore;
use OCA\UserImapDomains\Model\SslMode;
use OCA\UserImapDomains\Service\DomainConfigService;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Report missing domain mappings, IMAP connections without encryption and
 * imported users without domain that no domain mapping can log in
 */
class DomainConfigurationCheck implements ISetupCheck {
	public function __construct(
		private readonly DomainConfigService $domainConfig,
		private readonly UserStore $userStore,
		private readonly IL10N $l,
	) {
	}

	public function getCategory(): string {
		return 'security';
	}

	public function getName(): string {
		return $this->l->t('IMAP Multi-Domain Login: domain configuration');
	}

	public function run(): SetupResult {
		$mappings = $this->domainConfig->getDomainMappings();
		if ($mappings === []) {
			return SetupResult::info($this->l->t('No email domain is configured, nobody can log in via IMAP.'));
		}

		if ($this->domainConfig->findDefaultMapping() === null) {
			$importedWithoutDomain = $this->userStore->countImportedWithoutDomain();
			if ($importedWithoutDomain > 0) {
				return SetupResult::warning(
					$this->l->n(
						'%n imported user has no domain in the user ID. Enable "User IDs without domain" for one domain, otherwise this user gets a new account on the next login.',
						'%n imported users have no domain in their user ID. Enable "User IDs without domain" for one domain, otherwise these users get new accounts on their next login.',
						$importedWithoutDomain,
					)
				);
			}
		}

		$plaintextDomains = [];
		foreach ($mappings as $mapping) {
			if ($mapping->sslMode === SslMode::None) {
				$plaintextDomains[] = $mapping->emailDomain;
			}
		}
		if ($plaintextDomains !== []) {
			return SetupResult::warning(
				$this->l->t('Passwords are sent unencrypted to the IMAP servers of these domains: %s', [implode(', ', $plaintextDomains)])
			);
		}

		return SetupResult::success($this->l->t('All IMAP connections are encrypted.'));
	}
}
