<?php declare(strict_types=1);

namespace DatabaseWorkbench\Feature;

use Base3\Authorization\Api\IFeature;
use Base3\Translation\Api\ITranslation;
use Base3\Usermanager\Permission;

/** Describes access to the DatabaseWorkbench UI and all database operations via its AJAX endpoint. */
final class DatabaseWorkbenchFeature implements IFeature {

	public function __construct(private readonly ITranslation $translation) {}

	public static function getName(): string {
		return 'databaseworkbenchfeature';
	}

	public function getLabel(): string {
		return $this->translation->translate('Administration', 'feature', 'label_databaseworkbench');
	}

	public function getDescription(): string {
		return $this->translation->translate('Administration', 'feature', 'description_databaseworkbench');
	}

	public function getPermissions(): array {
		return [Permission::for('feature', self::getName())];
	}

	public function getDefaultAudience(): array {
		return [];
	}
}
