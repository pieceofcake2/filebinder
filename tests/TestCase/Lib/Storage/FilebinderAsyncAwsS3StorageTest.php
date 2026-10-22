<?php

use AsyncAws\S3\Exception\NoSuchKeyException;
use AsyncAws\SimpleS3\SimpleS3Client;
use Symfony\Component\HttpClient\Response\MockResponse;

App::uses('FilebinderAsyncAwsS3Storage', 'Filebinder.Storage');

class FilebinderAsyncAwsS3StorageTest extends CakeTestCase
{
    use MockS3Trait;

    public function setUp(): void
    {
        parent::setUp();
        $this->mockAsyncAws();
    }

    public function tearDown(): void
    {
        Configure::delete('AsyncAws');
        parent::tearDown();
    }

    /**
     * The client is created from the AsyncAws configuration, with the options given to the constructor.
     */
    public function testCreateClient(): void
    {
        $storage = new FilebinderAsyncAwsS3Storage();
        $this->assertInstanceOf(SimpleS3Client::class, $storage->client());
        $this->assertSame('https://my-bucket.s3.ap-northeast-1.amazonaws.com/a/b.txt', $storage->url('my-bucket', 'a/b.txt'));

        $storage = new FilebinderAsyncAwsS3Storage(null, [
            'region' => 'us-east-1',
            'credentials' => ['key' => 'other-key', 'secret' => 'other-secret'],
        ]);
        $this->assertSame('https://my-bucket.s3.us-east-1.amazonaws.com/a/b.txt', $storage->url('my-bucket', 'a/b.txt'));
        $storage->delete('my-bucket', 'a/b.txt');
        $this->assertStringContainsString('Credential=other-key/', $this->lastAsyncAwsRequest()['headers']['authorization']);
        $this->assertStringContainsString('/us-east-1/s3/', $this->lastAsyncAwsRequest()['headers']['authorization']);
    }

    /**
     * An injected client is used.
     */
    public function testInjectedClient(): void
    {
        $client = FilebinderAsyncAwsS3Storage::createClient(['region' => 'us-east-2']);

        $this->assertSame($client, (new FilebinderAsyncAwsS3Storage($client))->client());
    }

    /**
     * put() uploads the file with the options and the Content-Type of its contents.
     */
    public function testPut(): void
    {
        $file = CakePlugin::path('Filebinder') . 'tests' . DS . 'File' . DS . 'test.png';

        (new FilebinderAsyncAwsS3Storage())->put('my-bucket', 'FilebinderPost/1/logo/logo.png', $file, ['ACL' => 'public-read']);

        $request = $this->lastAsyncAwsRequest();
        $this->assertSame('PUT', $request['method']);
        $this->assertSame('https://my-bucket.s3.ap-northeast-1.amazonaws.com/FilebinderPost/1/logo/logo.png', $request['url']);
        $this->assertSame('public-read', $request['headers']['x-amz-acl']);
        $this->assertSame('image/png', $request['headers']['content-type']);
        $this->assertSame(file_get_contents($file), $request['body']);
    }

    /**
     * A ContentType option replaces the detected Content-Type.
     */
    public function testPutContentType(): void
    {
        $file = CakePlugin::path('Filebinder') . 'tests' . DS . 'File' . DS . 'test.png';

        (new FilebinderAsyncAwsS3Storage())->put('my-bucket', 'logo.png', $file, ['ContentType' => 'application/octet-stream']);

        $this->assertSame('application/octet-stream', $this->lastAsyncAwsRequest()['headers']['content-type']);
    }

    /**
     * get() returns the contents of the object.
     */
    public function testGet(): void
    {
        $this->asyncAwsResponses[] = new MockResponse('contents');

        $this->assertSame('contents', (new FilebinderAsyncAwsS3Storage())->get('my-bucket', 'a/b.txt'));
        $request = $this->lastAsyncAwsRequest();
        $this->assertSame('GET', $request['method']);
        $this->assertSame('https://my-bucket.s3.ap-northeast-1.amazonaws.com/a/b.txt', $request['url']);
    }

    /**
     * saveAs() writes the contents of the object to the file.
     */
    public function testSaveAs(): void
    {
        $this->asyncAwsResponses[] = new MockResponse('contents');
        $file = TMP . 'filebinder_save_as.txt';

        (new FilebinderAsyncAwsS3Storage())->saveAs('my-bucket', 'a/b.txt', $file);

        $this->assertStringEqualsFile($file, 'contents');
        unlink($file);
    }

    /**
     * delete() sends DeleteObject.
     */
    public function testDelete(): void
    {
        $this->asyncAwsResponses[] = new MockResponse('', ['http_code' => 204]);

        (new FilebinderAsyncAwsS3Storage())->delete('my-bucket', 'a/b.txt');

        $request = $this->lastAsyncAwsRequest();
        $this->assertSame('DELETE', $request['method']);
        $this->assertSame('https://my-bucket.s3.ap-northeast-1.amazonaws.com/a/b.txt', $request['url']);
    }

    /**
     * The errors of AsyncAws are thrown.
     */
    public function testError(): void
    {
        $this->asyncAwsResponses[] = new MockResponse(
            '<?xml version="1.0" encoding="UTF-8"?><Error><Code>NoSuchKey</Code><Message>The specified key does not exist.</Message></Error>',
            ['http_code' => 404],
        );

        $this->expectException(NoSuchKeyException::class);
        (new FilebinderAsyncAwsS3Storage())->get('my-bucket', 'a/b.txt');
    }
}
