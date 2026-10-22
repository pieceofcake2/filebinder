<?php

App::uses('Controller', 'Controller');
App::uses('AppController', 'Controller');
App::uses('FilebinderAppController', 'Filebinder.Controller');
App::uses('FilebinderController', 'Filebinder.Controller');
App::uses('Folder', 'Utility');
App::uses('FilebinderPost', 'Model');
App::uses('View', 'View');
App::uses('LabelHelper', 'Filebinder.View/Helper');

/**
 * Controller that exposes the Content-Disposition header
 */
class FilebinderTestController extends FilebinderController
{
    /**
     * @inheritDoc
     */
    public static function contentDisposition($fileName, $userAgent): string
    {
        return parent::contentDisposition($fileName, $userAgent);
    }
}

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
        new Folder($this->filePath . 'tmp', true);
        copy(CakePlugin::path('Filebinder') . 'tests' . DS . 'File' . DS . 'test.png', $this->filePath . 'tmp' . DS . 'ring_20261022_abc_logo.png');
        ClassRegistry::init('FilebinderPost')->bindFields = [
            [
                'field' => 'logo',
                'tmpPath' => $this->filePath . 'tmp' . DS,
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
        $this->_sign('FilebinderPost', '1', 'logo', null, 'logo.png');

        $output = $this->_load('FilebinderPost', '1', 'logo', 'logo.png');

        $this->assertSame(file_get_contents($this->filePath . 'FilebinderPost/1/logo/logo.png'), $output);
    }

    /**
     * loader() accepts the URLs of LabelHelper, for an uploaded file and for a saved record.
     */
    public function testLoaderUrlOfLabelHelper(): void
    {
        $Label = new LabelHelper(new View());
        $files = [
            ['model' => 'FilebinderPost', 'field_name' => 'logo', 'file_name' => 'logo.png', 'tmp_bind_path' => $this->filePath . 'tmp/ring_20261022_abc_logo.png'],
            ['model' => 'FilebinderPost', 'model_id' => '1', 'field_name' => 'logo', 'file_name' => 'logo.png', 'file_path' => $this->filePath . 'FilebinderPost/1/logo/logo.png'],
        ];
        foreach ($files as $file) {
            $url = $Label->_makeSrc($file);
            $this->Controller->request->query = $url['?'];

            $output = $this->_load($url[0], (string)$url[1], $url[2], $url[3]);

            $this->assertSame(file_get_contents(CakePlugin::path('Filebinder') . 'tests/File/test.png'), $output);
        }
    }

    /**
     * The URL of a saved record without a file name keeps the key of fusic/filebinder.
     */
    public function testLoaderWithoutFileName(): void
    {
        $expire = strtotime('+1 minute');
        $this->Controller->request->query = [
            'key' => Security::hash('FilebinderPost1logosecret' . $expire),
            'expire' => (string)$expire,
        ];

        $output = $this->_load('FilebinderPost', '1', 'logo', null);

        $this->assertSame(file_get_contents($this->filePath . 'FilebinderPost/1/logo/logo.png'), $output);
    }

    /**
     * loader() rejects a signed URL whose file name is changed.
     */
    public function testLoaderChangedFileName(): void
    {
        copy($this->filePath . 'tmp/ring_20261022_abc_logo.png', $this->filePath . 'tmp/other.png');
        $this->_sign('FilebinderPost', '0', 'logo', null, 'ring_20261022_abc_logo.png');

        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '0', 'logo', 'other.png');
    }

    /**
     * loader() rejects a signed URL of a saved record whose file name is changed.
     */
    public function testLoaderChangedFileNameOfRecord(): void
    {
        $this->_sign('FilebinderPost', '1', 'logo', null, 'logo.png');

        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '1', 'logo', 'other.html');
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
     * loader() rejects a URL signed without a secret, when the session has none.
     */
    public function testLoaderWithoutSecret(): void
    {
        CakeSession::delete('Filebinder');
        $expire = strtotime('+1 minute');
        $this->Controller->request->query = [
            'key' => Security::hash('FilebinderPost1logologo.png' . $expire),
            'expire' => (string)$expire,
        ];

        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '1', 'logo', 'logo.png');
    }

    /**
     * loader() rejects an expired URL.
     */
    public function testLoaderExpired(): void
    {
        $this->_sign('FilebinderPost', '1', 'logo', time() - 1, 'logo.png');

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
        $this->_sign('FilebinderPost', '999', 'logo', null, 'logo.png');

        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '999', 'logo', 'logo.png');
    }

    /**
     * loader() throws NotFoundException for a record without a file.
     */
    public function testLoaderRecordWithoutFile(): void
    {
        $this->_sign('FilebinderPost', '401', 'logo', null, 'logo.png');

        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '401', 'logo', 'logo.png');
    }

    /**
     * loader() sends an uploaded file of the tmp directory (model ID 0).
     */
    public function testLoaderTmpFile(): void
    {
        $this->_sign('FilebinderPost', '0', 'logo', null, 'ring_20261022_abc_logo.png');

        $output = $this->_load('FilebinderPost', '0', 'logo', 'ring_20261022_abc_logo.png');

        $this->assertSame(file_get_contents($this->filePath . 'tmp/ring_20261022_abc_logo.png'), $output);
    }

    /**
     * File names that are not a base name, for a file outside the tmp directory.
     *
     * @return array<array{string}>
     */
    public static function traversalFileNames(): array
    {
        return [
            ['../FilebinderPost/1/logo/logo.png'],
            ['..%2FFilebinderPost%2F1%2Flogo%2Flogo.png'],
            ['..\\FilebinderPost\\1\\logo\\logo.png'],
            ['..'],
            ['.'],
            [''],
            ["ring_20261022_abc_logo.png\0.txt"],
        ];
    }

    /**
     * loader() rejects a tmp file name that is not a base name.
     *
     * @dataProvider traversalFileNames
     */
    public function testLoaderTmpFileTraversal(string $fileName): void
    {
        $this->_sign('FilebinderPost', '0', 'logo', null, $fileName);

        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '0', 'logo', $fileName);
    }

    /**
     * loader() rejects a tmp file that is a symbolic link to a file outside the tmp directory.
     */
    public function testLoaderTmpFileSymlink(): void
    {
        symlink($this->filePath . 'FilebinderPost/1/logo/logo.png', $this->filePath . 'tmp/link.png');
        $this->_sign('FilebinderPost', '0', 'logo', null, 'link.png');

        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '0', 'logo', 'link.png');
    }

    /**
     * The Content-Disposition header has the file name without the characters that would end it.
     */
    public function testContentDisposition(): void
    {
        $this->assertSame(
            'attachment; filename="logo.png"; filename*=UTF-8\'\'logo.png',
            FilebinderTestController::contentDisposition('logo.png', 'Mozilla/5.0'),
        );
        $this->assertSame(
            'attachment; filename="a.htmlX-Injected: 1.png"; filename*=UTF-8\'\'a%22.html%0D%0AX-Injected%3A%201%5C.png',
            FilebinderTestController::contentDisposition("a\".html\r\nX-Injected: 1\\.png", 'Mozilla/5.0'),
        );
        $this->assertSame(
            'attachment; filename="ロゴ.png"; filename*=UTF-8\'\'%E3%83%AD%E3%82%B4.png',
            FilebinderTestController::contentDisposition('ロゴ.png', 'Mozilla/5.0'),
        );
        $this->assertSame(
            'inline; filename="' . mb_convert_encoding('ロゴ.png', 'SJIS', 'UTF-8') . '"',
            FilebinderTestController::contentDisposition('ロゴ".png', 'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1)'),
        );
    }

    /**
     * Signs the URL of a file as LabelHelper does.
     *
     * @param string $model Model name.
     * @param string $modelId Model ID.
     * @param string $fieldName Field name.
     * @param int|null $expire Expiration time. Default: in a minute.
     * @param string $fileName File name of the URL.
     * @return void
     */
    protected function _sign(string $model, string $modelId, string $fieldName, ?int $expire = null, string $fileName = ''): void
    {
        $expire = $expire ?? strtotime('+1 minute');
        $this->Controller->request->query = [
            'key' => Security::hash($model . $modelId . $fieldName . $fileName . 'secret' . $expire),
            'expire' => (string)$expire,
        ];
    }

    /**
     * Calls loader() and returns its output.
     *
     * @param string $model Model name.
     * @param string $modelId Model ID.
     * @param string $fieldName Field name.
     * @param string|null $fileName File name.
     * @return string
     */
    protected function _load(string $model, string $modelId, string $fieldName, ?string $fileName): string
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
