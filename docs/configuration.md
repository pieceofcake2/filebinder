# Configuration

Filebinder has three groups of settings:

- [Bound fields](#bound-fields): one entry in `$bindFields` of the model for each file field
- [Behavior settings](#behavior-settings): the settings of the `Filebinder.Bindable` behavior
  in `$actsAs`
- [Application settings](#application-settings): the `Filebinder` settings in `Configure`

## Bound Fields

Each entry of `$bindFields` sets up one file field. A model can have several file fields:

```php
public $bindFields = [
    ['field' => 'image', 'filePath' => WWW_ROOT . 'files' . DS],
    ['field' => 'document', 'filePath' => ROOT . DS . 'files' . DS],
];
```

An entry has these keys:

| Key | Default | Description |
| --- | --- | --- |
| `field` | | Name of the field. Required. |
| `tmpPath` | `CACHE` | Directory where `RingComponent::bindUp()` moves the uploaded files. |
| `filePath` | The `filePath` behavior setting | Directory of the saved files. `false`: do not save the files locally. |
| `bucket` | `Filebinder.S3.bucket` | S3 bucket of the files. |
| `acl` | `Filebinder.S3.acl`, or `public-read` | Canned ACL of the files on S3. |
| `urlPrefix` | `Filebinder.S3.urlPrefix` | Prefix of the keys of the files on S3, e.g. `files/`. |
| `region` | `Filebinder.S3.region`, then the plugin configuration | AWS region of the bucket. |

The S3 keys are used only with the S3 storage. See [Amazon S3](s3.md).

## Behavior Settings

Pass the settings of the behavior in `$actsAs`:

```php
public $actsAs = [
    'Filebinder.Bindable' => [
        'storage' => [BindableBehavior::STORAGE_DB, BindableBehavior::STORAGE_S3],
        'filePath' => WWW_ROOT . 'files' . DS,
    ],
];
```

The behavior has these settings:

| Setting | Default | Description |
| --- | --- | --- |
| `model` | `'Attachment'` | Model of the attachment table. |
| `filePath` | `WWW_ROOT . 'img' . DS` | Directory of the saved files, for the fields without `filePath`. `false`: do not save the files locally. |
| `storage` | `BindableBehavior::STORAGE_DB` | Where the behavior keeps a copy of the files, besides the local files. See below. |
| `restoreFromStorage` | `true` | `find()` restores a local file from the storage when the file does not exist or is older than its record. |
| `withObject` | `false` | `find()` returns the contents of the files in Base64 in `file_object`. With the S3 storage, it downloads them from S3 when the database has none. |
| `exchangeFile` | `true` | When a new file replaces a file, delete the old file. `false`: delete only the record of the old file. |
| `beforeAttach` | `null` | Callback for the uploaded file, before it is saved. |
| `afterAttach` | `null` | Callback for the saved local file. |
| `dirMode` | `0755` | Mode of the directories that the behavior creates. |
| `fileMode` | `0644` | Mode of the files that the behavior saves and restores. |
| `dbStorage` | | Older setting. `true` or `false` overrides whether the behavior uses the database storage. |

`storage` takes one of these values:

| Value | Storage |
| --- | --- |
| `BindableBehavior::STORAGE_DB` (`'Db'`) | The database, in the `file_object` column. |
| `BindableBehavior::STORAGE_S3` (`'S3'`) | Amazon S3. |
| `[BindableBehavior::STORAGE_DB, BindableBehavior::STORAGE_S3]` | Both. |
| `false` | None. Only the local files are kept. |

[Storage](storage.md) explains the storages, restoring and replacing files.

### Callbacks

The behavior calls `beforeAttach` with the path of the uploaded file before it saves the file. If
the callback returns a falsy value, the save is canceled.

The behavior calls `afterAttach` with the path of the saved local file. It also calls it after
`find()` restores a local file. If the callback returns a falsy value, `afterSave()` or `find()`
stops.

A callback is one of these:

- the name of a function
- the name of a method of the model
- a callable array, such as `[$object, 'method']` or `['ClassName', 'method']`

### Changing Settings at Runtime

`setSettings()` merges new settings into the current settings of the model:

```php
$this->Post->setSettings(['restoreFromStorage' => false]);
```

`getSettings()` returns the current settings:

```php
$settings = $this->Post->getSettings();
```

`withObject()` turns the `withObject` setting on or off:

```php
$this->Post->withObject(true);
```

## Application Settings

Write the application settings with `Configure::write()`, for example in `config/bootstrap.php`:

```php
Configure::write('Filebinder.expire', '+10 minutes');
```

These settings exist:

| Setting | Default | Description |
| --- | --- | --- |
| `Filebinder.expire` | `'+1 minute'` | Lifetime of the loader URLs and of the presigned S3 URLs, as a `strtotime()` time. |
| `Filebinder.S3.client` | The client of the loaded plugin | S3 client: `'AsyncAws'` or `'Aws'`. |
| `Filebinder.S3.bucket` | | S3 bucket, for the fields without `bucket`. |
| `Filebinder.S3.acl` | `public-read` | Canned ACL, for the fields without `acl`. |
| `Filebinder.S3.urlPrefix` | | Prefix of the S3 keys, for the fields without `urlPrefix`. |
| `Filebinder.S3.region` | | Deprecated. Region, for the fields without `region`. |
| `Filebinder.S3.key`, `Filebinder.S3.secret` | | Deprecated. Access key of AWS. |

[Amazon S3](s3.md) explains the S3 settings and the deprecated ones.

The Label helper and the loader also need the `Security.salt` of the application. See
[How the Loader Protects the Files](label.md#how-the-loader-protects-the-files).
