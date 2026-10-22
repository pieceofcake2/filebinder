<?php

App::uses('Folder', 'Utility');
App::uses('FilebinderPost', 'Model');

/**
 * Callbacks of beforeAttach and afterAttach
 */
class BindableBehaviorTestCallback
{
    /**
     * Paths given to the callbacks
     *
     * @var array<string>
     */
    public static array $paths = [];

    /**
     * Records the path and returns true.
     *
     * @param string $path File path.
     * @return bool
     */
    public function attach(string $path): bool
    {
        static::$paths[] = $path;

        return true;
    }

    /**
     * Records the path and returns true.
     *
     * @param string $path File path.
     * @return bool
     */
    public static function staticAttach(string $path): bool
    {
        static::$paths[] = $path;

        return true;
    }
}

class BindableBehaviorTest extends CakeTestCase
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

    protected FilebinderPost $FilebinderPost;

    /**
     * Directory of the saved files
     */
    protected string $filePath;

    /**
     * Uploaded file, as moved by RingComponent::bindUp()
     */
    protected string $tmpPath;

    public function setUp(): void
    {
        parent::setUp();
        $this->filePath = TMP . 'tests' . DS . 'filebinder' . DS;
        $this->tmpPath = $this->filePath . 'bindup.png';
        new Folder($this->filePath, true);
        $this->FilebinderPost = ClassRegistry::init('FilebinderPost');
        $this->FilebinderPost->bindFields = [
            [
                'field' => 'logo',
                'tmpPath' => CACHE,
                'filePath' => $this->filePath,
            ],
        ];
    }

    public function tearDown(): void
    {
        (new Folder($this->filePath))->delete();
        unset($this->FilebinderPost);
        parent::tearDown();
    }

    /**
     * The settings can be changed.
     */
    public function testSetSettings(): void
    {
        $settings = $this->FilebinderPost->getSettings();
        $settings['storage'] = false;
        $before = $settings;
        $this->FilebinderPost->setSettings($settings);
        $after = $this->FilebinderPost->getSettings();
        $this->assertSame($before, $after);
    }

    /**
     * find() adds the file data of the bound fields.
     */
    public function testFind(): void
    {
        $query = [];
        $query['conditions'] = ['FilebinderPost.id' => 1];
        $result = $this->FilebinderPost->find('first', $query);

        $expected = [
            'id' => 1,
            'title' => 'Title',
            'body' => 'Filebinder.Bindable Test',
            'created' => '2011-08-23 17:44:58',
            'modified' => '2011-08-23 12:05:02',
            'logo' => [
                'id' => 100,
                'model' => 'FilebinderPost',
                'model_id' => 1,
                'field_name' => 'logo',
                'file_name' => 'logo.png',
                'file_content_type' => 'image/png',
                'file_size' => '771311',
                'created' => '2011-08-22 19:29:32',
                'modified' => '2011-08-22 19:29:32',
                'file_path' => $this->filePath . 'FilebinderPost/1/logo/logo.png',
                'bindedModel' => 'Attachment',
            ],
        ];

        $this->assertEquals($expected, $result['FilebinderPost']);
    }

    /**
     * find() sets null to the bound fields without an attachment.
     */
    public function testFindNoAttachment(): void
    {
        $query = [];
        $query['conditions'] = ['FilebinderPost.id' => 401];
        $result = $this->FilebinderPost->find('first', $query);

        $expected = [
            'id' => 401,
            'title' => 'No Attachment',
            'body' => 'Filebinder.Bindable Test',
            'created' => '2011-08-23 17:44:58',
            'modified' => '2011-08-23 12:05:02',
            'logo' => null,
        ];

        $this->assertEquals($expected, $result['FilebinderPost']);
    }

    /**
     * The data of RingComponent::bindUp() can be saved and found.
     */
    public function testSave(): void
    {
        $result = $this->_saveLogo();

        $this->assertFileExists($result['FilebinderPost']['logo']['file_path']);
        $this->assertFileDoesNotExist($this->tmpPath);
    }

    /**
     * withObject returns the file contents.
     */
    public function testFindWithObject(): void
    {
        $settings = $this->FilebinderPost->getSettings();
        $settings['withObject'] = true;
        $this->FilebinderPost->setSettings($settings);

        $id = $this->_saveLogo()['FilebinderPost']['id'];
        $this->FilebinderPost->withObject(true);
        $result = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);

        $this->assertSame(base64_encode(file_get_contents($this->_testFile())), $result['FilebinderPost']['logo']['file_object']);
    }

    /**
     * Without the database storage, withObject returns no file contents.
     */
    public function testSaveNoStorage(): void
    {
        $settings = $this->FilebinderPost->getSettings();
        $settings['storage'] = false;
        $this->FilebinderPost->setSettings($settings);

        $id = $this->_saveLogo()['FilebinderPost']['id'];
        $this->FilebinderPost->withObject(true);
        $result = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);

        $this->assertNull($result['FilebinderPost']['logo']['file_object']);
    }

    /**
     * A local file is restored from the database.
     */
    public function testRestore(): void
    {
        $settings = $this->FilebinderPost->getSettings();
        $settings['storage'] = BindableBehavior::STORAGE_DB;
        $this->FilebinderPost->setSettings($settings);

        $result = $this->_saveLogo();
        $filePath = $result['FilebinderPost']['logo']['file_path'];
        unlink($filePath);
        $this->assertFileDoesNotExist($filePath);

        $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $result['FilebinderPost']['id']]]);
        $this->assertFileEquals($this->_testFile(), $filePath);
    }

    /**
     * With restoreFromStorage = false, a local file is not restored.
     */
    public function testRestoreFalse(): void
    {
        $settings = $this->FilebinderPost->getSettings();
        $settings['storage'] = BindableBehavior::STORAGE_DB;
        $settings['restoreFromStorage'] = false;
        $this->FilebinderPost->setSettings($settings);

        $result = $this->_saveLogo();
        $filePath = $result['FilebinderPost']['logo']['file_path'];
        unlink($filePath);

        $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $result['FilebinderPost']['id']]]);
        $this->assertFileDoesNotExist($filePath);
    }

    /**
     * With filePath = false, no local file is saved.
     */
    public function testSaveNoLocal(): void
    {
        $settings = $this->FilebinderPost->getSettings();
        $settings['storage'] = BindableBehavior::STORAGE_DB;
        $settings['filePath'] = false;
        $this->FilebinderPost->setSettings($settings);
        $this->FilebinderPost->bindFields[0]['filePath'] = false;

        $id = $this->_saveLogo()['FilebinderPost']['id'];
        $this->FilebinderPost->withObject(true);
        $result = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);

        $this->assertIsString($result['FilebinderPost']['logo']['file_object']);
        $this->assertFileDoesNotExist($this->tmpPath);
        $this->assertFalse($result['FilebinderPost']['logo']['file_path']);
    }

    /**
     * delete() deletes the attachments and the files.
     */
    public function testDelete(): void
    {
        $result = $this->_saveLogo();
        $id = $result['FilebinderPost']['id'];

        $this->FilebinderPost->delete($id);

        $this->assertFileDoesNotExist($result['FilebinderPost']['logo']['file_path']);
        $this->assertSame(0, ClassRegistry::init('Attachment')->find('count', ['conditions' => ['model_id' => $id]]));
    }

    /**
     * A record without an attachment can be deleted.
     */
    public function testDeleteNoAttachment(): void
    {
        $data = [
            'FilebinderPost' => [
                'title' => 'Title',
                'logo' => null,
            ],
        ];
        $this->FilebinderPost->save($data);
        $id = $this->FilebinderPost->getLastInsertId();

        $result = $this->FilebinderPost->delete($id);

        $this->assertTrue($result);
    }

    /**
     * delete_<field> deletes the file of the field.
     */
    public function testDeleteBindedFileOnly(): void
    {
        $result = $this->_saveLogo();
        $id = $result['FilebinderPost']['id'];

        $data = [
            'FilebinderPost' => [
                'id' => $id,
                'title' => 'Title',
                'logo' => null,
                'delete_logo' => '1',
            ],
        ];
        $this->FilebinderPost->save($data);

        $this->assertFileDoesNotExist($result['FilebinderPost']['logo']['file_path']);
        $result = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);
        $this->assertNull($result['FilebinderPost']['logo']);
    }

    /**
     * delete_<field> = '0' does not delete the file.
     */
    public function testNotDeleteBindedFile(): void
    {
        $result = $this->_saveLogo();
        $id = $result['FilebinderPost']['id'];

        $data = [
            'FilebinderPost' => [
                'id' => $id,
                'title' => 'Title',
                'logo' => null,
                'delete_logo' => '0',
            ],
        ];
        $this->FilebinderPost->save($data);

        $this->assertFileExists($result['FilebinderPost']['logo']['file_path']);
    }

    /**
     * A new file replaces the old one.
     */
    public function testUpdateFile(): void
    {
        $result = $this->_saveLogo();
        $id = $result['FilebinderPost']['id'];

        $tmpPath2 = $this->filePath . 'bindup2.png';
        copy($this->_testFile(), $tmpPath2);
        $data = [
            'FilebinderPost' => [
                'id' => $id,
                'title' => 'Title',
                'logo' => [
                    'model' => 'FilebinderPost',
                    'field_name' => 'logo',
                    'file_name' => 'logo2.png',
                    'file_content_type' => 'image/png',
                    'file_size' => 1395,
                    'tmp_bind_path' => $tmpPath2,
                ],
            ],
        ];
        $this->FilebinderPost->save($data);

        $this->assertFileDoesNotExist($result['FilebinderPost']['logo']['file_path']);
        $result2 = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);
        $this->assertSame('logo2.png', $result2['FilebinderPost']['logo']['file_name']);
        $this->assertFileExists($result2['FilebinderPost']['logo']['file_path']);
    }

    /**
     * beforeAttach and afterAttach call an object method, a static method or a function with the file path.
     */
    public function testAttachCallbacks(): void
    {
        BindableBehaviorTestCallback::$paths = [];
        $this->FilebinderPost->setSettings([
            'beforeAttach' => [new BindableBehaviorTestCallback(), 'attach'],
            'afterAttach' => ['BindableBehaviorTestCallback', 'staticAttach'],
        ]);

        $result = $this->_saveLogo();

        $this->assertSame([$this->tmpPath, $result['FilebinderPost']['logo']['file_path']], BindableBehaviorTestCallback::$paths);
    }

    /**
     * beforeAttach returning false cancels the save.
     */
    public function testBeforeAttachFalse(): void
    {
        $this->FilebinderPost->setSettings(['beforeAttach' => 'is_dir']);
        copy($this->_testFile(), $this->tmpPath);

        $result = $this->FilebinderPost->save(['FilebinderPost' => [
            'title' => 'Title',
            'logo' => [
                'model' => 'FilebinderPost',
                'field_name' => 'logo',
                'file_name' => 'logo.png',
                'file_content_type' => 'image/png',
                'file_size' => 1395,
                'tmp_bind_path' => $this->tmpPath,
            ],
        ]]);

        $this->assertFalse($result);
    }

    /**
     * '1MB' allows up to 1MB.
     */
    public function testCheckMaxFileSize(): void
    {
        $data = [
            'file' => ['file_size' => 1 * 1024 * 1024],
        ];
        $this->assertTrue($this->FilebinderPost->checkMaxFileSize($data, '1MB'));
        $data['file']['file_size']++;
        $this->assertFalse($this->FilebinderPost->checkMaxFileSize($data, '1MB'));
    }

    /**
     * '1MB' allows from 1MB.
     */
    public function testCheckMinFileSize(): void
    {
        $data = [
            'file' => ['file_size' => 1 * 1024 * 1024],
        ];
        $this->assertTrue($this->FilebinderPost->checkMinFileSize($data, '1MB'));
        $data['file']['file_size']--;
        $this->assertFalse($this->FilebinderPost->checkMinFileSize($data, '1MB'));
    }

    /**
     * checkExtension() accepts an extension or a list of extensions.
     */
    public function testCheckExtension(): void
    {
        $data = ['logo' => ['tmp_bind_path' => '/tmp/ring_logo.PNG']];

        $this->assertTrue($this->FilebinderPost->checkExtension($data, ['jpg', 'png']));
        $this->assertTrue($this->FilebinderPost->checkExtension($data, 'png'));
        $this->assertFalse($this->FilebinderPost->checkExtension($data, 'gif'));
        $this->assertFalse($this->FilebinderPost->checkExtension(['logo' => ['tmp_bind_path' => '/tmp/ring_logo.php']], ['allowEmpty' => true, 'rule' => ['checkExtension']]));
    }

    /**
     * checkContentType() accepts a MIME type or a list of MIME types.
     */
    public function testCheckContentType(): void
    {
        $data = ['logo' => ['file_content_type' => 'image/png']];

        $this->assertTrue($this->FilebinderPost->checkContentType($data, ['image/jpeg', 'image/png']));
        $this->assertTrue($this->FilebinderPost->checkContentType($data, 'image/png'));
        $this->assertFalse($this->FilebinderPost->checkContentType($data, 'image/gif'));
    }

    /**
     * The rules with a string parameter in $validate.
     */
    public function testValidateWithStringParameters(): void
    {
        $this->FilebinderPost->validate = [
            'logo' => [
                'extension' => ['rule' => ['checkExtension', 'png']],
                'contentType' => ['rule' => ['checkContentType', 'image/png']],
                'maxFileSize' => ['rule' => ['checkMaxFileSize', '1MB']],
            ],
        ];
        $logo = [
            'model' => 'FilebinderPost',
            'field_name' => 'logo',
            'file_name' => 'logo.png',
            'file_content_type' => 'image/png',
            'file_size' => 1395,
            'tmp_bind_path' => $this->tmpPath,
        ];

        $this->FilebinderPost->set(['FilebinderPost' => ['title' => 'Title', 'logo' => $logo]]);
        $this->assertTrue($this->FilebinderPost->validates());

        $logo['file_name'] = 'logo.gif';
        $logo['file_content_type'] = 'image/gif';
        $logo['tmp_bind_path'] = $this->filePath . 'bindup.gif';
        $this->FilebinderPost->set(['FilebinderPost' => ['title' => 'Title', 'logo' => $logo]]);
        $this->assertFalse($this->FilebinderPost->validates());
        $this->assertSame(['extension'], $this->FilebinderPost->validationErrors['logo']);

        $logo['tmp_bind_path'] = $this->tmpPath;
        $this->FilebinderPost->set(['FilebinderPost' => ['title' => 'Title', 'logo' => $logo]]);
        $this->assertFalse($this->FilebinderPost->validates());
        $this->assertSame(['contentType'], $this->FilebinderPost->validationErrors['logo']);
    }

    /**
     * calcFileSizeUnit() converts the units K, M, G and T, in upper or lower case, with or without B.
     */
    public function testCalcFileSizeUnit(): void
    {
        $Bindable = $this->FilebinderPost->Behaviors->Bindable;
        $this->assertSame(100, $Bindable->calcFileSizeUnit(100));
        $this->assertSame('100', $Bindable->calcFileSizeUnit('100'));
        $this->assertSame(102400, $Bindable->calcFileSizeUnit('100KB'));
        $this->assertSame(102400, $Bindable->calcFileSizeUnit('100K'));
        $this->assertSame(1048576, $Bindable->calcFileSizeUnit('1MB'));
        $this->assertSame(1048576, $Bindable->calcFileSizeUnit('1mb'));
        $this->assertSame(1572864.0, $Bindable->calcFileSizeUnit('1.5M'));
        $this->assertSame(2147483648, $Bindable->calcFileSizeUnit('2gb'));
        $this->assertSame(1099511627776, $Bindable->calcFileSizeUnit('1T'));
        $this->assertFalse($Bindable->calcFileSizeUnit('1PB'));
        $this->assertFalse($Bindable->calcFileSizeUnit('large'));
    }

    /**
     * Saves test.png to the logo field of a new record and returns the record.
     *
     * @return array
     */
    protected function _saveLogo(): array
    {
        copy($this->_testFile(), $this->tmpPath);
        $data = [
            'FilebinderPost' => [
                'title' => 'Title',
                'logo' => [
                    'model' => 'FilebinderPost',
                    'field_name' => 'logo',
                    'file_name' => 'logo.png',
                    'file_content_type' => 'image/png',
                    'file_size' => 1395,
                    'tmp_bind_path' => $this->tmpPath,
                ],
            ],
        ];
        $this->FilebinderPost->create();
        $this->assertNotFalse($this->FilebinderPost->save($data));
        $id = $this->FilebinderPost->getLastInsertId();

        return $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);
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
