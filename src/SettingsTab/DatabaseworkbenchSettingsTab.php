<?php declare(strict_types=1);

namespace DatabaseWorkbench\SettingsTab;

use Base3\Authorization\Api\IAuthorizationService;
use Base3\Authorization\Dto\AuthorizationRequest;
use Base3\Usermanager\Permission;
use Base3\Translation\Api\ITranslation;
use DatabaseWorkbench\Feature\DatabaseWorkbenchFeature;
use UiFoundation\Settings\AbstractSettingsTab;

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
