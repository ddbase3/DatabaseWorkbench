<?php declare(strict_types=1);

namespace DatabaseWorkbench\Display;

use Base3\Api\IAssetResolver;
use Base3\Api\IDisplay;
use Base3\Api\IMvcView;
use Base3\LinkTarget\Api\ILinkTargetService;
use DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf;
use Throwable;

final class DatabaseWorkbenchDisplay implements IDisplay {

	private array $data = [];

	public function __construct(
		private readonly IMvcView $view,
		private readonly IAssetResolver $assetResolver,
		private readonly ILinkTargetService $linkTargetService,
		private readonly IDatabaseWorkbenchCsrf $csrf
	) {}

	public static function getName(): string {
		return 'databaseworkbenchdisplay';
	}

	public function setData($data) {
		$this->data = is_array($data) ? $data : [];
	}

	public function getOutput(string $out = 'html', bool $final = false): string {
		$this->view->setPath(DIR_PLUGIN . 'DatabaseWorkbench');
		$this->view->loadBricks('Display');
		$translations = $this->view->getBricks('databaseworkbench_display');
		$translations = is_array($translations) ? $translations : [];

		try {
			$token = $this->csrf->issueToken();
		}
		catch (Throwable) {
			$message = trim((string)($translations['session_required'] ?? ''));
			$message = $message !== '' ? $message : 'DatabaseWorkbench requires an active session.';

			return '<div class="databaseworkbench-error">'
				. htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
				. '</div>';
		}

		$title = trim((string)($translations['title'] ?? ''));
		$config = array_merge([
			'title' => $title !== '' ? $title : 'Database Workbench',
			'page_size' => 50,
		], $this->data);

		$this->view->setTemplate('Display/DatabaseWorkbenchDisplay.php');
		$this->view->assign('title', (string) $config['title']);
		$this->view->assign('page_size', max(1, min(200, (int) $config['page_size'])));
		$this->view->assign('endpoint', $this->linkTargetService->getLink([
			'name' => 'databaseworkbenchapi',
			'out' => 'json',
		]));
		$this->view->assign('csrf', $token);
		$this->view->assign('translations', $translations);
		$this->view->assign('resolve', fn(string $path): string => $this->assetResolver->resolve($path));

		return $this->view->loadTemplate();
	}
}
