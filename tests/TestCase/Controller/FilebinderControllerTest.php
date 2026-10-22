<?php

App::uses('Controller', 'Controller');
App::uses('AppController', 'Controller');
App::uses('FilebinderAppController', 'Filebinder.Controller');
App::uses('FilebinderController', 'Filebinder.Controller');
App::uses('FilebinderSignature', 'Filebinder.Lib');
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
     * Sent headers
     *
     * @var array<string>
     */
    public array $sentHeaders = [];

    /**
     * @inheritDoc
     */
    protected function _sendHeader($header): void
    {
        $this->sentHeaders[] = $header;
    }

    /**
     * @inheritDoc
     */
    public static function contentDisposition($fileName): string
    {
        return parent::contentDisposition($fileName);
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

    protected FilebinderTestController $Controller;

    /**
     * Directory of the saved files
     */
    protected string $filePath;

    /**
     * Debug level before the test, which loader() changes
     */
    protected int $debug;

    /**
     * Security.salt before the test
     *
     * @var mixed
     */
    protected $salt;

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
        $this->salt = Configure::read('Security.salt');
        CakeSession::start();
        $this->Controller = new FilebinderTestController(new CakeRequest(null, false), new CakeResponse());
        $this->Controller->constructClasses();
    }

    public function tearDown(): void
    {
        Configure::write('debug', $this->debug);
        Configure::write('Security.salt', $this->salt);
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
        $this->assertContains('Content-Type: image/png', $this->Controller->sentHeaders);
        $this->assertContains('X-Content-Type-Options: nosniff', $this->Controller->sentHeaders);
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
     * The URL of a saved record without a file name has the key of an empty file name.
     */
    public function testLoaderWithoutFileName(): void
    {
        $this->_sign('FilebinderPost', '1', 'logo');

        $output = $this->_load('FilebinderPost', '1', 'logo', null);

        $this->assertSame(file_get_contents($this->filePath . 'FilebinderPost/1/logo/logo.png'), $output);
    }

    /**
     * loader() rejects the keys of fusic/filebinder, made with Security::hash() and a session secret.
     */
    public function testLoaderOldKey(): void
    {
        $expire = strtotime('+1 minute');
        foreach (['FilebinderPost1logosecret' . $expire, 'FilebinderPost1logologo.pngsecret' . $expire] as $string) {
            $this->Controller->request->query = ['key' => Security::hash($string), 'expire' => (string)$expire];
            try {
                $this->_load('FilebinderPost', '1', 'logo', str_contains($string, 'logo.png') ? 'logo.png' : null);
                $this->fail('The old key was accepted.');
            } catch (NotFoundException $e) {
                $this->assertSame('Invalid access', $e->getMessage());
            }
        }
    }

    /**
     * Moving characters from one part of the URL to another does not keep the signature.
     */
    public function testLoaderMovedParts(): void
    {
        $expire = (string)strtotime('+1 minute');
        $this->assertNotSame(
            FilebinderSignature::sign('FilebinderPost', '1', 'logo', 'logo.png', $expire),
            FilebinderSignature::sign('FilebinderPost1', '', 'logo', 'logo.png', $expire),
        );
        $this->assertNotSame(
            FilebinderSignature::sign('a', "b\0c", 'd', '', $expire),
            FilebinderSignature::sign("a\0b", 'c', 'd', '', $expire),
        );

        // ID "1" and field "logo" moved to ID "1l" and field "ogo", which concatenate to the same string
        $this->_sign('FilebinderPost', '1', 'logo', null, 'logo.png');
        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '1l', 'ogo', 'logo.png');
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
     * loader() rejects a URL that was signed for another session.
     */
    public function testLoaderOtherSession(): void
    {
        $expire = (string)strtotime('+1 minute');
        $parts = ['FilebinderPost', '1', 'logo', 'logo.png', $expire, 'other-session-id'];
        $this->Controller->request->query = [
            'key' => hash_hmac('sha256', implode("\0", array_map('rawurlencode', $parts)), (string)Configure::read('Security.salt')),
            'expire' => $expire,
        ];

        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '1', 'logo', 'logo.png');
    }

    /**
     * loader() accepts no key when Security.salt is empty.
     */
    public function testLoaderEmptySalt(): void
    {
        $expire = (string)strtotime('+1 minute');
        $parts = ['FilebinderPost', '1', 'logo', 'logo.png', $expire, (string)CakeSession::id()];
        Configure::write('Security.salt', '');
        $this->Controller->request->query = [
            'key' => hash_hmac('sha256', implode("\0", array_map('rawurlencode', $parts)), ''),
            'expire' => $expire,
        ];

        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '1', 'logo', 'logo.png');
    }

    /**
     * The key is the HMAC of the parts and the session ID with Security.salt.
     */
    public function testSignature(): void
    {
        $this->assertNotSame('', (string)CakeSession::id());
        $parts = ['FilebinderPost', '1', 'logo', 'logo.png', '1800000000', (string)CakeSession::id()];

        $this->assertSame(
            hash_hmac('sha256', implode("\0", array_map('rawurlencode', $parts)), (string)Configure::read('Security.salt')),
            FilebinderSignature::sign('FilebinderPost', '1', 'logo', 'logo.png', '1800000000'),
        );
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
            FilebinderTestController::contentDisposition('logo.png'),
        );
        $this->assertSame(
            'attachment; filename="a.htmlX-Injected: 1.png"; filename*=UTF-8\'\'a%22.html%0D%0AX-Injected%3A%201%5C.png',
            FilebinderTestController::contentDisposition("a\".html\r\nX-Injected: 1\\.png"),
        );
        $this->assertSame(
            'attachment; filename="ロゴ.png"; filename*=UTF-8\'\'%E3%83%AD%E3%82%B4.png',
            FilebinderTestController::contentDisposition('ロゴ.png'),
        );
    }

    /**
     * The loader sends the file as an attachment to every browser, also to MSIE.
     */
    public function testLoaderContentDispositionMsie(): void
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1)';
        try {
            $this->_sign('FilebinderPost', '1', 'logo', null, 'ロゴ.png');
            $this->_load('FilebinderPost', '1', 'logo', 'ロゴ.png');
        } finally {
            if ($userAgent === null) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $userAgent;
            }
        }

        $this->assertContains("Content-Disposition: attachment; filename=\"ロゴ.png\"; filename*=UTF-8''%E3%83%AD%E3%82%B4.png", $this->Controller->sentHeaders);
    }

    /**
     * loader() sends the headers of the file of a record: the file name of the record when the URL has none, its
     * size and its content type.
     */
    public function testLoaderHeaders(): void
    {
        $this->_sign('FilebinderPost', '1', 'logo');

        $this->_load('FilebinderPost', '1', 'logo', null);

        $this->assertSame([
            'Content-Disposition: attachment; filename="logo.png"; filename*=UTF-8\'\'logo.png',
            'Content-Length: ' . filesize($this->filePath . 'FilebinderPost/1/logo/logo.png'),
            'Content-Type: image/png',
            'X-Content-Type-Options: nosniff',
        ], $this->Controller->sentHeaders);
    }

    /**
     * loader() sends an uploaded file with the content type detected from its contents.
     */
    public function testLoaderTmpFileHeaders(): void
    {
        file_put_contents($this->filePath . 'tmp/ring_20261022_abc_page.png', '<html><body>page</body></html>');
        $this->_sign('FilebinderPost', '0', 'logo', null, 'ring_20261022_abc_page.png');

        $output = $this->_load('FilebinderPost', '0', 'logo', 'ring_20261022_abc_page.png');

        $this->assertSame('<html><body>page</body></html>', $output);
        $this->assertSame([
            'Content-Disposition: attachment; filename="ring_20261022_abc_page.png"; filename*=UTF-8\'\'ring_20261022_abc_page.png',
            'Content-Length: 30',
            'Content-Type: text/html',
            'X-Content-Type-Options: nosniff',
        ], $this->Controller->sentHeaders);
    }

    /**
     * Without a tmpPath, the uploaded files are sent from CACHE.
     */
    public function testLoaderTmpFileDefaultTmpPath(): void
    {
        ClassRegistry::init('FilebinderPost')->bindFields = [['field' => 'logo', 'filePath' => $this->filePath]];
        $fileName = 'ring_' . uniqid() . '_logo.png';
        copy(CakePlugin::path('Filebinder') . 'tests' . DS . 'File' . DS . 'test.png', CACHE . $fileName);
        try {
            $this->_sign('FilebinderPost', '0', 'logo', null, $fileName);

            $output = $this->_load('FilebinderPost', '0', 'logo', $fileName);
        } finally {
            unlink(CACHE . $fileName);
        }

        $this->assertStringEqualsFile(CakePlugin::path('Filebinder') . 'tests' . DS . 'File' . DS . 'test.png', $output);
    }

    /**
     * loader() rejects an uploaded file that does not exist.
     */
    public function testLoaderTmpFileNotFound(): void
    {
        $this->_sign('FilebinderPost', '0', 'logo', null, 'ring_missing.png');

        $this->expectException(NotFoundException::class);
        $this->_load('FilebinderPost', '0', 'logo', 'ring_missing.png');
    }

    /**
     * loader() throws NotFoundException for a record whose file can be neither found nor restored.
     */
    public function testLoaderFileNotFound(): void
    {
        unlink($this->filePath . 'FilebinderPost/1/logo/logo.png');
        $this->_sign('FilebinderPost', '1', 'logo', null, 'logo.png');

        try {
            $this->_load('FilebinderPost', '1', 'logo', 'logo.png');
            $this->fail('No exception was thrown.');
        } catch (NotFoundException $e) {
            $this->assertSame('Invalid access', $e->getMessage());
        }
        $this->assertSame([], $this->Controller->sentHeaders);
    }

    /**
     * loader() rejects a URL without a model, a model ID or a field.
     */
    public function testLoaderMissingParts(): void
    {
        foreach ([['', '1', 'logo'], ['FilebinderPost', null, 'logo'], ['FilebinderPost', '1', '']] as [$model, $modelId, $fieldName]) {
            $this->_sign((string)$model, (string)$modelId, (string)$fieldName, null, 'logo.png');
            try {
                $this->Controller->loader($model, $modelId, $fieldName, 'logo.png');
                $this->fail('No exception was thrown.');
            } catch (NotFoundException $e) {
                $this->assertSame('Invalid access', $e->getMessage());
            }
        }
    }

    /**
     * loader() sets the debug level to 0, so that no debug output is added to the file.
     */
    public function testLoaderDebug(): void
    {
        Configure::write('debug', 2);
        $this->_sign('FilebinderPost', '1', 'logo', null, 'logo.png');

        $this->_load('FilebinderPost', '1', 'logo', 'logo.png');

        $this->assertSame(0, Configure::read('debug'));
        $this->assertFalse($this->Controller->autoRender);
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
            'key' => FilebinderSignature::sign($model, $modelId, $fieldName, $fileName, (string)$expire),
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
