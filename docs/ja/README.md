# Filebinderドキュメント

![Filebinder](../img/filebinder.png)

Filebinderは、CakePHP 2のモデルのレコードにファイルを添付するプラグインです。モデルの`$bindFields`にファイルのフィールドを書いておくと、`Filebinder.Bindable`ビヘイビアがアップロードされたファイルを保存します。

ビヘイビアは、ファイル名やコンテンツタイプ、サイズといったファイルの情報を添付ファイルのテーブルに保存します。ファイル本体の保存先はローカルのディレクトリ、データベース、Amazon S3の3つで、複数を組み合わせることもできます。`find()`を実行すると、レコードと一緒にファイルの情報が返ります。

コントローラーとビューのためのクラスもあります。`Filebinder.Ring`コンポーネントは、アップロードされたファイルを保存できる形に整えます。確認画面のあるフォームでは、リクエストをまたいでファイルを保持します。`Filebinder.Label`ヘルパーは、ファイルを画像、リンク、URLとして出力します。

- [動作要件](#requirements)
- [インストール](#installation)
- [データベース](#database)
- [クイックスタート](#quick-start)
- [設定](configuration.md): ファイルのフィールド、ビヘイビアの設定、アプリケーションの設定
- [ストレージ](storage.md): ローカルのファイル、データベース、ファイルの復元、差し替え、削除
- [Amazon S3](s3.md): S3クライアント、認証情報、公開と非公開のファイル
- [バリデーション](validation.md): 拡張子、コンテンツタイプ、サイズのルール
- [Ringコンポーネント](ring.md): アップロードと確認画面のあるフォーム
- [Labelヘルパー](label.md): ファイルの画像、リンク、URLと、loaderによるファイルの保護
- [fusic/filebinderからの移行](upgrading.md)
- [由来](#origin)

<a id="requirements"></a>

## 動作要件

- PHP 8.1以上
- [pieceofcake2/cakephp](https://github.com/pieceofcake2/cakephp) 2.12以上
- S3に保存する場合は、[pieceofcake2/async-aws](https://github.com/pieceofcake2/async-aws)とasync-aws/simple-s3、または[pieceofcake2/aws](https://github.com/pieceofcake2/aws)。詳しくは[Amazon S3](s3.md)を参照してください。

<a id="installation"></a>

## インストール

[Composer](https://getcomposer.org/)でプラグインをインストールします。

```bash
composer require pieceofcake2/filebinder
```

プラグインのタイプは`cakephp-plugin`なので、`composer/installers`がプラグインのディレクトリにインストールします。アプリケーションの`composer.json`で`installer-paths`を設定してください。

| 構成 | `installer-paths` |
| --- | --- |
| モダン構成の`config/`、`src/`、`templates/`、`plugins/` | `"plugins/{$name}/": ["type:cakephp-plugin"]` |
| レガシー構成の`app/Config/`、`app/Plugin/` | `"app/Plugin/{$name}/": ["type:cakephp-plugin"]` |

`config/bootstrap.php`でプラグインを読み込みます。レガシー構成では`app/Config/bootstrap.php`です。すでに`CakePlugin::loadAll()`を呼んでいる場合、この手順は要りません。

```php
CakePlugin::load('Filebinder');
```

<a id="database"></a>

## データベース

Filebinderはファイルの情報を添付ファイルのテーブルに保存します。次のテーブルを作ってください。

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

PostgreSQLやSQLiteでは、対応する型を使ってください。たとえば`file_object`は`text`にします。

`file_object`には、ファイルの内容をBase64で保存します。ビヘイビアがこのカラムに書き込むのは、データベースのストレージを使うときだけです。デフォルトではデータベースのストレージを使います。詳しくは[ストレージ](storage.md)を参照してください。

ビヘイビアは`Attachment`モデルを通してこのテーブルを読み書きします。このモデルのクラスを作る必要はありません。別のモデルを使うには、ビヘイビアの`model`設定を変えます。[設定](configuration.md#behavior-settings)を参照してください。

モデルのテーブルには、ファイルのフィールドのカラムを追加しなくてかまいません。Filebinderはファイルのフィールドを「仮想テーブル」のように扱います。

![ファイルのフィールドが1つのモデル](../img/filebinder_image.png)

![ファイルのフィールドが2つのモデル](../img/filebinder_multi_fields.png)

![ファイルのフィールドを持つ2つのモデル](../img/filebinder_multi_models.png)

<a id="quick-start"></a>

## クイックスタート

ここで扱うのは、`Post`モデルに`image`フィールドを追加する例です。フォームから2MBまでのJPEGかPNGのファイルをアップロードし、ビューで表示します。

<a id="model"></a>

### モデル

モデルにビヘイビアを追加し、ファイルのフィールドを`$bindFields`に書きます。ファイルのチェックには、ビヘイビアのバリデーションルールを使います。

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

`tmpPath`は、アップロードされたファイルを保存前に置くディレクトリです。`filePath`は、保存したファイルを置くディレクトリです。フィールドに書けるキーは[設定](configuration.md#bound-fields)に、ルールは[バリデーション](validation.md)にまとめてあります。

<a id="controller"></a>

### コントローラー

コントローラーにRingコンポーネントを追加し、データを保存する前に`bindUp()`を呼びます。

```php
class PostsController extends AppController
{
    public $components = ['Session', 'Filebinder.Ring'];

    /**
     * Adds a post.
     *
     * @return \CakeResponse|null
     */
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

`bindUp()`は、アップロードされたファイルをフィールドの`tmpPath`に移動します。そのうえで、リクエストデータにあるアップロードの情報を、ビヘイビアが保存するファイルの情報に置き換えます。詳しくは[Ringコンポーネント](ring.md)を参照してください。

<a id="form"></a>

### フォーム

フォームには`file`タイプを指定します。

```php
echo $this->Form->create('Post', ['type' => 'file']);
echo $this->Form->input('title');
echo $this->Form->input('image', ['type' => 'file']);
echo $this->Form->end(__('Submit'));
```

<a id="reading-the-file-data"></a>

### ファイルの情報を読む

`find()`は、ファイルのフィールドにファイルの情報を入れて返します。

```php
$this->set('post', $this->Post->findById(1));
```

`$post['Post']['image']`の中身は次の配列です。

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

ファイルのないレコードでは、フィールドの値は`null`です。

<a id="showing-the-file"></a>

### ファイルを表示する

RingコンポーネントはLabelヘルパーもコントローラーに追加します。ヘルパーにファイルの情報を渡してください。

```php
echo $this->Label->image($post['Post']['image']);
```

ヘルパーは`img`タグを出力します。リンクやURL、非公開のファイルについては[Labelヘルパー](label.md)を参照してください。

<a id="origin"></a>

## 由来

このパッケージは、[株式会社Fusic](https://fusic.co.jp)が開発した[fusic/filebinder](https://github.com/fusic/filebinder)をフォークしたものです。フォーク元は2.0ブランチで、Packagistでは`fusic/filebinder`として公開されていました。モダンなPHPで動くCakePHP 2のために、[pieceofcake2](https://github.com/pieceofcake2)がメンテナンスしています。元のプロジェクトからの変更は[フォーク以降のコミット履歴](https://github.com/pieceofcake2/filebinder/compare/aa14aec...2.x)で確認できます。
