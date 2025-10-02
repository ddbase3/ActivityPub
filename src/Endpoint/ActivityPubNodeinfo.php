<?php declare(strict_types=1);

namespace ActivityPub\Endpoint;

use ActivityPub\Api\IActivityPubEndpoint;

/**
 * ActivityPub NodeInfo endpoint (/nodeinfo/2.0 or /2.1).
 * Returns instance metadata for Fediverse discovery.
 */
final class ActivityPubNodeinfo implements IActivityPubEndpoint {

	public static function getName(): string {
		return 'activitypubnodeinfo';
	}

	public function getOutput(): string {
		$domain = $_SERVER['SERVER_NAME'];

		// TODO: replace static values with DB queries if needed
		// e.g. count users, posts, active users
		return json_encode([
			'version' => '2.0',
			'software' => [
				'name'    => 'base3',
				'version' => '0.1.0'
			],
			'protocols' => ['activitypub'],
			'services' => [
				'inbound'  => [],
				'outbound' => []
			],
			'openRegistrations' => false,
			'usage' => [
				'users' => [
					'total'          => 1,
					'activeMonth'    => 1,
					'activeHalfyear' => 1
				],
				'localPosts' => 0
			],
			'metadata' => [
				'domain' => $domain
			]
		], JSON_UNESCAPED_SLASHES);
	}
}

