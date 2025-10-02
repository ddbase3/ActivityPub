<?php declare(strict_types=1);

namespace ActivityPub\Endpoint;

use Base3\Database\Api\IDatabase;
use Base3\Logger\Api\ILogger;
use ActivityPub\Api\IActivityPubEndpoint;
use ActivityPub\Api\IActivityPubKeyService;

/**
 * ActivityPub Actor endpoint.
 * Provides the Fediverse identity for the account.
 *
 * DB-backed: ensures actor exists and returns proper JSON.
 * Uses /users/<user> path structure for Mastodon/Pixelfed compatibility.
 */
final class ActivityPubActor implements IActivityPubEndpoint {

	public function __construct(
		private readonly IActivityPubKeyService $keyService,
		private readonly IDatabase $database,
		private readonly ILogger $logger
	) {}

	public static function getName(): string {
		return 'activitypubactor';
	}

	public function getOutput(): string {
		$domain = $_SERVER['SERVER_NAME'];

		// ---- determine actor name from path ----
		$actorName = null;
		$path = $_SERVER['REQUEST_URI'] ?? '';
		if (preg_match('#^/users/([^/?]+)#', $path, $m)) {
			$actorName = $m[1];
		}
		if (!$actorName) {
			header('HTTP/1.0 400 Bad Request');
			return json_encode(['error' => 'Missing actor name']);
		}

		// ---- load actor from DB ----
		$this->database->connect();
		$actorNameEsc = $this->database->escape($actorName);
		$row = $this->database->singleQuery("
			SELECT a.id, a.uri, a.publicKey, u.isPrivate, u.createdAt
			FROM actors a
			JOIN users u ON u.id = a.userId
			WHERE a.uri LIKE '%/users/$actorNameEsc'
		");

		if (!$row) {
			header('HTTP/1.0 404 Not Found');
			return json_encode(['error' => 'Actor not found']);
		}

		// ---- canonical actor URL in /users/... form ----
		$actorUrl     = "https://$domain/users/$actorName";
		$inboxUrl     = "$actorUrl/inbox";
		$followersUrl = "$actorUrl/followers";
		$followingUrl = "$actorUrl/following";
		$outboxUrl    = "$actorUrl/outbox";

		// ---- load or generate public key ----
		$publicKeyPem = $row['publicKey'] ?? '';
		if (empty($publicKeyPem)) {
			$this->keyService->ensureKeys($actorName);
			$publicKeyPem = $this->keyService->getPublicKeyPem($actorName);
		}

		$isPrivate = (int)($row['isPrivate'] ?? 0);
		$manuallyApprovesFollowers = $isPrivate === 1;

		// ---- dummy profile fields ----
		$summary = "Dummy bio for testing ActivityPub implementation.";
		$avatarUrl = "https://$domain/userfiles/ActivityPub/dummy.png";

		$published = "2025-01-01T00:00:00Z";
		if (!empty($row['createdAt'])) {
			$dt = new \DateTime($row['createdAt']);
			$published = $dt->format('Y-m-d\TH:i:s\Z');
		}

		$this->logger->info("Serving ActivityPub actor $actorUrl", ['scope' => 'ActivityPub']);

		return json_encode([
			'@context' => [
				"https://w3id.org/security/v1",
				"https://www.w3.org/ns/activitystreams",
				[
					'toot' => "http://joinmastodon.org/ns#",
					'manuallyApprovesFollowers' => "as:manuallyApprovesFollowers",
					'alsoKnownAs' => ['@id' => 'as:alsoKnownAs', '@type' => '@id'],
					'movedTo' => ['@id' => 'as:movedTo', '@type' => '@id'],
					'indexable' => "toot:indexable",
					'suspended' => "toot:suspended"
				]
			],
			'id' => $actorUrl,
			'type' => 'Person',
			'preferredUsername' => $actorName,
			'name' => ucfirst($actorName),
			'summary' => $summary,
			'url' => "https://$domain/$actorName",
			'inbox' => $inboxUrl,
			'outbox' => $outboxUrl,
			'followers' => $followersUrl,
			'following' => $followingUrl,
			'published' => $published,
			'manuallyApprovesFollowers' => $manuallyApprovesFollowers,
			'publicKey' => [
				'id' => $actorUrl . '#main-key',
				'owner' => $actorUrl,
				'publicKeyPem' => $publicKeyPem
			],
			'icon' => [
				'type' => 'Image',
				'mediaType' => 'image/png',
				'url' => $avatarUrl
			],
			'endpoints' => [
				'sharedInbox' => "https://$domain/inbox"
			]
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}
}

