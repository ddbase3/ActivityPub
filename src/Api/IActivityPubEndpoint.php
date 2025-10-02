<?php declare(strict_types=1);

namespace ActivityPub\Api;

use Base3\Api\IBase;

/**
 * Marker interface for ActivityPub endpoints.
 * Prevents them from being treated as generic IOutput.
 */
interface IActivityPubEndpoint extends IBase {

	/**
	 * Generate ActivityPub output (usually JSON).
	 */
	public function getOutput(): string;
}

