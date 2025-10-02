<?php declare(strict_types=1);

namespace ActivityPub\Endpoint;

use ActivityPub\Api\IActivityPubEndpoint;

/**
 * ActivityPub NodeInfo Well-Known endpoint.
 * Returns discovery links to /nodeinfo/2.0 or /nodeinfo/2.1.
 */
final class ActivityPubNodeinfowellknown implements IActivityPubEndpoint {

	public static function getName(): string {
		return 'activitypubnodeinfowellknown';
	}

	public function getOutput(): string {
		$domain = $_SERVER['SERVER_NAME'];
		return json_encode([
			'links' => [[
				'rel'  => 'http://nodeinfo.diaspora.software/ns/schema/2.0',
				'href' => "https://$domain/nodeinfo/2.0"
			]]
		], JSON_UNESCAPED_SLASHES);
	}
}

