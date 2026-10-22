<?php
App::uses('AppHelper', 'View/Helper');

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
     * - `sessionKey`: Session key of the secret of the loader URLs.
     *
     * @var array
     */
    public $settings = [
        'sessionKey' => 'Filebinder',
    ];

    /**
     * Returns an image tag of the file, or `$options['noFile']` when there is no file.
     *
     * @param array|null $file File data of a bound field.
     * @param array $options Options of `HtmlHelper::image()`, and `noFile`, `prefix`, `S3`, `ssl` and `url_prefix`.
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
     * @param array $options Options of `HtmlHelper::link()`, and `title`, `noFile`, `prefix`, `S3`, `ssl` and `url_prefix`.
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
     * @param array|bool $options Options of `HtmlHelper::url()`, and `noFile`, `prefix`, `S3`, `ssl` and `url_prefix`.
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
     * @param array $options Options: `prefix`, `S3`, `ssl` and `url_prefix`.
     * @return array|string|false|null
     */
    public function _makeSrc($file = null, $options = []) // phpcs:ignore CakePHP.NamingConventions.ValidFunctionName.PublicWithUnderscore
    {
        $secret = $this->Session->read($this->settings['sessionKey'] . '.secret');
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

        $expire = Configure::read('Filebinder.expire') ? strtotime(Configure::read('Filebinder.expire')) : strtotime('+1 minute');

        $key = Security::hash($file['model'] . $file['model_id'] . $file['field_name'] . $prefix . $file['file_name'] . $secret . $expire);

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
     * Returns the URL of the file on Amazon S3.
     *
     * @param array|null $file File data of a bound field.
     * @param array $options Options: `prefix`, `ssl` and `url_prefix`.
     * @return string|null
     */
    public function _makeS3Url($file, $options) // phpcs:ignore CakePHP.NamingConventions.ValidFunctionName.PublicWithUnderscore
    {
        if (empty($file['model'])) {
            return null;
        }
        $prefix = empty($options['prefix']) ? '' : $options['prefix'];
        $urlPrefix = !empty($options['url_prefix']) ? $options['url_prefix'] : Configure::read('Filebinder.S3.urlPrefix');
        $http = empty($options['ssl']) ? 'http' : 'https';

        return $http . '://' . Configure::read('Filebinder.S3.bucket') . '.s3.amazonaws.com/' . $urlPrefix . $file['model'] . '/' . $file['model_id'] . '/' . $file['field_name'] . '/' . $prefix . $file['file_name'];
    }
}
