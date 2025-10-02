<?php declare(strict_types=1);

namespace ActivityPub\Endpoint;

use Base3\Logger\Api\ILogger;
use Base3\Database\Api\IDatabase;
use ActivityPub\Api\IActivityPubEndpoint;

/**
 * ActivityPub Followers endpoint.
 * Returns only collection metadata (Pixelfed-compatible).
 */
final class ActivityPubFollowers implements IActivityPubEndpoint {

	public function __construct(
		private readonly IDatabase $database,
		private readonly ILogger $logger
	) {}

	public static function getName(): string {
		return 'activitypubfollowers';
	}

	public function getOutput(): string {
		$domain = $_SERVER['SERVER_NAME'];

		// ---- determine actor name ----
		$actorName = null;
		$path = $_SERVER['REQUEST_URI'] ?? '';
		if (preg_match('#^/users/([^/]+)/followers#', $path, $m)) {
			$actorName = $m[1];
		}
		if (!$actorName) {
			header('HTTP/1.0 400 Bad Request');
			return json_encode(['error' => 'Missing actor name']);
		}

		$followersUrl = "https://$domain/users/" . rawurlencode($actorName) . "/followers";

		// DB: resolve local actor id
		$this->database->connect();
		$actorNameEsc = $this->database->escape($actorName);
		$localActor = $this->database->singleQuery("
			SELECT id, uri FROM actors WHERE uri LIKE '%/users/$actorNameEsc'
		");

		if (!$localActor) {
			header('HTTP/1.0 404 Not Found');
			return json_encode(['error' => 'Local actor not found']);
		}
		$localActorId = (int)$localActor['id'];

		// DB: count followers
		$row = $this->database->singleQuery("
			SELECT COUNT(*) AS cnt
			FROM followers f
			WHERE f.targetId = $localActorId AND f.accepted = 1
		");
		$total = (int)$row['cnt'];

		$this->logger->info(
			"Serving followers metadata for $actorName at $followersUrl ($total items)",
			['scope' => 'ActivityPub']
		);

		return json_encode([
			'@context'   => 'https://www.w3.org/ns/activitystreams',
			'id'         => $followersUrl,
			'type'       => 'OrderedCollection',
			'totalItems' => $total
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}
}

