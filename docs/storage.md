# Storage

The behavior can keep each file in three places:

- a local directory, set by `filePath`
- the database, in the `file_object` column of the attachment table
- Amazon S3

The [Label helper](label.md) shows the local files, unless you ask it for the URL on S3. The
database and S3 keep a copy of each file. When a local file is missing, `find()` restores it from
that copy.

## Choosing the Storage

The `storage` setting of the behavior chooses the copies. By default, the behavior keeps a copy
in the database. This model keeps a copy in the database and on S3:

```php
public $actsAs = [
    'Filebinder.Bindable' => [
        'storage' => [BindableBehavior::STORAGE_DB, BindableBehavior::STORAGE_S3],
    ],
];
```

This model keeps only the local files:

```php
public $actsAs = [
    'Filebinder.Bindable' => [
        'storage' => false,
    ],
];
```

To keep no local files, set `filePath` to `false`. The behavior then saves the files only in the
storage.

## Local Directory

The behavior saves a file in this path:

```text
<filePath>/<model>/<model_id>/<field_name>/<file_name>
```

![The path of a saved file](img/filebinder_filepath.png)

`filePath` is the `filePath` of the field, or else the `filePath` setting of the behavior. The
default is `WWW_ROOT . 'img' . DS`.

The behavior creates the directories with `dirMode` (`0755`) and the files with `fileMode`
(`0644`).

A file under the webroot has a public URL, and anyone can open it. A file outside the webroot
has no URL of its own. The Label helper links it through the loader action of the plugin, which
checks a signature first. See [URLs of the Files](label.md#urls-of-the-files).

### Custom Paths

To save the files in another path, override `transferTo()` in the model. The method returns the
path of a file relative to `filePath`:

```php
/**
 * Returns the path of a file, relative to `filePath`.
 *
 * @param array $data File data.
 * @return string
 */
public function transferTo($data)
{
    return $this->alias . DS . $data['model_id'] . DS . $data['field_name'] . DS . $data['file_name'];
}
```

The behavior calls `transferTo()` when it saves a file and when it finds it again. The method must
return the same path for the same file data every time. Do not use random values or the time,
such as `mt_rand()`, `uniqid()` or `time()`.

## Database

With the database storage, the behavior saves the contents of each file in Base64 in the
`file_object` column.

`find()` does not return `file_object` by default. To get it, turn on `withObject`:

```php
$this->Post->withObject(true);
```

With the S3 storage, `withObject` downloads the contents from S3 when the database has none.

## Restoring Files

`find()` restores a local file from the storage in two cases:

- the local file does not exist
- the local file is older than the `modified` time of its record

The behavior restores the file from the database, or from S3 with the S3 storage. The restored
file gets `fileMode`. The behavior then calls the `afterAttach` callback with its path.

To turn off the restoring, set `restoreFromStorage` to `false`:

```php
$this->Post->setSettings(['restoreFromStorage' => false]);
```

## Replacing and Deleting Files

When you save a new file for a field that has a file, the new file replaces the old one. By
default, the behavior deletes the old file. With `exchangeFile` set to `false`, it deletes only
the record of the old file and leaves the file in place.

To delete the file of a field, save `delete_<field>` with a true value. A checkbox in the form
does this:

```php
echo $this->Form->input('delete_image', ['type' => 'checkbox', 'label' => __('Delete the image')]);
```

When you delete a record, the behavior deletes its files.

`deleteEntity()` deletes the files of a record without deleting the record. Pass the ID of the
record and the field:

```php
$this->Post->deleteEntity($id, 'image');
```

Without a field, `deleteEntity()` deletes the files of all the fields of the record.
