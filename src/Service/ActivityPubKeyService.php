<?php declare(strict_types=1);

namespace ActivityPub\Service;

use Base3\Configuration\Api\IConfiguration;
use Base3\Database\Api\IDatabase;
use ActivityPub\Api\IActivityPubKeyService;

final class ActivityPubKeyService implements IActivityPubKeyService {

        private string $keyBaseDir;

        public function __construct(
                private readonly IConfiguration $configuration,
                private readonly IDatabase $database
        ) {
                $dataDir   = rtrim((string)($this->configuration->get('directories')['data'] ?? ''), '/');
                $keyDirCfg = (string)($this->configuration->get('activitypub')['keydir'] ?? '');

                if ($dataDir === '' || $keyDirCfg === '') {
                        throw new \RuntimeException("Config [directories].data or [activitypub].keydir missing");
                }

                $this->keyBaseDir = str_starts_with($keyDirCfg, '/')
                        ? $keyDirCfg
                        : $dataDir . '/' . ltrim($keyDirCfg, '/');

                if (!is_dir($this->keyBaseDir) && !mkdir($this->keyBaseDir, 0700, true)) {
                        throw new \RuntimeException("Cannot create base key directory: " . $this->keyBaseDir);
                }
                @chmod($this->keyBaseDir, 0700);
        }

        public function getKeyDir(): string {
                return $this->keyBaseDir;
        }

        /**
         * PublicKey PEM from DB
         */
        public function getPublicKeyPem(string $actorName): string {
                $this->database->connect();
                $actorNameEsc = $this->database->escape($actorName);
                $row = $this->database->singleQuery("
                        SELECT publicKey
                        FROM actors
                        WHERE uri LIKE '%/users/$actorNameEsc'
                ");
                return $row['publicKey'] ?? '';
        }

        /**
         * PrivateKey PEM from file
         */
        public function getPrivateKeyPem(string $actorName): string {
                $this->ensureKeys($actorName);
                return file_get_contents($this->getPrivateFile($actorName)) ?: '';
        }

        /**
         * Ensure private key exists, generate if missing
         */
        public function ensureKeys(string $actorName): void {
                $dir = $this->getActorDir($actorName);
                if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
                        throw new \RuntimeException("Cannot create actor key directory: " . $dir);
                }
                @chmod($dir, 0700);

                $privateFile = $this->getPrivateFile($actorName);

                if (!is_file($privateFile)) {
                        $this->generateKeys($actorName, $privateFile);
                }
        }

        /**
         * Generate RSA keypair: private → file, public → DB
         */
        private function generateKeys(string $actorName, string $privateFile): void {
                $config = [
                        "private_key_bits" => 4096,
                        "private_key_type" => OPENSSL_KEYTYPE_RSA
                ];

                $res = openssl_pkey_new($config);
                if ($res === false) {
                        throw new \RuntimeException("Failed to generate RSA keypair: " . openssl_error_string());
                }

                // Private key → file
                if (!openssl_pkey_export($res, $privatePem)) {
                        throw new \RuntimeException("Failed to export private key: " . openssl_error_string());
                }
                file_put_contents($privateFile, $privatePem);
                @chmod($privateFile, 0600);

                // Public key → DB
                $details = openssl_pkey_get_details($res);
                if ($details === false || empty($details['key'])) {
                        throw new \RuntimeException("Failed to extract public key");
                }
                $publicPem = $details['key'];

                $this->database->connect();
                $actorNameEsc = $this->database->escape($actorName);
                $pubEsc       = $this->database->escape($publicPem);

                $this->database->nonQuery("
                        UPDATE actors
                        SET publicKey = '$pubEsc'
                        WHERE uri LIKE '%/users/$actorNameEsc'
                ");
        }

        private function getActorDir(string $actorName): string {
                return $this->keyBaseDir . '/' . $this->sanitize($actorName);
        }

        private function getPrivateFile(string $actorName): string {
                return $this->getActorDir($actorName) . '/private.pem';
        }

        private function sanitize(string $actorName): string {
                return preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower($actorName));
        }
}

