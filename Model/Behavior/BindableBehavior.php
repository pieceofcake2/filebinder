<?php

App::uses('FilebinderS3StorageFactory', 'Filebinder.Storage');
App::uses('FilebinderContentType', 'Filebinder.Lib');

/**
 * Bindable Behavior
 *
 * Binds files to the fields listed in `$bindFields` of the model. The file data are saved in the
 * attachment model, and the files in a local path, the database and/or Amazon S3.
 */
class BindableBehavior extends ModelBehavior
{
    /**
     * Settings by model alias
     *
     * @var array
     */
    public $settings = [];

    /**
     * Runtime values by model alias
     *
     * @var array
     */
    public $runtime = [];

    /**
     * Bound fields of the current find() by model alias, set by beforeFind() and used by afterFind()
     *
     * The behavior is shared by the models, so each model keeps its own fields: the afterFind() of an associated
     * model must not get the fields of the primary model.
     *
     * @var array<string, array>
     */
    public $bindFields = [];

    /**
     * Storage: the database (the `file_object` field of the attachment model)
     *
     * @var string
     */
    public const STORAGE_DB = 'Db';

    /**
     * Storage: Amazon S3
     *
     * @var string
     */
    public const STORAGE_S3 = 'S3';

    /**
     * Setup
     *
     * @param Model $model Model using the behavior.
     * @param array $settings Settings.
     * @return void
     */
    public function setUp(Model $model, $settings = [])
    {
        $defaults = [
            'model' => 'Attachment', // attachment model
            'filePath' => WWW_ROOT . 'img' . DS, // default attached local file path. if 'false', not save local
            // 'dbStorage' => true, // backward compatible
            'storage' => BindableBehavior::STORAGE_DB, // file entity save table
            'beforeAttach' => null, // hook function
            'afterAttach' => null, // hook function
            'withObject' => false, // find attachment with file object
            'exchangeFile' => true, // save new file after deleting old file
            'restoreFromStorage' => true, // if local file not exists, restore from strage
            'dirMode' => 0755,
            'fileMode' => 0644,
        ];

        $defaultRuntime = [
            'bindedModel' => null,
            'primaryKey' => 'id',
        ];

        // Default settings
        $this->settings[$model->alias] = Set::merge($defaults, $settings);
        $this->runtime[$model->alias] = $defaultRuntime;

        // Set runtimes
        App::uses($this->settings[$model->alias]['model'], 'Model');
        $this->runtime[$model->alias]['bindedModel'] = ClassRegistry::init($this->settings[$model->alias]['model']);
        $this->runtime[$model->alias]['primaryKey'] = empty($model->primaryKey) ? 'id' : $model->primaryKey;
    }

    /**
     * Changes the settings of the model.
     *
     * @param Model $model Model using the behavior.
     * @param array $settings Settings merged into the current settings.
     * @return void
     */
    public function setSettings(Model $model, $settings)
    {
        $before = $this->getSettings($model);
        $this->setUp($model, Set::merge($before, $settings));
    }

    /**
     * Returns the settings of the model.
     *
     * @param Model $model Model using the behavior.
     * @return array
     */
    public function getSettings(Model $model)
    {
        return $this->settings[$model->alias];
    }

    /**
     * beforeValidate
     *
     * @param Model $model Model using the behavior.
     * @param array $options Options passed from Model::save().
     * @return bool
     */
    public function beforeValidate(Model $model, $options = [])
    {
        return true;
    }

    /**
     * Sets whether find() returns the file contents (`file_object`) of the attachments.
     *
     * @param Model $model Model using the behavior.
     * @param bool $bool Whether to return the file contents.
     * @return void
     */
    public function withObject(Model $model, $bool = true)
    {
        $this->settings[$model->alias]['withObject'] = (bool)$bool;
    }

    /**
     * Removes the bound fields from the fields of the query.
     *
     * @param Model $model Model using the behavior.
     * @param array $queryData Query.
     * @return array
     */
    public function beforeFind(Model $model, $queryData = null)
    {
        if (empty($model->bindFields)) {
            return $queryData;
        }
        $modelName = $model->alias;
        $this->bindFields[$modelName] = Set::combine($model->bindFields, '/field', '/');
        if (empty($queryData['fields'])) {
            return $queryData;
        }

        $fields = (array)$queryData['fields'];
        $flip = array_flip($fields);
        foreach ($this->bindFields[$modelName] as $fieldName => $data) {
            unset($flip[$modelName . '.' . $fieldName]);
            unset($flip[$fieldName]);
            if (!in_array($modelName . '.' . $fieldName, $fields) && !in_array($fieldName, $fields)) {
                unset($this->bindFields[$modelName][$fieldName]);
            }
        }
        $queryData['fields'] = array_flip($flip);

        return $queryData;
    }

    /**
     * Adds the file data of the bound fields to the results.
     *
     * @param Model $model Model using the behavior.
     * @param mixed $result Results.
     * @param bool $primary Whether the model was queried directly.
     * @return mixed
     */
    public function afterFind(Model $model, $result, $primary = false)
    {
        return $this->bindFile($model, $result);
    }

    /**
     * Saves the attachments of the bound fields.
     *
     * @param Model $model Model using the behavior.
     * @param array $options Options passed from Model::save().
     * @return bool
     */
    public function beforeSave(Model $model, $options = [])
    {
        $modelName = $model->alias;
        $model->bindedData = $model->data;
        foreach ($model->data[$modelName] as $fieldName => $value) {
            if (!in_array($fieldName, Set::extract('/field', $model->bindFields))) {
                continue;
            }

            if (empty($value) || empty($value['tmp_bind_path'])) {
                unset($model->data[$modelName][$fieldName]);
                continue;
            }

            if (empty($value['file_size'])) {
                $model->data[$modelName][$fieldName] = null;
                $model->invalidate($fieldName, __('Validation Error: File Upload Error', true));

                return false;
            }

            $bind = $value;
            $bind['model'] = $modelName;
            $bind['model_id'] = 0;

            $tmpFile = $value['tmp_bind_path'];
            // The same type as RingComponent::bindUp(), whatever the data say
            $bind['file_content_type'] = FilebinderContentType::detect((string)$tmpFile) ?? 'application/octet-stream';
            if (file_exists($tmpFile) && is_file($tmpFile)) {
                /**
                 * beforeAttach
                 */
                if (!empty($this->settings[$model->alias]['beforeAttach'])) {
                    $res = $this->_userfunc($model, $this->settings[$model->alias]['beforeAttach'], [$tmpFile]);
                    if (!$res) {
                        return false;
                    }
                }

                /**
                 * Database storage
                 */
                $dbStorage = in_array(BindableBehavior::STORAGE_DB, (array)$this->settings[$model->alias]['storage']);
                // backward compatible
                if (isset($this->settings[$model->alias]['dbStorage'])) {
                    $dbStorage = $this->settings[$model->alias]['dbStorage'];
                }
                if ($dbStorage) {
                    $bind['file_object'] = base64_encode(file_get_contents($tmpFile));
                }
            }

            $this->runtime[$model->alias]['bindedModel']->create();
            if (!$data = $this->runtime[$model->alias]['bindedModel']->save($bind)) {
                return false;
            }

            $bind_id = $this->runtime[$model->alias]['bindedModel']->getLastInsertId();
            unset($model->data[$modelName][$fieldName]);
            $model->bindedData[$modelName][$fieldName] = $data[$this->runtime[$model->alias]['bindedModel']->alias] + [$this->runtime[$model->alias]['bindedModel']->primaryKey => $bind_id];
        }

        return true;
    }

    /**
     * Saves the files of the bound fields and links the attachments to the record.
     *
     * @param Model $model Model using the behavior.
     * @param bool $created Whether a new record was created.
     * @param array $options Options passed from Model::save().
     * @return false|void
     */
    public function afterSave(Model $model, $created, $options = [])
    {
        $modelName = $model->alias;

        if ($created) {
            $model_id = $model->getLastInsertId();
        } else {
            if (empty($model->bindedData[$modelName][$this->runtime[$model->alias]['primaryKey']])) {
                // SoftDeletable
                return;
            }
            $model_id = $model->bindedData[$modelName][$this->runtime[$model->alias]['primaryKey']];
        }

        $bindFields = Set::combine($model->bindFields, '/field', '/');
        $fields = Set::extract('/field', $model->bindFields);
        $deleteFields = [];

        foreach ($fields as $field) {
            $deleteFields[] = 'delete_' . $field;
        }

        // set model_id
        foreach ($model->bindedData[$modelName] as $fieldName => $value) {
            if (in_array($fieldName, $deleteFields) && $value) {
                $delete = true;
                $fieldName = substr($fieldName, 7);
            } elseif (in_array($fieldName, $fields)) {
                $delete = false;
            } else {
                continue;
            }

            if ($delete || (!$created && !empty($value['tmp_bind_path']))) {
                unset($model->data[$modelName]['delete_' . $fieldName]);

                if ($delete || $this->settings[$model->alias]['exchangeFile']) {
                    $this->deleteEntity($model, $model_id, $fieldName);
                } else {
                    $this->runtime[$model->alias]['bindedModel']->deleteAll([
                            'model' => $modelName,
                            'model_id' => $model_id,
                            'field_name' => $fieldName,
                        ]);
                }
            }

            if (!is_array($value) || empty($value['tmp_bind_path'])) {
                continue;
            }

            $bind = [];
            $bind[$this->runtime[$model->alias]['bindedModel']->primaryKey] = $value[$this->runtime[$model->alias]['bindedModel']->primaryKey];
            $bind['model_id'] = $model_id;

            $this->runtime[$model->alias]['bindedModel']->create();
            if (!$this->runtime[$model->alias]['bindedModel']->save($bind)) {
                return false;
            }

            $baseDir = empty($bindFields[$fieldName]['filePath']) ? $this->settings[$model->alias]['filePath'] : $bindFields[$fieldName]['filePath'];
            if ($baseDir) {
                $filePath = $baseDir . $model->transferTo(array_diff_key(['model_id' => $model_id] + $value, Set::normalize(['tmp_bind_path'])));
            } else {
                $filePath = false;
            }
            $tmpFile = $value['tmp_bind_path'];

            if (file_exists($tmpFile) && is_file($tmpFile)) {

                /**
                 * Local file
                 */
                if ($filePath) {
                    $currentMask = umask();
                    umask(0);
                    if (!is_dir(dirname($filePath))) {
                        mkdir(dirname($filePath), $this->settings[$model->alias]['dirMode'], true);
                    }
                    if (!copy($tmpFile, $filePath) || !chmod($filePath, $this->settings[$model->alias]['fileMode'])) {
                        $this->_removeFile($tmpFile);
                        umask($currentMask);

                        return false;
                    }
                    umask($currentMask);
                }

                /**
                 * S3 storage
                 */
                $s3Storage = in_array(BindableBehavior::STORAGE_S3, (array)$this->settings[$model->alias]['storage']);
                if ($s3Storage) {
                    $s3 = $this->_s3Settings($bindFields[$fieldName] ?? []);
                    if (empty($s3['bucket'])) {
                        //__('Validation Error: S3 Parameter Error');
                        $this->_removeFile($tmpFile);

                        return false;
                    }

                    try {
                        $this->s3Storage($model, $fieldName)->put(
                            $s3['bucket'],
                            $this->s3Key($model, ['model_id' => $model_id] + $value, $s3['urlPrefix']),
                            $tmpFile,
                            ['ACL' => $s3['acl']],
                        );
                    } catch (Exception $e) {
                        $this->_removeFile($tmpFile);

                        throw $e;
                    }
                }
            }

            if ($filePath && file_exists($filePath) && is_file($filePath)) {

                /**
                 * afterAttach
                 */
                if (!empty($this->settings[$model->alias]['afterAttach'])) {
                    $res = $this->_userfunc($model, $this->settings[$model->alias]['afterAttach'], [$filePath]);
                    if (!$res) {
                        $this->_removeFile($tmpFile);

                        return false;
                    }
                }
            }
            $this->_removeFile($tmpFile);
        }
    }

    /**
     * Deletes the attachments and the files of the deleted record.
     *
     * @param Model $model Model using the behavior.
     * @return bool
     */
    public function afterDelete(Model $model)
    {
        $this->deleteEntity($model, $model->id);

        return true;
    }

    /**
     * Generate save path for binded file
     *
     * Notice: Don't use a random string or same functions.
     * This method used by afterSave and afterFind method,
     * When a random string is used, it doesn't generate correct file path.
     *
     * Bad function example:
     *   - mt_rand()
     *   - time()
     *   - uniqid()
     *   - etc..
     *
     * @param Model $model Model using the behavior.
     * @param array $data binded file data
     *   - id: binded model id
     *   - model: model name
     *   - model_id: model id
     *   - field_name: binding field name
     *   - file_name: uploaded file name
     *   - file_content_type: uploaded file content type
     *   - file_size: uploaded file size
     *   - created: created date
     *   - modified: modified date
     * @return string file path
     * @see BindableBehavior::afterSave()
     * @see BindableBehavior::afterFind()
     */
    public function transferTo(Model $model, $data)
    {
        return $model->alias . DS . $data['model_id'] . DS . $data['field_name'] . DS . $data['file_name'];
    }

    /**
     * Returns the key of a file on S3: the URL prefix and the path of `transferTo()`, so that a model that overrides
     * `transferTo()` gets the same key when the file is saved, restored, deleted and shown by `LabelHelper`.
     *
     * @param Model $model Model using the behavior.
     * @param array $data File data of a bound field, as for `transferTo()`. `tmp_bind_path` and `file_object` are
     *   not passed to `transferTo()`.
     * @param string|null $urlPrefix Prefix of the key, e.g. the `urlPrefix` of the field.
     * @return string
     */
    public function s3Key(Model $model, array $data, $urlPrefix = '')
    {
        return $urlPrefix . $model->transferTo(array_diff_key($data, Set::normalize(['tmp_bind_path', 'file_object'])));
    }

    /**
     * Deletes the attachments and the files of a record.
     *
     * @param Model $model Model using the behavior.
     * @param mixed $modelId The model id
     * @param array|string $fields Field names. Default: all the bound fields.
     * @return bool
     */
    public function deleteEntity(Model $model, $modelId, $fields = [])
    {
        if (!$deleteFields = $this->_findBindedFields($model, $modelId, $fields)) {
            return false;
        }

        $result = $this->runtime[$model->alias]['bindedModel']->deleteAll([
                'model' => $model->alias,
                'model_id' => $modelId,
                'field_name' => array_keys($deleteFields),
            ]);

        if ($result) {
            $bindFields = Set::combine($model->bindFields, '/field', '/');
            foreach ($bindFields as $fieldName => $value) {
                if (!isset($deleteFields[$fieldName])) {
                    continue;
                }

                /**
                 * S3 storage
                 */
                $s3Storage = in_array(BindableBehavior::STORAGE_S3, (array)$this->settings[$model->alias]['storage']);
                if ($s3Storage) {
                    $s3 = $this->_s3Settings($value);
                    if (empty($s3['bucket'])) {
                        //__('Validation Error: S3 Parameter Error');
                        return false;
                    }

                    $this->s3Storage($model, $fieldName)->delete(
                        $s3['bucket'],
                        $this->s3Key($model, $deleteFields[$fieldName], $s3['urlPrefix']),
                    );
                }

                /**
                 * Local file
                 */
                $baseDir = empty($value['filePath']) ? $this->settings[$model->alias]['filePath'] : $value['filePath'];
                if ($baseDir) {
                    $filePath = $baseDir . $model->transferTo($deleteFields[$fieldName]);
                } else {
                    $filePath = false;
                }

                if ($filePath && file_exists($filePath) && !unlink($filePath)) {
                    $result = false;
                }
            }
        }

        return $result;
    }

    /**
     * Recursively removes a directory.
     *
     * @param string $dir Directory.
     * @return bool
     */
    protected function _recursiveRemoveDir($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != '.' && $object != '..') {
                    if (filetype($dir . '/' . $object) == 'dir') {
                        $this->_recursiveRemoveDir($dir . '/' . $object);
                    } else {
                        $this->_removeFile($dir . '/' . $object);
                    }
                }
            }
            reset($objects);

            return rmdir($dir);
        }

        return false;
    }

    /**
     * Returns the S3 storage of a bound field.
     *
     * The S3 client is chosen with `Filebinder.S3.client` (`Aws` or `AsyncAws`), or is the client of the loaded
     * plugin (see `FilebinderS3StorageFactory`). It is configured with the `Aws` (or `AsyncAws`) configuration of the
     * plugin, and with the deprecated `Filebinder.S3.key`, `Filebinder.S3.secret` and `Filebinder.S3.region`, and
     * the `region` of the field, which take precedence.
     *
     * @param Model $model Model using the behavior.
     * @param string|null $fieldName Bound field.
     * @return \FilebinderS3StorageInterface
     * @throws \FilebinderS3StorageException When no S3 client can be used.
     */
    public function s3Storage(Model $model, $fieldName = null)
    {
        $field = [];
        foreach ((array)$model->bindFields as $bindField) {
            if (isset($bindField['field']) && $bindField['field'] === $fieldName) {
                $field = $bindField;
            }
        }

        return FilebinderS3StorageFactory::create(FilebinderS3StorageFactory::fieldArgs($field));
    }

    /**
     * Returns the S3 settings of a bound field: `bucket`, `acl` and `urlPrefix`, from the field or `Filebinder.S3`.
     *
     * @param array $field Settings of the bound field.
     * @return array{bucket: mixed, acl: string, urlPrefix: mixed}
     */
    protected function _s3Settings(array $field)
    {
        return FilebinderS3StorageFactory::fieldSettings($field);
    }

    /**
     * Removes a file if it exists.
     *
     * @param string $path File path.
     * @return bool
     */
    protected function _removeFile($path)
    {
        if (!is_file($path)) {
            return false;
        }

        return unlink($path);
    }

    /**
     * Validation method: alpha number only
     *
     * @param Model $model Model using the behavior.
     * @param array $value Field name and value.
     * @return bool
     */
    public function alphaNumericFileName(Model $model, $value)
    {
        $file = array_shift($value);
        if (!is_array($file)) {
            return false;
        }
        if (in_array('allowEmpty', $file)) {
            return false;
        }

        $fileName = $file['file_name'];

        // alphaNumeric + .
        if (!preg_match('/^[\p{Ll}\p{Lm}\p{Lo}\p{Lt}\p{Lu}\p{Nd}.]+$/mu', $fileName)) {
            return false;
        }

        return true;
    }

    /**
     * Validation method: check extension
     *
     * @param Model $model Model using the behavior.
     * @param array $value Field name and value.
     * @param array|string $extension Allowed extensions.
     * @return bool
     */
    public function checkExtension(Model $model, $value, $extension)
    {
        $file = array_shift($value);
        if (!is_array($file)) {
            return false;
        }
        if (is_array($extension) && in_array('allowEmpty', $extension)) {
            $extension = ['jpg', 'jpeg', 'gif', 'png']; // set default
        }
        if (empty($file['tmp_bind_path']) && empty($file['file_path'])) {
            return false;
        }

        $tmpFilePath = empty($file['tmp_bind_path']) ? $file['file_path'] : $file['tmp_bind_path'];

        $regexp = '/\.(' . implode('|', (array)$extension) . ')$/i';
        if (!preg_match($regexp, $tmpFilePath)) {
            return false;
        }

        return true;
    }

    /**
     * Validation method: check MIME type
     *
     * @param Model $model Model using the behavior.
     * @param array $value Field name and value.
     * @param array|string $mimeType Allowed MIME types (regular expressions).
     * @return bool
     */
    public function checkContentType(Model $model, $value, $mimeType)
    {
        $file = array_shift($value);
        if (!is_array($file)) {
            return false;
        }
        if (is_array($mimeType) && in_array('allowEmpty', $mimeType)) {
            return true;
        }

        $contentType = $file['file_content_type'];

        $regexp = '#^(' . implode('|', (array)$mimeType) . ')$#';
        if (!preg_match($regexp, $contentType)) {
            return false;
        }

        return true;
    }

    /**
     * Validation method: check max file size
     *
     * @param Model $model Model using the behavior.
     * @param array $value Field name and value.
     * @param string|int $max Max file size, e.g. `1048576` or `'1MB'`.
     * @return bool
     */
    public function checkMaxFileSize(Model $model, $value, $max)
    {
        $file = array_shift($value);
        if (!is_array($file)) {
            return false;
        }
        if (in_array('allowEmpty', $file)) {
            return false;
        }

        $fileSize = $file['file_size'];
        $max = $this->calcFileSizeUnit($max);

        if ($fileSize > $max) {
            return false;
        }

        return true;
    }

    /**
     * Validation method: check min file size
     *
     * @param Model $model Model using the behavior.
     * @param array $value Field name and value.
     * @param string|int $min Min file size, e.g. `1024` or `'1KB'`.
     * @return bool
     */
    public function checkMinFileSize(Model $model, $value, $min)
    {
        $file = array_shift($value);
        if (!is_array($file)) {
            return false;
        }
        if (in_array('allowEmpty', $file)) {
            return false;
        }

        $fileSize = $file['file_size'];
        $min = $this->calcFileSizeUnit($min);

        if ($fileSize < $min) {
            return false;
        }

        return true;
    }

    /**
     * Validation method: check file size (alias of checkMaxFileSize)
     *
     * @param Model $model Model using the behavior.
     * @param array $value Field name and value.
     * @param string|int $max Max file size, e.g. `1048576` or `'1MB'`.
     * @return bool
     */
    public function checkFileSize(Model $model, $value, $max)
    {
        return $this->checkMaxFileSize($model, $value, $max);
    }

    /**
     * Validation method: check is empty
     *
     * @param Model $model Model using the behavior.
     * @param array $value Field name and value.
     * @return bool
     */
    public function notEmptyFile(Model $model, $value)
    {
        return $this->checkMinFileSize($model, $value, -1);
    }

    /**
     * Validation method: check file with user function
     *
     * @param Model $model Model using the behavior.
     * @param array $value Field name and value.
     * @param callable|string $func Function name, or method name of the model, called with the file path.
     * @return mixed
     */
    public function funcCheckFile(Model $model, $value, $func)
    {
        $file = array_shift($value);
        if (!is_array($file)) {
            return false;
        }
        if (is_array($func)) {
            return false;
        }
        if (in_array('allowEmpty', $file)) {
            return false;
        }

        if (empty($file['tmp_bind_path']) && empty($file['file_path'])) {
            return false;
        }

        $tmpFilePath = empty($file['tmp_bind_path']) ? $file['file_path'] : $file['tmp_bind_path'];

        if (!is_file($tmpFilePath)) {
            return false;
        }

        $result = $this->_userfunc($model, $func, [$tmpFilePath]);

        return $result;
    }

    /**
     * Calculate file size by unit
     *
     * e.g.) 100KB -> 102400
     *
     * @param mixed $size File size, e.g. `102400` or `'100KB'`.
     * @return string|float|int|false file size
     */
    public function calcFileSizeUnit($size)
    {
        $units = ['K', 'M', 'G', 'T'];
        $byte = 1024;

        if (is_numeric($size)) {
            return $size;
        } elseif (is_string($size) && preg_match('/^([0-9]+(?:\.[0-9]+)?)(' . implode('|', $units) . ')B?$/i', $size, $matches)) {
            $number = str_contains($matches[1], '.') ? (float)$matches[1] : (int)$matches[1];

            return $number * pow($byte, (int)array_search(strtoupper($matches[2]), $units, true) + 1);
        }

        return false;
    }

    /**
     * Find attached file data from binded model
     *
     * @param Model $model Model using the behavior.
     * @param mixed $modelId The model id
     * @param array|string $fields The fields that searches in string or array
     * @return array
     */
    protected function _findBindedFields(Model $model, $modelId, $fields = [])
    {
        $query = [
            'conditions' => [
                'model' => $model->alias,
                'model_id' => $modelId,
            ],
            'fields' => [
                'id', 'model', 'model_id', 'field_name', 'file_name',
                'file_content_type', 'file_size', 'created', 'modified',
            ],
            'recursive' => -1,
        ];

        if ($fields) {
            if (is_string($fields)) {
                App::uses('CakeText', 'Utility');
                $fields = CakeText::tokenize($fields);
            }

            $query['conditions']['field_name'] = $fields;
        }

        $data = $this->runtime[$model->alias]['bindedModel']->find('all', $query);

        if ($data) {
            $data = Set::combine(
                $data,
                '{n}.' . $this->runtime[$model->alias]['bindedModel']->alias . '.field_name',
                '{n}.' . $this->runtime[$model->alias]['bindedModel']->alias,
            );
        }

        return $data;
    }

    /**
     * Call user function
     *
     * @param Model $model Model using the behavior.
     * @param mixed $function The callable function name
     * @param array $args The arguments array
     * @return mixed
     */
    protected function _userfunc(Model $model, $function, $args = [])
    {
        if (is_array($function) && count($function) > 1) {
            [$class, $method] = $function;

            if (is_callable([$class, $method])) {
                return call_user_func_array([$class, $method], $args);
            }
        } elseif (is_string($function)) {
            if (function_exists($function)) {
                return call_user_func_array($function, $args);
            } elseif (method_exists($model, $function)) {
                return $model->dispatchMethod($function, $args);
            }
        }

        return false;
    }

    /**
     * Bind file fields
     *
     * @param Model $model Model using the behavior.
     * @param mixed $data Results of find().
     * @return mixed
     */
    protected function bindFile(Model $model, $data = [])
    {
        $modelName = $model->alias;
        // The fields of the beforeFind() of this model, used once; without one (e.g. an associated model), all
        $findFields = $this->bindFields[$modelName] ?? null;
        unset($this->bindFields[$modelName]);
        if (empty($model->bindFields) || empty($data) || $findFields === []) {
            // No bound field, or a field list without a bound field
            return $data;
        }

        // Detect $data array format
        $tmpData = [];
        if (isset($data[$model->alias][$model->primaryKey])) {
            $tmpData = [$data];
        } elseif (isset($data[$model->alias][0][$model->primaryKey])) {
            foreach ($data[$model->alias] as $i => $_data) {
                $tmpData[$i] = [$model->alias => $_data];
            }
        } elseif (isset($data[$model->primaryKey])) {
            $tmpData = [[$model->alias => $data]];
        } elseif (isset($data[0][$model->primaryKey])) {
            foreach ($data as $i => $_data) {
                $tmpData[$i] = [$model->alias => $_data];
            }
        } else {
            $tmpData = $data;
        }

        $bindFields = $findFields ?? Set::combine($model->bindFields, '/field', '/');
        $model_ids = Set::extract('/' . $modelName . '/' . $this->runtime[$model->alias]['primaryKey'], $tmpData);

        if (!$model_ids) {
            return $data;
        }

        $query = [];
        $query['fields'] = [
            'id',
            'model',
            'model_id',
            'field_name',
            'file_name',
            'file_content_type',
            'file_size',
            'created',
            'modified'];
        // with Object
        if ($this->settings[$model->alias]['withObject']) {
            $query['fields'][] = 'file_object';
        }

        $query['recursive'] = -1;
        $query['conditions'] = [
            'model' => $modelName,
            'model_id' => $model_ids];

        // Mongodb.MondodbSource Support
        $dataSource = $this->runtime[$model->alias]['bindedModel']->getDataSource();
        if ($dataSource->config['datasource'] === 'Mongodb.MongodbSource') {
            $query['conditions'] = [
                'model' => $modelName,
                'model_id' => ['$in' => $model_ids,
                ],
            ];
        }

        $binds = $this->runtime[$model->alias]['bindedModel']->find('all', $query);
        $binds = Set::combine($binds, ['%1$s.%2$s' , '/' . $this->settings[$model->alias]['model'] . '/model_id', '/' . $this->settings[$model->alias]['model'] . '/field_name'], '/' . $this->settings[$model->alias]['model']);
        foreach ($tmpData as $key => $value) {
            if (empty($tmpData[$key][$modelName])) {
                continue;
            }
            $model_id = $value[$modelName][$this->runtime[$model->alias]['primaryKey']];
            foreach ($bindFields as $fieldName => $bindValue) {
                if (array_key_exists($model_id . '.' . $fieldName, $binds)) {
                    $bind = $binds[$model_id . '.' . $fieldName][$this->settings[$model->alias]['model']];
                    $baseDir = empty($bindFields[$fieldName]['filePath']) ? $this->settings[$model->alias]['filePath'] : $bindFields[$fieldName]['filePath'];
                    if ($baseDir) {
                        $filePath = $baseDir . $model->transferTo(array_diff_key($bind, Set::normalize(['file_object'])));
                    } else {
                        $filePath = false;
                    }
                    $bind['file_path'] = $filePath;
                    $bind['bindedModel'] = $this->runtime[$model->alias]['bindedModel']->alias;
                    $tmpData[$key][$modelName][$fieldName] = $bind;

                    $restoreFromStorage = $this->settings[$model->alias]['restoreFromStorage'];

                    if ((!file_exists($filePath) || filemtime($filePath) < strtotime($bind['modified']))) {

                        /**
                         * Database storage
                         */
                        $dbStorage = in_array(BindableBehavior::STORAGE_DB, (array)$this->settings[$model->alias]['storage']);
                        // backward compatible
                        if (isset($this->settings[$model->alias]['dbStorage'])) {
                            $dbStorage = $this->settings[$model->alias]['dbStorage'];
                        }
                        if ($restoreFromStorage && $filePath && $dbStorage) {

                            /**
                             * create entity from record data
                             */
                            if ($this->settings[$model->alias]['withObject']) {
                                $fileObject = $bind['file_object'];
                            } else {
                                $all = $this->runtime[$model->alias]['bindedModel']->findById($bind['id']);
                                $fileObject = $all[$this->settings[$model->alias]['model']]['file_object'];
                            }

                            if (!$fileObject) {
                                continue;
                            }

                            $currentMask = umask();
                            umask(0);
                            if (!is_dir(dirname($filePath))) {
                                mkdir(dirname($filePath), $this->settings[$model->alias]['dirMode'], true);
                            }

                            if (file_exists($filePath) && is_file($filePath)) {
                                unlink($filePath);
                            }

                            if (
                                !file_put_contents($filePath, base64_decode($fileObject))
                                || !chmod($filePath, $this->settings[$model->alias]['fileMode'])
                            ) {
                                umask($currentMask);

                                return false;
                            }
                            umask($currentMask);
                        }

                        /**
                         * S3 storage
                         */
                        $s3Storage = in_array(BindableBehavior::STORAGE_S3, (array)$this->settings[$model->alias]['storage']);
                        if ($restoreFromStorage && $filePath && $s3Storage) {
                            $s3 = $this->_s3Settings($bindFields[$fieldName]);
                            if (empty($s3['bucket'])) {
                                //__('Validation Error: S3 Parameter Error');
                                return false;
                            }

                            $currentMask = umask();
                            umask(0);
                            try {
                                if (!is_dir(dirname($filePath))) {
                                    mkdir(dirname($filePath), $this->settings[$model->alias]['dirMode'], true);
                                }

                                $this->s3Storage($model, $fieldName)->saveAs(
                                    $s3['bucket'],
                                    $this->s3Key($model, $bind, $s3['urlPrefix']),
                                    $filePath,
                                );
                                if (is_file($filePath)) {
                                    chmod($filePath, $this->settings[$model->alias]['fileMode']);
                                }
                            } finally {
                                umask($currentMask);
                            }
                        }

                        if (file_exists($filePath) && is_file($filePath)) {
                            /**
                             * afterAttach
                             */
                            if (!empty($this->settings[$model->alias]['afterAttach'])) {
                                $res = $this->_userfunc($model, $this->settings[$model->alias]['afterAttach'], [$filePath]);

                                if (!$res) {
                                    return false;
                                }
                            }
                        }
                    }

                    /**
                     * withObject support
                     */
                    if ($this->settings[$model->alias]['withObject'] && empty($tmpData[$key][$modelName][$fieldName]['file_object'])) {
                        /**
                         * S3 storage
                         */
                        $s3Storage = in_array(BindableBehavior::STORAGE_S3, (array)$this->settings[$model->alias]['storage']);
                        if ($s3Storage) {
                            $s3 = $this->_s3Settings($bindFields[$fieldName]);
                            if (empty($s3['bucket'])) {
                                //__('Validation Error: S3 Parameter Error');
                                return false;
                            }

                            $tmpData[$key][$modelName][$fieldName]['file_object'] = base64_encode($this->s3Storage($model, $fieldName)->get(
                                $s3['bucket'],
                                $this->s3Key($model, $bind, $s3['urlPrefix']),
                            ));
                        }
                    }
                } else {
                    $tmpData[$key][$modelName][$fieldName] = null;
                }
            }
        }

        // Update $data array
        if (isset($data[$model->alias][$model->primaryKey])) {
            $data[$model->alias] = $tmpData[0][$model->alias];
        } elseif (isset($data[$model->alias][0][$model->primaryKey])) {
            $data[$model->alias] = Set::extract($tmpData, '{n}.' . $model->alias);
        } elseif (isset($data[$model->primaryKey])) {
            $data = $tmpData[0][$model->alias];
        } elseif (isset($data[0][$model->primaryKey])) {
            $data = Set::extract($tmpData, '{n}.' . $model->alias);
        } else {
            $data = $tmpData;
        }

        return $data;
    }
}
