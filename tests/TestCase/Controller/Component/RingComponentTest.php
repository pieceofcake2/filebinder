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
        'plugin.filebinder.filebinder_comment',
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

        $this->assertSame([], $this->Controller->helpers['Filebinder.Label']);
    }

    /**
     * startup() keeps no secret in the session; Filebinder.secret is no longer used.
     */
    public function testStartupNoSecret(): void
    {
        Configure::write('Filebinder.secret', 'configured secret');
        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->startup($this->Controller);
        Configure::delete('Filebinder.secret');

        $this->assertNull($this->Controller->Session->read('Filebinder.secret'));
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
     * bindUp() detects the content type from the contents of the file, not from the type sent by the browser.
     */
    public function testBindUpContentType(): void
    {
        $this->_setUploadedFile([
            'name' => 'logo.html',
            'tmp_name' => $this->path . 'bindup.png',
            'type' => 'text/html',
            'size' => 100,
            'error' => 0,
        ]);

        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp();

        $this->assertSame('image/png', $this->Controller->request->data['FilebinderPost']['logo']['file_content_type']);
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
     * Without a tmpPath, bindUp() moves the uploaded files to CACHE.
     */
    public function testBindUpDefaultTmpPath(): void
    {
        unset($this->Controller->FilebinderPost->bindFields[0]['tmpPath']);
        $this->_setUploadedFile();

        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp();

        $this->assertStringStartsWith(CACHE . 'ring_', $this->Controller->request->data['FilebinderPost']['logo']['tmp_bind_path']);
    }

    /**
     * bindUp() of a request without data of the model forgets the files kept in the session.
     */
    public function testBindUpWithoutData(): void
    {
        $this->_setUploadedFile();
        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp();
        $this->Controller->Ring->bindDown();
        $this->assertNotNull($this->Controller->Session->read('Filebinder.FilebinderPost.logo'));

        $this->Controller->request->data = [];
        $this->assertFalse($this->Controller->Ring->bindUp());

        $this->assertNull($this->Controller->Session->read('Filebinder.FilebinderPost'));
    }

    /**
     * bindDown() of a request without data of the model forgets the files kept in the session.
     */
    public function testBindDownWithoutData(): void
    {
        $this->_setUploadedFile();
        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp();
        $this->Controller->Ring->bindDown();

        $this->Controller->request->data = ['Other' => ['title' => 'Title']];
        $this->assertFalse($this->Controller->Ring->bindDown('FilebinderPost'));

        $this->assertNull($this->Controller->Session->read('Filebinder.FilebinderPost'));
    }

    /**
     * delete_<field> drops the uploaded file and the file kept in the session.
     */
    public function testBindUpDelete(): void
    {
        $this->_setUploadedFile();
        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp();
        $this->Controller->Ring->bindDown();

        $this->_setUploadedFile();
        $this->Controller->request->data['FilebinderPost']['delete_logo'] = '1';
        $this->Controller->Ring->bindUp();
        $this->assertNull($this->Controller->request->data['FilebinderPost']['logo']);

        $this->Controller->request->data['FilebinderPost']['logo'] = [
            'name' => '',
            'type' => '',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
        ];
        $this->Controller->Ring->bindUp();
        $this->assertNull($this->Controller->request->data['FilebinderPost']['logo']);
    }

    /**
     * A field that is not upload data, e.g. a string, gets null, and the other fields are kept.
     */
    public function testBindUpNotUploadData(): void
    {
        $this->Controller->request->data = ['FilebinderPost' => ['title' => 'Title', 'logo' => 'logo.png']];

        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp();
        $this->Controller->Ring->bindDown();

        $this->assertSame(['FilebinderPost' => ['title' => 'Title', 'logo' => null]], $this->Controller->request->data);
        $this->assertNull($this->Controller->Session->read('Filebinder.FilebinderPost'));
    }

    /**
     * bindDown() does not keep a file with a validation error.
     */
    public function testBindDownValidationError(): void
    {
        $this->_setUploadedFile();
        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp();
        $this->Controller->FilebinderPost->validationErrors = ['logo' => ['extension']];

        $this->Controller->Ring->bindDown();

        $this->assertNull($this->Controller->Session->read('Filebinder.FilebinderPost.logo'));
    }

    /**
     * With $autoBindDown, beforeRender() keeps the file data in the session, once.
     */
    public function testAutoBindDown(): void
    {
        $this->_setUploadedFile();
        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp('FilebinderPost', true);
        $this->Controller->Ring->bindUp('FilebinderPost', true);
        $this->assertNull($this->Controller->Session->read('Filebinder.FilebinderPost.logo'));

        $this->Controller->Ring->beforeRender($this->Controller);

        $this->assertSame($this->Controller->request->data['FilebinderPost']['logo'], $this->Controller->Session->read('Filebinder.FilebinderPost.logo'));
        $this->assertSame([], $this->Controller->Ring->_autoBindDown);
    }

    /**
     * Without $autoBindDown, beforeRender() keeps nothing in the session.
     */
    public function testNoAutoBindDown(): void
    {
        $this->_setUploadedFile();
        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp();

        $this->Controller->Ring->beforeRender($this->Controller);

        $this->assertNull($this->Controller->Session->read('Filebinder.FilebinderPost'));
    }

    /**
     * The records of hasMany data (with numeric keys) are bound up and down one by one.
     */
    public function testHasManyData(): void
    {
        $tmpPath = $this->path . 'bindup.png';
        copy($this->_testFile(), $tmpPath);
        $this->Controller->request->data = ['FilebinderPost' => [
            ['title' => 'First', 'logo' => ['name' => 'first.png', 'tmp_name' => $tmpPath, 'type' => 'image/png', 'size' => 100, 'error' => 0]],
            ['title' => 'Second', 'logo' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]],
        ]];

        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp();
        $this->Controller->Ring->bindDown();

        $first = $this->Controller->request->data['FilebinderPost'][0]['logo'];
        $this->assertSame('first.png', $first['file_name']);
        $this->assertNull($this->Controller->request->data['FilebinderPost'][1]['logo']);
        $this->assertSame($first, $this->Controller->Session->read('Filebinder.FilebinderPost.0.logo'));
        $this->assertNull($this->Controller->Session->read('Filebinder.FilebinderPost.1.logo'));

        // The next request without files gets the file of the first record back
        $this->Controller->request->data = ['FilebinderPost' => [
            ['title' => 'First', 'logo' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]],
            ['title' => 'Second', 'logo' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]],
        ]];
        $this->Controller->Ring->bindUp();
        $this->assertSame($first, $this->Controller->request->data['FilebinderPost'][0]['logo']);
        $this->assertNull($this->Controller->request->data['FilebinderPost'][1]['logo']);
    }

    /**
     * bindUp() and bindDown() of another model than the model of the controller.
     */
    public function testOtherModel(): void
    {
        $Comment = ClassRegistry::init('FilebinderComment');
        $Comment->bindFields = [['field' => 'image', 'tmpPath' => $this->path]];
        $tmpPath = $this->path . 'bindup.png';
        copy($this->_testFile(), $tmpPath);
        $this->_setUploadedFile();
        $this->Controller->request->data['FilebinderComment'] = [
            'body' => 'Comment',
            'image' => ['name' => 'image.png', 'tmp_name' => $tmpPath, 'type' => 'image/png', 'size' => 100, 'error' => 0],
        ];

        $this->Controller->Components->init($this->Controller);
        $this->Controller->Ring->bindUp('FilebinderComment');
        $this->Controller->Ring->bindDown('FilebinderComment');

        $image = $this->Controller->request->data['FilebinderComment']['image'];
        $this->assertSame('FilebinderComment', $image['model']);
        $this->assertSame('image', $image['field_name']);
        $this->assertSame($image, $this->Controller->Session->read('Filebinder.FilebinderComment.image'));
        // The data of the model of the controller are not changed
        $this->assertSame('logo.png', $this->Controller->request->data['FilebinderPost']['logo']['name']);
        $this->assertNull($this->Controller->Session->read('Filebinder.FilebinderPost'));
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
