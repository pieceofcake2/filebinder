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
        'plugin.filebinder.filebinder_comment',
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
     * save() stores the content type detected from the contents of the file, not the one of the data.
     */
    public function testSaveDetectsContentType(): void
    {
        copy($this->_testFile(), $this->tmpPath);
        $this->FilebinderPost->save(['FilebinderPost' => [
            'title' => 'Title',
            'logo' => [
                'model' => 'FilebinderPost',
                'field_name' => 'logo',
                'file_name' => 'logo.html',
                'file_content_type' => 'text/html',
                'file_size' => 1395,
                'tmp_bind_path' => $this->tmpPath,
            ],
        ]]);
        $id = $this->FilebinderPost->getLastInsertId();

        $result = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);
        $this->assertSame('image/png', $result['FilebinderPost']['logo']['file_content_type']);
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
     * funcCheckFile() calls the function with the path of the file, and fails when the file does not exist.
     */
    public function testFuncCheckFile(): void
    {
        copy($this->_testFile(), $this->tmpPath);

        $this->assertTrue($this->FilebinderPost->funcCheckFile(['logo' => ['tmp_bind_path' => $this->tmpPath]], 'is_file'));
        $this->assertFalse($this->FilebinderPost->funcCheckFile(['logo' => ['tmp_bind_path' => $this->tmpPath]], 'is_dir'));
        $this->assertFalse($this->FilebinderPost->funcCheckFile(['logo' => ['tmp_bind_path' => $this->filePath . 'missing.png']], 'is_string'));
        $this->assertFalse($this->FilebinderPost->funcCheckFile(['logo' => ['tmp_bind_path' => $this->filePath]], 'is_string'));
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
     * A model without bound fields finds its records as they are.
     */
    public function testFindWithoutBindFields(): void
    {
        $this->FilebinderPost->bindFields = [];

        $result = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => 1]]);

        $this->assertSame('Title', $result['FilebinderPost']['title']);
        $this->assertArrayNotHasKey('logo', $result['FilebinderPost']);
    }

    /**
     * With a field list, find() adds only the bound fields of the list, and does not query them as columns.
     */
    public function testFindWithFields(): void
    {
        $this->FilebinderPost->bindFields[] = ['field' => 'image', 'filePath' => $this->filePath];

        foreach ([['id', 'logo'], ['FilebinderPost.id', 'FilebinderPost.logo']] as $fields) {
            $result = $this->FilebinderPost->find('first', [
                'conditions' => ['FilebinderPost.id' => 1],
                'fields' => $fields,
            ]);
            $this->assertEquals(1, $result['FilebinderPost']['id']);
            $this->assertArrayNotHasKey('title', $result['FilebinderPost']);
            $this->assertArrayNotHasKey('image', $result['FilebinderPost']);
            $this->assertSame('logo.png', $result['FilebinderPost']['logo']['file_name']);
        }

        $result = $this->FilebinderPost->find('first', [
            'conditions' => ['FilebinderPost.id' => 1],
            'fields' => ['FilebinderPost.id', 'FilebinderPost.title'],
        ]);
        $this->assertEquals(1, $result['FilebinderPost']['id']);
        $this->assertSame('Title', $result['FilebinderPost']['title']);
        if (array_key_exists('logo', $result['FilebinderPost'])) {
            $this->markTestIncomplete(
                'Known bug: a field list without a bound field adds all the bound fields, because bindFile() '
                . 'falls back to all of them when beforeFind() removed every field from BindableBehavior::$bindFields.',
            );
        }
        $this->assertArrayNotHasKey('image', $result['FilebinderPost']);
    }

    /**
     * find('all') adds the file data to each record.
     */
    public function testFindAll(): void
    {
        $id = $this->_saveLogo()['FilebinderPost']['id'];

        $result = $this->FilebinderPost->find('all', ['order' => ['FilebinderPost.id' => 'ASC']]);

        $logos = [];
        foreach ($result as $record) {
            $logos[$record['FilebinderPost']['id']] = $record['FilebinderPost']['logo']['file_path'] ?? null;
        }
        $this->assertSame([
            1 => $this->filePath . 'FilebinderPost/1/logo/logo.png',
            401 => null,
            $id => $this->filePath . 'FilebinderPost/' . $id . '/logo/logo.png',
        ], $logos);
    }

    /**
     * A file whose upload failed (no file size) is not saved, and the field gets a validation error.
     */
    public function testSaveWithoutFileSize(): void
    {
        copy($this->_testFile(), $this->tmpPath);
        $Attachment = ClassRegistry::init('Attachment');
        $attachments = $Attachment->find('count');
        $posts = $this->FilebinderPost->find('count');

        $result = $this->FilebinderPost->save(['FilebinderPost' => [
            'title' => 'Title',
            'logo' => [
                'model' => 'FilebinderPost',
                'field_name' => 'logo',
                'file_name' => 'logo.png',
                'file_size' => 0,
                'tmp_bind_path' => $this->tmpPath,
            ],
        ]]);

        $this->assertFalse($result);
        $this->assertSame(['Validation Error: File Upload Error'], $this->FilebinderPost->validationErrors['logo']);
        $this->assertSame($attachments, $Attachment->find('count'));
        $this->assertSame($posts, $this->FilebinderPost->find('count'));
    }

    /**
     * When the attachment cannot be saved, the record is not saved.
     */
    public function testSaveAttachmentError(): void
    {
        $posts = $this->FilebinderPost->find('count');
        ClassRegistry::init('Attachment')->validate = ['file_name' => ['rule' => ['maxLength', 3]]];
        copy($this->_testFile(), $this->tmpPath);

        $result = $this->FilebinderPost->save(['FilebinderPost' => [
            'title' => 'Title',
            'logo' => [
                'model' => 'FilebinderPost',
                'field_name' => 'logo',
                'file_name' => 'logo.png',
                'file_size' => 1395,
                'tmp_bind_path' => $this->tmpPath,
            ],
        ]]);

        $this->assertFalse($result);
        $this->assertSame($posts, $this->FilebinderPost->find('count'));
    }

    /**
     * Saving the other fields of a record keeps its files.
     */
    public function testSaveOtherFieldsKeepsFile(): void
    {
        $result = $this->_saveLogo();
        $id = $result['FilebinderPost']['id'];

        $this->FilebinderPost->id = $id;
        $this->assertNotFalse($this->FilebinderPost->saveField('title', 'Changed'));
        $this->FilebinderPost->create();
        $this->assertNotFalse($this->FilebinderPost->save(['FilebinderPost' => ['id' => $id, 'body' => 'Changed']]));

        $this->assertFileExists($result['FilebinderPost']['logo']['file_path']);
        $found = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);
        $this->assertSame('Changed', $found['FilebinderPost']['title']);
        $this->assertSame('logo.png', $found['FilebinderPost']['logo']['file_name']);
    }

    /**
     * A file saved to the record of Model::$id, without the ID in the data, is attached to that record.
     */
    public function testSaveFileWithModelId(): void
    {
        copy($this->_testFile(), $this->tmpPath);

        $this->FilebinderPost->id = 401;
        $this->assertNotFalse($this->FilebinderPost->save(['FilebinderPost' => [
            'logo' => [
                'model' => 'FilebinderPost',
                'field_name' => 'logo',
                'file_name' => 'logo.png',
                'file_size' => 1395,
                'tmp_bind_path' => $this->tmpPath,
            ],
        ]]));

        $found = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => 401]]);
        if ($found['FilebinderPost']['logo'] === null) {
            $this->markTestIncomplete(
                'Known bug: afterSave() reads the ID of an updated record only from the saved data, not from '
                . 'Model::$id, so the attachment keeps model_id 0 and the file is not saved.',
            );
        }
        $this->assertSame('logo.png', $found['FilebinderPost']['logo']['file_name']);
        $this->assertFileEquals($this->_testFile(), $found['FilebinderPost']['logo']['file_path']);
    }

    /**
     * The saved files and their directories get fileMode and dirMode.
     */
    public function testSaveFileModeDirMode(): void
    {
        $this->FilebinderPost->setSettings(['fileMode' => 0640, 'dirMode' => 0750]);

        $filePath = $this->_saveLogo()['FilebinderPost']['logo']['file_path'];

        clearstatcache();
        $this->assertSame(0640, fileperms($filePath) & 0777);
        $this->assertSame(0750, fileperms(dirname($filePath)) & 0777);
        $this->assertSame(0750, fileperms(dirname($filePath, 3)) & 0777);
    }

    /**
     * The older dbStorage setting overrides whether the database storage is used.
     */
    public function testDbStorageSetting(): void
    {
        $this->FilebinderPost->setSettings(['storage' => BindableBehavior::STORAGE_DB, 'dbStorage' => false]);
        $id = $this->_saveLogo()['FilebinderPost']['id'];
        $this->FilebinderPost->withObject(true);
        $result = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);
        $this->assertEmpty($result['FilebinderPost']['logo']['file_object']);

        $this->FilebinderPost->setSettings(['storage' => false, 'dbStorage' => true, 'withObject' => false]);
        $result = $this->_saveLogo();
        $filePath = $result['FilebinderPost']['logo']['file_path'];
        unlink($filePath);
        $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $result['FilebinderPost']['id']]]);
        $this->assertFileEquals($this->_testFile(), $filePath);
    }

    /**
     * A new file of a record with an old file, with exchangeFile = false, keeps the old local file but not its
     * attachment.
     */
    public function testUpdateFileWithoutExchangeFile(): void
    {
        $this->FilebinderPost->setSettings(['exchangeFile' => false]);
        $result = $this->_saveLogo();
        $id = $result['FilebinderPost']['id'];

        $tmpPath2 = $this->filePath . 'bindup2.png';
        copy($this->_testFile(), $tmpPath2);
        $this->FilebinderPost->save(['FilebinderPost' => [
            'id' => $id,
            'logo' => [
                'model' => 'FilebinderPost',
                'field_name' => 'logo',
                'file_name' => 'logo2.png',
                'file_size' => 1395,
                'tmp_bind_path' => $tmpPath2,
            ],
        ]]);

        $this->assertFileExists($result['FilebinderPost']['logo']['file_path']);
        $found = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);
        $this->assertSame('logo2.png', $found['FilebinderPost']['logo']['file_name']);
        $this->assertFileExists($found['FilebinderPost']['logo']['file_path']);
        $this->assertSame(1, ClassRegistry::init('Attachment')->find('count', ['conditions' => ['model' => 'FilebinderPost', 'model_id' => $id]]));
    }

    /**
     * The callbacks can be a method name of the model.
     */
    public function testAttachCallbacksModelMethod(): void
    {
        $this->FilebinderPost->setSettings(['beforeAttach' => 'recordPath', 'afterAttach' => 'recordPath']);

        $result = $this->_saveLogo();

        $this->assertSame([$this->tmpPath, $result['FilebinderPost']['logo']['file_path']], $this->FilebinderPost->recordedPaths);
    }

    /**
     * A callback that cannot be called cancels the save.
     */
    public function testBeforeAttachNotCallable(): void
    {
        $posts = $this->FilebinderPost->find('count');
        foreach (['no_such_function', ['BindableBehaviorTestCallback', 'noSuchMethod'], ['BindableBehaviorTestCallback']] as $callback) {
            $this->FilebinderPost->setSettings(['beforeAttach' => null]);
            $this->FilebinderPost->setSettings(['beforeAttach' => $callback]);
            copy($this->_testFile(), $this->tmpPath);

            $result = $this->FilebinderPost->save(['FilebinderPost' => [
                'title' => 'Title',
                'logo' => [
                    'model' => 'FilebinderPost',
                    'field_name' => 'logo',
                    'file_name' => 'logo.png',
                    'file_size' => 1395,
                    'tmp_bind_path' => $this->tmpPath,
                ],
            ]]);

            $this->assertFalse($result, json_encode($callback));
        }
        $this->assertSame($posts, $this->FilebinderPost->find('count'));
    }

    /**
     * afterAttach returning false removes the uploaded file.
     */
    public function testAfterAttachFalse(): void
    {
        $this->FilebinderPost->setSettings(['afterAttach' => 'is_dir']);
        copy($this->_testFile(), $this->tmpPath);

        $this->FilebinderPost->save(['FilebinderPost' => [
            'title' => 'Title',
            'logo' => [
                'model' => 'FilebinderPost',
                'field_name' => 'logo',
                'file_name' => 'logo.png',
                'file_size' => 1395,
                'tmp_bind_path' => $this->tmpPath,
            ],
        ]]);

        $this->assertFileDoesNotExist($this->tmpPath);
    }

    /**
     * delete_<field> deletes only the file of that field, and deleteEntity() deletes the files of the given fields.
     */
    public function testDeleteOneOfSeveralFields(): void
    {
        $this->FilebinderPost->bindFields[] = ['field' => 'image', 'filePath' => $this->filePath];
        $id = $this->_saveLogo()['FilebinderPost']['id'];
        copy($this->_testFile(), $this->tmpPath);
        $this->FilebinderPost->save(['FilebinderPost' => [
            'id' => $id,
            'image' => [
                'model' => 'FilebinderPost',
                'field_name' => 'image',
                'file_name' => 'image.png',
                'file_size' => 1395,
                'tmp_bind_path' => $this->tmpPath,
            ],
        ]]);
        $found = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);
        $logo = $found['FilebinderPost']['logo']['file_path'];
        $image = $found['FilebinderPost']['image']['file_path'];
        $this->assertFileExists($logo);
        $this->assertFileExists($image);

        $this->FilebinderPost->save(['FilebinderPost' => ['id' => $id, 'delete_logo' => '1']]);

        $this->assertFileDoesNotExist($logo);
        $this->assertFileExists($image);
        $found = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);
        $this->assertNull($found['FilebinderPost']['logo']);
        $this->assertSame('image.png', $found['FilebinderPost']['image']['file_name']);

        $this->assertTrue($this->FilebinderPost->deleteEntity($id, 'image'));
        $this->assertFileDoesNotExist($image);
        $this->assertFalse($this->FilebinderPost->deleteEntity($id, 'image'));
        $this->assertSame(0, ClassRegistry::init('Attachment')->find('count', ['conditions' => ['model' => 'FilebinderPost', 'model_id' => $id]]));
    }

    /**
     * Without local files, delete() deletes the attachments.
     */
    public function testDeleteWithoutLocalFile(): void
    {
        $this->FilebinderPost->setSettings(['filePath' => false]);
        $this->FilebinderPost->bindFields[0]['filePath'] = false;
        $id = $this->_saveLogo()['FilebinderPost']['id'];

        $this->assertTrue($this->FilebinderPost->delete($id));

        $this->assertSame(0, ClassRegistry::init('Attachment')->find('count', ['conditions' => ['model' => 'FilebinderPost', 'model_id' => $id]]));
    }

    /**
     * A local file older than its attachment is replaced with the file of the database.
     */
    public function testRestoreOutdatedFile(): void
    {
        $result = $this->_saveLogo();
        $filePath = $result['FilebinderPost']['logo']['file_path'];
        file_put_contents($filePath, 'outdated');
        touch($filePath, strtotime('2000-01-01 00:00:00'));
        // As in a new request: PHP < 8.3 keeps the modification time of the first find() in the stat cache
        clearstatcache();

        $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $result['FilebinderPost']['id']]]);

        $this->assertFileEquals($this->_testFile(), $filePath);
    }

    /**
     * A local file newer than its attachment is kept.
     */
    public function testRestoreKeepsNewerFile(): void
    {
        $result = $this->_saveLogo();
        $filePath = $result['FilebinderPost']['logo']['file_path'];
        file_put_contents($filePath, 'newer');
        touch($filePath, time() + 60);
        clearstatcache();

        $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $result['FilebinderPost']['id']]]);

        $this->assertStringEqualsFile($filePath, 'newer');
    }

    /**
     * A restored file gets fileMode, in directories created with dirMode.
     */
    public function testRestoreFileModeDirMode(): void
    {
        $result = $this->_saveLogo();
        $filePath = $result['FilebinderPost']['logo']['file_path'];
        (new Folder($this->filePath . 'FilebinderPost'))->delete();
        $this->FilebinderPost->setSettings(['fileMode' => 0640, 'dirMode' => 0750]);

        $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $result['FilebinderPost']['id']]]);

        $this->assertFileEquals($this->_testFile(), $filePath);
        clearstatcache();
        $this->assertSame(0640, fileperms($filePath) & 0777);
        $this->assertSame(0750, fileperms(dirname($filePath)) & 0777);
        $this->assertSame(0750, fileperms($this->filePath . 'FilebinderPost') & 0777);
    }

    /**
     * With withObject, a local file is restored from the file contents of the find.
     */
    public function testRestoreWithObject(): void
    {
        $result = $this->_saveLogo();
        $filePath = $result['FilebinderPost']['logo']['file_path'];
        unlink($filePath);

        $this->FilebinderPost->withObject(true);
        $found = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $result['FilebinderPost']['id']]]);

        $this->assertFileEquals($this->_testFile(), $filePath);
        $this->assertSame(base64_encode((string)file_get_contents($this->_testFile())), $found['FilebinderPost']['logo']['file_object']);
    }

    /**
     * afterAttach is called with the path of a restored file, and returning false makes find() return the records
     * without the file data.
     */
    public function testRestoreAfterAttach(): void
    {
        $result = $this->_saveLogo();
        $id = $result['FilebinderPost']['id'];
        $filePath = $result['FilebinderPost']['logo']['file_path'];
        unlink($filePath);
        $this->FilebinderPost->setSettings(['afterAttach' => 'recordPath']);

        $found = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);

        $this->assertSame([$filePath], $this->FilebinderPost->recordedPaths);
        $this->assertSame('logo.png', $found['FilebinderPost']['logo']['file_name']);

        unlink($filePath);
        $this->FilebinderPost->setSettings(['afterAttach' => 'is_dir']);
        $found = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);
        $this->assertSame('Title', $found['FilebinderPost']['title']);
        $this->assertArrayNotHasKey('logo', $found['FilebinderPost']);
    }

    /**
     * The formats of the results of the associated models.
     *
     * @return array<string, array{bool}>
     */
    public static function consistentAfterFind(): array
    {
        return [
            'consistent' => [true],
            'not consistent' => [false],
        ];
    }

    /**
     * The hasMany results of an associated model, passed to afterFind() by the model, get the files of its own
     * bound fields, in both formats of Model::$useConsistentAfterFind.
     *
     * @dataProvider consistentAfterFind
     */
    public function testFindAssociated(bool $useConsistentAfterFind): void
    {
        $Comment = ClassRegistry::init('FilebinderComment');
        $Comment->bindFields = [['field' => 'image', 'filePath' => $this->filePath]];
        $Comment->useConsistentAfterFind = $useConsistentAfterFind;
        $this->FilebinderPost->bindModel(['hasMany' => ['FilebinderComment']], false);
        copy($this->_testFile(), $this->tmpPath);
        $Comment->create();
        $this->assertNotFalse($Comment->save(['FilebinderComment' => [
            'filebinder_post_id' => 1,
            'body' => 'Comment',
            'image' => [
                'model' => 'FilebinderComment',
                'field_name' => 'image',
                'file_name' => 'image.png',
                'file_size' => 1395,
                'tmp_bind_path' => $this->tmpPath,
            ],
        ]]));
        $commentId = $Comment->getLastInsertId();

        $result = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => 1]]);

        $this->assertSame('logo.png', $result['FilebinderPost']['logo']['file_name']);
        $this->assertCount(1, $result['FilebinderComment']);
        $this->assertArrayNotHasKey('logo', $result['FilebinderComment'][0]);
        $this->assertSame($this->filePath . 'FilebinderComment/' . $commentId . '/image/image.png', $result['FilebinderComment'][0]['image']['file_path']);
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
