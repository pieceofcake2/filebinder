<?php

App::uses('FilebinderContentType', 'Filebinder.Lib');

/**
 * Content-Type of the files uploaded by the S3 storages
 */
trait FilebinderS3ContentTypeTrait
{
    /**
     * Adds the `ContentType` option of an upload, detected from the contents of the file as for the file data of
     * the records (`FilebinderContentType`), unless the options already have it. S3 serves an object without a
     * Content-Type as `binary/octet-stream`.
     *
     * @param string $file Path of the file.
     * @param array<string, mixed> $options Options of the upload.
     * @return array<string, mixed>
     */
    protected static function withContentType(string $file, array $options): array
    {
        if (isset($options['ContentType'])) {
            return $options;
        }
        $contentType = FilebinderContentType::detect($file);
        if ($contentType !== null) {
            $options['ContentType'] = $contentType;
        }

        return $options;
    }
}
