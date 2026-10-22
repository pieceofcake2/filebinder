<?php

App::uses('View', 'View');
App::uses('SessionHelper', 'View/Helper');
App::uses('LabelHelper', 'Filebinder.View/Helper');

class LabelHelperTest extends CakeTestCase
{
    protected View $View;

    protected LabelHelper $Label;

    public function setUp(): void
    {
        parent::setUp();
        $this->View = new View();
        $this->Label = new LabelHelper($this->View);
        CakeSession::write('Filebinder.secret', 'secret');
    }

    public function tearDown(): void
    {
        CakeSession::delete('Filebinder');
        Configure::delete('Filebinder');
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
            '?' => ['key' => Security::hash('FilebinderPost1logologo.pngsecret' . $expire), 'expire' => $expire],
        ], $result);
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
     * The S3 option returns the URL of the file on S3.
     */
    public function testMakeS3Url(): void
    {
        Configure::write('Filebinder.S3.bucket', 'my-bucket');
        $file = [
            'model' => 'FilebinderPost',
            'model_id' => '1',
            'field_name' => 'logo',
            'file_name' => 'logo.png',
        ];

        $this->assertSame('http://my-bucket.s3.amazonaws.com/FilebinderPost/1/logo/logo.png', $this->Label->_makeSrc($file, ['S3' => true]));
        $this->assertSame('https://my-bucket.s3.amazonaws.com/FilebinderPost/1/logo/logo.png', $this->Label->_makeSrc($file, ['s3' => true, 'ssl' => true]));
        $this->assertSame('http://my-bucket.s3.amazonaws.com/files/FilebinderPost/1/logo/thumb_logo.png', $this->Label->_makeSrc($file, ['S3' => true, 'url_prefix' => 'files/', 'prefix' => 'thumb_']));

        Configure::write('Filebinder.S3.urlPrefix', 'prefix/');
        $this->assertSame('http://my-bucket.s3.amazonaws.com/prefix/FilebinderPost/1/logo/logo.png', $this->Label->_makeS3Url($file, []));
        $this->assertNull($this->Label->_makeS3Url([], []));
    }
}
