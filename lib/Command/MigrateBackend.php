<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\Command;

use OCA\UserImapDomains\Service\BackendMigrationService;
use OCA\UserImapDomains\Service\DomainConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ user_imap_domains:migrate-backend <legacy-backend-id> [--dry-run]
 *
 * Same as "Import users" in the admin settings, for scripted deployments.
 * Users are moved out of the table users_external; user_external itself is not needed.
 * User IDs are kept as they are, no domain is added.
 * Exit code 0: migrated or nothing to do, 1: invalid input,
 * 2 (--dry-run only): warnings (see the output) that need attention first.
 */
class MigrateBackend extends Command {
	public function __construct(
		private readonly BackendMigrationService $migrationService,
		private readonly DomainConfigService $domainConfig,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('user_imap_domains:migrate-backend')
			->setDescription('Move users of a previous user_external backend to this app')
			->addArgument('legacy-backend-id', InputArgument::REQUIRED, 'Previous backend identifier, e.g. 127.0.0.1')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only show what would be migrated');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$legacyBackendId = trim((string)$input->getArgument('legacy-backend-id'));
		if ($legacyBackendId === '') {
			$output->writeln('<error>The legacy backend identifier must not be empty</error>');
			return 1;
		}

		$analysis = $this->migrationService->analyze($legacyBackendId);
		if (!$analysis['available']) {
			$output->writeln('<error>The table users_external does not exist, there is nothing to import</error>');
			return 1;
		}
		$output->writeln(sprintf('Users of backend "%s": %d', $legacyBackendId, $analysis['total']));
		$this->printList($output, 'Already exist in this app, only their old entry is removed', $analysis['conflicts']);
		$this->printList($output, 'Match no domain mapping, would get a new account on next login', $analysis['unmatched']);

		$warnings = [];
		if ($analysis['missingDefaultDomain']) {
			$warnings[] = sprintf('%d user IDs have no domain. Enable "User IDs without domain" (strip_domain) for one domain, otherwise these users get new accounts on their next login.', $analysis['withoutDomain']);
		}
		if ($analysis['configuredBackends'] !== []) {
			$warnings[] = sprintf('config.php still contains user_backends (%s). Remove it, otherwise the old backend keeps authenticating users.', implode(', ', $analysis['configuredBackends']));
		}
		foreach ($warnings as $warning) {
			$output->writeln('<comment>Warning: ' . $warning . '</comment>');
		}

		if ($input->getOption('dry-run')) {
			return ($analysis['conflicts'] === [] && $analysis['unmatched'] === [] && $warnings === []) ? 0 : 2;
		}

		$this->domainConfig->saveLegacyBackendId($legacyBackendId);
		$imported = $this->migrationService->import($legacyBackendId);
		$output->writeln(sprintf('<info>Moved users: %d (%d were already in this app)</info>', $imported, count($analysis['conflicts'])));
		return 0;
	}

	/**
	 * @param list<string> $userIds
	 */
	private function printList(OutputInterface $output, string $title, array $userIds): void {
		if ($userIds === []) {
			return;
		}
		$output->writeln(sprintf('<comment>%s (%d):</comment>', $title, count($userIds)));
		foreach ($userIds as $userId) {
			$output->writeln('  ' . $userId);
		}
	}
}
