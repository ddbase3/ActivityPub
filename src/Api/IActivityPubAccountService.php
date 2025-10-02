<?php declare(strict_types=1);

namespace ActivityPub\Api;

/**
 * Manages local Fediverse accounts (backed by users + actors).
 * Remote actors are NOT handled here.
 */
interface IActivityPubAccountService {

        /**
         * Creates a new local account (user + actor).
         * Returns the new actorId.
         */
        public function createAccount(string $username, string $email, string $password): int;

        /**
         * Finds actorId by username for a local account.
         */
        public function getActorIdByUsername(string $username): ?int;

        /**
         * Returns the full actor row by userId (local only).
         */
        public function getActorByUserId(int $userId): ?array;

        /**
         * Verifies login credentials for a local account.
         */
        public function verifyLogin(string $username, string $password): ?int;
}

