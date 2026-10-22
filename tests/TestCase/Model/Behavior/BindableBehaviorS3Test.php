<?php

use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Utils;
use Symfony\Component\HttpClient\Response\MockResponse;

App::uses('Folder', 'Utility');
App::uses('FilebinderPost', 'Model');

/**
 * The S3 storage of the Bindable behavior, with mocked S3 clients
 */
class BindableBehaviorS3Test extends CakeTestCase
{
    use MockS3Trait;

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
        $this->mockAws();
        $this->mockAsyncAws();
        // Most tests use the AWS SDK; AsyncAws is the default when both plugins are loaded
        Configure::write('Filebinder.S3.client', 'Aws');
        $this->FilebinderPost = ClassRegistry::init('FilebinderPost');
        $this->FilebinderPost->setSettings(['storage' => [BindableBehavior::STORAGE_DB, BindableBehavior::STORAGE_S3]]);
        $this->FilebinderPost->bindFields = [
            [
                'field' => 'logo',
                'tmpPath' => CACHE,
                'filePath' => $this->filePath,
                'bucket' => 'my-bucket',
                'acl' => 'private',
            ],
        ];
    }

    public function tearDown(): void
    {
        (new Folder($this->filePath))->delete();
        Configure::delete('Filebinder');
        Configure::delete('Aws');
        Configure::delete('AsyncAws');
        unset($this->FilebinderPost);
        parent::tearDown();
    }

    /**
     * The file is uploaded to S3 with the bucket and the ACL of the field.
     */
    public function testSaveS3(): void
    {
        $result = $this->_saveLogo();
        $id = $result['FilebinderPost']['id'];

        $this->assertSame(['PutObject'], $this->awsCommandNames());
        $command = $this->awsCommands[0];
        $this->assertSame('my-bucket', $command['params']['Bucket']);
        $this->assertSame('FilebinderPost/' . $id . '/logo/logo.png', $command['params']['Key']);
        $this->assertSame('private', $command['params']['ACL']);
        $this->assertSame('image/png', $command['request']->getHeaderLine('Content-Type'));
        $this->assertSame(file_get_contents($this->_testFile()), (string)$command['request']->getBody());
        $this->assertStringContainsString('Credential=aws-key/', $command['request']->getHeaderLine('Authorization'));
        $this->assertStringContainsString('/ap-northeast-1/s3/', $command['request']->getHeaderLine('Authorization'));
        $this->assertFileExists($result['FilebinderPost']['logo']['file_path']);
        $this->assertFileDoesNotExist($this->tmpPath);
    }

    /**
     * Filebinder.S3 sets the bucket, the ACL (public-read by default) and the URL prefix.
     */
    public function testSaveS3WithConfiguredSettings(): void
    {
        Configure::write('Filebinder.S3', ['client' => 'Aws', 'bucket' => 'configured-bucket', 'urlPrefix' => 'files/']);
        unset($this->FilebinderPost->bindFields[0]['bucket'], $this->FilebinderPost->bindFields[0]['acl']);

        $id = $this->_saveLogo()['FilebinderPost']['id'];

        $command = $this->lastAwsCommand();
        $this->assertSame('configured-bucket', $command['params']['Bucket']);
        $this->assertSame('files/FilebinderPost/' . $id . '/logo/logo.png', $command['params']['Key']);
        $this->assertSame('public-read', $command['params']['ACL']);
    }

    /**
     * The urlPrefix of the field sets the directory of the file on S3, also when the file is deleted.
     */
    public function testSaveS3WithUrlPrefix(): void
    {
        $this->FilebinderPost->bindFields[0]['urlPrefix'] = 'testPrefix/';

        $id = $this->_saveLogo()['FilebinderPost']['id'];
        $this->assertSame('testPrefix/FilebinderPost/' . $id . '/logo/logo.png', $this->lastAwsCommand()['params']['Key']);

        $this->FilebinderPost->delete($id);
        $command = $this->lastAwsCommand();
        $this->assertSame('DeleteObject', $command['command']);
        $this->assertSame('my-bucket', $command['params']['Bucket']);
        $this->assertSame('testPrefix/FilebinderPost/' . $id . '/logo/logo.png', $command['params']['Key']);
    }

    /**
     * Without a bucket, the file is not uploaded.
     */
    public function testSaveS3WithoutBucket(): void
    {
        unset($this->FilebinderPost->bindFields[0]['bucket']);

        $result = $this->_saveLogo();

        $this->assertSame([], $this->awsCommands);
        $this->assertFileExists($result['FilebinderPost']['logo']['file_path']);
        $this->assertFileDoesNotExist($this->tmpPath);
    }

    /**
     * An error of S3 is thrown, and the uploaded file is removed.
     */
    public function testSaveS3Error(): void
    {
        $this->awsResults[] = fn($command) => new S3Exception('Access Denied', $command);

        try {
            $this->_saveLogo();
            $this->fail('No exception was thrown.');
        } catch (S3Exception $e) {
            $this->assertSame('Access Denied', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->tmpPath);
    }

    /**
     * The deprecated Filebinder.S3.key, secret and region take precedence over the Aws configuration.
     */
    public function testDeprecatedCredentials(): void
    {
        Configure::write('Filebinder.S3', ['client' => 'Aws', 'key' => 'legacy-key', 'secret' => 'legacy-secret', 'region' => 'us-west-2']);

        $this->_saveLogo();

        $authorization = $this->lastAwsCommand()['request']->getHeaderLine('Authorization');
        $this->assertStringContainsString('Credential=legacy-key/', $authorization);
        $this->assertStringContainsString('/us-west-2/s3/', $authorization);
    }

    /**
     * The region of the field takes precedence over Filebinder.S3.region.
     */
    public function testFieldRegion(): void
    {
        Configure::write('Filebinder.S3.region', 'us-west-2');
        $this->FilebinderPost->bindFields[0]['region'] = 'eu-central-1';

        $this->_saveLogo();

        $this->assertStringContainsString('/eu-central-1/s3/', $this->lastAwsCommand()['request']->getHeaderLine('Authorization'));
    }

    /**
     * A local file is restored from S3.
     */
    public function testRestoreS3(): void
    {
        $this->FilebinderPost->setSettings(['storage' => BindableBehavior::STORAGE_S3]);
        $result = $this->_saveLogo();
        $id = $result['FilebinderPost']['id'];
        $filePath = $result['FilebinderPost']['logo']['file_path'];
        unlink($filePath);

        $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);

        $command = $this->lastAwsCommand();
        $this->assertSame('GetObject', $command['command']);
        $this->assertSame('my-bucket', $command['params']['Bucket']);
        $this->assertSame('FilebinderPost/' . $id . '/logo/logo.png', $command['params']['Key']);
        $this->assertSame($filePath, $command['params']['@http']['sink']);
    }

    /**
     * With restoreFromStorage = false, a local file is not restored from S3.
     */
    public function testRestoreFalseS3(): void
    {
        $this->FilebinderPost->setSettings(['storage' => BindableBehavior::STORAGE_S3, 'restoreFromStorage' => false]);
        $result = $this->_saveLogo();
        $filePath = $result['FilebinderPost']['logo']['file_path'];
        unlink($filePath);

        $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $result['FilebinderPost']['id']]]);

        $this->assertSame(['PutObject'], $this->awsCommandNames());
        $this->assertFileDoesNotExist($filePath);
    }

    /**
     * withObject returns the contents of the file on S3 when the database has none.
     */
    public function testFindWithObjectS3(): void
    {
        $this->FilebinderPost->setSettings(['storage' => BindableBehavior::STORAGE_S3]);
        $id = $this->_saveLogo()['FilebinderPost']['id'];
        $this->awsResults[] = new Result(['Body' => Utils::streamFor('contents on S3')]);

        $this->FilebinderPost->withObject(true);
        $result = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);

        $this->assertSame(['PutObject', 'GetObject'], $this->awsCommandNames());
        $this->assertSame('FilebinderPost/' . $id . '/logo/logo.png', $this->lastAwsCommand()['params']['Key']);
        $this->assertSame(base64_encode('contents on S3'), $result['FilebinderPost']['logo']['file_object']);
    }

    /**
     * delete() deletes the file on S3.
     */
    public function testDeleteS3(): void
    {
        $result = $this->_saveLogo();
        $id = $result['FilebinderPost']['id'];

        $this->FilebinderPost->delete($id);

        $this->assertSame(['PutObject', 'DeleteObject'], $this->awsCommandNames());
        $this->assertSame('FilebinderPost/' . $id . '/logo/logo.png', $this->lastAwsCommand()['params']['Key']);
        $this->assertFileDoesNotExist($result['FilebinderPost']['logo']['file_path']);
    }

    /**
     * The AsyncAws client uploads, restores, returns and deletes the files.
     */
    public function testAsyncAws(): void
    {
        Configure::write('Filebinder.S3.client', 'AsyncAws');
        $this->FilebinderPost->setSettings(['storage' => BindableBehavior::STORAGE_S3]);

        $result = $this->_saveLogo();
        $id = $result['FilebinderPost']['id'];
        $request = $this->lastAsyncAwsRequest();
        $this->assertSame('PUT', $request['method']);
        $this->assertSame('https://my-bucket.s3.ap-northeast-1.amazonaws.com/FilebinderPost/' . $id . '/logo/logo.png', $request['url']);
        $this->assertSame('private', $request['headers']['x-amz-acl']);
        $this->assertSame('image/png', $request['headers']['content-type']);
        $this->assertStringContainsString('Credential=async-aws-key/', $request['headers']['authorization']);

        $filePath = $result['FilebinderPost']['logo']['file_path'];
        unlink($filePath);
        $this->asyncAwsResponses[] = new MockResponse('restored');
        $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);
        $this->assertSame('GET', $this->lastAsyncAwsRequest()['method']);
        $this->assertStringEqualsFile($filePath, 'restored');

        $this->asyncAwsResponses[] = new MockResponse('contents on S3');
        $this->FilebinderPost->withObject(true);
        $result = $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $id]]);
        $this->FilebinderPost->withObject(false);
        $this->assertSame(base64_encode('contents on S3'), $result['FilebinderPost']['logo']['file_object']);

        $this->asyncAwsResponses[] = new MockResponse('', ['http_code' => 204]);
        $this->FilebinderPost->delete($id);
        $request = $this->lastAsyncAwsRequest();
        $this->assertSame('DELETE', $request['method']);
        $this->assertSame('https://my-bucket.s3.ap-northeast-1.amazonaws.com/FilebinderPost/' . $id . '/logo/logo.png', $request['url']);
    }

    /**
     * A file restored from S3 gets the fileMode of the settings, as a file restored from the database.
     */
    public function testRestoreS3FileMode(): void
    {
        Configure::write('Filebinder.S3.client', 'AsyncAws');
        $this->FilebinderPost->setSettings(['storage' => BindableBehavior::STORAGE_S3, 'fileMode' => 0640]);
        $result = $this->_saveLogo();
        $filePath = $result['FilebinderPost']['logo']['file_path'];
        unlink($filePath);

        $this->asyncAwsResponses[] = new MockResponse('restored');
        $this->FilebinderPost->find('first', ['conditions' => ['FilebinderPost.id' => $result['FilebinderPost']['id']]]);

        $this->assertStringEqualsFile($filePath, 'restored');
        clearstatcache();
        $this->assertSame(0640, fileperms($filePath) & 0777);
    }

    /**
     * The deprecated Filebinder.S3.key, secret and region also configure the AsyncAws client.
     */
    public function testAsyncAwsDeprecatedCredentials(): void
    {
        Configure::write('Filebinder.S3', ['client' => 'AsyncAws', 'key' => 'legacy-key', 'secret' => 'legacy-secret', 'region' => 'us-west-2']);

        $this->_saveLogo();

        $request = $this->lastAsyncAwsRequest();
        $this->assertStringStartsWith('https://my-bucket.s3.us-west-2.amazonaws.com/', $request['url']);
        $this->assertStringContainsString('Credential=legacy-key/', $request['headers']['authorization']);
    }

    /**
     * s3Storage() returns the S3 storage of a field.
     */
    public function testS3Storage(): void
    {
        $this->FilebinderPost->bindFields[0]['region'] = 'eu-central-1';

        $storage = $this->FilebinderPost->s3Storage('logo');

        $this->assertInstanceOf(FilebinderAwsS3Storage::class, $storage);
        $this->assertSame('https://my-bucket.s3.eu-central-1.amazonaws.com/FilebinderPost/1/logo/logo.png', $storage->url('my-bucket', 'FilebinderPost/1/logo/logo.png'));
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
