<?php declare(strict_types=1);

namespace DatabaseWorkbench\Test\Support;

use Throwable;

/** Renders the plugin-owned template in the same variable scope as a BASE3 view. */
final class WorkbenchTemplateRenderer {

	private array $_ = [];

	public function render(array $data): string {
		$this->_ = $data;
		ob_start();
		try {
			require dirname(__DIR__, 2) . '/tpl/Display/DatabaseWorkbenchDisplay.php';
			return (string) ob_get_clean();
		} catch (Throwable $error) {
			ob_end_clean();
			throw $error;
		}
	}
}
