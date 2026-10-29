<?php

declare(strict_types=1);

namespace OCA\UserImapDomains\Migration;

use Closure;
use OCA\UserImapDomains\Db\UserStore;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Table of the users of this app, see {@see UserStore}
 */
class Version1000Date20261021000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable(UserStore::TABLE)) {
			return null;
		}

		$table = $schema->createTable(UserStore::TABLE);
		$table->addColumn('uid', Types::STRING, [
			'notnull' => true,
			'length' => IUser::MAX_USERID_LENGTH,
		]);
		// Nullable: Oracle cannot store '' in NOT NULL columns
		$table->addColumn('display_name', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		// Domain mapping and local part the user was created with,
		// null for users imported from user_external until their next login
		$table->addColumn('email_domain', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		$table->addColumn('local_part', Types::STRING, [
			'notnull' => false,
			'length' => IUser::MAX_USERID_LENGTH,
		]);
		$table->setPrimaryKey(['uid']);

		return $schema;
	}
}
