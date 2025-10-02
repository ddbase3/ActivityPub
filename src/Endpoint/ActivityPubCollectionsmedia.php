<?php declare(strict_types=1);

namespace ActivityPub\Endpoint;

use ActivityPub\Api\IActivityPubEndpoint;

/**
 * ActivityPub Media collection endpoint.
 * Returns media objects for this actor (OrderedCollection).
 */
final class ActivityPubCollectionsmedia implements IActivityPubEndpoint {

	public static function getName(): string {
		return 'activitypubcollectionsmedia';
	}

	public function getOutput(): string {
		$domain = $_SERVER['SERVER_NAME'];
		$path   = $_SERVER['REQUEST_URI'] ?? '';
		$url    = "https://$domain" . strtok($path, '?');

		return json_encode([
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id'       => $url,
			'type'     => 'OrderedCollection',
			'totalItems' => 0,
			'orderedItems' => []
		], JSON_UNESCAPED_SLASHES);
	}
}

