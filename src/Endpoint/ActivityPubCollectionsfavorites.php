<?php declare(strict_types=1);

namespace ActivityPub\Endpoint;

use ActivityPub\Api\IActivityPubEndpoint;

/**
 * ActivityPub Favorites collection endpoint.
 * Returns favorited objects by this actor (OrderedCollection).
 */
final class ActivityPubCollectionsfavorites implements IActivityPubEndpoint {

	public static function getName(): string {
		return 'activitypubcollectionsfavorites';
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

