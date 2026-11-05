<?php declare(strict_types=1);

namespace DatabaseWorkbench\Test\Exception;

use DatabaseWorkbench\Exception\DatabaseWorkbenchCsrfException;
use DatabaseWorkbench\Exception\DatabaseWorkbenchException;
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
