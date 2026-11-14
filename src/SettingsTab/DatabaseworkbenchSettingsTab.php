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

namespace Base3\DatabaseWorkbench\SettingsTab;

use Base3\CoreFoundation\Authorization\Api\IAuthorizationService;
use Base3\CoreFoundation\Authorization\Dto\AuthorizationRequest;
use Base3\CoreFoundation\Usermanager\Permission;
use Base3\CoreFoundation\Translation\Api\ITranslation;
use Base3\DatabaseWorkbench\Feature\DatabaseWorkbenchFeature;
use Base3\UiFoundation\Settings\AbstractSettingsTab;

final class DatabaseworkbenchSettingsTab extends AbstractSettingsTab {

	public function __construct(
		ITranslation $translation,
		private readonly IAuthorizationService $authorization
	) {
		parent::__construct($translation);
	}

	public function isVisible(): bool {
		return $this->authorization->decide(new AuthorizationRequest(
			$this->authorization->getDefaultAuthority(),
			Permission::for('feature', DatabaseWorkbenchFeature::getName())
		))->isAllowed();
	}

	public function getParentTabName(): ?string {
		return 'base3settingstab';
	}

	public function getDisplayName(): ?string {
		return 'databaseworkbenchdisplay';
	}

	public function getOrder(): int {
		return 70;
	}
}
