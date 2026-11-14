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

namespace Base3\DatabaseWorkbench\Test\Exception;

use Base3\DatabaseWorkbench\Exception\DatabaseWorkbenchCsrfException;
use Base3\DatabaseWorkbench\Exception\DatabaseWorkbenchException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DatabaseWorkbenchException::class)]
#[CoversClass(DatabaseWorkbenchCsrfException::class)]
final class DatabaseWorkbenchExceptionTest extends TestCase {

	public function testDomainExceptionsPreserveRuntimeExceptionContracts(): void {
		foreach ([DatabaseWorkbenchException::class, DatabaseWorkbenchCsrfException::class] as $type) {
			$exception = new $type('error', 42);
			self::assertInstanceOf(RuntimeException::class, $exception);
			self::assertSame('error', $exception->getMessage());
			self::assertSame(42, $exception->getCode());
		}
	}
}
