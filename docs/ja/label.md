# Labelヘルパー

Labelヘルパーは、ファイルの画像、リンク、URLを出力します。`find()`がファイルのフィールドに入れて返したファイルの情報を、そのままヘルパーに渡します。

<a id="adding-the-helper"></a>

## ヘルパーを追加する

[Ringコンポーネント](ring.md)は、`Filebinder.Label`ヘルパーをコントローラーに追加します。Ringコンポーネントを使わない場合は、ヘルパーを自分で追加してください。

```php
public $helpers = ['Filebinder.Label'];
```

<a id="images-links-and-urls"></a>

## 画像、リンク、URL

`image()`は`img`タグを出力します。

```php
echo $this->Label->image($post['Post']['image'], ['alt' => 'Photo', 'noFile' => 'No image']);
```

`link()`はファイルへのリンクを出力します。

```php
echo $this->Label->link($post['Post']['image'], ['title' => 'Download']);
```

`url()`の戻り値はファイルのURLです。

```php
echo $this->Label->url($post['Post']['image']);
```

ファイルがないとき、3つのメソッドは`noFile`オプションの値を返します。`noFile`がなければ空文字列を返します。

<a id="options"></a>

## オプション

| オプション | 説明 |
| --- | --- |
| `prefix` | ファイル名の接頭辞。たとえば、ファイルの隣に保存したサムネイルなら`thumb_`です。 |
| `noFile` | ファイルがないときに返す値。 |
| `title` | `link()`のリンクのテキスト。デフォルトはファイル名です。 |
| `S3`または`s3` | ローカルのファイルではなく、S3のファイルのURLを返します。 |
| `url_prefix` | `S3`と一緒に使うキーの接頭辞。デフォルトはフィールドの`urlPrefix`、なければ`Filebinder.S3.urlPrefix`です。 |

ほかのオプションは、`HtmlHelper::image()`、`link()`、`url()`にそのまま渡します。

<a id="urls-of-the-files"></a>

## ファイルのURL

URLは、ファイルの置き場所によって変わります。

| ファイル | URL |
| --- | --- |
| webrootの下のローカルのファイル | webrootの下のパス。たとえば`/files/Post/1/image/photo.jpg`です。誰でも開けます。 |
| webrootの外のローカルのファイル | プラグインの`loader`アクションのURL。セッションに結び付けて署名され、`Filebinder.expire`の時間で期限が切れます。 |
| 確認画面でアップロードした、`tmpPath`にあるファイル | 上と同じく`loader`アクションのURL。 |
| 公開のACLの`public-read`や`public-read-write`を付けたS3のファイル | オブジェクトのURL。たとえば`https://<bucket>.s3.<region>.amazonaws.com/<key>`です。 |
| `private`などそれ以外のACLを付けたS3のファイル | オブジェクトの署名付きURL。`Filebinder.expire`の時間で期限が切れます。 |

ヘルパーがS3のURLを出力するのは、`S3`オプションを指定したときだけです。

```php
echo $this->Label->image($post['Post']['image'], ['S3' => true]);
```

S3のURLは、[S3クライアント](s3.md#choosing-the-client)が設定のリージョンやエンドポイントに合わせて組み立てます。バケット、ACL、キーの接頭辞、リージョンには、モデルの`$bindFields`にあるフィールドの設定を使います。フィールドになければ`Filebinder.S3`の設定を使います。S3のファイルには`loader`アクションを使いません。

S3のファイルを非公開にするには、フィールドの`acl`か`Filebinder.S3.acl`を`private`にします。ヘルパーは有効期間の短い署名付きURLを出力します。署名はS3が確認し、ファイルのデータはアプリケーションを通りません。

<a id="how-the-loader-protects-the-files"></a>

## loaderによるファイルの保護

`loader`アクションは、webrootの外にあるローカルのファイルを送ります。送る前に、URLの次の2点を確認します。

- `key`パラメーターの署名
- 有効期限

`loader`アクションは、ユーザーがそのレコードを見てよいかどうかを確認しません。署名がこの確認の代わりになります。どのファイルをユーザーに見せるかは、アプリケーションが決めます。コントローラーやビューで認可を確認してから、LabelヘルパーでURLを出力してください。

署名は、アプリケーションの`Security.salt`を鍵にしたHMAC-SHA256です。モデル、ID、フィールド、ファイル名、有効期限、セッションIDから計算します。そのため、使えるのは、アプリケーションがそのセッションのために出力したURLだけです。URLのモデル、ID、フィールド、ファイル名を書き換えると、loaderは404を返します。

次の点に注意してください。

- 認可は、リンクを出力する前に確認してください。
- URLは、それを出力したセッションで、期限が切れるまで使えます。そのセッションを持っている人なら誰でも使えます。
- URLの有効期間は`Filebinder.expire`で決まり、デフォルトは1分です。ページに合わせて`'+10 minutes'`のように長くできます。
- ログインなどでセッションIDが変わると、それまでのURLは使えなくなります。
- `Security.salt`はランダムな値にし、外部に漏らさないでください。
- `Security.salt`かセッションがないと、Labelヘルパーは`CakeException`を投げ、loaderは404を返します。

`Filebinder.S3.secret`は別の設定です。これは非推奨になったAWSのシークレットアクセスキーです。
