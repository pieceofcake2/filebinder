# ストレージ

ビヘイビアは、1つのファイルを最大3か所に保存できます。

- `filePath`で指定したローカルのディレクトリ
- 添付ファイルのテーブルの`file_object`カラム
- Amazon S3

[Labelヘルパー](label.md)は、S3のURLを指定しないかぎりローカルのファイルを表示します。データベースとS3に置くのはファイルのコピーで、ローカルのファイルがなくなったときは`find()`がこのコピーから復元します。

<a id="choosing-the-storage"></a>

## ストレージを選ぶ

コピーの置き場所は、ビヘイビアの`storage`設定で選びます。デフォルトでは、ビヘイビアはデータベースにコピーを置きます。次のモデルは、データベースとS3にコピーを置きます。

```php
public $actsAs = [
    'Filebinder.Bindable' => [
        'storage' => [BindableBehavior::STORAGE_DB, BindableBehavior::STORAGE_S3],
    ],
];
```

次のモデルは、ローカルのファイルだけを残します。

```php
public $actsAs = [
    'Filebinder.Bindable' => [
        'storage' => false,
    ],
];
```

ローカルにファイルを残さない場合は、`filePath`を`false`にします。ビヘイビアはストレージにだけファイルを保存します。

<a id="local-directory"></a>

## ローカルのディレクトリ

ビヘイビアは、次のパスにファイルを保存します。

```text
<filePath>/<model>/<model_id>/<field_name>/<file_name>
```

![保存されたファイルのパス](../img/filebinder_filepath.png)

`filePath`には、フィールドの`filePath`を使います。フィールドになければ、ビヘイビアの`filePath`設定を使います。デフォルトは`WWW_ROOT . 'img' . DS`です。

ビヘイビアは、ディレクトリを`dirMode`の`0755`で、ファイルを`fileMode`の`0644`で作ります。

webrootの下に置いたファイルには公開のURLがあり、誰でも開けます。webrootの外に置いたファイルには、それ自体のURLがありません。Labelヘルパーは、このファイルをプラグインのloaderアクション経由でリンクします。loaderアクションは、先に署名を確認します。[ファイルのURL](label.md#urls-of-the-files)を参照してください。

<a id="custom-paths"></a>

### パスを変える

ファイルを別のパスに保存するには、モデルで`transferTo()`をオーバーライドします。このメソッドは、`filePath`からの相対パスを返します。

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

ビヘイビアは、ファイルを保存するときと、あとでファイルを探すときの両方で`transferTo()`を呼びます。そのため、同じファイルの情報に対しては毎回同じパスを返してください。`mt_rand()`、`uniqid()`、`time()`のような乱数や時刻は使えません。

<a id="database"></a>

## データベース

データベースのストレージでは、ビヘイビアは各ファイルの内容をBase64にして`file_object`カラムに保存します。

`find()`は、デフォルトでは`file_object`を返しません。内容が必要なときは`withObject`を有効にします。

```php
$this->Post->withObject(true);
```

S3のストレージも使っている場合、データベースに内容がなければ、`withObject`はS3から内容をダウンロードします。

<a id="restoring-files"></a>

## ファイルの復元

`find()`は、次のどちらかのときにストレージからローカルのファイルを復元します。

- ローカルのファイルがない
- ローカルのファイルが、レコードの`modified`の時刻より古い

ビヘイビアはデータベースからファイルを復元します。S3のストレージを使っている場合はS3から復元します。復元したファイルには`fileMode`を設定し、そのパスを渡して`afterAttach`コールバックを呼びます。

復元を止めるには、`restoreFromStorage`を`false`にします。

```php
$this->Post->setSettings(['restoreFromStorage' => false]);
```

<a id="replacing-and-deleting-files"></a>

## ファイルの差し替えと削除

すでにファイルのあるフィールドに新しいファイルを保存すると、新しいファイルで差し替わります。デフォルトでは、ビヘイビアは古いファイルを削除します。`exchangeFile`を`false`にすると、古いファイルのレコードだけを削除し、ファイルはそのまま残します。

フィールドのファイルを削除するには、`delete_<field>`に真の値を入れて保存します。フォームにチェックボックスを置くと、この値を送れます。

```php
echo $this->Form->input('delete_image', ['type' => 'checkbox', 'label' => __('Delete the image')]);
```

レコードを削除すると、ビヘイビアはそのレコードのファイルも削除します。

レコードを残してファイルだけを削除するには、`deleteEntity()`にレコードのIDとフィールドを渡します。

```php
$this->Post->deleteEntity($id, 'image');
```

フィールドを省略すると、`deleteEntity()`はレコードのすべてのフィールドのファイルを削除します。
