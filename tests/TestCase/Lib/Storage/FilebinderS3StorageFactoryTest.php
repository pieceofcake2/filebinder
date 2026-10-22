<?php

App::uses('FilebinderS3StorageFactory', 'Filebinder.Storage');

/**
 * Factory with S3 client classes that are not installed
 */
class FilebinderS3StorageFactoryWithoutClients extends FilebinderS3StorageFactory
{
    /**
     * @inheritDoc
     */
    protected static function clients(): array
    {
        $clients = parent::clients();
        foreach ($clients as $name => $client) {
            $clients[$name]['class'] = $client['class'] . 'NotInstalled';
        }

        return $clients;
    }
}

class FilebinderS3StorageFactoryTest extends CakeTestCase
{
    use MockS3Trait;

    /**
     * Paths of the plugins of the S3 clients
     *
     * @var array<string, string>
     */
    protected array $pluginPaths = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->pluginPaths = ['Aws' => CakePlugin::path('Aws'), 'AsyncAws' => CakePlugin::path('AsyncAws')];
        $this->mockAws();
        $this->mockAsyncAws();
    }

    public function tearDown(): void
    {
        foreach ($this->pluginPaths as $plugin => $path) {
            if (!CakePlugin::loaded($plugin)) {
                CakePlugin::load($plugin, ['path' => $path]);
            }
        }
        Configure::delete('Filebinder');
        Configure::delete('Aws');
        Configure::delete('AsyncAws');
        parent::tearDown();
    }

    /**
     * Aws is used when both plugins are loaded.
     */
    public function testBothPluginsLoaded(): void
    {
        $this->assertSame('Aws', FilebinderS3StorageFactory::client());
        $this->assertInstanceOf(FilebinderAwsS3Storage::class, FilebinderS3StorageFactory::create());
    }

    /**
     * AsyncAws is used when only its plugin is loaded, also when the AWS SDK is installed.
     */
    public function testAsyncAwsPluginLoaded(): void
    {
        CakePlugin::unload('Aws');

        $this->assertSame('AsyncAws', FilebinderS3StorageFactory::client());
        $this->assertInstanceOf(FilebinderAsyncAwsS3Storage::class, FilebinderS3StorageFactory::create());
    }

    /**
     * Without the plugins, the exception says what to install and load, also when the AWS SDK is installed.
     */
    public function testNoPlugin(): void
    {
        CakePlugin::unload('Aws');
        CakePlugin::unload('AsyncAws');

        $this->expectException(FilebinderS3StorageException::class);
        $this->expectExceptionMessage("The S3 storage of Filebinder requires pieceofcake2/aws or pieceofcake2/async-aws. Install one with: composer require pieceofcake2/aws (or: composer require pieceofcake2/async-aws async-aws/simple-s3), and load it with CakePlugin::load('Aws') (or CakePlugin::load('AsyncAws')).");
        FilebinderS3StorageFactory::client();
    }

    /**
     * Filebinder.S3.client chooses the client.
     */
    public function testConfiguredClient(): void
    {
        Configure::write('Filebinder.S3.client', 'AsyncAws');
        $this->assertSame('AsyncAws', FilebinderS3StorageFactory::client());
        $this->assertInstanceOf(FilebinderAsyncAwsS3Storage::class, FilebinderS3StorageFactory::create());

        Configure::write('Filebinder.S3.client', 'Aws');
        $this->assertSame('Aws', FilebinderS3StorageFactory::client());
        $this->assertInstanceOf(FilebinderAwsS3Storage::class, FilebinderS3StorageFactory::create());
    }

    /**
     * The Aws client needs its plugin, also when the AWS SDK is installed.
     */
    public function testConfiguredAwsWithoutPlugin(): void
    {
        CakePlugin::unload('Aws');
        Configure::write('Filebinder.S3.client', 'Aws');

        $this->expectException(FilebinderS3StorageException::class);
        $this->expectExceptionMessage("The Aws client of the Filebinder S3 storage requires the Aws plugin. Install it with: composer require pieceofcake2/aws, and load it with CakePlugin::load('Aws').");
        FilebinderS3StorageFactory::client();
    }

    /**
     * The AsyncAws client needs its plugin.
     */
    public function testConfiguredAsyncAwsWithoutPlugin(): void
    {
        CakePlugin::unload('AsyncAws');
        Configure::write('Filebinder.S3.client', 'AsyncAws');

        $this->expectException(FilebinderS3StorageException::class);
        $this->expectExceptionMessage("The AsyncAws client of the Filebinder S3 storage requires the AsyncAws plugin. Install it with: composer require pieceofcake2/async-aws, and load it with CakePlugin::load('AsyncAws').");
        FilebinderS3StorageFactory::client();
    }

    /**
     * The AsyncAws client needs async-aws/simple-s3.
     */
    public function testConfiguredAsyncAwsWithoutSimpleS3(): void
    {
        Configure::write('Filebinder.S3.client', 'AsyncAws');

        $this->expectException(FilebinderS3StorageException::class);
        $this->expectExceptionMessage('The AsyncAws client of the Filebinder S3 storage requires async-aws/simple-s3. Install it with: composer require async-aws/simple-s3');
        FilebinderS3StorageFactoryWithoutClients::client();
    }

    /**
     * The loaded AsyncAws plugin without async-aws/simple-s3.
     */
    public function testAsyncAwsPluginLoadedWithoutSimpleS3(): void
    {
        CakePlugin::unload('Aws');

        $this->expectException(FilebinderS3StorageException::class);
        $this->expectExceptionMessage('requires async-aws/simple-s3');
        FilebinderS3StorageFactoryWithoutClients::client();
    }

    /**
     * An unknown client.
     */
    public function testUnknownClient(): void
    {
        Configure::write('Filebinder.S3.client', 'Minio');

        $this->expectException(FilebinderS3StorageException::class);
        $this->expectExceptionMessage('Filebinder.S3.client must be "Aws" or "AsyncAws", not "Minio".');
        FilebinderS3StorageFactory::client();
    }

    /**
     * create() passes the options to the client.
     */
    public function testCreateWithArgs(): void
    {
        $storage = FilebinderS3StorageFactory::create(['region' => 'eu-west-1']);

        $this->assertInstanceOf(FilebinderAwsS3Storage::class, $storage);
        $this->assertSame('eu-west-1', $storage->client()->getRegion());
    }
}
