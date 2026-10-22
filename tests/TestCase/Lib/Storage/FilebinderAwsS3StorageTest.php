<?php

use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;

App::uses('FilebinderAwsS3Storage', 'Filebinder.Storage');

class FilebinderAwsS3StorageTest extends CakeTestCase
{
    use MockS3Trait;

    public function setUp(): void
    {
        parent::setUp();
        $this->mockAws();
    }

    public function tearDown(): void
    {
        Configure::delete('Aws');
        parent::tearDown();
    }

    /**
     * The client is created from the Aws configuration, with the options given to the constructor.
     */
    public function testCreateClient(): void
    {
        $client = (new FilebinderAwsS3Storage())->client();
        $this->assertInstanceOf(S3Client::class, $client);
        $this->assertSame('ap-northeast-1', $client->getRegion());
        $this->assertSame('aws-key', $client->getCredentials()->wait()->getAccessKeyId());

        $client = (new FilebinderAwsS3Storage(null, [
            'region' => 'us-east-1',
            'credentials' => ['key' => 'other-key', 'secret' => 'other-secret'],
        ]))->client();
        $this->assertSame('us-east-1', $client->getRegion());
        $this->assertSame('other-key', $client->getCredentials()->wait()->getAccessKeyId());
    }

    /**
     * An injected client is used.
     */
    public function testInjectedClient(): void
    {
        $client = FilebinderAwsS3Storage::createClient(['region' => 'us-east-2']);

        $this->assertSame($client, (new FilebinderAwsS3Storage($client))->client());
    }

    /**
     * put() sends PutObject with the file and the options, and the SDK sets the Content-Type from the extension.
     */
    public function testPut(): void
    {
        $file = CakePlugin::path('Filebinder') . 'tests' . DS . 'File' . DS . 'test.png';

        (new FilebinderAwsS3Storage())->put('my-bucket', 'FilebinderPost/1/logo/logo.png', $file, ['ACL' => 'private']);

        $command = $this->lastAwsCommand();
        $this->assertSame('PutObject', $command['command']);
        $this->assertSame('my-bucket', $command['params']['Bucket']);
        $this->assertSame('FilebinderPost/1/logo/logo.png', $command['params']['Key']);
        $this->assertSame('private', $command['params']['ACL']);
        $this->assertSame('image/png', $command['request']->getHeaderLine('Content-Type'));
        $this->assertSame(file_get_contents($file), (string)$command['request']->getBody());
    }

    /**
     * get() returns the contents of the object.
     */
    public function testGet(): void
    {
        $this->awsResults[] = new Result(['Body' => Utils::streamFor('contents')]);

        $this->assertSame('contents', (new FilebinderAwsS3Storage())->get('my-bucket', 'a/b.txt'));
        $command = $this->lastAwsCommand();
        $this->assertSame('GetObject', $command['command']);
        $this->assertSame('my-bucket', $command['params']['Bucket']);
        $this->assertSame('a/b.txt', $command['params']['Key']);
    }

    /**
     * saveAs() sends GetObject with SaveAs, which the SDK passes to the HTTP handler as the sink.
     */
    public function testSaveAs(): void
    {
        (new FilebinderAwsS3Storage())->saveAs('my-bucket', 'a/b.txt', TMP . 'b.txt');

        $command = $this->lastAwsCommand();
        $this->assertSame('GetObject', $command['command']);
        $this->assertSame('a/b.txt', $command['params']['Key']);
        $this->assertSame(TMP . 'b.txt', $command['params']['@http']['sink']);
    }

    /**
     * delete() sends DeleteObject.
     */
    public function testDelete(): void
    {
        (new FilebinderAwsS3Storage())->delete('my-bucket', 'a/b.txt');

        $command = $this->lastAwsCommand();
        $this->assertSame('DeleteObject', $command['command']);
        $this->assertSame('my-bucket', $command['params']['Bucket']);
        $this->assertSame('a/b.txt', $command['params']['Key']);
    }

    /**
     * url() returns the URL of the object.
     */
    public function testUrl(): void
    {
        $this->assertSame('https://my-bucket.s3.ap-northeast-1.amazonaws.com/a/b.txt', (new FilebinderAwsS3Storage())->url('my-bucket', 'a/b.txt'));
    }

    /**
     * presignedUrl() returns a URL signed for GetObject that expires at the time.
     */
    public function testPresignedUrl(): void
    {
        $url = (new FilebinderAwsS3Storage())->presignedUrl('my-bucket', 'files/a b.txt', new DateTimeImmutable('+10 minutes'));

        $this->assertStringStartsWith('https://my-bucket.s3.ap-northeast-1.amazonaws.com/files/a%20b.txt?', $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('AWS4-HMAC-SHA256', $query['X-Amz-Algorithm']);
        $this->assertStringStartsWith('aws-key/', $query['X-Amz-Credential']);
        $this->assertMatchesRegularExpression('/\\A[0-9a-f]{64}\\z/', $query['X-Amz-Signature']);
        $this->assertEqualsWithDelta(600, (int)$query['X-Amz-Expires'], 5);
    }

    /**
     * The errors of the SDK are thrown.
     */
    public function testError(): void
    {
        $this->awsResults[] = fn($command) => new S3Exception('Access Denied', $command);

        $this->expectException(S3Exception::class);
        $this->expectExceptionMessage('Access Denied');
        (new FilebinderAwsS3Storage())->delete('my-bucket', 'a/b.txt');
    }
}
