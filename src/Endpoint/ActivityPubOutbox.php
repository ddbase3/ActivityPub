<?php declare(strict_types=1);

namespace ActivityPub\Endpoint;

use Base3\Database\Api\IDatabase;
use Base3\Logger\Api\ILogger;
use ActivityPub\Api\IActivityPubEndpoint;

/**
 * ActivityPub Outbox endpoint.
 * Returns only collection metadata (no orderedItems).
 * Individual objects must be fetched from their own URIs.
 *
 * Uses /users/<user>/outbox path for Mastodon/Pixelfed compatibility.
 */
final class ActivityPubOutbox implements IActivityPubEndpoint {

	public function __construct(
		private readonly IDatabase $database,
		private readonly ILogger $logger
	) {}

	public static function getName(): string {
		return 'activitypuboutbox';
	}

	public function getOutput(): string {
		$domain = $_SERVER['SERVER_NAME'] ?? 'localhost';

		// ---- determine actor name from path ----
		$actorName = null;
		$path = $_SERVER['REQUEST_URI'] ?? '';
		if (preg_match('#^/users/([^/]+)/outbox#', $path, $m)) {
			$actorName = $m[1];
		}
		if (!$actorName) {
			header('HTTP/1.0 400 Bad Request');
			return json_encode(['error' => 'Missing actor name']);
		}

		$outboxUrl = "https://$domain/users/" . rawurlencode($actorName) . "/outbox";

		// DB: resolve local actor id
		$this->database->connect();
		$actorNameEsc = $this->database->escape($actorName);
		$localActor = $this->database->singleQuery("
			SELECT id, uri 
			FROM actors 
			WHERE uri LIKE '%/actor/$actorNameEsc'
			   OR uri LIKE '%/users/$actorNameEsc'
		");

		if (!$localActor) {
			header('HTTP/1.0 404 Not Found');
			return json_encode(['error' => 'Local actor not found']);
		}

		$localActorId = (int)$localActor['id'];

		// DB: count objects for this actor
		$row = $this->database->singleQuery("
			SELECT COUNT(*) AS cnt 
			FROM objects 
			WHERE actorId = $localActorId
		");
		$totalItems = (int)$row['cnt'];

		$this->logger->info(
			"Serving outbox metadata for $actorName at $outboxUrl ($totalItems items)",
			['scope' => 'ActivityPub']
		);

		// Return collection metadata only (Pixelfed-style)
		return json_encode([
			'@context'   => 'https://www.w3.org/ns/activitystreams',
			'id'         => $outboxUrl,
			'type'       => 'OrderedCollection',
			'totalItems' => $totalItems
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}
}

