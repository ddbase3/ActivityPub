<?php declare(strict_types=1);

namespace ActivityPub;

use Base3\Api\ICheck;
use Base3\Api\IContainer;
use Base3\Api\IPlugin;

class ActivityPubPlugin implements IPlugin, ICheck {

	public function __construct(private readonly IContainer $container) {}

	// Implementation of IBase

	public static function getName(): string {
		return 'activitypubplugin';
	}

	// Implementation of IPlugin

	public function init() {
		$this->container
			->set(self::getName(), $this, IContainer::SHARED);
	}

	// Implementation of ICheck

	public function checkDependencies() {
		return [];
	}
}
