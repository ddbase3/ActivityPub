<?php declare(strict_types=1);

namespace ActivityPub;

use Base3\Api\ICheck;
use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use Base3\Configuration\Api\IConfiguration;
use Base3\Database\Api\IDatabase;
use ActivityPub\Api\IActivityPubKeyService;
use ActivityPub\Api\IActivityPubAccountService;
use ActivityPub\Service\ActivityPubKeyService;
use ActivityPub\Service\ActivityPubAccountService;

class ActivityPubPlugin implements IPlugin, ICheck {

	public function __construct(private readonly IContainer $container) {}

	// Implementation of IBase

	public static function getName(): string {
		return 'activitypubplugin';
	}

	// Implementation of IPlugin

	public function init() {
		$this->container
			->set(self::getName(), $this, IContainer::SHARED)
			->set(IActivityPubKeyService::class, fn($c) => new ActivityPubKeyService($c->get(IConfiguration::class), $c->get(IDatabase::class)), IContainer::SHARED)
			->set(IActivityPubAccountService::class, fn($c) => new ActivityPubAccountService($c->get(IDatabase::class), $c->get(IActivityPubKeyService::class)), IContainer::SHARED);
	}

	// Implementation of ICheck

	public function checkDependencies() {
		return [];
	}
}
