<?php
App::uses('Security', 'Utility');

/**
 * Ring Component
 *
 * Keeps the uploaded files of the bound fields in the session between the requests of a form with a
 * confirmation page.
 *
 * @property SessionComponent $Session
 */
class RingComponent extends Component
{
    /**
     * Components
     *
     * @var array
     */
    public $components = ['Session'];

    /**
     * Controller
     *
     * @var Controller
     */
    public $controller;

    /**
     * Models to bind down before rendering
     *
     * @var array<string>
     */
    public $_autoBindDown = []; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

    /**
     * Session key
     *
     * @var string
     */
    public static $sessionKey = 'Filebinder';

    /**
     * Constructor
     *
     * @param ComponentCollection $collection instance for the ComponentCollection
     * @param array $settings Settings to set to the component
     * @return void
     */
    public function __construct(ComponentCollection $collection, $settings = [])
    {
        $this->controller = $collection->getController();
        parent::__construct($collection, $settings);
    }

    /**
     * Sets the Label helper and the secret of the loader URLs.
     *
     * @param Controller $controller Controller.
     * @return void
     */
    public function startUp(Controller $controller)
    {
        $controller->helpers['Filebinder.Label'] = ['sessionKey' => self::$sessionKey];
        if (!$this->Session->read(self::$sessionKey . '.secret')) {
            if (Configure::read('Filebinder.secret')) {
                $this->Session->write(self::$sessionKey . '.secret', Configure::read('Filebinder.secret'));
            } else {
                $this->Session->write(self::$sessionKey . '.secret', bin2hex(random_bytes(32)));
            }
        }
    }

    /**
     * Binds down the models given to `bindUp()` with `$autoBindDown`.
     *
     * @param Controller $controller Controller.
     * @return void
     */
    public function beforeRender(Controller $controller)
    {
        if ($this->_autoBindDown) {
            foreach ($this->_autoBindDown as $i => $bindDownModel) {
                $this->bindDown($bindDownModel);
                unset($this->_autoBindDown[$i]);
            }
        }
    }

    /**
     * Moves the uploaded files of the request data to the temporary path and replaces them with their file data.
     *
     * @param string|null $modelName Model name. Default: the model of the controller.
     * @param bool $autoBindDown Whether to call `bindDown()` before rendering.
     * @return false|void
     */
    public function bindUp($modelName = null, $autoBindDown = false)
    {
        if (empty($modelName)) {
            $modelName = $this->controller->modelClass;
        }
        $model = ClassRegistry::init($modelName);
        if (!$model instanceof Model) {
            return false;
        }
        if (empty($this->controller->request->data[$model->alias])) {
            $this->Session->delete(self::$sessionKey . '.' . $model->alias);

            return false;
        }
        $value = reset($this->controller->request->data[$model->alias]);
        $key = key($this->controller->request->data[$model->alias]);

        if (is_int($key) && is_array($value)) { // hasMany model data
            foreach ($this->controller->request->data[$model->alias] as $i => $data) {
                $this->_bindUp($model, $this->controller->request->data[$model->alias][$i], $i);
            }
        } else { // single model data
            $this->_bindUp($model, $this->controller->request->data[$model->alias]);
        }

        if ($autoBindDown && !in_array($model->alias, $this->_autoBindDown)) {
            $this->_autoBindDown[] = $model->alias;
        }
    }

    /**
     * Keeps the file data of the request data in the session.
     *
     * @param string|null $modelName Model name. Default: the model of the controller.
     * @return false|void
     */
    public function bindDown($modelName = null)
    {
        if (empty($modelName)) {
            $modelName = $this->controller->modelClass;
        }
        $model = ClassRegistry::init($modelName);
        if (!$model instanceof Model) {
            return false;
        }
        $this->Session->delete(self::$sessionKey . '.' . $model->alias);
        if (empty($this->controller->request->data[$model->alias])) {
            return false;
        }

        $value = reset($this->controller->request->data[$model->alias]);
        $key = key($this->controller->request->data[$model->alias]);

        if (is_int($key) && is_array($value)) { // hasMany model data
            foreach ($this->controller->request->data[$model->alias] as $i => $data) {
                $this->_bindDown($model, $this->controller->request->data[$model->alias][$i], $i);
            }
        } else { // single model data
            $this->_bindDown($model, $this->controller->request->data[$model->alias]);
        }
    }

    /**
     * bindUp support method
     *
     * @param Model $model Model.
     * @param array $data Data of a record.
     * @param int|null $i Index of the record in hasMany data.
     * @return void
     */
    protected function _bindUp(Model $model, &$data, $i = null)
    {
        $bindFields = Set::combine($model->bindFields, '/field', '/');

        foreach ($data as $fieldName => $value) {
            if (!in_array($fieldName, Set::extract('/field', $model->bindFields))) {
                continue;
            }

            if (!$this->_checkFileUploaded($value) || $value['error'] == UPLOAD_ERR_NO_FILE || !empty($this->controller->request->data[$model->alias]['delete_' . $fieldName])) {
                $data[$fieldName] = null;
                continue;
            }

            $fileName = $value['name'];
            $tmpFile = $value['tmp_name'];
            $contentType = $value['type'];
            $fileSize = filesize($tmpFile);

            $tmpPath = empty($bindFields[$fieldName]['tmpPath']) ? CACHE : $bindFields[$fieldName]['tmpPath'];
            $tmpBindPath = $tmpPath . 'ring_' . date('YmdHis') . '_' . Security::hash($model->alias . $fieldName . $fileName . time()) . $fileName;

            // move_uploaded_file
            move_uploaded_file($tmpFile, $tmpBindPath);

            $ring = [
                'model' => $model->alias,
                'field_name' => $fieldName,
                'file_name' => $fileName,
                'file_content_type' => $contentType,
                'file_size' => $fileSize,
                'tmp_bind_path' => $tmpBindPath,
            ];

            $data[$fieldName] = $ring;
        }

        $sessionKey = is_int($i) ? self::$sessionKey . ".{$model->alias}.{$i}" : self::$sessionKey . ".{$model->alias}";

        if ($this->Session->check($sessionKey)) {
            $sessionData = $this->Session->read($sessionKey);
            foreach ($sessionData as $fieldName => $value) {
                if (empty($data[$fieldName]) && empty($this->controller->request->data[$model->alias]['delete_' . $fieldName])) {
                    $data[$fieldName] = $value;
                }
            }
        }
    }

    /**
     * bindDown support method
     *
     * @param Model $model Model.
     * @param array $data Data of a record.
     * @param int|null $i Index of the record in hasMany data.
     * @return void
     */
    protected function _bindDown(Model $model, $data, $i = null)
    {
        $sessionKey = is_int($i) ? self::$sessionKey . ".{$model->alias}.{$i}." : self::$sessionKey . ".{$model->alias}.";

        foreach ($data as $fieldName => $value) {
            if (!in_array($fieldName, Set::extract('/field', $model->bindFields))) {
                continue;
            }

            if (!$this->_checkBindUpped($value)) {
                continue;
            }
            // file upload error
            if (empty($value['file_size'])) {
                $this->controller->request->data[$model->alias][$fieldName] = null;
                continue;
            }
            if (isset($model->validationErrors[$fieldName])) {
                continue;
            }

            $this->Session->write($sessionKey . $fieldName, $value);
        }
    }

    /**
     * Find the model automatically
     *
     * @param string $modelName Model name.
     * @return Model|null
     */
    protected function &_getModel($modelName)
    {
        $model = null;

        if (!empty($this->controller->{$modelName})) {
            $model = $this->controller->{$modelName};
        } elseif (!empty($this->controller->{$this->controller->modelClass}->{$modelName})) {
            $model = $this->controller->{$this->controller->modelClass}->{$modelName};
        } else {
            $model = ClassRegistry::init($modelName);
        }

        return $model;
    }

    /**
     * Check whether array is valid uploaded-data
     *
     * @param mixed $array The array of uploaded-data
     * @return bool
     */
    protected function _checkFileUploaded($array)
    {
        if (!is_array($array)) {
            return false;
        }

        $keys = ['name', 'type', 'tmp_name', 'error', 'size'];

        return $this->_checkKeyExists($array, $keys);
    }

    /**
     * Check whether array is valid bind-upped data
     *
     * @param mixed $array The array of bind-upped data
     * @return bool
     */
    protected function _checkBindUpped($array)
    {
        if (!is_array($array)) {
            return false;
        }

        $keys = ['field_name', 'file_name', 'file_content_type', 'file_size', 'tmp_bind_path'];

        return $this->_checkKeyExists($array, $keys);
    }

    /**
     * Check whether array has keys
     *
     * @param array $array The array
     * @param array $keys The array of keys
     * @return bool
     */
    protected function _checkKeyExists($array, $keys)
    {
        $diff = array_intersect_key(Set::normalize($keys), $array);

        if (count($keys) !== count($diff)) {
            return false;
        }

        return true;
    }
}
