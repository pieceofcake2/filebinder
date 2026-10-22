<?php

/**
 * Content type of the bound files
 *
 * The content type of a file is detected from its contents with the fileinfo extension. The type sent by the
 * browser, which a user can change, and the extension of the file are not used. The file data of the records and
 * the files uploaded to S3 get the same type.
 */
class FilebinderContentType
{
    /**
     * Returns the content type of a file, detected from its contents.
     *
     * @param string $file Path of the file.
     * @return string|null The content type, or null when the file does not exist or the type cannot be detected.
     */
    public static function detect(string $file): ?string
    {
        if ($file === '' || !is_file($file) || !function_exists('mime_content_type')) {
            return null;
        }
        $contentType = mime_content_type($file);

        return is_string($contentType) && $contentType !== '' ? $contentType : null;
    }
}
