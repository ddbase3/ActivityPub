<?php declare(strict_types=1);

namespace ActivityPub\Api;

/**
 * Interface for ActivityPub key management.
 */
interface IActivityPubKeyService {

        /**
         * Returns absolute path to key directory (base dir for all actors).
         */
        public function getKeyDir(): string;

        /**
         * Returns PEM-encoded public key for given actor (creates if missing).
         */
        public function getPublicKeyPem(string $actorName): string;

        /**
         * Returns PEM-encoded private key for given actor (creates if missing).
         */
        public function getPrivateKeyPem(string $actorName): string;

        /**
         * Ensure keypair exists for given actor (creates if missing).
         */
        public function ensureKeys(string $actorName): void;
}

