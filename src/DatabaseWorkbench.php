<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of DatabaseWorkbench for BASE3 Framework.
 *
 * DatabaseWorkbench provides embedded MySQL and MariaDB administration.
 * It supports browsing, schema inspection, row changes and SQL queries.
 * Server-side operations use a dedicated authorized JSON endpoint.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de
 * https://github.com/ddbase3/DatabaseWorkbench
 **********************************************************************/

namespace Base3\DatabaseWorkbench;

use Base3\CoreFoundation\Api\IContainer;
use Base3\CoreFoundation\Api\IPlugin;
use Base3\CoreFoundation\Database\Api\IDatabase;
use Base3\CoreFoundation\Session\Api\ISession;
use Base3\DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf;
use Base3\DatabaseWorkbench\Api\IDatabaseWorkbenchService;
use Base3\DatabaseWorkbench\Security\SessionDatabaseWorkbenchCsrf;
use Base3\DatabaseWorkbench\Service\DatabaseWorkbenchService;

final class DatabaseWorkbench implements IPlugin {

	public function __construct(
		private readonly IContainer $container
	) {}

	public static function getName(): string {
		return 'databaseworkbench';
	}

	public function init() {
		$this->container
			->set(
				IDatabaseWorkbenchService::class,
				fn($c) => new DatabaseWorkbenchService($c->get(IDatabase::class)),
				IContainer::SHARED
			)
			->set(
				IDatabaseWorkbenchCsrf::class,
				fn($c) => new SessionDatabaseWorkbenchCsrf($c->get(ISession::class)),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(self::getName(), $this, IContainer::SHARED);
	}
}
