# 設定

Filebinderの設定は3種類あります。

- [ファイルのフィールド](#bound-fields): モデルの`$bindFields`に、ファイルのフィールドごとに1つずつ書きます
- [ビヘイビアの設定](#behavior-settings): `$actsAs`で`Filebinder.Bindable`ビヘイビアに渡します
- [アプリケーションの設定](#application-settings): `Configure`に`Filebinder`の設定として書きます

<a id="bound-fields"></a>

## ファイルのフィールド

`$bindFields`の要素1つが、ファイルのフィールド1つに対応します。1つのモデルに複数のファイルのフィールドを持たせることもできます。

```php
public $bindFields = [
    ['field' => 'image', 'filePath' => WWW_ROOT . 'files' . DS],
    ['field' => 'document', 'filePath' => ROOT . DS . 'files' . DS],
];
```

各要素には次のキーを書けます。

| キー | デフォルト | 説明 |
| --- | --- | --- |
| `field` | | フィールドの名前。必須です。 |
| `tmpPath` | `CACHE` | `RingComponent::bindUp()`がアップロードされたファイルを移動するディレクトリ。 |
| `filePath` | ビヘイビアの`filePath`設定 | 保存したファイルを置くディレクトリ。`false`にするとローカルに保存しません。 |
| `bucket` | `Filebinder.S3.bucket` | ファイルを置くS3のバケット。 |
| `acl` | `Filebinder.S3.acl`、なければ`public-read` | S3に置くファイルの既定ACL。 |
| `urlPrefix` | `Filebinder.S3.urlPrefix` | S3のキーの接頭辞。たとえば`files/`。 |
| `region` | `Filebinder.S3.region`、なければプラグインの設定 | バケットのAWSリージョン。 |

S3のキーを使うのは、S3のストレージを使うときだけです。[Amazon S3](s3.md)を参照してください。

<a id="behavior-settings"></a>

## ビヘイビアの設定

ビヘイビアの設定は`$actsAs`で渡します。

```php
public $actsAs = [
    'Filebinder.Bindable' => [
        'storage' => [BindableBehavior::STORAGE_DB, BindableBehavior::STORAGE_S3],
        'filePath' => WWW_ROOT . 'files' . DS,
    ],
];
```

ビヘイビアには次の設定があります。

| 設定 | デフォルト | 説明 |
| --- | --- | --- |
| `model` | `'Attachment'` | 添付ファイルのテーブルのモデル。 |
| `filePath` | `WWW_ROOT . 'img' . DS` | `filePath`のないフィールドで、保存したファイルを置くディレクトリ。`false`にするとローカルに保存しません。 |
| `storage` | `BindableBehavior::STORAGE_DB` | ローカルのファイルとは別に、ファイルのコピーを置く場所。値は下の表を参照してください。 |
| `restoreFromStorage` | `true` | ローカルのファイルがないときや、ファイルがレコードより古いときに、`find()`がストレージからファイルを復元します。 |
| `withObject` | `false` | `find()`がファイルの内容をBase64で`file_object`に入れて返します。S3のストレージでは、データベースに内容がなければS3からダウンロードします。 |
| `exchangeFile` | `true` | 新しいファイルで差し替えたとき、古いファイルを削除します。`false`にすると古いファイルのレコードだけを削除します。 |
| `beforeAttach` | `null` | アップロードされたファイルを保存する前に呼ぶコールバック。 |
| `afterAttach` | `null` | ローカルに保存したファイルに対して呼ぶコールバック。 |
| `dirMode` | `0755` | ビヘイビアが作るディレクトリのパーミッション。 |
| `fileMode` | `0644` | ビヘイビアが保存、復元するファイルのパーミッション。 |
| `dbStorage` | | 古い設定です。`true`か`false`を指定すると、データベースのストレージを使うかどうかを上書きします。 |

`storage`に指定できる値は次のとおりです。

| 値 | ストレージ |
| --- | --- |
| `BindableBehavior::STORAGE_DB`、つまり`'Db'` | データベースの`file_object`カラム。 |
| `BindableBehavior::STORAGE_S3`、つまり`'S3'` | Amazon S3。 |
| `[BindableBehavior::STORAGE_DB, BindableBehavior::STORAGE_S3]` | 両方。 |
| `false` | なし。ローカルのファイルだけを残します。 |

ストレージごとの動き、ファイルの復元や差し替えについては[ストレージ](storage.md)で説明します。

<a id="callbacks"></a>

### コールバック

ビヘイビアは、アップロードされたファイルを保存する前に、そのパスを渡して`beforeAttach`を呼びます。コールバックが偽とみなされる値を返すと、保存を中止します。

ビヘイビアは、ローカルに保存したファイルのパスを渡して`afterAttach`を呼びます。`find()`がローカルのファイルを復元したときも呼びます。コールバックが偽とみなされる値を返すと、`afterSave()`や`find()`はそこで処理をやめます。

コールバックには次のいずれかを指定します。

- 関数の名前
- モデルのメソッドの名前
- `[$object, 'method']`や`['ClassName', 'method']`のような呼び出し可能な配列

<a id="changing-settings-at-runtime"></a>

### 実行時に設定を変える

`setSettings()`は、モデルの現在の設定に新しい設定をマージします。

```php
$this->Post->setSettings(['restoreFromStorage' => false]);
```

`getSettings()`は現在の設定を返します。

```php
$settings = $this->Post->getSettings();
```

`withObject()`で`withObject`設定を切り替えられます。

```php
$this->Post->withObject(true);
```

<a id="application-settings"></a>

## アプリケーションの設定

アプリケーションの設定は`Configure::write()`で書きます。たとえば`config/bootstrap.php`に次のように書きます。

```php
Configure::write('Filebinder.expire', '+10 minutes');
```

次の設定があります。

| 設定 | デフォルト | 説明 |
| --- | --- | --- |
| `Filebinder.expire` | `'+1 minute'` | loaderのURLとS3の署名付きURLの有効期間。`strtotime()`の書式で指定します。 |
| `Filebinder.S3.client` | 読み込んだプラグインのクライアント | S3クライアント。`'AsyncAws'`か`'Aws'`を指定します。 |
| `Filebinder.S3.bucket` | | `bucket`のないフィールドで使うS3のバケット。 |
| `Filebinder.S3.acl` | `public-read` | `acl`のないフィールドで使う既定ACL。 |
| `Filebinder.S3.urlPrefix` | | `urlPrefix`のないフィールドで使うS3のキーの接頭辞。 |
| `Filebinder.S3.region` | | 非推奨。`region`のないフィールドで使うリージョン。 |
| `Filebinder.S3.key`、`Filebinder.S3.secret` | | 非推奨。AWSのアクセスキー。 |

S3の設定と非推奨の設定は[Amazon S3](s3.md)で説明します。

Labelヘルパーとloaderには、アプリケーションの`Security.salt`も必要です。[loaderによるファイルの保護](label.md#how-the-loader-protects-the-files)を参照してください。
