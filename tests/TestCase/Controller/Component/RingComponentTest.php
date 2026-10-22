<?php

App::uses('Controller', 'Controller');
App::uses('ComponentCollection', 'Controller');
App::uses('RingComponent', 'Filebinder.Controller/Component');
App::uses('Folder', 'Utility');
App::uses('FilebinderPost', 'Model');

/**
 * Controller of the tests
 *
 * @property FilebinderPost $FilebinderPost
 * @property RingComponent $Ring
 * @property SessionComponent $Session
 */
class FilebinderPostsTestController extends Controller
{
    public $name = 'FilebinderPostsTest';

    public $uses = ['FilebinderPost'];

    public $components = ['Session', 'Filebinder.Ring'];
}

class RingComponentTest extends CakeTestCase
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

    protected FilebinderPostsTestController $Controller;

    /**
     * Directory of the uploaded files
     */
    protected string $path;

    public function setUp(): void
    {
        parent::setUp();
        $this->path = TMP . 'tests' . DS . 'filebinder' . DS;
        new Folder($this->path, true);
        $this->Controller = new FilebinderPostsTestController(new CakeRequest(null, false), new CakeResponse());
        $this->Controller->constructClasses();
        $this->Controller->FilebinderPost->bindFields = [
            [
                'field' => 'logo',
                'tmpPath' => $this->path,
                'filePath' => $this->path,
            ],
        ];
    }

    public function tearDown(): void
    {
        $this->Controller->Session->delete('Filebinder');
        (new Folder($this->path))->delete();
        unset($this->Controller);
        parent::tearDown();
    }

    /**
     * startup() sets the Filebinder.Label helper.
     */
    public function testStartup(): void
    {
        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->startup($this->Controller);

        $this->assertSame(['sessionKey' => 'Filebinder'], $this->Controller->helpers['Filebinder.Label']);
        $this->assertNotEmpty($this->Controller->Session->read('Filebinder.secret'));
    }

    /**
     * startup() uses Filebinder.secret as the secret.
     */
    public function testStartupSecret(): void
    {
        Configure::write('Filebinder.secret', 'configured secret');
        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->startup($this->Controller);
        Configure::delete('Filebinder.secret');

        $this->assertSame('configured secret', $this->Controller->Session->read('Filebinder.secret'));
    }

    /**
     * bindUp() replaces the uploaded files with their file data.
     */
    public function testBindUp(): void
    {
        $this->_setUploadedFile();

        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp();

        $logo = $this->Controller->request->data['FilebinderPost']['logo'];
        $this->assertSame('FilebinderPost', $logo['model']);
        $this->assertSame('logo', $logo['field_name']);
        $this->assertSame('logo.png', $logo['file_name']);
        $this->assertSame('image/png', $logo['file_content_type']);
        $this->assertSame(filesize($this->_testFile()), $logo['file_size']);
        $this->assertStringStartsWith($this->path . 'ring_', $logo['tmp_bind_path']);
        $this->assertStringEndsWith('logo.png', $logo['tmp_bind_path']);
    }

    /**
     * bindUp() sets null to a field whose upload data has invalid keys.
     */
    public function testBindUpInvalidUploadedFile(): void
    {
        $this->_setUploadedFile([
            'name' => 'logo.png',
            'tmp_name' => $this->path . 'bindup.png',
            'invalid_key' => 'invalid',
            'size' => 100,
            'error' => 0,
        ]);

        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp();

        $expected = ['FilebinderPost' => [
            'title' => 'Title',
            'logo' => null,
        ]];
        $this->assertSame($expected, $this->Controller->request->data);
    }

    /**
     * A failed upload, e.g. a file larger than upload_max_filesize, has no file size, so that bindDown() drops it
     * and Model::save() reports the upload error.
     */
    public function testBindUpUploadError(): void
    {
        $this->_setUploadedFile([
            'name' => 'large.png',
            'type' => '',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_INI_SIZE,
            'size' => 0,
        ]);

        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp();

        $this->assertFalse($this->Controller->request->data['FilebinderPost']['logo']['file_size']);
        $this->Controller->Ring->bindDown();
        $this->assertNull($this->Controller->request->data['FilebinderPost']['logo']);
        $this->assertNull($this->Controller->Session->read('Filebinder.FilebinderPost.logo'));
    }

    /**
     * The file of the tests is not an uploaded file, so move_uploaded_file() does not move it.
     */
    public function testBindUpMoveUploadedFile(): void
    {
        $this->_setUploadedFile();

        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp();

        $this->assertFileDoesNotExist($this->Controller->request->data['FilebinderPost']['logo']['tmp_bind_path']);
    }

    /**
     * bindDown() keeps the file data in the session.
     */
    public function testBindDown(): void
    {
        $this->_setUploadedFile();

        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp();
        $this->Controller->Ring->bindDown();

        $expected = $this->Controller->request->data['FilebinderPost']['logo'];
        $this->assertSame($expected, $this->Controller->Session->read('Filebinder.FilebinderPost.logo'));
    }

    /**
     * bindUp() restores the file data kept in the session when no file is uploaded.
     */
    public function testBindUpFromSession(): void
    {
        $this->_setUploadedFile();
        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp();
        $this->Controller->Ring->bindDown();
        $expected = $this->Controller->request->data['FilebinderPost']['logo'];

        $this->_setUploadedFile([
            'name' => '',
            'type' => '',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
        ]);
        $this->Controller->Ring->bindUp();

        $this->assertSame($expected, $this->Controller->request->data['FilebinderPost']['logo']);
    }

    /**
     * Sets the request data with an uploaded file.
     *
     * @param array|null $logo Upload data. Default: test.png.
     * @return void
     */
    protected function _setUploadedFile(?array $logo = null): void
    {
        $tmpPath = $this->path . 'bindup.png';
        copy($this->_testFile(), $tmpPath);

        $this->Controller->request->data = [
            'FilebinderPost' => [
                'title' => 'Title',
                'logo' => $logo ?? [
                    'name' => 'logo.png',
                    'tmp_name' => $tmpPath,
                    'type' => 'image/png',
                    'size' => 100,
                    'error' => 0,
                ],
            ],
        ];
    }

    /**
     * Returns the path of test.png.
     *
     * @return string
     */
    protected function _testFile(): string
    {
        return CakePlugin::path('Filebinder') . 'tests' . DS . 'File' . DS . 'test.png';
    }
}
