<?php declare(strict_types=1);

namespace DatabaseWorkbench\Security;

use DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf;
use DatabaseWorkbench\Exception\DatabaseWorkbenchCsrfException;

final class SessionDatabaseWorkbenchCsrf implements IDatabaseWorkbenchCsrf {

	private const SESSION_KEY = 'databaseworkbench.csrf';

	public function issueToken(): string {
		$this->assertSessionActive();

		$token = $_SESSION[self::SESSION_KEY] ?? null;
		if (!is_string($token) || strlen($token) < 32) {
			$token = bin2hex(random_bytes(32));
			$_SESSION[self::SESSION_KEY] = $token;
		}

		return $token;
	}

	public function isValid(string $token): bool {
		if (session_status() !== PHP_SESSION_ACTIVE || $token === '') {
			return false;
		}

		$expected = $_SESSION[self::SESSION_KEY] ?? null;
		return is_string($expected) && hash_equals($expected, $token);
	}

	public function assertValid(string $token): void {
		if ($this->isValid($token)) {
			return;
		}

		throw new DatabaseWorkbenchCsrfException('The request token is invalid or the session has expired.');
	}

	private function assertSessionActive(): void {
		if (session_status() === PHP_SESSION_ACTIVE) {
			return;
		}

		throw new DatabaseWorkbenchCsrfException(
			'DatabaseWorkbench requires an active PHP session before rendering the display.'
		);
	}
}
