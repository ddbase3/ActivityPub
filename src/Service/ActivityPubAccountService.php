<?php declare(strict_types=1);

namespace ActivityPub\Service;

use ActivityPub\Api\IActivityPubAccountService;
use ActivityPub\Api\IActivityPubKeyService;
use Base3\Database\Api\IDatabase;

/**
 * Account service for ActivityPub local users.
 * Handles creation, lookup and login verification.
 */
final class ActivityPubAccountService implements IActivityPubAccountService {

	public function __construct(
		private readonly IDatabase $database,
		private readonly IActivityPubKeyService $keyService
	) {}

	/**
	 * Create a new local user + actor entry and generate keys.
	 */
	public function createAccount(string $username, string $email, string $password): int {
		$this->database->connect();
		$usernameEsc = $this->database->escape($username);
		$emailEsc    = $this->database->escape($email);
		$passHash    = password_hash($password, PASSWORD_BCRYPT);
		$passEsc     = $this->database->escape($passHash);

		// Insert user
		$sqlUser = "
			INSERT INTO users (username, email, passwordHash)
			VALUES ('$usernameEsc', '$emailEsc', '$passEsc')
		";
		$this->database->nonQuery($sqlUser);
		if ($this->database->isError()) {
			throw new \RuntimeException("DB error inserting user: " . $this->database->errorMessage() . " | SQL: $sqlUser");
		}
		$userId = (int)$this->database->insertId();
		if ($userId <= 0) {
			throw new \RuntimeException("User insertId is 0 – SQL: $sqlUser");
		}

		// Canonical URIs in /users/... style
		$domain   = $_SERVER['SERVER_NAME'] ?? 'localhost';
		$actorUri = "https://$domain/users/$usernameEsc";
		$inbox    = "$actorUri/inbox";
		$outbox   = "$actorUri/outbox";

		$actorUriEsc = $this->database->escape($actorUri);
		$inboxEsc    = $this->database->escape($inbox);
		$outboxEsc   = $this->database->escape($outbox);

		// Insert actor
		$sqlActor = "
			INSERT INTO actors (userId, uri, type, inbox, outbox, publicKey)
			VALUES ($userId, '$actorUriEsc', 'Person', '$inboxEsc', '$outboxEsc', '')
		";
		$this->database->nonQuery($sqlActor);
		if ($this->database->isError()) {
			throw new \RuntimeException("DB error inserting actor: " . $this->database->errorMessage() . " | SQL: $sqlActor");
		}
		$actorId = (int)$this->database->insertId();
		if ($actorId <= 0) {
			throw new \RuntimeException("Actor insertId is 0 – SQL: $sqlActor");
		}

		// Generate keys and sync publicKey into DB
		$this->keyService->ensureKeys($username);

		return $actorId;
	}

	/**
	 * Resolve actorId by username.
	 */
	public function getActorIdByUsername(string $username): ?int {
		$this->database->connect();
		$usernameEsc = $this->database->escape($username);
		$row = $this->database->singleQuery("
			SELECT id FROM actors WHERE uri LIKE '%/users/$usernameEsc'
		");
		return $row ? (int)$row['id'] : null;
	}

	/**
	 * Resolve actor by userId.
	 */
	public function getActorByUserId(int $userId): ?array {
		$this->database->connect();
		$row = $this->database->singleQuery("
			SELECT * FROM actors WHERE userId = $userId
		");
		return $row ?: null;
	}

	/**
	 * Verify login credentials, return userId or null.
	 */
	public function verifyLogin(string $username, string $password): ?int {
		$this->database->connect();
		$usernameEsc = $this->database->escape($username);
		$row = $this->database->singleQuery("
			SELECT id, passwordHash FROM users WHERE username='$usernameEsc'
		");
		if ($row && password_verify($password, $row['passwordHash'])) {
			return (int)$row['id'];
		}
		return null;
	}
}

