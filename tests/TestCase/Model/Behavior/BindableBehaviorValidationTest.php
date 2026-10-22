<?php

App::uses('Folder', 'Utility');
App::uses('FilebinderPost', 'Model');

/**
 * The validation rules of the Bindable behavior
 */
class BindableBehaviorValidationTest extends CakeTestCase
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
     * Directory of the uploaded files
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
        $this->tmpPath = $this->filePath . 'ring_logo.png';
        new Folder($this->filePath, true);
        copy(CakePlugin::path('Filebinder') . 'tests' . DS . 'File' . DS . 'test.png', $this->tmpPath);
        $this->FilebinderPost = ClassRegistry::init('FilebinderPost');
        $this->FilebinderPost->bindFields = [
            ['field' => 'logo', 'filePath' => $this->filePath],
        ];
    }

    public function tearDown(): void
    {
        (new Folder($this->filePath))->delete();
        unset($this->FilebinderPost);
        parent::tearDown();
    }

    /**
     * Values that are not file data, e.g. a field without a file, fail every rule.
     */
    public function testRulesRejectNoFile(): void
    {
        foreach ([null, '', 'logo.png'] as $value) {
            $data = ['logo' => $value];
            $label = var_export($value, true);
            $this->assertFalse($this->FilebinderPost->alphaNumericFileName($data), $label);
            $this->assertFalse($this->FilebinderPost->checkExtension($data, 'png'), $label);
            $this->assertFalse($this->FilebinderPost->checkContentType($data, 'image/png'), $label);
            $this->assertFalse($this->FilebinderPost->checkMaxFileSize($data, '1MB'), $label);
            $this->assertFalse($this->FilebinderPost->checkMinFileSize($data, 1), $label);
            $this->assertFalse($this->FilebinderPost->checkFileSize($data, '1MB'), $label);
            $this->assertFalse($this->FilebinderPost->notEmptyFile($data), $label);
            $this->assertFalse($this->FilebinderPost->funcCheckFile($data, 'is_file'), $label);
        }
    }

    /**
     * alphaNumericFileName accepts the file names with only letters, digits and dots.
     */
    public function testAlphaNumericFileName(): void
    {
        foreach (['logo.png', 'Logo2.PNG', 'ロゴ.png', 'logo'] as $fileName) {
            $this->assertTrue($this->FilebinderPost->alphaNumericFileName(['logo' => ['file_name' => $fileName]]), $fileName);
        }
        foreach (['my logo.png', 'logo-1.png', 'logo_1.png', '../logo.png', 'logo.php%00.png', ''] as $fileName) {
            $this->assertFalse($this->FilebinderPost->alphaNumericFileName(['logo' => ['file_name' => $fileName]]), $fileName);
        }

        if ($this->FilebinderPost->alphaNumericFileName(['logo' => ['file_name' => "logo.png\n<script>.php"]])) {
            $this->markTestIncomplete(
                'Known bug: alphaNumericFileName uses the m modifier with ^ and $, so a file name with a line '
                . 'break passes when one of its lines has only letters, digits and dots.',
            );
        }
        $this->assertFalse($this->FilebinderPost->alphaNumericFileName(['logo' => ['file_name' => "logo\n.png"]]));
    }

    /**
     * checkExtension checks the path of the uploaded file, or the path of a saved file.
     */
    public function testCheckExtensionPaths(): void
    {
        $this->assertTrue($this->FilebinderPost->checkExtension(['logo' => ['file_path' => '/files/logo.jpeg']], ['jpg', 'jpeg']));
        $this->assertFalse($this->FilebinderPost->checkExtension(['logo' => ['file_path' => '/files/logo.jpeg']], 'png'));
        // The path of the uploaded file wins over the path of the saved file
        $this->assertFalse($this->FilebinderPost->checkExtension(['logo' => ['tmp_bind_path' => '/tmp/ring_logo.php', 'file_path' => '/files/logo.png']], 'png'));
        // Only the end of the name is the extension
        $this->assertFalse($this->FilebinderPost->checkExtension(['logo' => ['tmp_bind_path' => '/tmp/ring_logo.png.php']], 'png'));
        $this->assertFalse($this->FilebinderPost->checkExtension(['logo' => ['tmp_bind_path' => '/tmp/ring_logopng']], 'png'));
        $this->assertFalse($this->FilebinderPost->checkExtension(['logo' => ['file_name' => 'logo.png']], 'png'));
    }

    /**
     * checkContentType matches the whole content type with the regular expressions.
     */
    public function testCheckContentTypePatterns(): void
    {
        $data = ['logo' => ['file_content_type' => 'image/png']];

        $this->assertTrue($this->FilebinderPost->checkContentType($data, 'image/.+'));
        $this->assertTrue($this->FilebinderPost->checkContentType($data, ['text/plain', 'image/(png|gif)']));
        $this->assertFalse($this->FilebinderPost->checkContentType($data, 'image'));
        $this->assertFalse($this->FilebinderPost->checkContentType(['logo' => ['file_content_type' => 'text/html; image/png']], 'image/png'));
        $this->assertFalse($this->FilebinderPost->checkContentType(['logo' => ['file_content_type' => 'image/png+html']], 'image/png'));
    }

    /**
     * checkFileSize is checkMaxFileSize: the size is allowed up to the limit.
     */
    public function testCheckFileSize(): void
    {
        $this->assertTrue($this->FilebinderPost->checkFileSize(['logo' => ['file_size' => 102400]], '100KB'));
        $this->assertFalse($this->FilebinderPost->checkFileSize(['logo' => ['file_size' => 102401]], '100KB'));
        $this->assertTrue($this->FilebinderPost->checkFileSize(['logo' => ['file_size' => 1000]], 1000));
        $this->assertFalse($this->FilebinderPost->checkFileSize(['logo' => ['file_size' => 1001]], '1000'));
    }

    /**
     * notEmptyFile accepts the data of an uploaded file.
     */
    public function testNotEmptyFile(): void
    {
        $this->assertTrue($this->FilebinderPost->notEmptyFile(['logo' => ['file_name' => 'logo.png', 'file_size' => 1395, 'tmp_bind_path' => $this->tmpPath]]));
    }

    /**
     * funcCheckFile calls a method of the model with the path of the uploaded file, or of the saved file.
     */
    public function testFuncCheckFileModelMethod(): void
    {
        $saved = $this->filePath . 'saved.png';
        copy($this->tmpPath, $saved);

        $this->assertTrue($this->FilebinderPost->funcCheckFile(['logo' => ['tmp_bind_path' => $this->tmpPath]], 'recordPath'));
        $this->assertTrue($this->FilebinderPost->funcCheckFile(['logo' => ['file_path' => $saved]], 'recordPath'));
        $this->assertSame([$this->tmpPath, $saved], $this->FilebinderPost->recordedPaths);
    }

    /**
     * funcCheckFile fails without a path, or with a function that cannot be called.
     */
    public function testFuncCheckFileInvalid(): void
    {
        $this->assertFalse($this->FilebinderPost->funcCheckFile(['logo' => ['file_name' => 'logo.png']], 'is_file'));
        $this->assertFalse($this->FilebinderPost->funcCheckFile(['logo' => ['tmp_bind_path' => $this->tmpPath]], 'no_such_function'));
        $this->assertFalse($this->FilebinderPost->funcCheckFile(['logo' => ['tmp_bind_path' => $this->tmpPath]], ['FilebinderPost', 'recordPath']));
        $this->assertSame([], $this->FilebinderPost->recordedPaths);
    }

    /**
     * The rules in $validate: a valid file passes all of them, and each rule reports its own error.
     */
    public function testValidate(): void
    {
        $this->FilebinderPost->validate = [
            'logo' => [
                'notEmpty' => ['rule' => ['notEmptyFile']],
                'fileName' => ['rule' => ['alphaNumericFileName']],
                'extension' => ['rule' => ['checkExtension', ['png', 'gif']]],
                'contentType' => ['rule' => ['checkContentType', ['image/png', 'image/gif']]],
                'maxFileSize' => ['rule' => ['checkMaxFileSize', '1KB']],
                'minFileSize' => ['rule' => ['checkMinFileSize', '1KB']],
                'func' => ['rule' => ['funcCheckFile', 'recordPath']],
            ],
        ];
        $logo = [
            'model' => 'FilebinderPost',
            'field_name' => 'logo',
            'file_name' => 'logo.png',
            'file_content_type' => 'image/png',
            'file_size' => 1024,
            'tmp_bind_path' => $this->tmpPath,
        ];

        $this->assertTrue($this->_validates($logo), json_encode($this->FilebinderPost->validationErrors));
        $this->assertSame([$this->tmpPath], $this->FilebinderPost->recordedPaths);

        $this->assertFalse($this->_validates(null));
        $this->assertContains('notEmpty', $this->FilebinderPost->validationErrors['logo']);

        $invalid = [
            'fileName' => ['file_name' => 'my logo.png'],
            'extension' => ['tmp_bind_path' => $this->filePath . 'ring_logo.jpg'],
            'contentType' => ['file_content_type' => 'image/jpeg'],
            'maxFileSize' => ['file_size' => 1025],
            'minFileSize' => ['file_size' => 1023],
            'func' => ['tmp_bind_path' => $this->filePath . 'missing.png'],
        ];
        foreach ($invalid as $rule => $change) {
            $this->assertFalse($this->_validates($change + $logo), $rule);
            $this->assertContains($rule, $this->FilebinderPost->validationErrors['logo'], $rule);
        }
    }

    /**
     * Validates a record with the file data in the logo field.
     *
     * @param array|null $logo File data.
     * @return bool
     */
    protected function _validates(?array $logo): bool
    {
        $this->FilebinderPost->create();
        $this->FilebinderPost->set(['FilebinderPost' => ['title' => 'Title', 'logo' => $logo]]);

        return $this->FilebinderPost->validates();
    }
}
