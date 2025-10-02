<?php declare(strict_types=1);

namespace ActivityPub\Endpoint;

use Base3\Database\Api\IDatabase;
use Base3\Logger\Api\ILogger;
use ActivityPub\Api\IActivityPubEndpoint;

/**
 * ActivityPub Object endpoint.
 * Serves individual objects (Notes, Images, etc.) from DB.
 *
 * Uses /ap/objects/<id> for addressing objects, but generates canonical
 * followers-URL in /users/<user>/followers form for compatibility.
 */
final class ActivityPubObject implements IActivityPubEndpoint {

	public function __construct(
		private readonly IDatabase $database,
		private readonly ILogger $logger
	) {}

	public static function getName(): string {
		return 'activitypubobject';
	}

	public function getOutput(): string {
		$domain = $_SERVER['SERVER_NAME'] ?? 'localhost';

		// determine object id from path: /ap/objects/<id>
		$objectId = null;
		$path = $_SERVER['REQUEST_URI'] ?? '';
		if (preg_match('#^/ap/objects/(\d+)#', $path, $m)) {
			$objectId = (int)$m[1];
		}
		if (!$objectId) {
			header('HTTP/1.0 400 Bad Request');
			return json_encode(['error' => 'Missing object id']);
		}

		// DB lookup
		$this->database->connect();
		$row = $this->database->singleQuery("
			SELECT o.id, o.uri, o.type, o.content, o.mediaUrl, o.visibility,
			       o.createdAt, a.uri AS actorUri
			FROM objects o
			JOIN actors a ON a.id = o.actorId
			WHERE o.id = $objectId
		");

		if (!$row) {
			header('HTTP/1.0 404 Not Found');
			return json_encode(['error' => 'Object not found']);
		}

		// Followers-Collection des Actors bestimmen
		$actorUri = $row['actorUri'];
		$actorName = basename($actorUri);
		$followersUrl = "https://$domain/users/" . rawurlencode($actorName) . "/followers";

		// build base object
		$object = [
			'@context'     => 'https://www.w3.org/ns/activitystreams',
			'id'           => $row['uri'],
			'type'         => $row['type'] ?: 'Note',
			'attributedTo' => $actorUri,
			'published'    => gmdate('c', strtotime($row['createdAt'])),
			'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
			'cc'           => [$followersUrl]
		];

		if (!empty($row['content'])) {
			$object['content'] = $row['content'];
		}

		if (!empty($row['mediaUrl'])) {
			$object['attachment'] = [[
				'type'      => 'Image',
				'mediaType' => $this->guessMediaType($row['mediaUrl']),
				'url'       => $row['mediaUrl']
			]];
		}

		$this->logger->info("Serving ActivityPub object {$row['uri']}", ['scope' => 'ActivityPub']);
		return json_encode($object, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}

	private function guessMediaType(string $url): string {
		$ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
		return match ($ext) {
			'jpg', 'jpeg' => 'image/jpeg',
			'png'        => 'image/png',
			'gif'        => 'image/gif',
			default      => 'application/octet-stream'
		};
	}
}

