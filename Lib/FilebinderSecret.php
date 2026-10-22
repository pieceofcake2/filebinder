<?php

App::uses('CakeSession', 'Model/Datasource');
App::uses('Configure', 'Core');

/**
 * Secret of the loader URLs
 *
 * The secret signs the URLs of the `loader` action. It is kept in the session: `Filebinder.secret` when it is
 * configured, otherwise a random value for each session.
 */
class FilebinderSecret
{
    /**
     * Returns the secret of the session, and creates it when the session has none.
     *
     * @param string $sessionKey Session key of the plugin.
     * @return string
     */
    public static function get(string $sessionKey = 'Filebinder'): string
    {
        $secret = CakeSession::read($sessionKey . '.secret');
        if (!is_string($secret) || $secret === '') {
            $secret = static::create();
            CakeSession::write($sessionKey . '.secret', $secret);
        }

        return $secret;
    }

    /**
     * Creates a secret: `Filebinder.secret` when it is configured, otherwise a random value.
     *
     * @return string
     */
    public static function create(): string
    {
        $secret = Configure::read('Filebinder.secret');
        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        return bin2hex(random_bytes(32));
    }
}
