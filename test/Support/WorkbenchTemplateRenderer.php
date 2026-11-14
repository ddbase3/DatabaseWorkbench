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

namespace Base3\DatabaseWorkbench\Test\Support;

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
