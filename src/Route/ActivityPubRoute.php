<?php declare(strict_types=1);

namespace ActivityPub\Route;

use Base3\Route\Api\IRoute;
use Base3\Api\IClassMap;
use ActivityPub\Api\IActivityPubEndpoint;
use Base3\Api\IOutput;

/**
 * Central ActivityPub route.
 * Supports:
 *   - .well-known/webfinger
 *   - /users/<user>.atom (feed)
 *   - /users/<user>
 *   - /users/<user>/inbox
 *   - /users/<user>/followers
 *   - /users/<user>/following
 *   - /users/<user>/outbox
 *   - /ap/objects/<objectid>
 */
final class ActivityPubRoute implements IRoute {

        public function __construct(private readonly IClassMap $classmap) {}

        public function match(string $path): ?array {
                $path = explode('?', $path, 2)[0];

                // map of supported endpoints with regex
                $patterns = [
                        'webfinger' => '#^/\.well-known/webfinger$#',
                        'feed'      => '#^/users/([^/]+)\.atom$#',
                        'actor'     => '#^/users/([^/]+)$#',
                        'inbox'     => '#^/users/([^/]+)/inbox$#',
                        'followers' => '#^/users/([^/]+)/followers$#',
                        'following' => '#^/users/([^/]+)/following$#',
                        'outbox'    => '#^/users/([^/]+)/outbox$#',
                        'object'    => '#^/ap/objects/(\d+)$#',
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
                // special case: relme page (HTML, IOutput)
                if ($match['ap'] === 'relme') {
                        $instance = $this->classmap->getInstanceByInterfaceName(IOutput::class, 'activitypubrelmepage');
                        if (!is_object($instance)) {
                                header('HTTP/1.0 404 Not Found');
                                return "404 Not Found\n";
                        }
                        header('Content-Type: text/html; charset=utf-8');
                        return (string)$instance->getOutput();
                }

                // default ActivityPub endpoints
                $name = 'activitypub' . $match['ap'];
                $instance = $this->classmap->getInstanceByInterfaceName(IActivityPubEndpoint::class, $name);
                if (!is_object($instance)) {
                        header('HTTP/1.0 404 Not Found');
                        return "404 Not Found\n";
                }

                // correct Content-Type
                switch ($match['ap']) {
                        case 'webfinger':
                                header('Content-Type: application/jrd+json; charset=utf-8');
                                break;
                        case 'feed':
                                header('Content-Type: application/atom+xml; charset=utf-8');
                                break;
                        default:
                                header('Content-Type: application/activity+json; charset=utf-8');
                                break;
                }

                return (string)$instance->getOutput();
        }
}

