# Filebinder Plugin Documentation

![Filebinder](img/filebinder.png)

Filebinder attaches files to the records of CakePHP 2 models. You list the file fields of a model
in `$bindFields`, and the `Filebinder.Bindable` behavior saves the uploaded files: their data
(name, content type, size) in an attachment table, and the files in a local directory, in the
database and/or on Amazon S3. `find()` returns the file data with the record, and the
`Filebinder.Label` helper shows the files.

The `Filebinder.Ring` component keeps the uploaded files between the requests of a form with a
confirmation page.

- [Installation](#installation)
- [Database](#database)
- [Usage](#usage)
- [Bound Fields](#bound-fields)
- [Behavior Settings](#behavior-settings)
- [Validation](#validation)
- [Deleting and Replacing Files](#deleting-and-replacing-files)
- [Label Helper](#label-helper)
- [Ring Component](#ring-component)
- [Amazon S3](#amazon-s3)
- [Migrating from fusic/filebinder](#migrating-from-fusic-filebinder)
- [Origin](#origin)

## Installation

Requirements: PHP 8.1+ and [pieceofcake2/cakephp](https://github.com/pieceofcake2/cakephp) 2.12+.
For the S3 storage, also [pieceofcake2/aws](https://github.com/pieceofcake2/aws) or
[pieceofcake2/async-aws](https://github.com/pieceofcake2/async-aws) (see [Amazon S3](#amazon-s3)).

Install the plugin with [Composer](https://getcomposer.org/):

```bash
composer require pieceofcake2/filebinder
```

The plugin has the `cakephp-plugin` type, so it is installed into your plugins directory by
`composer/installers`. Set `installer-paths` in your application's `composer.json`:

| Layout | `installer-paths` |
| --- | --- |
| Modern (`config/`, `src/`, `templates/`, `plugins/`) | `"plugins/{$name}/": ["type:cakephp-plugin"]` |
| Legacy (`app/Config/`, `app/Plugin/`) | `"app/Plugin/{$name}/": ["type:cakephp-plugin"]` |

Load the plugin in `config/bootstrap.php` (legacy layout: `app/Config/bootstrap.php`). If you
already use `CakePlugin::loadAll()`, this is not necessary:

```php
CakePlugin::load('Filebinder');
```

## Database

Create the attachment table. The behavior uses the `Attachment` model (the `model` setting), which
does not need a model class:

```sql
CREATE TABLE `attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `model` text NOT NULL,
  `model_id` int(11) NOT NULL,
  `field_name` text NOT NULL,
  `file_name` text NOT NULL,
  `file_content_type` text NOT NULL,
  `file_size` int(11) NOT NULL,
  `file_object` longtext,
  `created` datetime DEFAULT NULL,
  `modified` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

`file_object` holds the contents of the files (Base64) when the database storage is used, which
is the default. With PostgreSQL or SQLite, use the equivalent types (`text` for `file_object`).

The tables of your models do not need a column for the file fields. Filebinder manages them as a
"virtual table":

![A model with one file field](img/filebinder_image.png)

![A model with two file fields](img/filebinder_multi_fields.png)

![Two models with file fields](img/filebinder_multi_models.png)

## Usage

Add the behavior and the file fields to the model, with validation rules:

```php
class Post extends AppModel
{
    public $actsAs = ['Filebinder.Bindable'];

    public $bindFields = [
        [
            'field' => 'image',
            'tmpPath' => '/var/www/html/myapp/tmp/cache/',
            'filePath' => '/var/www/html/myapp/webroot/files/',
        ],
    ];

    public $validate = [
        'title' => ['notBlank'],
        'image' => [
            'allowExtension' => [
                'rule' => ['checkExtension', ['jpg', 'jpeg', 'png']],
                'allowEmpty' => true,
            ],
            'maxFileSize' => [
                'rule' => ['checkMaxFileSize', '2MB'],
                'allowEmpty' => true,
            ],
        ],
    ];
}
```

Add the Ring component to the controller and call `bindUp()` before saving. It moves the uploaded
file to the `tmpPath` of the field and replaces the upload data with the file data that the
behavior saves:

```php
class PostsController extends AppController
{
    public $components = ['Session', 'Filebinder.Ring'];

    public function add()
    {
        if ($this->request->is('post')) {
            $this->Ring->bindUp();
            if ($this->Post->save($this->request->data)) {
                return $this->redirect(['action' => 'index']);
            }
        }
    }
}
```

The form needs the `file` type:

```php
echo $this->Form->create('Post', ['type' => 'file']);
echo $this->Form->input('title');
echo $this->Form->input('image', ['type' => 'file']);
echo $this->Form->end(__('Submit'));
```

`find()` returns the file data in the field:

```php
$this->set('post', $this->Post->findById(1));
```

`$post['Post']['image']` then holds:

```php
[
    'id' => 10,
    'model' => 'Post',
    'model_id' => 1,
    'field_name' => 'image',
    'file_name' => 'photo.jpg',
    'file_content_type' => 'image/jpeg',
    'file_size' => 12345,
    'created' => '2026-10-22 10:00:00',
    'modified' => '2026-10-22 10:00:00',
    'file_path' => '/var/www/html/myapp/webroot/files/Post/1/image/photo.jpg',
    'bindedModel' => 'Attachment',
]
```

The field is `null` when the record has no file. Show the file with the Label helper, which the
Ring component adds to the controller:

```php
echo $this->Label->image($post['Post']['image']);
```

The files are saved in `<filePath>/<model>/<model_id>/<field_name>/<file_name>`:

![The path of a saved file](img/filebinder_filepath.png)

Override `transferTo()` in the model to save the files somewhere else. It must return the same path
for the same file data every time (do not use random values or the time):

```php
public function transferTo($data)
{
    return $this->alias . DS . $data['model_id'] . DS . $data['field_name'] . DS . $data['file_name'];
}
```

## Bound Fields

Each entry of `$bindFields` sets a file field:

| Key | Default | Description |
| --- | --- | --- |
| `field` | | Name of the field. Required. |
| `tmpPath` | `CACHE` | Directory where `RingComponent::bindUp()` moves the uploaded files. |
| `filePath` | The `filePath` setting of the behavior | Directory of the saved files. `false`: do not save the files locally. |
| `bucket` | `Filebinder.S3.bucket` | S3 bucket. |
| `acl` | `Filebinder.S3.acl`, or `public-read` | Canned ACL of the files on S3. |
| `urlPrefix` | `Filebinder.S3.urlPrefix` | Prefix of the keys of the files on S3, e.g. `files/`. |
| `region` | `Filebinder.S3.region`, then the plugin configuration | AWS region of the bucket. |

## Behavior Settings

```php
public $actsAs = [
    'Filebinder.Bindable' => [
        'storage' => [BindableBehavior::STORAGE_DB, BindableBehavior::STORAGE_S3],
        'filePath' => WWW_ROOT . 'files' . DS,
    ],
];
```

| Setting | Default | Description |
| --- | --- | --- |
| `model` | `'Attachment'` | Model of the attachment table. |
| `filePath` | `WWW_ROOT . 'img' . DS` | Directory of the saved files, for the fields without `filePath`. `false`: do not save the files locally. |
| `storage` | `BindableBehavior::STORAGE_DB` | Where the contents of the files are stored, besides the local files: `BindableBehavior::STORAGE_DB` (`'Db'`, the `file_object` column), `BindableBehavior::STORAGE_S3` (`'S3'`), an array of both, or `false`. |
| `restoreFromStorage` | `true` | When a local file does not exist (or is older than its record), `find()` restores it from the storage. |
| `withObject` | `false` | `find()` returns the contents of the files (Base64) in `file_object`. With the S3 storage, they are downloaded from S3 when the database has none. |
| `exchangeFile` | `true` | When a new file replaces a file, delete the old file. `false`: delete only its record. |
| `beforeAttach` | `null` | Callback called with the path of the uploaded file before it is saved. Returning a falsy value cancels the save. |
| `afterAttach` | `null` | Callback called with the path of the saved local file (also after a restore). Returning a falsy value makes `afterSave()` / `find()` stop. |
| `dirMode` | `0755` | Mode of the created directories. |
| `fileMode` | `0644` | Mode of the saved and restored files. |
| `dbStorage` | | Older setting: `true` or `false` overrides whether the database storage is used. |

The callbacks are a function name, a method name of the model, or a callable array such as
`[$object, 'method']` or `['ClassName', 'method']`.

The settings can be changed at runtime with `setSettings()`, read with `getSettings()`, and
`withObject()` changes `withObject`:

```php
$this->Post->setSettings(['restoreFromStorage' => false]);
$this->Post->withObject(true);
```

## Validation

The behavior adds these validation rules for the file fields:

| Rule | Description |
| --- | --- |
| `['checkExtension', ['jpg', 'png']]` | The file name has one of the extensions (case-insensitive). Also accepts a string. |
| `['checkContentType', ['image/jpeg', 'image/png']]` | The content type of the file (detected from its contents) matches one of the types (regular expressions). Also accepts a string. |
| `['checkMaxFileSize', '2MB']` | The file is not larger than the size: bytes, or a number with `K`, `M`, `G` or `T` (and optional `B`), in upper or lower case. |
| `['checkMinFileSize', '1KB']` | The file is not smaller than the size. |
| `['checkFileSize', '2MB']` | Same as `checkMaxFileSize`. |
| `['notEmptyFile']` | A file is uploaded. |
| `['alphaNumericFileName']` | The file name has only letters, digits and dots. |
| `['funcCheckFile', 'checkIllegalCode']` | The function or model method returns true for the path of the uploaded file. Fails when the file does not exist. |

The Ring component detects the content type (`file_content_type`) from the contents of the file
with the fileinfo extension; the type sent by the browser, which a user can change, is not used.
The behavior detects it again when it saves the file, so the stored type is the detected one
whatever the saved data have (`application/octet-stream` when fileinfo cannot tell).
The loader sends the files with this type and `X-Content-Type-Options: nosniff`. Use
`funcCheckFile` to check more of the contents of the file:

```php
public $validate = [
    'image' => [
        'illegalCode' => [
            'rule' => ['funcCheckFile', 'checkIllegalCode'],
            'allowEmpty' => true,
        ],
    ],
];

public function checkIllegalCode($filePath)
{
    return !preg_match('/<\?php/i', (string)file_get_contents($filePath));
}
```

## Deleting and Replacing Files

Save `delete_<field>` with a true value to delete the file of a field:

```php
echo $this->Form->input('delete_image', ['type' => 'checkbox', 'label' => __('Delete the image')]);
```

Saving a new file replaces the old one (see `exchangeFile`). Deleting a record deletes its files.
`deleteEntity()` deletes the files of a record:

```php
$this->Post->deleteEntity($id, 'image');
```

## Label Helper

The Ring component adds the `Filebinder.Label` helper to the controller. Without it, add the helper
yourself:

```php
public $helpers = ['Filebinder.Label'];
```

```php
echo $this->Label->image($post['Post']['image'], ['alt' => 'Photo', 'noFile' => 'No image']);
echo $this->Label->link($post['Post']['image'], ['title' => 'Download']);
echo $this->Label->url($post['Post']['image']);
```

`image()`, `link()` and `url()` return `noFile` (or an empty string) when there is no file.

| Option | Description |
| --- | --- |
| `prefix` | Prefix of the file name, e.g. `thumb_` for a thumbnail saved next to the file. |
| `noFile` | Returned when there is no file. |
| `title` | Text of the link of `link()`. Default: the file name. |
| `S3` (or `s3`) | Return the URL of the file on S3 (see below) instead of the local file. |
| `url_prefix` | With `S3`, the prefix of the key. Default: the `urlPrefix` of the field, or `Filebinder.S3.urlPrefix`. |

The other options are passed to `HtmlHelper::image()`, `link()` or `url()`.

### URLs of the Files

The URL depends on where the file is:

| File | URL |
| --- | --- |
| Local file under the webroot | Its path under the webroot, e.g. `/files/Post/1/image/photo.jpg`. Anyone can open it. |
| Local file outside the webroot, or uploaded file of a confirmation page (in `tmpPath`) | A URL of the `loader` action of the plugin, signed for the session, that expires after `Filebinder.expire`. |
| File on S3 (`S3` option) with a public ACL (`public-read`, `public-read-write`) | The URL of the object, as the [S3 client](#s3-client) builds it with its region or endpoint, e.g. `https://<bucket>.s3.<region>.amazonaws.com/<key>`. |
| File on S3 (`S3` option) with another ACL (e.g. `private`) | A presigned URL of the object, which expires after `Filebinder.expire`. |

The bucket, the ACL, the key prefix and the region of a file on S3 are those of its field in
`$bindFields` of the model, or of `Filebinder.S3`. The `loader` action is not used for the files on
S3.

To keep the files on S3 private, set the `acl` of the field (or `Filebinder.S3.acl`) to `private`.
The helper then outputs presigned URLs, which are short-lived, and S3 checks their signature; the
files are not sent through the application.

### How the Loader Protects the Files

The `loader` action checks the signature (the `key` parameter) and the expiration time of the URL.
It does not check whether the user may see the record. The signature stands in for that check: the
application decides which files a user may see by rendering their URLs with the Label helper, after
its own authorization in the controller or the view. The key is an HMAC-SHA256, with the
`Security.salt` of the application, of the model, the ID, the field, the file name, the expiration
time and the session ID, so only the URLs that the application rendered for this session work, and
changing the model, the ID, the field or the file name of a URL gives a 404.

- Do the authorization before you render the link.
- A URL works only in the session that rendered it, for anyone who holds that session, until it
  expires (`Filebinder.expire`, one minute by default, e.g. `'+10 minutes'`). A new session ID,
  e.g. after a login, invalidates the earlier URLs.
- Keep `Security.salt` secret and set it to a random value. Without `Security.salt` or a session,
  the Label helper throws a `CakeException` and the loader answers 404.
- `Filebinder.S3.secret` is a different setting: the (deprecated) secret access key of AWS.

## Ring Component

```php
public $components = ['Session', 'Filebinder.Ring'];
```

| Method | Description |
| --- | --- |
| `bindUp($modelName = null, $autoBindDown = false)` | Moves the uploaded files of the request data to the `tmpPath` of the fields and replaces them with their file data. When no file is uploaded, restores the file data kept in the session by `bindDown()`. With `$autoBindDown`, calls `bindDown()` before rendering. |
| `bindDown($modelName = null)` | Keeps the file data of the request data in the session, except for the fields with validation errors. |

For a form with a confirmation page, call `bindUp()` on each request: the file uploaded on the
form is kept in the session and restored on the confirmation and the save.

## Amazon S3

The S3 storage saves the files on Amazon S3, in addition to the local files and the database:

```php
public $actsAs = [
    'Filebinder.Bindable' => [
        'storage' => [BindableBehavior::STORAGE_DB, BindableBehavior::STORAGE_S3],
    ],
];

public $bindFields = [
    [
        'field' => 'image',
        'filePath' => '/var/www/html/myapp/webroot/files/',
        'bucket' => 'my-bucket',
        'acl' => 'private',
    ],
];
```

The files are saved with the key `<urlPrefix><model>/<model_id>/<field_name>/<file_name>`. When a
local file does not exist, `find()` downloads it (see `restoreFromStorage`).

### S3 Client

The S3 storage uses [AsyncAws](https://async-aws.com) of
[pieceofcake2/async-aws](https://github.com/pieceofcake2/async-aws), or the AWS SDK for PHP of
[pieceofcake2/aws](https://github.com/pieceofcake2/aws). Install and load one of them.

For AsyncAws, install pieceofcake2/async-aws with async-aws/simple-s3:

```bash
composer require pieceofcake2/async-aws async-aws/simple-s3
```

Load it:

```php
CakePlugin::load('AsyncAws');
```

For the AWS SDK for PHP, install pieceofcake2/aws:

```bash
composer require pieceofcake2/aws
```

Load it:

```php
CakePlugin::load('Aws');
```

The client of the loaded plugin is used, `AsyncAws` when both are loaded. Choose it with
`Filebinder.S3.client`:

```php
Configure::write('Filebinder.S3.client', 'AsyncAws');
```

The plugin must be loaded, also when the AWS SDK for PHP is installed. When no client can be used,
saving a file throws a `FilebinderS3StorageException` that says what to install and load.

### Configuration

The clients are created with the configuration of the plugin: `Aws` for pieceofcake2/aws, `AsyncAws`
(or `Aws` when it is not set) for pieceofcake2/async-aws. When no credentials are configured, the
SDKs use their default credentials: environment variables, the shared credentials file, and the
IAM role of the EC2 instance, ECS task or Lambda function.

```php
Configure::write('Aws', [
    'region' => 'ap-northeast-1',
]);
Configure::write('Filebinder.S3', [
    'bucket' => 'my-bucket',
    'acl' => 'private',
    'urlPrefix' => 'files/',
]);
```

| Setting | Description |
| --- | --- |
| `Filebinder.S3.client` | `'Aws'` or `'AsyncAws'`. Default: the client of the loaded plugin. |
| `Filebinder.S3.bucket` | Bucket, for the fields without `bucket`. |
| `Filebinder.S3.acl` | Canned ACL, for the fields without `acl`. Default: `public-read`. |
| `Filebinder.S3.urlPrefix` | Prefix of the keys, for the fields without `urlPrefix`. |
| `Filebinder.S3.region` | Deprecated: set `region` in the plugin configuration. Region, for the fields without `region`; overrides the plugin configuration. |
| `Filebinder.S3.key`, `Filebinder.S3.secret` | Deprecated: set the credentials in the plugin configuration, or use the default credentials. Access key, which overrides the plugin configuration. |

The `Content-Type` of the files on S3 is detected from their contents with the fileinfo extension,
as `file_content_type`, not from the type sent by the browser or the extension of the file.

### S3 Storage API

`s3Storage()` returns the S3 storage of a field, an instance of `FilebinderS3StorageInterface`
configured as for the behavior:

```php
$storage = $this->Post->s3Storage('image');
$this->set('imageUrl', $storage->url('my-bucket', 'files/Post/1/image/photo.jpg'));
```

| Method | Description |
| --- | --- |
| `put(string $bucket, string $key, string $file, array $options = []): void` | Uploads a local file. Options: `ACL`. |
| `get(string $bucket, string $key): string` | Returns the contents of an object. |
| `saveAs(string $bucket, string $key, string $file): void` | Downloads an object to a local file. |
| `delete(string $bucket, string $key): void` | Deletes an object. |
| `url(string $bucket, string $key): string` | URL of an object (not signed), as the client builds it. |
| `presignedUrl(string $bucket, string $key, DateTimeImmutable $expires): string` | Presigned URL to download an object, which expires at `$expires`. |

`FilebinderS3StorageFactory::create(array $args = [])` creates the storage of the configured client
with client options (`region`, `credentials`, ...), and `FilebinderS3StorageFactory::client()`
returns the name of that client. Load them with
`App::uses('FilebinderS3StorageFactory', 'Filebinder.Storage')`.

## Migrating from fusic/filebinder

This package replaces `fusic/filebinder`. The plugin name, the settings, the saved data and the
URLs are the same.

1. Replace the package. For the S3 storage, also install an S3 client plugin:

   ```bash
   composer remove fusic/filebinder
   composer require pieceofcake2/filebinder
   composer require pieceofcake2/aws
   ```

2. For the S3 storage, load the plugin (`CakePlugin::load('Aws')`) and move the credentials and the
   region from `Filebinder.S3.key`, `Filebinder.S3.secret` and `Filebinder.S3.region` to the `Aws`
   configuration, or use the default credentials (e.g. an IAM role). The old settings still work and
   take precedence, but they are deprecated.

Changes in behavior:

- The S3 storage needs pieceofcake2/aws (or pieceofcake2/async-aws), installed and loaded with
  `CakePlugin::load()`; the AWS SDK alone is not enough. Without it, saving a file to S3 throws a
  `FilebinderS3StorageException`. Before, the file was saved locally, and `afterSave()` returned
  `false` without uploading it.
- Without `Filebinder.S3.key` and `Filebinder.S3.secret`, the files are uploaded with the
  credentials of the plugin configuration or the default credentials. Before, they were not
  uploaded.
- The `region` of a field is used for all the S3 requests of the field.
- An S3 error during the upload throws the original exception of the SDK.
- `funcCheckFile` fails when the file does not exist, without calling the function.
- File sizes with a lowercase unit (`'1mb'`) are read as that unit; they were read as kilobytes.
- Files restored from S3 get `fileMode`.
- The `Content-Type` of the files uploaded to S3 is detected from their contents; the AWS SDK set it
  from the extension.
- `file_content_type` is detected from the contents of the uploaded file, so the stored value, and
  what `checkContentType` checks, may differ from the type that the browser sent. The loader also
  sends `X-Content-Type-Options: nosniff`.
- The S3 URLs of the Label helper (`S3` option) are built by the S3 client: they use `https` and the
  region or the endpoint of the configuration (e.g. `https://<bucket>.s3.<region>.amazonaws.com/...`
  instead of `http://<bucket>.s3.amazonaws.com/...`), and the `ssl` option is no longer used. They
  need an S3 client plugin, and are `null` without a bucket.
- The S3 URLs of the Label helper use the bucket, `urlPrefix` and region of the field, and are
  presigned when the ACL of the field (or `Filebinder.S3.acl`) is not public, e.g. `private`.
- The key of the loader URLs is an HMAC with `Security.salt`, bound to the session and to the file
  name. Loader URLs created before the upgrade stop working; they are short-lived
  (`Filebinder.expire`, one minute by default), so reload the pages that show them. A URL works
  only in the session that rendered it.
- The loader sends only the files inside the tmp directory for the uploaded files (model ID `0`).
- `Filebinder.secret` is no longer used and can be removed from the configuration.
- The `Content-Disposition` header of the loader has no quotes or control characters in the quoted
  file name, and has the full name in `filename*`.
- The loader sends the files as attachments to every browser. MSIE got them inline, with a
  Shift_JIS file name.

See the [CHANGELOG](https://github.com/pieceofcake2/filebinder/blob/2.x/CHANGELOG.md) for the fixes.

## Origin

This package is a maintained fork of [fusic/filebinder](https://github.com/fusic/filebinder)
(2.0 branch, `fusic/filebinder` on Packagist), originally by [Fusic Co., Ltd.](https://fusic.co.jp).
It is maintained for CakePHP 2 on modern PHP by [pieceofcake2](https://github.com/pieceofcake2).
Changes from the original are listed in the
[commit history since the fork](https://github.com/pieceofcake2/filebinder/compare/aa14aec...2.x).
