<?php

use Aws\S3\S3ClientInterface;
use Aws\Sdk;

App::uses('Configure', 'Core');
App::uses('FilebinderS3StorageInterface', 'Filebinder.Storage');
App::uses('FilebinderS3ContentTypeTrait', 'Filebinder.Storage');

/**
 * S3 storage with the AWS SDK for PHP of pieceofcake2/aws
 *
 * The client is created from the `Aws` configuration of pieceofcake2/aws, as `AwsComponent` creates it,
 * with the options given to the constructor.
 */
class FilebinderAwsS3Storage implements FilebinderS3StorageInterface
{
    use FilebinderS3ContentTypeTrait;

    private S3ClientInterface $client;

    /**
     * Constructor
     *
     * @param \Aws\S3\S3ClientInterface|null $client S3 client. Default: a client created with `createClient($args)`.
     * @param array<string, mixed> $args Client options that override the `Aws` configuration, e.g. `region` or `credentials`.
     */
    public function __construct(?S3ClientInterface $client = null, array $args = [])
    {
        $this->client = $client ?? static::createClient($args);
    }

    /**
     * Creates an S3 client from the `Aws` configuration merged with `$args`.
     *
     * @param array<string, mixed> $args Client options that override the `Aws` configuration.
     * @return \Aws\S3\S3ClientInterface
     */
    public static function createClient(array $args = []): S3ClientInterface
    {
        return (new Sdk((array)Configure::read('Aws') + ['version' => 'latest']))->createS3($args);
    }

    /**
     * Returns the S3 client.
     *
     * @return \Aws\S3\S3ClientInterface
     */
    public function client(): S3ClientInterface
    {
        return $this->client;
    }

    /**
     * @inheritDoc
     */
    public function put(string $bucket, string $key, string $file, array $options = []): void
    {
        $this->client->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => fopen($file, 'r'),
        ] + static::withContentType($file, $options));
    }

    /**
     * @inheritDoc
     */
    public function get(string $bucket, string $key): string
    {
        $result = $this->client->getObject(['Bucket' => $bucket, 'Key' => $key]);

        return (string)$result['Body'];
    }

    /**
     * @inheritDoc
     */
    public function saveAs(string $bucket, string $key, string $file): void
    {
        $this->client->getObject(['Bucket' => $bucket, 'Key' => $key, 'SaveAs' => $file]);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $bucket, string $key): void
    {
        $this->client->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
    }

    /**
     * @inheritDoc
     */
    public function url(string $bucket, string $key): string
    {
        return $this->client->getObjectUrl($bucket, $key);
    }

    /**
     * @inheritDoc
     */
    public function presignedUrl(string $bucket, string $key, DateTimeImmutable $expires): string
    {
        $command = $this->client->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $key]);

        return (string)$this->client->createPresignedRequest($command, $expires)->getUri();
    }
}
