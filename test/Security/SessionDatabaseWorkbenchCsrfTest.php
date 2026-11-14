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

namespace Base3\DatabaseWorkbench\Test\Security;

use Base3\CoreFoundation\Session\Api\ISession;
use Base3\DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf;
use Base3\DatabaseWorkbench\Exception\DatabaseWorkbenchCsrfException;
use Base3\DatabaseWorkbench\Security\SessionDatabaseWorkbenchCsrf;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionDatabaseWorkbenchCsrf::class)]
final class SessionDatabaseWorkbenchCsrfTest extends TestCase {

	public function testIssueTokenReusesExistingSessionTokenWithoutWriting(): void {
		$session = $this->createMock(ISession::class);
		$session->expects(self::once())->method('started')->willReturn(true);
		$session->expects(self::never())->method('start');
		$session->expects(self::once())->method('get')->with('databaseworkbench.csrf')->willReturn(str_repeat('x', 32));
		$session->expects(self::never())->method('set');
		$csrf = new SessionDatabaseWorkbenchCsrf($session);

		self::assertInstanceOf(IDatabaseWorkbenchCsrf::class, $csrf);
		self::assertSame(str_repeat('x', 32), $csrf->issueToken());
	}

	public function testIssueTokenCreatesCryptographicallyRandomTokenForMissingOrShortSessionValues(): void {
		foreach ([null, 123, 'too short'] as $stored) {
			$session = $this->createMock(ISession::class);
			$session->method('started')->willReturn(true);
			$session->method('get')->with('databaseworkbench.csrf')->willReturn($stored);
			$written = null;
			$session->expects(self::once())->method('set')->willReturnCallback(
				static function(string $key, mixed $value) use (&$written): void {
					self::assertSame('databaseworkbench.csrf', $key);
					$written = $value;
				}
			);
			$token = (new SessionDatabaseWorkbenchCsrf($session))->issueToken();
			self::assertSame($written, $token);
			self::assertSame(64, strlen($token));
			self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
		}
	}

	public function testIssueTokenStartsInactiveSession(): void {
		$session = $this->createMock(ISession::class);
		$session->method('started')->willReturn(false);
		$session->expects(self::once())->method('start')->willReturn(true);
		$session->method('get')->willReturn(str_repeat('y', 32));
		self::assertSame(str_repeat('y', 32), (new SessionDatabaseWorkbenchCsrf($session))->issueToken());
	}

	public function testUnstartableSessionRejectsIssuingTokensWithoutReadingStorage(): void {
		$session = $this->createMock(ISession::class);
		$session->method('started')->willReturn(false);
		$session->expects(self::once())->method('start')->willReturn(false);
		$session->expects(self::never())->method('get');
		$this->expectException(DatabaseWorkbenchCsrfException::class);
		$this->expectExceptionMessage('DatabaseWorkbench could not start the BASE3 session.');
		(new SessionDatabaseWorkbenchCsrf($session))->issueToken();
	}

	public function testEmptyCandidateIsRejectedWithoutStartingSession(): void {
		$session = $this->createMock(ISession::class);
		$session->expects(self::never())->method('started');
		$session->expects(self::never())->method('get');
		self::assertFalse((new SessionDatabaseWorkbenchCsrf($session))->isValid(''));
	}

	public function testCandidateMustExactlyMatchAStringStoredInSession(): void {
		foreach ([
			['token' => str_repeat('a', 64), 'expected' => str_repeat('a', 64), 'valid' => true],
			['token' => str_repeat('b', 64), 'expected' => str_repeat('a', 64), 'valid' => false],
			['token' => str_repeat('a', 64), 'expected' => null, 'valid' => false],
		] as $case) {
			$session = $this->createStub(ISession::class);
			$session->method('started')->willReturn(true);
			$session->method('get')->willReturn($case['expected']);
			self::assertSame($case['valid'], (new SessionDatabaseWorkbenchCsrf($session))->isValid($case['token']));
		}
	}

	public function testAssertValidAcceptsCurrentSessionToken(): void {
		$session = $this->createStub(ISession::class);
		$session->method('started')->willReturn(true);
		$session->method('get')->willReturn('known-secret');
		self::assertNull((new SessionDatabaseWorkbenchCsrf($session))->assertValid('known-secret'));
	}

	public function testAssertValidRejectsInvalidTokenWithDomainException(): void {
		$session = $this->createStub(ISession::class);
		$session->method('started')->willReturn(true);
		$session->method('get')->willReturn('expected');
		$this->expectException(DatabaseWorkbenchCsrfException::class);
		$this->expectExceptionMessage('The request token is invalid or the session has expired.');
		(new SessionDatabaseWorkbenchCsrf($session))->assertValid('bad');
	}
}
