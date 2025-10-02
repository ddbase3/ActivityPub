<?php declare(strict_types=1);

namespace ActivityPub\Debug;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use ActivityPub\Api\IActivityPubAccountService;
use Base3\Database\Api\IDatabase;
use Base3\Logger\Api\ILogger;

/**
 * Simple account creation endpoint for local users.
 * Example: /createaccount?username=alice&email=alice@example.com&password=secret
 * Also inserts a first demo post with an image.
 */
final class ActivityPubCreateAccount implements IOutput {

	public function __construct(
		private readonly IActivityPubAccountService $accountService,
		private readonly IRequest $request,
		private readonly IDatabase $database,
		private readonly ILogger $logger
	) {}

	public static function getName(): string {
		return 'activitypubcreateaccount';
	}

	public function getOutput($out = "json") {
		$username = trim((string)($this->request->get('username') ?? ''));
		$email    = trim((string)($this->request->get('email') ?? ''));
		$password = trim((string)($this->request->get('password') ?? ''));

		if ($username === '' || $email === '' || $password === '') {
			header('HTTP/1.0 400 Bad Request');
			$msg = "Missing parameter: username, email and password are required.";
			$this->logger->warning("Account creation failed: $msg", ['scope' => 'ActivityPub']);
			return json_encode(['error' => $msg]);
		}

		try {
			// create local actor
			$actorId = $this->accountService->createAccount($username, $email, $password);

			$domain   = $_SERVER['SERVER_NAME'];
			$actorUrl = "https://$domain/users/" . rawurlencode($username);

			// insert demo post
			$this->database->connect();
			$content   = "My first Fediverse post! 🎉";
			$mediaUrl  = "https://$domain/userfiles/ActivityPub/vyara.jpg";
			$createdAt = gmdate('Y-m-d H:i:s');
			$objectUri = "https://$domain/ap/objects/" . uniqid();

			$this->database->nonQuery("
				INSERT INTO objects (actorId, uri, type, content, mediaUrl, visibility, createdAt)
				VALUES ($actorId, '{$this->database->escape($objectUri)}', 'Note',
				        '{$this->database->escape($content)}', '{$this->database->escape($mediaUrl)}',
				        'public', '{$this->database->escape($createdAt)}')
			");
			$postId = (int)$this->database->insertId();

			$this->logger->info(
				"Created local account: $username (actorId=$actorId, url=$actorUrl, firstPost=$postId)",
				['scope' => 'ActivityPub']
			);

			return json_encode([
				'status'    => 'ok',
				'username'  => $username,
				'actorId'   => $actorId,
				'actorUrl'  => $actorUrl,
				'firstPost' => [
					'id'       => $postId,
					'uri'      => $objectUri,
					'content'  => $content,
					'mediaUrl' => $mediaUrl
				]
			]);

		} catch (\Throwable $e) {
			header('HTTP/1.0 500 Internal Server Error');
			$this->logger->error(
				"Account creation error: " . $e->getMessage(),
				['scope' => 'ActivityPub']
			);
			return json_encode(['error' => $e->getMessage()]);
		}
	}

	public function getHelp() {
		return 'Creates a new local ActivityPub account. Params: username, email, password. Also inserts a first post with an image.';
	}
}

