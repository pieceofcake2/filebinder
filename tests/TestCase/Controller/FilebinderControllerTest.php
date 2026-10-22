<?php

App::uses('Controller', 'Controller');
App::uses('AppController', 'Controller');
App::uses('FilebinderAppController', 'Filebinder.Controller');
App::uses('FilebinderController', 'Filebinder.Controller');
App::uses('Folder', 'Utility');
App::uses('FilebinderPost', 'Model');

class FilebinderControllerTest extends CakeTestCase
{
    /**
     * Fixtures
     *
     * @var array
     */
    public $fixtures = [
        'plugin.filebinder.attachment',
        'plugin.filebinder.filebinder_post',
    ];

    protected FilebinderController $Controller;

    /**
     * Directory of the saved files
     */
    protected string $filePath;

    /**
     * Debug level before the test, which loader() changes
     */
    protected int $debug;

    public function setUp(): void
    {
        parent::setUp();
        $this->debug = (int)Configure::read('debug');
        $this->filePath = TMP . 'tests' . DS . 'filebinder' . DS;
        new Folder($this->filePath . 'FilebinderPost' . DS . '1' . DS . 'logo', true);
        copy(CakePlugin::path('Filebinder') . 'tests' . DS . 'File' . DS . 'test.png', $this->filePath . 'FilebinderPost' . DS . '1' . DS . 'logo' . DS . 'logo.png');
        ClassRegistry::init('FilebinderPost')->bindFields = [
            [
                'field' => 'logo',
                'filePath' => $this->filePath,
            ],
        ];
        CakeSession::write('Filebinder.secret', 'secret');
        $this->Controller = new FilebinderController(new CakeRequest(null, false), new CakeResponse());
        $this->Controller->constructClasses();
    }

    public function tearDown(): void
    {
        Configure::write('debug', $this->debug);
        CakeSession::delete('Filebinder');
        (new Folder($this->filePath))->delete();
        unset($this->Controller);
        parent::tearDown();
    }

    /**
     * loader() sends the file of a signed URL.
     */
    public function testLoader(): void
    {
        $this->_sign('FilebinderPost', '1', 'logo');

        $output = $this->_load('FilebinderPost', '1', 'logo', 'logo.png');

        $this->assertSame(file_get_contents($this->filePath . 'FilebinderPost/1/logo/logo.png'), $output);
    }

    /**
     * loader() rejects a URL with a wrong key.
     */
    public function testLoaderWrongKey(): void
    {
        $this->_sign('FilebinderPost', '1', 'logo');
        $this->Controller->request->query['key'] = 'wrong';

        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '1', 'logo', 'logo.png');
    }

    /**
     * loader() rejects an expired URL.
     */
    public function testLoaderExpired(): void
    {
        $this->_sign('FilebinderPost', '1', 'logo', time() - 1);

        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '1', 'logo', 'logo.png');
    }

    /**
     * loader() rejects a URL without a key.
     */
    public function testLoaderWithoutKey(): void
    {
        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '1', 'logo', 'logo.png');
    }

    /**
     * loader() throws NotFoundException for a record that does not exist.
     */
    public function testLoaderRecordNotFound(): void
    {
        $this->_sign('FilebinderPost', '999', 'logo');

        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '999', 'logo', 'logo.png');
    }

    /**
     * loader() throws NotFoundException for a record without a file.
     */
    public function testLoaderRecordWithoutFile(): void
    {
        $this->_sign('FilebinderPost', '401', 'logo');

        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '401', 'logo', 'logo.png');
    }

    /**
     * Signs the URL of a file as LabelHelper does.
     *
     * @param string $model Model name.
     * @param string $modelId Model ID.
     * @param string $fieldName Field name.
     * @param int|null $expire Expiration time. Default: in a minute.
     * @return void
     */
    protected function _sign(string $model, string $modelId, string $fieldName, ?int $expire = null): void
    {
        $expire = $expire ?? strtotime('+1 minute');
        $this->Controller->request->query = [
            'key' => Security::hash($model . $modelId . $fieldName . 'secret' . $expire),
            'expire' => (string)$expire,
        ];
    }

    /**
     * Calls loader() and returns its output.
     *
     * @param string $model Model name.
     * @param string $modelId Model ID.
     * @param string $fieldName Field name.
     * @param string $fileName File name.
     * @return string
     */
    protected function _load(string $model, string $modelId, string $fieldName, string $fileName): string
    {
        $level = ob_get_level();
        ob_start();
        // loader() removes the output buffer of the page
        ob_start();
        try {
            $this->Controller->loader($model, $modelId, $fieldName, $fileName);

            return (string)ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}
