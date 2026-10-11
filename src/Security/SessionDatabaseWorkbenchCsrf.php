<?php declare(strict_types=1);

namespace DatabaseWorkbench\Security;

use Base3\Session\Api\ISession;
use DatabaseWorkbench\Api\IDatabaseWorkbenchCsrf;
use DatabaseWorkbench\Exception\DatabaseWorkbenchCsrfException;

final class SessionDatabaseWorkbenchCsrf implements IDatabaseWorkbenchCsrf {

	private const SESSION_KEY = 'databaseworkbench.csrf';

	public function __construct(private readonly ISession $session) {}

	public function issueToken(): string {
		$this->ensureStarted();

		$token = $this->session->get(self::SESSION_KEY);
		if (!is_string($token) || strlen($token) < 32) {
			$token = bin2hex(random_bytes(32));
			$this->session->set(self::SESSION_KEY, $token);
		}

		return $token;
	}

	public function isValid(string $token): bool {
		if ($token === '') return false;
		$this->ensureStarted();

		$expected = $this->session->get(self::SESSION_KEY);
		return is_string($expected) && hash_equals($expected, $token);
	}

	public function assertValid(string $token): void {
		if ($this->isValid($token)) return;
		throw new DatabaseWorkbenchCsrfException('The request token is invalid or the session has expired.');
	}

	private function ensureStarted(): void {
		if ($this->session->started() || $this->session->start()) return;
		throw new DatabaseWorkbenchCsrfException('DatabaseWorkbench could not start the BASE3 session.');
	}
}
