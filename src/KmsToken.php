<?php
namespace Serato\Jwt;

use Aws\Sdk;
use Aws\Result;
use DateTime;
use Ramsey\Uuid\Uuid;
use Psr\Cache\CacheItemPoolInterface;
use Serato\Jwt\Exception\InvalidSignatureException;

/**
 * Provides functionality to allow the use of the AWS KMS service to create
 * and encrypt hashing secrets in JWTs.
 */
abstract class KmsToken extends Token
{
    /**
     * The KMS key spec used to create hashing secrets
     */
    const KMS_KEY_SPEC = 'AES_128';

    /**
     * The name of the JWT header that stores the client application ID
     */
    const APP_ID_HEADER_NAME = 'aid';
    
    /**
     * The name of the JWT header that stores the encrypted hash secret
     */
    const KEY_CIPHERTEXT_HEADER_NAME = 'kct';
    
    /**
     * The name of the JWT header that stores a cache id for the encrypted hash secret
     */
    const KEY_ID_HEADER_NAME = 'kid';

    /**
     * AWS SDK client
     *
     * @var Sdk
     */
    private $aws;

    /**
     * Constructs the class
     *
     * @return void
     *
     * @param Sdk           $aws            AWS client
     */
    public function __construct(Sdk $aws)
    {
        $this->aws = $aws;
    }

    /**
     * Get the AWS client
     *
     * @return Sdk
     */
    public function getAws(): Sdk
    {
        return $this->aws;
    }

    /**
     * Parse a JSON compact notation string that includes a hash secret encrypted
     * with the AWS KMS service, and use this hash secret to verify the token's
     * signature
     *
     * @todo Specify void return type in PHP 7.1
     *
     * @param string                    $json      JSON-encoded token payload
     * @param string                    $keyId     Name of signing key
     * @param CacheItemPoolInterface    $cache     PSR-6 cache item pool
     *
     * @return void
     *
     * @throws InvalidSignatureException
     */
    final protected function parseBase64EncodedTokenDataWithKms(
        string $json,
        string $keyId,
        CacheItemPoolInterface $cache = null
    ) {
        $this->parseBase64EncodedTokenData($json);
        $this->verifySignature(
            $keyId,
            $this->getPlaintextEncryptionKey($cache)
        );
    }

    /**
     * Return the plaintext encryption key for the token. A optional cache pool
     * can be provided to minimise round trips to KMS to decrypt the plaintext key
     * from the key cipher text.
     *
     * @param CacheItemPoolInterface    $cache   PSR-6 cache item pool
     *
     * @return string
     */
    private function getPlaintextEncryptionKey(CacheItemPoolInterface $cache = null)
    {
        if ($cache === null) {
            return $this->decryptCipherTextEncryptionKey();
        } else {
            $key = $this->getCacheKey(
                $this->getProtectedHeader(self::APP_ID_HEADER_NAME),
                $this->getProtectedHeader(self::KEY_ID_HEADER_NAME)
            );

            // Look for the plaintext key in the cache
            $item = $cache->getItem($key);
            if ($item->isHit()) {
                return $item->get();
            }

            // Doesn't exist. So decrypt it from the cipher text.
            $plaintext = $this->decryptCipherTextEncryptionKey();

            // Add it to the cache
            $expiryTime = new DateTime();
            $expiryTime->setTimestamp($this->getClaim('exp'));
            $item->set($plaintext);
            $item->expiresAt($expiryTime);
            $cache->save($item);

            return $plaintext;
        }
    }

    /**
     * Create a cache key
     *
     * @param string $clientAppId   Client Application ID
     * @param string $keyId                 Unique ID
     *
     * @return string
     */
    private function getCacheKey(string $clientAppId, string $keyId)
    {
        return "Jwt-Kms-" . $clientAppId . '-' . $keyId;
    }

    /**
     * Extract the encryption key cipher text and decrypt the plaintext encryption
     * key from it using AWS KMS
     *
     * @return string
     */
    private function decryptCipherTextEncryptionKey()
    {
        $result = $this->getAws()->createKms()->decrypt([
            'CiphertextBlob' => base64_decode($this->getProtectedHeader(self::KEY_CIPHERTEXT_HEADER_NAME))
        ]);
        return $result['Plaintext'];
    }

    /**
     * Construct a JWS token using a hashing secret generated by the AWS KMS service
     *
     * @todo Specify void return type in PHP 7.1
     *
     * @param string        $clientAppKmsMasterKeyId      Client Application KMS Master Key
     * @param string        $clientAppId                  Client Application ID
     * @param array         $audience                     JWT `aud` claim
     * @param string        $subject                      JWT `sub` claim
     * @param int           $issuedAtTime                 JWT `iat` claim
     * @param int           $expiresAtTime                JWT `exp` claim
     * @param array         $customClaims                 Custom JWT claims
     * @param string        $signingKeyId                 Name of signing key
     *
     * @return void
     */
    final protected function createTokenWithKms(
        string $clientAppKmsMasterKeyId,
        string $clientAppId,
        array $audience,
        string $subject,
        int $issuedAtTime,
        int $expiresAtTime,
        array $customClaims,
        string $signingKeyId
    ) {
        // Generate a new hashing secret key
        $generatedKey = $this->generateKeyData($clientAppKmsMasterKeyId);
        // Create the token
        $this->createToken(
            $audience,
            $subject,
            $issuedAtTime,
            $expiresAtTime,
            $customClaims,
            $this->getTokenKeyHeaders($clientAppId, base64_encode($generatedKey['CiphertextBlob'])),
            $signingKeyId,
            $generatedKey['Plaintext']
        );
    }

    /**
     * Create an array of token headers that store the encrypted key data
     *
     * @param string        $clientAppId    Client Application ID
     * @param string        $ciphertext     The encrypted data encryption key
     * @returns array
     */
    private function getTokenKeyHeaders(string $clientAppId, string $ciphertext): array
    {
        return [
            // Client application ID
            self::APP_ID_HEADER_NAME => $clientAppId,
            // A GUID to be used a caching key
            self::KEY_ID_HEADER_NAME => Uuid::uuid4()->toString(),
            // Encrypted secret key blob from KMS
            self::KEY_CIPHERTEXT_HEADER_NAME => $ciphertext
        ];
    }

    /**
     * Generate key data using the AWS KMS service
     *
     * @param string     $clientAppKmsMasterKeyId  Client Application KMS Master Key
     * @returns Result
     */
    private function generateKeyData(string $clientAppKmsMasterKeyId): Result
    {
        return $this->getAws()->createKms()->generateDataKey([
            'KeySpec' => self::KMS_KEY_SPEC,
            'KeyId' => $clientAppKmsMasterKeyId
        ]);
    }
}
