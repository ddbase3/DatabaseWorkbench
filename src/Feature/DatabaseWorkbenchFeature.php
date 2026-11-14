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

namespace Base3\DatabaseWorkbench\Feature;

use Base3\CoreFoundation\Authorization\Api\IFeature;
use Base3\CoreFoundation\Translation\Api\ITranslation;
use Base3\CoreFoundation\Usermanager\Permission;

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
