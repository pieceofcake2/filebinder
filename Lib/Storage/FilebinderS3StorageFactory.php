<?php

App::uses('CakePlugin', 'Core');
App::uses('Configure', 'Core');
App::uses('FilebinderS3StorageException', 'Filebinder.Storage');
App::uses('FilebinderAwsS3Storage', 'Filebinder.Storage');
App::uses('FilebinderAsyncAwsS3Storage', 'Filebinder.Storage');

/**
 * Creates the S3 storage of the Bindable behavior
 *
 * The S3 client is chosen with `Filebinder.S3.client`:
 *
 * - `'Aws'`: the AWS SDK for PHP with pieceofcake2/aws (`FilebinderAwsS3Storage`)
 * - `'AsyncAws'`: AsyncAws with pieceofcake2/async-aws and async-aws/simple-s3 (`FilebinderAsyncAwsS3Storage`)
 *
 * When it is not set, the client of the plugin that is loaded is used, `Aws` when both are loaded. The plugin of
 * the client must be loaded.
 */
class FilebinderS3StorageFactory
{
    /**
     * Client: the AWS SDK for PHP with pieceofcake2/aws
     *
     * @var string
     */
    public const CLIENT_AWS = 'Aws';

    /**
     * Client: AsyncAws with pieceofcake2/async-aws
     *
     * @var string
     */
    public const CLIENT_ASYNC_AWS = 'AsyncAws';

    /**
     * Storage classes, plugins and client classes (with their Composer package) of the clients, in the order of
     * the automatic choice.
     *
     * @var array<string, array{storage: string, plugin: string, package: string, class: string, classPackage: string}>
     */
    private const CLIENTS = [
        self::CLIENT_AWS => [
            'storage' => FilebinderAwsS3Storage::class,
            'plugin' => 'Aws',
            'package' => 'pieceofcake2/aws',
            'class' => 'Aws\S3\S3Client',
            'classPackage' => 'aws/aws-sdk-php',
        ],
        self::CLIENT_ASYNC_AWS => [
            'storage' => FilebinderAsyncAwsS3Storage::class,
            'plugin' => 'AsyncAws',
            'package' => 'pieceofcake2/async-aws',
            'class' => 'AsyncAws\SimpleS3\SimpleS3Client',
            'classPackage' => 'async-aws/simple-s3',
        ],
    ];

    /**
     * Creates the S3 storage of the configured or installed client.
     *
     * @param array<string, mixed> $args Client options that override the configuration of the plugin, e.g. `region` or `credentials`.
     * @return \FilebinderS3StorageInterface
     * @throws \FilebinderS3StorageException When no client can be used.
     */
    public static function create(array $args = []): FilebinderS3StorageInterface
    {
        $storage = static::clients()[static::client()]['storage'];

        return new $storage(null, $args);
    }

    /**
     * Returns the client to use: `Filebinder.S3.client`, or the first client whose plugin is loaded.
     *
     * @return string `Aws` or `AsyncAws`.
     * @throws \FilebinderS3StorageException When `Filebinder.S3.client` is unknown, when its plugin is not loaded or
     *   its package is not installed, or when no plugin is loaded.
     */
    public static function client(): string
    {
        $clients = static::clients();
        $client = Configure::read('Filebinder.S3.client');
        if ($client !== null && $client !== '') {
            if (!is_string($client) || !isset($clients[$client])) {
                throw new FilebinderS3StorageException(sprintf(
                    'Filebinder.S3.client must be "%s", not "%s".',
                    implode('" or "', array_keys($clients)),
                    is_string($client) ? $client : gettype($client),
                ));
            }
            static::check($client);

            return $client;
        }

        foreach ($clients as $client => $config) {
            if (CakePlugin::loaded($config['plugin'])) {
                static::check($client);

                return $client;
            }
        }
        throw new FilebinderS3StorageException(
            'The S3 storage of Filebinder requires pieceofcake2/aws or pieceofcake2/async-aws. ' .
            'Install one with: composer require pieceofcake2/aws (or: composer require pieceofcake2/async-aws ' .
            'async-aws/simple-s3), and load it with CakePlugin::load(\'Aws\') (or CakePlugin::load(\'AsyncAws\')).',
        );
    }

    /**
     * Returns the storage classes, plugins and client classes (with their Composer package) of the clients, in the
     * order of the automatic choice.
     *
     * @return array<string, array{storage: string, plugin: string, package: string, class: string, classPackage: string}>
     */
    protected static function clients(): array
    {
        return self::CLIENTS;
    }

    /**
     * Checks that the plugin of a client is loaded and that its S3 client is installed.
     *
     * @param string $client `Aws` or `AsyncAws`.
     * @return void
     * @throws \FilebinderS3StorageException When the plugin is not loaded or the S3 client is not installed.
     */
    protected static function check(string $client): void
    {
        $config = static::clients()[$client];
        if (!CakePlugin::loaded($config['plugin'])) {
            throw new FilebinderS3StorageException(sprintf(
                'The %s client of the Filebinder S3 storage requires the %s plugin. Install it with: ' .
                'composer require %s, and load it with CakePlugin::load(\'%s\').',
                $client,
                $config['plugin'],
                $config['package'],
                $config['plugin'],
            ));
        }
        if (!class_exists($config['class'])) {
            throw new FilebinderS3StorageException(sprintf(
                'The %s client of the Filebinder S3 storage requires %s. Install it with: composer require %s',
                $client,
                $config['classPackage'],
                $config['classPackage'],
            ));
        }
    }
}
