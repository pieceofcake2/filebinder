<?php

App::uses('View', 'View');
App::uses('LabelHelper', 'Filebinder.View/Helper');
App::uses('FilebinderSignature', 'Filebinder.Lib');

class LabelHelperTest extends CakeTestCase
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

    protected View $View;

    protected LabelHelper $Label;

    /**
     * Security.salt before the test
     *
     * @var mixed
     */
    protected $salt;

    public function setUp(): void
    {
        parent::setUp();
        $this->View = new View();
        $this->Label = new LabelHelper($this->View);
        $this->salt = Configure::read('Security.salt');
        CakeSession::start();
    }

    public function tearDown(): void
    {
        Configure::write('Security.salt', $this->salt);
        Configure::delete('Filebinder');
        Configure::delete('Aws');
        Configure::delete('AsyncAws');
        unset($this->View, $this->Label);
        parent::tearDown();
    }

    /**
     * A file under the webroot has a path under the webroot.
     */
    public function testMakeSrc(): void
    {
        $file = [
            'file_path' => WWW_ROOT . 'files/FilebinderPost/1/logo/logo.png',
            'model' => 'FilebinderPost',
            'model_id' => '1',
            'field_name' => 'logo',
            'file_name' => 'logo.png',
        ];
        $this->assertSame('/files/FilebinderPost/1/logo/logo.png', $this->Label->_makeSrc($file));
        $this->assertSame('/files/FilebinderPost/1/logo/thumb_logo.png', $this->Label->_makeSrc($file, ['prefix' => 'thumb_']));
    }

    /**
     * A file outside the webroot has a signed URL of the loader action.
     */
    public function testMakeSrcLoader(): void
    {
        $file = [
            'file_path' => TMP . 'files/FilebinderPost/1/logo/logo.png',
            'model' => 'FilebinderPost',
            'model_id' => '1',
            'field_name' => 'logo',
            'file_name' => 'logo.png',
        ];
        $result = $this->Label->_makeSrc($file);

        $expire = $result['?']['expire'];
        $this->assertEqualsWithDelta(strtotime('+1 minute'), $expire, 5);
        $this->assertSame([
            'plugin' => 'filebinder',
            'controller' => 'filebinder',
            'action' => 'loader',
            'FilebinderPost',
            '1',
            'logo',
            'logo.png',
            '?' => ['key' => FilebinderSignature::sign('FilebinderPost', '1', 'logo', 'logo.png', (string)$expire), 'expire' => $expire],
        ], $result);
    }

    /**
     * The helper keeps no secret in the session, and cannot sign a loader URL with an empty Security.salt.
     */
    public function testMakeSrcEmptySalt(): void
    {
        $file = [
            'file_path' => TMP . 'files/FilebinderPost/1/logo/logo.png',
            'model' => 'FilebinderPost',
            'model_id' => '1',
            'field_name' => 'logo',
            'file_name' => 'logo.png',
        ];
        $this->Label->_makeSrc($file);
        $this->assertNull(CakeSession::read('Filebinder.secret'));

        Configure::write('Security.salt', '');
        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('You cannot sign the URLs of Filebinder with an empty Security.salt.');
        $this->Label->_makeSrc($file);
    }

    /**
     * The sessionKey setting, which the helper no longer uses, is ignored.
     */
    public function testSessionKeyIgnored(): void
    {
        $Label = new LabelHelper($this->View, ['sessionKey' => 'Other']);
        $file = [
            'file_path' => WWW_ROOT . 'files/FilebinderPost/1/logo/logo.png',
            'model' => 'FilebinderPost',
            'model_id' => '1',
            'field_name' => 'logo',
            'file_name' => 'logo.png',
        ];

        $this->assertSame('/files/FilebinderPost/1/logo/logo.png', $Label->url($file));
        $this->assertSame(['Html'], $Label->helpers);
    }

    /**
     * No file, no source.
     */
    public function testMakeSrcNoFile(): void
    {
        $this->assertFalse($this->Label->_makeSrc(null));
        $this->assertFalse($this->Label->_makeSrc([]));
        $this->assertSame('', $this->Label->image(null));
        $this->assertSame('No file', $this->Label->link(null, ['noFile' => 'No file']));
        $this->assertSame('No file', $this->Label->url(null, ['noFile' => 'No file']));
    }

    /**
     * image(), link() and url() of a file under the webroot.
     */
    public function testImageLinkUrl(): void
    {
        $file = [
            'file_path' => WWW_ROOT . 'files/FilebinderPost/1/logo/logo.png',
            'model' => 'FilebinderPost',
            'model_id' => '1',
            'field_name' => 'logo',
            'file_name' => 'logo.png',
        ];
        $this->assertSame('<img src="/files/FilebinderPost/1/logo/logo.png" alt=""/>', $this->Label->image($file));
        $this->assertSame('<a href="/files/FilebinderPost/1/logo/logo.png">logo.png</a>', $this->Label->link($file));
        $this->assertSame('<a href="/files/FilebinderPost/1/logo/logo.png">Logo</a>', $this->Label->link($file, ['title' => 'Logo']));
        $this->assertSame('/files/FilebinderPost/1/logo/logo.png', $this->Label->url($file));
    }

    /**
     * The loader URL of a file of a prefixed page (e.g. admin) has no routing prefix.
     */
    public function testMakeSrcLoaderRoutingPrefixes(): void
    {
        $prefixes = Configure::read('Routing.prefixes');
        $admin = Configure::read('Routing.admin');
        $file = [
            'file_path' => TMP . 'files/FilebinderPost/1/logo/logo.png',
            'model' => 'FilebinderPost',
            'model_id' => '1',
            'field_name' => 'logo',
            'file_name' => 'logo.png',
        ];
        try {
            Configure::write('Routing.prefixes', ['admin', 'manager']);
            $result = $this->Label->_makeSrc($file);
            $this->assertFalse($result['admin']);
            $this->assertFalse($result['manager']);
            $this->assertSame('loader', $result['action']);

            // The Routing.admin setting of CakePHP 1.2
            Configure::write('Routing.prefixes', null);
            Configure::write('Routing.admin', 'admin');
            $result = $this->Label->_makeSrc($file);
            $this->assertFalse($result['admin']);
            $this->assertArrayNotHasKey('manager', $result);
        } finally {
            Configure::write('Routing.prefixes', $prefixes);
            Configure::write('Routing.admin', $admin);
        }
    }

    /**
     * The loader URL of an uploaded file, not saved yet, has the model ID 0 and the name of the uploaded file.
     */
    public function testMakeSrcTmpFile(): void
    {
        $file = [
            'model' => 'FilebinderPost',
            'field_name' => 'logo',
            'file_name' => 'logo.png',
            'tmp_bind_path' => CACHE . 'ring_20261022_abc_logo.png',
        ];

        $result = $this->Label->_makeSrc($file, ['prefix' => 'thumb_']);

        $this->assertSame(['FilebinderPost', 0, 'logo', 'thumb_ring_20261022_abc_logo.png'], [$result[0], $result[1], $result[2], $result[3]]);
        $this->assertSame(FilebinderSignature::sign('FilebinderPost', '0', 'logo', 'thumb_ring_20261022_abc_logo.png', (string)$result['?']['expire']), $result['?']['key']);
    }

    /**
     * image(), link() and url() of a file outside the webroot have the signed URL of the loader action.
     */
    public function testImageLinkUrlLoader(): void
    {
        $file = [
            'file_path' => TMP . 'files/FilebinderPost/1/logo/logo.png',
            'model' => 'FilebinderPost',
            'model_id' => '1',
            'field_name' => 'logo',
            'file_name' => 'logo.png',
        ];
        // The same expiration time, and key, for each URL
        Configure::write('Filebinder.expire', '2100-01-01 00:00:00');
        $src = $this->Label->_makeSrc($file);
        $this->assertSame(strtotime('2100-01-01 00:00:00'), $src['?']['expire']);
        $url = Router::url($src);
        $this->assertStringStartsWith('/filebinder/filebinder/loader/FilebinderPost/1/logo/logo.png?key=', $url);

        // url() escapes the URL for HTML, as HtmlHelper::url()
        $this->assertSame(h($url), $this->Label->url($file));
        $this->assertSame('<img src="' . h($url) . '" alt="Logo"/>', $this->Label->image($file, ['alt' => 'Logo']));
        $this->assertSame('<a href="' . h($url) . '" class="file">logo.png</a>', $this->Label->link($file, ['class' => 'file']));
    }

    /**
     * image() returns noFile when there is no file.
     */
    public function testImageNoFile(): void
    {
        $this->assertSame('No image', $this->Label->image(null, ['noFile' => 'No image']));
        $this->assertSame('', $this->Label->link(['model' => 'FilebinderPost']));
        $this->assertSame('', $this->Label->url(['file_path' => '', 'tmp_bind_path' => '']));
    }

    /**
     * The S3 option without file data or bucket gives noFile.
     */
    public function testS3NoFile(): void
    {
        $this->mockAws();
        Configure::write('Filebinder.S3', ['client' => 'Aws']);
        $file = ['model' => 'FilebinderPost', 'model_id' => '1', 'field_name' => 'logo', 'file_name' => 'logo.png'];

        $this->assertSame('No file', $this->Label->url($file, ['S3' => true, 'noFile' => 'No file']));
        $this->assertSame('', $this->Label->image(null, ['S3' => true]));
    }

    /**
     * The S3 option returns the URL of the file on S3, as the client builds it.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function s3Clients(): array
    {
        return [
            'Aws' => ['Aws', 'https://my-bucket.s3.ap-northeast-1.amazonaws.com/', 'https://my-bucket.s3.us-west-2.amazonaws.com/'],
            'AsyncAws' => ['AsyncAws', 'https://my-bucket.s3.ap-northeast-1.amazonaws.com/', 'https://my-bucket.s3.us-west-2.amazonaws.com/'],
        ];
    }

    /**
     * The S3 option returns the URL of the file on S3, as the S3 client builds it.
     *
     * @dataProvider s3Clients
     */
    public function testMakeS3Url(string $client, string $base, string $legacyRegionBase): void
    {
        $this->mockAws();
        $this->mockAsyncAws();
        Configure::write('Filebinder.S3', ['client' => $client, 'bucket' => 'my-bucket']);
        $file = [
            'model' => 'FilebinderPost',
            'model_id' => '1',
            'field_name' => 'logo',
            'file_name' => 'logo.png',
        ];

        $this->assertSame($base . 'FilebinderPost/1/logo/logo.png', $this->Label->_makeSrc($file, ['S3' => true]));
        $this->assertSame($base . 'FilebinderPost/1/logo/logo.png', $this->Label->_makeSrc($file, ['s3' => true]));
        $this->assertSame($base . 'files/FilebinderPost/1/logo/thumb_logo.png', $this->Label->_makeSrc($file, ['S3' => true, 'url_prefix' => 'files/', 'prefix' => 'thumb_']));

        Configure::write('Filebinder.S3.urlPrefix', 'prefix/');
        $this->assertSame($base . 'prefix/FilebinderPost/1/logo/logo.png', $this->Label->_makeS3Url($file, []));
        $this->assertNull($this->Label->_makeS3Url([], []));

        // The deprecated Filebinder.S3.region takes precedence
        Configure::write('Filebinder.S3.region', 'us-west-2');
        $Label = new LabelHelper($this->View);
        $this->assertSame($legacyRegionBase . 'prefix/FilebinderPost/1/logo/logo.png', $Label->_makeS3Url($file, []));

        Configure::delete('Filebinder.S3.bucket');
        $this->assertNull($Label->_makeS3Url($file, []));
    }

    /**
     * The S3 URLs follow the endpoint of the client configuration.
     *
     * @dataProvider s3Clients
     */
    public function testMakeS3UrlWithEndpoint(string $client): void
    {
        $this->mockAws(['endpoint' => 'https://storage.example.com', 'use_path_style_endpoint' => true]);
        $this->mockAsyncAws(['endpoint' => 'https://storage.example.com', 'pathStyleEndpoint' => 'true']);
        Configure::write('Filebinder.S3', ['client' => $client, 'bucket' => 'my-bucket']);
        $file = ['model' => 'FilebinderPost', 'model_id' => '1', 'field_name' => 'logo', 'file_name' => 'logo.png'];

        $this->assertSame('https://storage.example.com/my-bucket/FilebinderPost/1/logo/logo.png', $this->Label->_makeSrc($file, ['S3' => true]));
    }

    /**
     * A file on S3 with a non-public ACL gets a presigned URL that expires as the loader URLs.
     *
     * @dataProvider s3Clients
     */
    public function testMakeS3UrlPresigned(string $client): void
    {
        $this->mockAws();
        $this->mockAsyncAws();
        Configure::write('Filebinder.S3', ['client' => $client, 'bucket' => 'my-bucket', 'acl' => 'private', 'urlPrefix' => 'files/']);
        $file = ['model' => 'FilebinderPost', 'model_id' => '1', 'field_name' => 'logo', 'file_name' => 'logo.png'];

        $url = $this->Label->_makeSrc($file, ['S3' => true]);
        $this->assertStringStartsWith('https://my-bucket.s3.ap-northeast-1.amazonaws.com/files/FilebinderPost/1/logo/logo.png?', $url);
        $query = $this->_query($url);
        $this->assertArrayHasKey('X-Amz-Signature', $query);
        $this->assertEqualsWithDelta(60, (int)$query['X-Amz-Expires'], 5);

        Configure::write('Filebinder.expire', '+10 minutes');
        $this->assertEqualsWithDelta(600, (int)$this->_query($this->Label->_makeSrc($file, ['S3' => true]))['X-Amz-Expires'], 5);

        foreach (['authenticated-read', 'bucket-owner-read', 'bucket-owner-full-control', 'aws-exec-read'] as $acl) {
            Configure::write('Filebinder.S3.acl', $acl);
            $this->assertArrayHasKey('X-Amz-Signature', $this->_query($this->Label->_makeSrc($file, ['S3' => true])), $acl);
        }
        foreach (['public-read', 'public-read-write'] as $acl) {
            Configure::write('Filebinder.S3.acl', $acl);
            $this->assertSame('https://my-bucket.s3.ap-northeast-1.amazonaws.com/files/FilebinderPost/1/logo/logo.png', $this->Label->_makeSrc($file, ['S3' => true]), $acl);
        }
    }

    /**
     * The bucket, the ACL, the key prefix and the region of the bound field take precedence over Filebinder.S3.
     *
     * @dataProvider s3Clients
     */
    public function testMakeS3UrlFieldSettings(string $client): void
    {
        $this->mockAws();
        $this->mockAsyncAws();
        Configure::write('Filebinder.S3', ['client' => $client, 'bucket' => 'my-bucket', 'acl' => 'public-read']);
        ClassRegistry::init('FilebinderPost')->bindFields = [
            ['field' => 'logo', 'bucket' => 'field-bucket', 'acl' => 'private', 'urlPrefix' => 'field/', 'region' => 'us-west-2'],
            ['field' => 'image'],
        ];
        $file = ['model' => 'FilebinderPost', 'model_id' => '1', 'field_name' => 'logo', 'file_name' => 'logo.png'];

        $url = $this->Label->_makeSrc($file, ['S3' => true]);
        $this->assertStringStartsWith('https://field-bucket.s3.us-west-2.amazonaws.com/field/FilebinderPost/1/logo/logo.png?', $url);
        $this->assertArrayHasKey('X-Amz-Signature', $this->_query($url));

        $file['field_name'] = 'image';
        $this->assertSame('https://my-bucket.s3.ap-northeast-1.amazonaws.com/FilebinderPost/1/image/logo.png', $this->Label->_makeSrc($file, ['S3' => true]));

        // A model name without a model class uses Filebinder.S3
        $file['model'] = 'NoSuchModel';
        $this->assertSame('https://my-bucket.s3.ap-northeast-1.amazonaws.com/NoSuchModel/1/image/logo.png', $this->Label->_makeSrc($file, ['S3' => true]));
    }

    /**
     * The S3 URLs of a model that overrides transferTo() have the key of its files on S3.
     *
     * @dataProvider s3Clients
     */
    public function testMakeS3UrlCustomTransferTo(string $client, string $base): void
    {
        $this->mockAws();
        $this->mockAsyncAws();
        Configure::write('Filebinder.S3', ['client' => $client, 'bucket' => 'my-bucket', 'urlPrefix' => 'files/']);
        ClassRegistry::init('FilebinderCustomPathPost')->bindFields = [['field' => 'logo']];
        $file = ['model' => 'FilebinderCustomPathPost', 'model_id' => '1', 'field_name' => 'logo', 'file_name' => 'logo.png'];

        $this->assertSame($base . 'files/custom/1/logo-logo.png', $this->Label->_makeSrc($file, ['S3' => true]));
        $this->assertSame($base . 'other/custom/1/logo-thumb_logo.png', $this->Label->_makeSrc($file, ['S3' => true, 'url_prefix' => 'other/', 'prefix' => 'thumb_']));

        Configure::write('Filebinder.S3.acl', 'private');
        $url = $this->Label->_makeSrc($file, ['S3' => true]);
        $this->assertStringStartsWith($base . 'files/custom/1/logo-logo.png?', $url);
        $this->assertArrayHasKey('X-Amz-Signature', $this->_query($url));
    }

    /**
     * Returns the query parameters of a URL.
     *
     * @param string $url URL.
     * @return array
     */
    protected function _query(string $url): array
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
