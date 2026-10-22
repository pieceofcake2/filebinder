<?php

/**
 * Content-Type of the files uploaded by the S3 storages
 */
trait FilebinderS3ContentTypeTrait
{
    /**
     * Adds the `ContentType` option of an upload, detected from the contents of the file with the fileinfo
     * extension, unless the options already have it.
     *
     * The type sent by the browser, which a user can change, and the extension of the file are not used. S3 serves
     * an object without a Content-Type as `binary/octet-stream`.
     *
     * @param string $file Path of the file.
     * @param array<string, mixed> $options Options of the upload.
     * @return array<string, mixed>
     */
    protected static function withContentType(string $file, array $options): array
    {
        if (isset($options['ContentType']) || !function_exists('mime_content_type')) {
            return $options;
        }
        $contentType = mime_content_type($file);
        if (is_string($contentType) && $contentType !== '') {
            $options['ContentType'] = $contentType;
        }

        return $options;
    }
}
