<?php
App::uses('Security', 'Utility');

/**
 * Filebinder Controller
 *
 * Sends the files that are not under the webroot, with the signed URLs of `LabelHelper`.
 *
 * @property SessionComponent $Session
 */
class FilebinderController extends FilebinderAppController
{
    public $name = 'Filebinder';
    public $uses = ['Attachment'];
    public $components = ['Session'];
    public $noUpdateHash = true;

    /**
     * Sends a file.
     *
     * @param string|null $model Model name.
     * @param string|null $model_id Model ID, or `0` for an uploaded file that is not saved yet.
     * @param string|null $fieldName Field name.
     * @param string|null $fileName File name.
     * @return void
     * @throws \NotFoundException When the URL is not valid or the file does not exist.
     */
    public function loader($model = null, $model_id = null, $fieldName = null, $fileName = null)
    {
        $this->layout = false;
        $this->autoRender = false;
        Configure::write('debug', 0);

        if (!$model || $model_id == null || !$fieldName || empty($this->request->query['key']) || empty($this->request->query['expire'])) {
            throw new NotFoundException(__('Invalid access'));
        }
        $key = $this->request->query['key'];
        $expire = $this->request->query['expire'];

        if ($expire < time()) {
            throw new NotFoundException(__('Invalid access'));
        }

        $secret = $this->Session->read('Filebinder.secret');

        // The key signs the file name too; a URL without a file name has the key of an empty file name.
        $expected = Security::hash($model . $model_id . $fieldName . (string)$fileName . $secret . $expire);
        if (!is_string($key) || !hash_equals($expected, $key)) {
            throw new NotFoundException(__('Invalid access'));
        }

        $this->loadModel($model);

        if ($model_id == 0) {
            // tmp file
            $tmpPath = CACHE;
            if (!empty($this->{$model}->bindFields)) {
                foreach ($this->{$model}->bindFields as $value) {
                    if ($value['field'] === $fieldName && !empty($value['tmpPath'])) {
                        $tmpPath = $value['tmpPath'];
                    }
                }
            }
            if (!static::isBaseName($fileName)) {
                throw new NotFoundException(__('Invalid access'));
            }
            $filePath = static::fileInDirectory($tmpPath, $fileName);
            if ($filePath === null) {
                throw new NotFoundException(__('Invalid access'));
            }
        } else {
            $query = [];
            $query['recursive'] = -1;
            $query['fields'] = [
                $this->{$model}->primaryKey,
                $fieldName,
            ];
            $query['conditions'] = [$this->{$model}->primaryKey => $model_id];
            $file = $this->{$model}->find('first', $query);
            if (empty($file[$model][$fieldName]['file_path'])) {
                throw new NotFoundException(__('Invalid access'));
            }

            if (empty($fileName)) {
                $fileName = $file[$model][$fieldName]['file_name'];
            }
            $fileContentType = $file[$model][$fieldName]['file_content_type'];
            $filePath = $file[$model][$fieldName]['file_path'];
        }

        if (!file_exists($filePath)) {
            throw new NotFoundException(__('Invalid access'));
        }
        if (str_contains((string)env('HTTP_USER_AGENT'), 'MSIE')) {
            $fileName = mb_convert_encoding($fileName, 'SJIS', 'UTF-8');
            header('Content-Disposition: inline; filename="' . $fileName . '"');
        } else {
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
        }

        header('Content-Length: ' . filesize($filePath));
        if (!empty($fileContentType)) {
            header('Content-Type: ' . $fileContentType);
        } elseif (class_exists('FInfo')) {
            $info = new FInfo(FILEINFO_MIME_TYPE);
            $fileContentType = $info->file($filePath);
            header('Content-Type: ' . $fileContentType);
        } elseif (function_exists('mime_content_type')) {
            $fileContentType = mime_content_type($filePath);
            header('Content-Type: ' . $fileContentType);
        }

        if (ob_get_level() > 0) {
            ob_end_clean(); // clean
        }
        readfile($filePath);
    }

    /**
     * Checks that a file name is a plain base name, without a directory.
     *
     * @param mixed $fileName File name.
     * @return bool
     */
    protected static function isBaseName($fileName)
    {
        if (!is_string($fileName) || $fileName === '' || $fileName === '.' || $fileName === '..') {
            return false;
        }
        if (strpbrk($fileName, "/\\\0") !== false) {
            return false;
        }

        return basename($fileName) === $fileName;
    }

    /**
     * Returns the real path of a file in a directory, or null when the file does not exist or is not in the
     * directory (e.g. through a symbolic link).
     *
     * @param string $directory Directory.
     * @param string $fileName File name.
     * @return string|null
     */
    protected static function fileInDirectory($directory, $fileName)
    {
        $realDirectory = realpath($directory);
        $realPath = realpath($directory . DS . $fileName);
        if ($realDirectory === false || $realPath === false || !is_file($realPath)) {
            return null;
        }
        if (!str_starts_with($realPath, rtrim($realDirectory, DS) . DS)) {
            return null;
        }

        return $realPath;
    }
}
