<?php

App::uses('CakeException', 'Error');

/**
 * Thrown when the S3 storage of the Bindable behavior cannot be created: no S3 client plugin is installed and
 * loaded, or `Filebinder.S3.client` names an unknown client.
 */
class FilebinderS3StorageException extends CakeException
{
}
