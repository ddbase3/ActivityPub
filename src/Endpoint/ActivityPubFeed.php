<?php declare(strict_types=1);

namespace ActivityPub\Endpoint;

use Base3\Database\Api\IDatabase;
use Base3\Logger\Api\ILogger;
use ActivityPub\Api\IActivityPubEndpoint;

/**
 * ActivityPub Atom feed endpoint.
 * Provides Atom XML with user posts (Pixelfed-style).
 * Uses /users/<user>.atom and resolves actor by users.username to avoid domain/URI drift.
 */
final class ActivityPubFeed implements IActivityPubEndpoint {

	public function __construct(
		private readonly IDatabase $database,
		private readonly ILogger $logger
	) {}

	public static function getName(): string {
		return 'activitypubfeed';
	}

	public function getOutput(): string {
		$domain = $_SERVER['SERVER_NAME'];

		// ---- determine user from path (/users/<user>.atom) ----
		$user = null;
		$path = $_SERVER['REQUEST_URI'] ?? '';
		if (preg_match('#^/users/([^/]+)\.atom#', $path, $m)) {
			$user = $m[1];
		}
		if (!$user) {
			header('HTTP/1.0 400 Bad Request');
			return "Missing user\n";
		}

		$this->database->connect();
		$userEsc = $this->database->escape($user);

		// ---- resolve actor by username (robust against URI formatting issues) ----
		$actor = $this->database->singleQuery("
			SELECT a.id, a.uri, u.username
			FROM users u
			JOIN actors a ON a.userId = u.id
			WHERE u.username = '$userEsc'
		");
		if (!$actor) {
			header('HTTP/1.0 404 Not Found');
			return json_encode(['error' => 'Actor not found']);
		}

		// ---- fetch latest posts ----
		$posts = $this->database->multiQuery("
			SELECT o.id, o.uri, o.type, o.content, o.mediaUrl, o.createdAt
			FROM objects o
			WHERE o.actorId = {$actor['id']}
			ORDER BY o.createdAt DESC
			LIMIT 20
		");

		$feedUrl    = "https://$domain/users/$user.atom";
		$profileUrl = "https://$domain/$user";

		$updated = date('c');
		if (!empty($posts)) {
			$updated = date('c', strtotime($posts[0]['createdAt']));
		}

		$this->logger->info("Serving Atom feed for $user with " . count($posts) . " items", ['scope' => 'ActivityPub']);

		// ---- build Atom XML ----
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">' . "\n";
		$xml .= "  <id>$feedUrl</id>\n";
		$xml .= "  <title>$user on $domain</title>\n";
		$xml .= "  <updated>$updated</updated>\n";
		$xml .= "  <author><name>$user</name><uri>$profileUrl</uri></author>\n";
		$xml .= "  <link rel=\"alternate\" type=\"text/html\" href=\"$profileUrl\" />\n";
		$xml .= "  <link rel=\"self\" type=\"application/atom+xml\" href=\"$feedUrl\" />\n";

		foreach ($posts as $p) {
			$postId  = htmlspecialchars($p['uri']);
			$title   = htmlspecialchars(mb_strimwidth(strip_tags($p['content'] ?? ''), 0, 100, '...'));
			$updated = date('c', strtotime($p['createdAt']));
			$content = $p['content'] ?? '';
			$htmlLink = $p['uri'];
			$mediaUrl = $p['mediaUrl'] ?? null;

			$xml .= "  <entry>\n";
			$xml .= "    <id>$postId</id>\n";
			$xml .= "    <title>$title</title>\n";
			$xml .= "    <updated>$updated</updated>\n";
			$xml .= "    <author><name>{$user}</name><uri>$profileUrl</uri></author>\n";
			$xml .= "    <content type=\"html\"><![CDATA[$content]]></content>\n";
			$xml .= "    <link rel=\"alternate\" href=\"$htmlLink\" />\n";
			if ($mediaUrl) {
				$xml .= "    <media:content url=\"$mediaUrl\" type=\"image/jpeg\" medium=\"image\" />\n";
			}
			$xml .= "  </entry>\n";
		}

		$xml .= "</feed>\n";
		return $xml;
	}
}

