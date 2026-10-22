<?php

App::uses('CakeSession', 'Model/Datasource');
App::uses('Configure', 'Core');

/**
 * Signature of the loader URLs
 *
 * The `key` parameter of a loader URL is an HMAC-SHA256, with the `Security.salt` of the application, of the
 * model, the model ID, the field, the file name, the expiration time and the session ID. A URL works only in the
 * session that rendered it, until it expires.
 */
class FilebinderSignature
{
    /**
     * Returns the signature of a loader URL for the current session.
     *
     * @param string $model Model name.
     * @param string $modelId Model ID.
     * @param string $fieldName Field name.
     * @param string $fileName File name of the URL, or an empty string.
     * @param string $expire Expiration time (Unix time).
     * @return string
     * @throws \CakeException When `Security.salt` is empty or there is no session.
     */
    public static function sign(string $model, string $modelId, string $fieldName, string $fileName, string $expire): string
    {
        $salt = (string)Configure::read('Security.salt');
        if ($salt === '') {
            throw new CakeException('You cannot sign the URLs of Filebinder with an empty Security.salt.');
        }
        $sessionId = static::sessionId();
        if ($sessionId === '') {
            throw new CakeException('You cannot sign the URLs of Filebinder without a session.');
        }

        return static::hmac($salt, $sessionId, [$model, $modelId, $fieldName, $fileName, $expire]);
    }

    /**
     * Checks the signature of a loader URL for the current session, in constant time. Without `Security.salt` or
     * a session, no signature is valid.
     *
     * @param mixed $key Signature of the URL.
     * @param string $model Model name.
     * @param string $modelId Model ID.
     * @param string $fieldName Field name.
     * @param string $fileName File name of the URL, or an empty string.
     * @param string $expire Expiration time (Unix time).
     * @return bool
     */
    public static function verify($key, string $model, string $modelId, string $fieldName, string $fileName, string $expire): bool
    {
        $salt = (string)Configure::read('Security.salt');
        $sessionId = static::sessionId();
        if (!is_string($key) || $salt === '' || $sessionId === '') {
            return false;
        }

        return hash_equals(static::hmac($salt, $sessionId, [$model, $modelId, $fieldName, $fileName, $expire]), $key);
    }

    /**
     * Returns the HMAC of the parts and the session ID. The parts are URL-encoded and joined with NUL, which they
     * cannot contain once encoded, so that moving characters from one part to another changes the signature.
     *
     * @param string $salt Key of the HMAC.
     * @param string $sessionId Session ID.
     * @param array<string> $parts Parts of the URL.
     * @return string
     */
    protected static function hmac(string $salt, string $sessionId, array $parts): string
    {
        $parts[] = $sessionId;

        return hash_hmac('sha256', implode("\0", array_map('rawurlencode', $parts)), $salt);
    }

    /**
     * Returns the ID of the session, which is started if needed.
     *
     * @return string
     */
    protected static function sessionId(): string
    {
        if (!CakeSession::started()) {
            CakeSession::start();
        }

        return (string)CakeSession::id();
    }
}
