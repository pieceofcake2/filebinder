<?php

/**
 * S3 storage of the Bindable behavior
 *
 * The behavior saves, restores and deletes the files of the `S3` storage through an implementation of this
 * interface: `FilebinderAwsS3Storage` (pieceofcake2/aws) or `FilebinderAsyncAwsS3Storage`
 * (pieceofcake2/async-aws). `FilebinderS3StorageFactory` creates the one that is configured or installed.
 */
interface FilebinderS3StorageInterface
{
    /**
     * Uploads a file, with the Content-Type detected from its contents unless the options have `ContentType`.
     *
     * @param string $bucket Bucket.
     * @param string $key Object key.
     * @param string $file Path of the local file.
     * @param array<string, mixed> $options Options of the upload: `ACL`, `ContentType`.
     * @return void
     */
    public function put(string $bucket, string $key, string $file, array $options = []): void;

    /**
     * Downloads an object and returns its contents.
     *
     * @param string $bucket Bucket.
     * @param string $key Object key.
     * @return string
     */
    public function get(string $bucket, string $key): string;

    /**
     * Downloads an object to a local file.
     *
     * @param string $bucket Bucket.
     * @param string $key Object key.
     * @param string $file Path of the local file.
     * @return void
     */
    public function saveAs(string $bucket, string $key, string $file): void;

    /**
     * Deletes an object.
     *
     * @param string $bucket Bucket.
     * @param string $key Object key.
     * @return void
     */
    public function delete(string $bucket, string $key): void;

    /**
     * Returns the URL of an object (not signed), as the S3 client builds it.
     *
     * @param string $bucket Bucket.
     * @param string $key Object key.
     * @return string
     */
    public function url(string $bucket, string $key): string;

    /**
     * Returns a presigned URL to download an object, for an object that is not public.
     *
     * @param string $bucket Bucket.
     * @param string $key Object key.
     * @param \DateTimeImmutable $expires Expiration time.
     * @return string
     */
    public function presignedUrl(string $bucket, string $key, DateTimeImmutable $expires): string;
}
