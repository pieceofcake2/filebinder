<?php

use AsyncAws\SimpleS3\SimpleS3Client;

App::uses('ComponentCollection', 'Controller');
App::uses('AsyncAwsComponent', 'AsyncAws.Controller/Component');
App::uses('S3Storage', 'AsyncAws.Lib');
App::uses('FilebinderS3StorageInterface', 'Filebinder.Storage');

/**
 * S3 storage with the `S3Storage` helper of pieceofcake2/async-aws (async-aws/simple-s3)
 *
 * The client is created from the `AsyncAws` configuration (or the `Aws` configuration) of
 * pieceofcake2/async-aws, as `AsyncAwsComponent::createSimpleS3()` creates it, with the options given to the
 * constructor.
 */
class FilebinderAsyncAwsS3Storage implements FilebinderS3StorageInterface
{
    private S3Storage $storage;

    /**
     * Constructor
     *
     * @param \AsyncAws\SimpleS3\SimpleS3Client|null $client S3 client. Default: a client created with `createClient($args)`.
     * @param array<string, mixed> $args Client options that override the `AsyncAws` configuration, e.g. `region` or `credentials`.
     * @throws \MissingPackageException When async-aws/simple-s3 is not installed.
     */
    public function __construct(?SimpleS3Client $client = null, array $args = [])
    {
        $this->storage = new S3Storage($client ?? static::createClient($args));
    }

    /**
     * Creates an S3 client from the `AsyncAws` configuration merged with `$args`.
     *
     * @param array<string, mixed> $args Client options that override the `AsyncAws` configuration.
     * @return \AsyncAws\SimpleS3\SimpleS3Client
     * @throws \MissingPackageException When async-aws/simple-s3 is not installed.
     */
    public static function createClient(array $args = []): SimpleS3Client
    {
        return (new AsyncAwsComponent(new ComponentCollection()))->createSimpleS3($args);
    }

    /**
     * Returns the S3 client.
     *
     * @return \AsyncAws\SimpleS3\SimpleS3Client
     */
    public function client(): SimpleS3Client
    {
        return $this->storage->client();
    }

    /**
     * @inheritDoc
     */
    public function put(string $bucket, string $key, string $file, array $options = []): void
    {
        if (!isset($options['ContentType'])) {
            $contentType = static::contentType($file);
            if ($contentType !== null) {
                $options['ContentType'] = $contentType;
            }
        }
        $stream = fopen($file, 'r');
        try {
            $this->storage->write(static::path($bucket, $key), $stream, $options);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @inheritDoc
     */
    public function get(string $bucket, string $key): string
    {
        return $this->storage->read(static::path($bucket, $key));
    }

    /**
     * @inheritDoc
     */
    public function saveAs(string $bucket, string $key, string $file): void
    {
        $source = $this->storage->readStream(static::path($bucket, $key));
        $target = fopen($file, 'w');
        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($target);
        }
    }

    /**
     * @inheritDoc
     */
    public function delete(string $bucket, string $key): void
    {
        $this->storage->delete(static::path($bucket, $key));
    }

    /**
     * @inheritDoc
     */
    public function url(string $bucket, string $key): string
    {
        return $this->storage->url(static::path($bucket, $key));
    }

    /**
     * @inheritDoc
     */
    public function presignedUrl(string $bucket, string $key, DateTimeImmutable $expires): string
    {
        return $this->storage->presignedUrl(static::path($bucket, $key), $expires);
    }

    /**
     * Returns the Content-Type of a file, detected from its contents with the fileinfo extension.
     *
     * AsyncAws does not detect the Content-Type, and S3 serves an object without it as `binary/octet-stream`.
     *
     * @param string $file Path of the file.
     * @return string|null The Content-Type, or null when it cannot be detected.
     */
    protected static function contentType(string $file): ?string
    {
        if (!function_exists('mime_content_type')) {
            return null;
        }
        $contentType = mime_content_type($file);

        return is_string($contentType) ? $contentType : null;
    }

    /**
     * Returns the `s3://bucket/key` path of `S3Storage`.
     *
     * @param string $bucket Bucket.
     * @param string $key Object key.
     * @return string
     */
    protected static function path(string $bucket, string $key): string
    {
        return 's3://' . $bucket . '/' . $key;
    }
}
