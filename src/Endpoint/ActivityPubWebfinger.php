<?php declare(strict_types=1);

namespace ActivityPub\Endpoint;

use Base3\Logger\Api\ILogger;
use ActivityPub\Api\IActivityPubEndpoint;
use ActivityPub\Api\IActivityPubAccountService;

/**
 * ActivityPub WebFinger service.
 * Provides discovery endpoint for Fediverse accounts.
 *
 * Uses /users/<user> style URLs for Mastodon/Pixelfed compatibility.
 */
final class ActivityPubWebfinger implements IActivityPubEndpoint {

	public function __construct(
		private readonly ILogger $logger,
		private readonly IActivityPubAccountService $accountService
	) {}

	public static function getName(): string {
		return 'activitypubwebfinger';
	}

	public function getOutput(): string {
		$domain   = $_SERVER['SERVER_NAME'];
		$resource = $_GET['resource'] ?? '';

		if (!str_starts_with($resource, 'acct:')) {
			header('HTTP/1.0 400 Bad Request');
			return json_encode(['error' => 'Missing acct: prefix']);
		}

		[$username, $reqDomain] = explode('@', substr($resource, 5), 2) + [null, null];

		if ($reqDomain !== $domain) {
			header('HTTP/1.0 404 Not Found');
			return json_encode(['error' => 'Wrong domain']);
		}

		// check if username exists
		$actorId = $this->accountService->getActorIdByUsername($username);
		if ($actorId === null) {
			header('HTTP/1.0 404 Not Found');
			$this->logger->warning("WebFinger: user $username not found", ['scope' => 'ActivityPub']);
			return json_encode(['error' => 'User not found']);
		}

		// Actor + Profile URLs (canonical: /users/<user>)
		$actorUrl   = "https://$domain/users/" . rawurlencode($username);
		$profileUrl = "https://$domain/" . rawurlencode($username);
		$atomUrl    = "https://$domain/users/" . rawurlencode($username) . ".atom";
		$avatarUrl  = "https://$domain/userfiles/ActivityPub/base3.png";

		$this->logger->info(
			"Serving WebFinger for acct:$username@$domain → $actorUrl",
			['scope' => 'ActivityPub']
		);

		return json_encode([
			'subject' => $resource,
			'aliases' => [
				$profileUrl,
				$actorUrl
			],
			'links' => [
				[
					'rel'  => 'http://webfinger.net/rel/profile-page',
					'type' => 'text/html',
					'href' => $profileUrl
				],
				[
					'rel'  => 'http://schemas.google.com/g/2010#updates-from',
					'type' => 'application/atom+xml',
					'href' => $atomUrl
				],
				[
					'rel'  => 'self',
					'type' => 'application/activity+json',
					'href' => $actorUrl
				],
				[
					'rel'  => 'http://webfinger.net/rel/avatar',
					'type' => 'image/png',
					'href' => $avatarUrl
				],
				[
					'rel'      => 'http://ostatus.org/schema/1.0/subscribe',
					'template' => "https://$domain/authorize_interaction?uri={uri}"
				]
			]
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}
}

