<?php
App::uses('AppHelper', 'View/Helper');
App::uses('FilebinderSignature', 'Filebinder.Lib');
App::uses('FilebinderS3StorageFactory', 'Filebinder.Storage');

/**
 * Label Helper
 *
 * Renders an image, a link or a URL of a file attached with the Bindable behavior.
 *
 * @property HtmlHelper $Html
 * @property SessionHelper $Session
 */
class LabelHelper extends AppHelper
{
    /**
     * Helpers
     *
     * @var array
     */
    public $helpers = ['Html', 'Session'];

    /**
     * Settings
     *
     * - `sessionKey`: Session key of the plugin (no longer used: the loader URLs are signed with `FilebinderSignature`).
     *
     * @var array
     */
    public $settings = [
        'sessionKey' => 'Filebinder',
    ];

    /**
     * S3 storages of the S3 URLs by client options, created when they are first used
     *
     * @var array<string, \FilebinderS3StorageInterface>
     */
    protected $_s3Storages = [];

    /**
     * Returns an image tag of the file, or `$options['noFile']` when there is no file.
     *
     * @param array|null $file File data of a bound field.
     * @param array $options Options of `HtmlHelper::image()`, and `noFile`, `prefix`, `S3` and `url_prefix`.
     * @return string
     */
    public function image($file = null, $options = [])
    {
        if (!$this->_makeSrc($file, $options)) {
            return empty($options['noFile']) ? '' : $options['noFile'];
        }

        return $this->Html->image($this->_makeSrc($file, $options), $options);
    }

    /**
     * Returns a link to the file, or `$options['noFile']` when there is no file.
     *
     * @param array|null $file File data of a bound field.
     * @param array $options Options of `HtmlHelper::link()`, and `title`, `noFile`, `prefix`, `S3` and `url_prefix`.
     * @return string
     */
    public function link($file = null, $options = [])
    {
        $src = $this->_makeSrc($file, $options);
        if (!$src) {
            return empty($options['noFile']) ? '' : $options['noFile'];
        }
        $fileTitle = empty($options['title']) ? $file['file_name'] : $options['title'];
        unset($options['title']);

        return $this->Html->link($fileTitle, $src, $options);
    }

    /**
     * Returns the URL of the file, or `$options['noFile']` when there is no file.
     *
     * @param array|null $file File data of a bound field.
     * @param array|bool $options Options of `HtmlHelper::url()`, and `noFile`, `prefix`, `S3` and `url_prefix`.
     * @return string
     */
    public function url($file = null, $options = [])
    {
        $src = $this->_makeSrc($file, $options);
        if (!$src) {
            return empty($options['noFile']) ? '' : $options['noFile'];
        }

        return $this->Html->url($src, $options);
    }

    /**
     * Returns the source of the file: a path under the webroot, a URL array of the loader action, or an S3 URL.
     *
     * @param array|null $file File data of a bound field.
     * @param array $options Options: `prefix`, `S3` and `url_prefix`.
     * @return array|string|false|null
     */
    public function _makeSrc($file = null, $options = []) // phpcs:ignore CakePHP.NamingConventions.ValidFunctionName.PublicWithUnderscore
    {
        $prefix = empty($options['prefix']) ? '' : $options['prefix'];

        /**
         * S3 url
         */
        if (!empty($options['S3']) || !empty($options['s3'])) {
            return $this->_makeS3Url($file, $options);
        }

        $filePath = empty($file['file_path']) ? (empty($file['tmp_bind_path']) ? false : $file['tmp_bind_path']) : preg_replace('#/([^/]+)$#', '/' . $prefix . '$1', $file['file_path']);
        if (empty($file) || !$filePath) {
            return false;
        }

        if (preg_match('#' . WWW_ROOT . '#', $filePath)) {
            $src = preg_replace('#' . WWW_ROOT . '#', DS, $filePath);

            return $src;
        }

        if (!empty($file['tmp_bind_path'])) {
            if (empty($file['model_id']) || file_exists($file['tmp_bind_path'])) {
                $file['model_id'] = 0;
                $file['file_name'] = preg_replace('#.+/([^/]+)$#', '$1', $file['tmp_bind_path']);
            }
        }

        // over 1.3
        $prefixes = Configure::read('Routing.prefixes');

        if (!$prefixes && Configure::read('Routing.admin')) {
            $prefixes = Configure::read('Routing.admin');
        }

        $url = [];

        foreach ((array)$prefixes as $p) {
            $url[$p] = false;
        }

        $expire = $this->_expire();

        $key = FilebinderSignature::sign((string)$file['model'], (string)$file['model_id'], (string)$file['field_name'], $prefix . $file['file_name'], (string)$expire);

        $url = array_merge($url, [
            'plugin' => 'filebinder',
            'controller' => 'filebinder',
            'action' => 'loader',
            $file['model'],
            $file['model_id'],
            $file['field_name'],
            $prefix . $file['file_name'],
            '?' => ['key' => $key, 'expire' => $expire],
        ]);

        return $url;
    }

    /**
     * Returns the URL of the file on Amazon S3, as the S3 client builds it with the region or the endpoint of the
     * configuration.
     *
     * The bucket, the ACL, the key prefix and the region are those of the bound field (in `$bindFields` of the
     * model), or of `Filebinder.S3`. A file with a public ACL (`public-read`, `public-read-write`) gets its URL; a
     * file with another ACL (e.g. `private`) gets a presigned URL that expires as the loader URLs
     * (`Filebinder.expire`, one minute by default).
     *
     * @param array|null $file File data of a bound field.
     * @param array $options Options: `prefix` and `url_prefix`.
     * @return string|null The URL, or null without file data or bucket.
     * @throws \FilebinderS3StorageException When no S3 client can be used.
     */
    public function _makeS3Url($file, $options) // phpcs:ignore CakePHP.NamingConventions.ValidFunctionName.PublicWithUnderscore
    {
        if (empty($file['model'])) {
            return null;
        }
        $field = $this->_bindField($file);
        $s3 = FilebinderS3StorageFactory::fieldSettings($field);
        if (empty($s3['bucket'])) {
            return null;
        }
        $prefix = empty($options['prefix']) ? '' : $options['prefix'];
        $urlPrefix = !empty($options['url_prefix']) ? $options['url_prefix'] : $s3['urlPrefix'];
        $key = $urlPrefix . $file['model'] . '/' . $file['model_id'] . '/' . $file['field_name'] . '/' . $prefix . $file['file_name'];

        $args = FilebinderS3StorageFactory::fieldArgs($field);
        $cacheKey = (string)json_encode($args);
        if (!isset($this->_s3Storages[$cacheKey])) {
            $this->_s3Storages[$cacheKey] = FilebinderS3StorageFactory::create($args);
        }
        $storage = $this->_s3Storages[$cacheKey];

        if (in_array($s3['acl'], FilebinderS3StorageFactory::PUBLIC_ACLS, true)) {
            return $storage->url($s3['bucket'], $key);
        }

        return $storage->presignedUrl($s3['bucket'], $key, new DateTimeImmutable('@' . $this->_expire()));
    }

    /**
     * Returns the settings of the bound field of the file data, from `$bindFields` of its model.
     *
     * @param array $file File data of a bound field.
     * @return array
     */
    protected function _bindField(array $file)
    {
        $name = $file['model'];
        if (empty($file['field_name']) || !is_string($name)) {
            return [];
        }
        if (!ClassRegistry::isKeySet($name)) {
            // Do not create a model for a name that has no model class
            App::uses($name, 'Model');
            if (!class_exists($name)) {
                return [];
            }
        }
        $model = ClassRegistry::init($name);
        if (!$model instanceof Model) {
            return [];
        }
        foreach ((array)$model->bindFields as $bindField) {
            if (isset($bindField['field']) && $bindField['field'] === $file['field_name']) {
                return (array)$bindField;
            }
        }

        return [];
    }

    /**
     * Returns the expiration time of the URLs: `Filebinder.expire` (a `strtotime()` time), or in one minute.
     *
     * @return int
     */
    protected function _expire()
    {
        return Configure::read('Filebinder.expire') ? (int)strtotime(Configure::read('Filebinder.expire')) : (int)strtotime('+1 minute');
    }
}
