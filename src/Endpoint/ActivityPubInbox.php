<?php declare(strict_types=1);

namespace ActivityPub\Endpoint;

use Base3\Database\Api\IDatabase;
use Base3\Logger\Api\ILogger;
use ActivityPub\Api\IActivityPubEndpoint;
use ActivityPub\Api\IActivityPubKeyService;

/**
 * ActivityPub Inbox endpoint.
 * GET: Returns an OrderedCollection (usually empty or with received activities).
 * POST: Receives incoming activities (currently Follow only).
 */
final class ActivityPubInbox implements IActivityPubEndpoint {

	public function __construct(
		private readonly IActivityPubKeyService $keyService,
		private readonly IDatabase $database,
		private readonly ILogger $logger
	) {}

	public static function getName(): string {
		return 'activitypubinbox';
	}

	public function getOutput(): string {
		$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
		$path   = $_SERVER['REQUEST_URI'] ?? '';

		// determine local actor name from path (/users/<name>/inbox)
		$actorName = null;
		if (preg_match('#^/users/([^/?]+)/inbox#', $path, $m)) {
			$actorName = $m[1];
		}
		if (!$actorName) {
			header('HTTP/1.0 400 Bad Request');
			return json_encode(['error' => 'Missing actor name']);
		}

		// ---- GET: return OrderedCollection (like Mastodon/Pixelfed) ----
		if ($method === 'GET') {
			$domain = $_SERVER['SERVER_NAME'];
			$inboxUrl = "https://$domain/users/$actorName/inbox";

			return json_encode([
				'@context' => 'https://www.w3.org/ns/activitystreams',
				'id' => $inboxUrl,
				'type' => 'OrderedCollection',
				'totalItems' => 0,
				'orderedItems' => []
			], JSON_UNESCAPED_SLASHES);
		}

		// ---- POST: process incoming activities ----
		$input = file_get_contents("php://input") ?: '';
		if ($input === '') {
			header('HTTP/1.0 400 Bad Request');
			return json_encode(['error' => 'Empty body']);
		}

		$activity = json_decode($input, true);
		if (!is_array($activity)) {
			header('HTTP/1.0 400 Bad Request');
			return json_encode(['error' => 'Invalid JSON']);
		}

		// ---- handle Follow activity ----
		if (($activity['type'] ?? '') === 'Follow') {
			$remoteActorUri = $activity['actor'] ?? null;
			if (!$remoteActorUri) {
				header('HTTP/1.0 400 Bad Request');
				return json_encode(['error' => 'Follow missing actor']);
			}

			$this->logger->info("Inbox($actorName): Follow from $remoteActorUri", ['scope' => 'ActivityPub']);

			// load local actor from DB
			$this->database->connect();
			$actorNameEsc = $this->database->escape($actorName);
			$localActor = $this->database->singleQuery("
				SELECT a.id, a.uri, u.isPrivate
				FROM actors a
				JOIN users u ON u.id = a.userId
				WHERE a.uri LIKE '%/users/$actorNameEsc'
			");
			if (!$localActor) {
				header('HTTP/1.0 404 Not Found');
				return json_encode(['error' => 'Local actor not found']);
			}
			$localActorId  = (int)$localActor['id'];
			$localActorUri = $localActor['uri'];
			$isPrivate     = (int)($localActor['isPrivate'] ?? 0);

			// ensure remote actor exists in DB
			[$remoteActorId, $remoteActorDoc] = $this->ensureRemoteActor($remoteActorUri, wantDoc: true);

			// store follower relation
			$remoteId = (int)$remoteActorId;
			$accepted = $isPrivate ? 0 : 1;
			$this->database->nonQuery("
				INSERT IGNORE INTO followers (actorId, targetId, accepted)
				VALUES ($remoteId, $localActorId, $accepted)
			");

			// auto-accept if public account
			if ($isPrivate === 0) {
				$domain = $_SERVER['SERVER_NAME'];
				$acceptObject = $activity['id'] ?? $activity;

				$accept = [
					'@context' => 'https://www.w3.org/ns/activitystreams',
					'id'       => "https://$domain/users/$actorName/activities/accept-" . uniqid(),
					'type'     => 'Accept',
					'actor'    => $localActorUri,
					'object'   => $acceptObject,
					'to'       => [$remoteActorUri]
				];

				// discover inbox of remote actor
				$inboxUrl = $remoteActorDoc['inbox'] ?? null;
				if (!$inboxUrl && isset($remoteActorDoc['endpoints']['sharedInbox'])) {
					$inboxUrl = $remoteActorDoc['endpoints']['sharedInbox'];
				}

				if ($inboxUrl) {
					$ok = $this->postSigned($actorName, $inboxUrl, $accept);
					if (!$ok) {
						$this->logger->warning("Inbox($actorName): Accept POST failed to $inboxUrl", ['scope' => 'ActivityPub']);
					}
				} else {
					$this->logger->warning("Inbox($actorName): no inbox/sharedInbox found for $remoteActorUri", ['scope' => 'ActivityPub']);
				}
			}

			return json_encode(['status' => 'ok']);
		}

		// ---- ignore other activity types ----
		return json_encode(['status' => 'ignored']);
	}

	/**
	 * Ensure remote actor exists in DB.
	 * @return array [remoteActorId, remoteActorDoc]
	 */
	private function ensureRemoteActor(string $uri, bool $wantDoc = false): array {
		$this->database->connect();
		$uriEsc = $this->database->escape($uri);
		$row = $this->database->singleQuery("SELECT id FROM actors WHERE uri='$uriEsc'");
		$doc = null;

		if ($row) {
			if ($wantDoc) {
				$doc = $this->fetchActorDocument($uri);
			}
			return [(int)$row['id'], $doc ?? []];
		}

		// fetch remote actor document
		$doc = $this->fetchActorDocument($uri);

		$inbox = $doc['inbox'] ?? '';
		$outbox = $doc['outbox'] ?? '';
		$type = $doc['type'] ?? 'Person';
		$publicKey = $doc['publicKey']['publicKeyPem'] ?? '';

		$inboxEsc  = $this->database->escape((string)$inbox);
		$outboxEsc = $this->database->escape((string)$outbox);
		$typeEsc   = $this->database->escape((string)$type);
		$pubEsc    = $this->database->escape((string)$publicKey);

		$this->database->nonQuery("
			INSERT INTO actors (userId, uri, type, inbox, outbox, publicKey)
			VALUES (NULL, '$uriEsc', '$typeEsc', '$inboxEsc', '$outboxEsc', '$pubEsc')
		");
		$id = (int)$this->database->insertId();

		return [$id, $doc ?? []];
	}

	/**
	 * Fetch remote actor document with correct Accept header.
	 */
	private function fetchActorDocument(string $uri): array {
		$ch = curl_init($uri);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Accept: application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
			'User-Agent: BASE3-ActivityPub'
		]);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		$resp = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err  = curl_error($ch);
		curl_close($ch);

		if ($code >= 200 && $code < 300 && $resp) {
			$json = json_decode($resp, true);
			if (is_array($json)) return $json;
		} else {
			$this->logger->warning("fetchActorDocument failed ($code) for $uri err=$err", ['scope' => 'ActivityPub']);
		}
		return [];
	}

	/**
	 * POST JSON body to $url with HTTP Signature.
	 */
	private function postSigned(string $actorName, string $url, array $body): bool {
		$domain = $_SERVER['SERVER_NAME'];
		$keyId = "https://$domain/users/$actorName#main-key";
		$privateKeyPem = $this->keyService->getPrivateKeyPem($actorName);

		$bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES);
		$date  = gmdate('D, d M Y H:i:s T');
		$digest = 'SHA-256=' . base64_encode(hash('sha256', $bodyJson, true));
		$host = parse_url($url, PHP_URL_HOST) ?: '';
		$path = parse_url($url, PHP_URL_PATH) ?: '/';
		$query = parse_url($url, PHP_URL_QUERY);
		if ($query) $path .= '?' . $query;

		$contentType = 'application/activity+json';

		$signingString =
			"(request-target): post $path\n" .
			"host: $host\n" .
			"date: $date\n" .
			"digest: $digest\n" .
			"content-type: $contentType";

		$sigOk = openssl_sign($signingString, $sig, $privateKeyPem, OPENSSL_ALGO_SHA256);
		if (!$sigOk) {
			$this->logger->error("HTTP Sign failed for $url", ['scope' => 'ActivityPub']);
			return false;
		}

		$signature = sprintf(
			'keyId="%s",algorithm="rsa-sha256",headers="(request-target) host date digest content-type",signature="%s"',
			$keyId,
			base64_encode($sig)
		);

		$headers = [
			"Host: $host",
			"Date: $date",
			"Digest: $digest",
			"Signature: $signature",
			"Content-Type: $contentType",
			"User-Agent: BASE3-ActivityPub"
		];

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyJson);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		$resp = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err  = curl_error($ch);
		curl_close($ch);

		if ($code >= 200 && $code < 300) {
			$this->logger->info("POST Accept → $url ($code)", ['scope' => 'ActivityPub']);
			return true;
		}

		$this->logger->warning("POST Accept FAILED ($code) to $url err=$err resp=" . substr((string)$resp, 0, 500), ['scope' => 'ActivityPub']);
		return false;
	}
}

