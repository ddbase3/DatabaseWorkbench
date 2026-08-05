<?php declare(strict_types=1);

namespace DatabaseWorkbench;

use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use Base3\Database\Api\IDatabase;
use DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf;
use DatabaseWorkbench\Api\IDatabaseWorkbenchService;
use DatabaseWorkbench\Security\SessionDatabaseWorkbenchCsrf;
use DatabaseWorkbench\Service\DatabaseWorkbenchService;

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
				fn() => new SessionDatabaseWorkbenchCsrf(),
				IContainer::SHARED | IContainer::NOOVERWRITE
			)
			->set(self::getName(), $this, IContainer::SHARED);
	}
}
