<?php declare(strict_types=1);

namespace ActivityPub\Route;

use Base3\Route\Api\IRoute;
use Base3\Api\IClassMap;
use Base3\Logger\Api\ILogger;
use ActivityPub\Api\IActivityPubEndpoint;
use Base3\Api\IOutput;

/**
 * Central ActivityPub route.
 * Supports:
 *   - .well-known/webfinger
 *   - .well-known/nodeinfo
 *   - /nodeinfo/2.0, /nodeinfo/2.1
 *   - /users/<user>.atom (feed)
 *   - /users/<user>
 *   - /users/<user>/inbox
 *   - /users/<user>/followers
 *   - /users/<user>/following
 *   - /users/<user>/outbox
 *   - /users/<user>/collections/featured
 *   - /users/<user>/collections/media
 *   - /users/<user>/collections/favorites
 *   - /users/<user>/liked
 *   - /ap/objects/<objectid>
 */
final class ActivityPubRoute implements IRoute {

	public function __construct(
		private readonly IClassMap $classmap,
		private readonly ILogger $logger
	) {}

	public function match(string $path): ?array {
		$path = explode('?', $path, 2)[0];

		$patterns = [
			'nodeinfowellknown' => '#^/\.well-known/nodeinfo$#',
			'nodeinfo'          => '#^/nodeinfo/(2\.0|2\.1)$#',
			'webfinger'         => '#^/\.well-known/webfinger$#',
			'feed'              => '#^/users/([^/]+)\.atom$#',
			'actor'             => '#^/users/([^/]+)$#',
			'inbox'             => '#^/users/([^/]+)/inbox$#',
			'followers'         => '#^/users/([^/]+)/followers$#',
			'following'         => '#^/users/([^/]+)/following$#',
			'outbox'            => '#^/users/([^/]+)/outbox$#',
			'collectionsfeatured' => '#^/users/([^/]+)/collections/featured$#',
			'collectionsmedia'    => '#^/users/([^/]+)/collections/media$#',
			'collectionsfavorites'=> '#^/users/([^/]+)/collections/favorites$#',
			'liked'               => '#^/users/([^/]+)/liked$#',
			'object'              => '#^/ap/objects/(\d+)$#',
		];

		foreach ($patterns as $key => $regex) {
			if (preg_match($regex, $path, $m)) {
				$match = ['ap' => $key];
				if (isset($m[1])) {
					if ($key === 'object') {
						$match['id'] = $m[1];
					} else {
						$match['user'] = $m[1];
					}
				}
				return $match;
			}
		}
		return null;
	}

	public function dispatch(array $match): string {
		$uri     = $_SERVER['REQUEST_URI'] ?? '';
		$method  = $_SERVER['REQUEST_METHOD'] ?? '';
		$headers = function_exists('getallheaders') ? getallheaders() : [];
		$body    = file_get_contents('php://input');

		// log incoming request
		$this->logger->info("ActivityPub request $method $uri", [
			'scope'   => 'ActivityPub',
			'match'   => $match,
			'headers' => $headers,
			'body'    => $body
		]);

		// special case: relme page (HTML, IOutput)
		if ($match['ap'] === 'relme') {
			$instance = $this->classmap->getInstanceByInterfaceName(IOutput::class, 'activitypubrelmepage');
			if (!is_object($instance)) {
				header('HTTP/1.0 404 Not Found');
				return "404 Not Found\n";
			}
			header('Content-Type: text/html; charset=utf-8');
			$out = (string)$instance->getOutput();

			$this->logger->info("ActivityPub response $uri", [
				'scope'    => 'ActivityPub',
				'response' => mb_substr($out, 0, 2000)
			]);

			return $out;
		}

		// default ActivityPub endpoints
		$name = 'activitypub' . $match['ap'];
		$instance = $this->classmap->getInstanceByInterfaceName(IActivityPubEndpoint::class, $name);
		if (!is_object($instance)) {
			header('HTTP/1.0 404 Not Found');
			return "404 Not Found\n";
		}

		switch ($match['ap']) {
			case 'webfinger':
				header('Content-Type: application/jrd+json; charset=utf-8');
				break;

			case 'feed':
				header('Content-Type: application/atom+xml; charset=utf-8');
				break;

			case 'nodeinfowellknown':
			case 'nodeinfo':
				header('Content-Type: application/json; charset=utf-8');
				break;

			default:
				header('Content-Type: application/activity+json; charset=utf-8');
				break;
		}

		$out = (string)$instance->getOutput();

		// log response
		$this->logger->info("ActivityPub response $uri", [
			'scope'    => 'ActivityPub',
			'response' => mb_substr($out, 0, 2000)
		]);

		return $out;
	}
}

