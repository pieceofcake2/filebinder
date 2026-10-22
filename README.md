# Filebinder Plugin

[![GitHub License](https://img.shields.io/github/license/pieceofcake2/filebinder?label=License)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/pieceofcake2/filebinder?label=Packagist)](https://packagist.org/packages/pieceofcake2/filebinder)
![PHP](https://img.shields.io/packagist/dependency-v/pieceofcake2/filebinder/php?logo=php&logoColor=%23FFFFFF&label=PHP&labelColor=%23777BB4&color=%23FFFFFF)
![CakePHP](https://img.shields.io/packagist/dependency-v/pieceofcake2/filebinder/pieceofcake2/cakephp?logo=cakephp&logoColor=%23FFFFFF&label=CakePHP&labelColor=%23D33C43&color=%23FFFFFF)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%205-brightgreen?label=PHPStan)](https://github.com/pieceofcake2/filebinder/actions/workflows/CI.yml)
[![CI](https://img.shields.io/github/actions/workflow/status/pieceofcake2/filebinder/CI.yml?label=CI)](https://github.com/pieceofcake2/filebinder/actions/workflows/CI.yml)
[![Codecov](https://img.shields.io/codecov/c/gh/pieceofcake2/filebinder?label=Coverage)](https://codecov.io/gh/pieceofcake2/filebinder)

Simple file attachment for the models of [pieceofcake2/cakephp](https://github.com/pieceofcake2/cakephp)
applications. List the file fields of a model in `$bindFields`, and the `Filebinder.Bindable`
behavior saves the uploaded files.

- Simple settings: no column for the files in the tables of your models
- Several file fields per model, in any number of models
- Files saved in a local directory, in the database and/or on Amazon S3, and restored from the
  database or S3 when the local file is missing
- Validation rules for the extension, the content type and the size of the files
- `Filebinder.Ring` keeps the uploaded files for a confirmation page, and `Filebinder.Label`
  shows them
- Amazon S3 through [pieceofcake2/aws](https://github.com/pieceofcake2/aws) (AWS SDK for PHP) or
  [pieceofcake2/async-aws](https://github.com/pieceofcake2/async-aws) (AsyncAws)

![The attachment table and the path of a saved file](docs/img/filebinder_filepath.png)

## Documentation

- Website: https://pieceofcake2.github.io/filebinder/ (日本語: https://pieceofcake2.github.io/filebinder/ja/)
- Markdown: [docs/README.md](docs/README.md) (日本語: [docs/ja/README.md](docs/ja/README.md)) (for AI tools and offline reading)

It covers the database table, the settings, the validation rules, the helper and the component,
the S3 storage and the migration from `fusic/filebinder`.

The Markdown documentation is also included in the Composer package, in the plugin's directory: `plugins/Filebinder/docs/README.md` (the location depends on your `installer-paths`).

## Requirements

* PHP 8.1+
* pieceofcake2/cakephp 2.12+
* For the S3 storage: pieceofcake2/aws, or pieceofcake2/async-aws with async-aws/simple-s3

## Installation

```bash
composer require pieceofcake2/filebinder
```

Load the plugin in `config/bootstrap.php` (legacy layout: `app/Config/bootstrap.php`):

```php
CakePlugin::load('Filebinder');
```

Then create the `attachments` table. See [Installation](docs/README.md#installation) and
[Database](docs/README.md#database).

## Usage

```php
class Post extends AppModel
{
    public $actsAs = ['Filebinder.Bindable'];

    public $bindFields = [
        ['field' => 'image', 'filePath' => WWW_ROOT . 'files' . DS],
    ];
}
```

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

```php
echo $this->Label->image($post['Post']['image']);
```

See [Usage](docs/README.md#usage) and [Amazon S3](docs/README.md#amazon-s3).

## Contributing

See the [contribution guidelines](https://github.com/pieceofcake2/.github/blob/master/CONTRIBUTING.md).

## Origin

This package is a maintained fork of [fusic/filebinder](https://github.com/fusic/filebinder)
(2.0 branch, `fusic/filebinder` on Packagist), originally by Fusic Co., Ltd. It is maintained for
CakePHP 2 on modern PHP by [pieceofcake2](https://github.com/pieceofcake2). Changes from the
original are listed in the [CHANGELOG](CHANGELOG.md) and the
[commit history since the fork](https://github.com/pieceofcake2/filebinder/compare/aa14aec...2.x).

## License

MIT License. See [LICENSE](LICENSE).
