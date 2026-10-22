# fusic/filebinderからの移行

このパッケージは`fusic/filebinder`を置き換えます。プラグイン名、設定、保存されるデータ、URLは変わりません。変わったのは一部の動作で、主にS3のストレージとloaderです。

修正の一覧は[CHANGELOG](https://github.com/pieceofcake2/filebinder/blob/2.x/CHANGELOG.md)を参照してください。

<a id="upgrade-steps"></a>

## 移行の手順

パッケージを入れ替えます。

```bash
composer remove fusic/filebinder
composer require pieceofcake2/filebinder
```

S3のストレージを使っている場合は、S3クライアントのプラグインもインストールします。AsyncAwsを使う場合は次のとおりです。

```bash
composer require pieceofcake2/async-aws async-aws/simple-s3
```

AWS SDK for PHPを使う場合は次のとおりです。

```bash
composer require pieceofcake2/aws
```

`CakePlugin::load('AsyncAws')`か`CakePlugin::load('Aws')`でプラグインを読み込みます。[S3クライアントのインストール](s3.md#installing-an-s3-client)を参照してください。

次に、`Filebinder.S3.key`、`Filebinder.S3.secret`、`Filebinder.S3.region`に書いていた認証情報とリージョンを、プラグインの`AsyncAws`か`Aws`の設定に移します。IAMロールなどのデフォルトの認証情報を使ってもかまいません。古い設定も動作し、プラグインの設定より優先されますが、非推奨です。[クライアントの設定](s3.md#client-configuration)を参照してください。

<a id="s3-storage"></a>

## S3のストレージ

- S3のストレージには、pieceofcake2/async-awsかpieceofcake2/awsが必要です。プラグインをインストールし、`CakePlugin::load()`で読み込んでください。AWS SDKだけでは使えません。
- クライアントのプラグインがないと、S3にファイルを保存するときに`FilebinderS3StorageException`が発生します。以前は、ビヘイビアがファイルをローカルに保存し、アップロードしないまま`afterSave()`が`false`を返していました。
- `Filebinder.S3.key`と`Filebinder.S3.secret`がなくても、ビヘイビアはプラグインの設定の認証情報か、デフォルトの認証情報でファイルをアップロードします。以前はアップロードしませんでした。
- フィールドの`region`を、そのフィールドのすべてのS3リクエストで使います。
- アップロード中にS3でエラーが起きると、SDKの元の例外が発生します。
- S3から復元したファイルに`fileMode`を設定します。
- S3に置くファイルの`Content-Type`は、ファイルの内容から決まります。以前のAWS SDKでは拡張子から決まっていました。

<a id="s3-urls-of-the-label-helper"></a>

## LabelヘルパーのS3のURL

- `S3`オプションで出力するS3のURLは、S3クライアントが組み立てます。URLは`https`で、設定のリージョンやエンドポイントを使います。たとえば、`http://<bucket>.s3.amazonaws.com/...`だったURLが`https://<bucket>.s3.<region>.amazonaws.com/...`になります。
- `ssl`オプションは使わなくなりました。
- S3のURLには、S3クライアントのプラグインが必要です。バケットがなければURLは`null`になります。
- S3のURLには、フィールドのバケット、`urlPrefix`、リージョンを使います。
- フィールドのACLか`Filebinder.S3.acl`が`private`などの非公開のACLなら、URLは署名付きになります。

<a id="loader"></a>

## loader

- loaderのURLのキーは、`Security.salt`を使ったHMACになりました。署名にはセッションとファイル名も含まれます。[loaderによるファイルの保護](label.md#how-the-loader-protects-the-files)を参照してください。
- 移行前に作られたloaderのURLは使えなくなります。有効期間は`Filebinder.expire`で決まり、デフォルトでは1分と短いので、そのURLを表示しているページを再読み込みしてください。
- URLは、それを出力したセッションでだけ使えます。
- モデルIDが`0`のアップロードされたファイルについては、loaderはtmpディレクトリの中のファイルだけを送ります。
- `Content-Disposition`ヘッダーでは、引用符で囲んだファイル名から引用符と制御文字を取り除きます。ファイル名全体は`filename*`に入れます。
- loaderは、どのブラウザにもファイルを添付ファイルとして送ります。以前はMSIEにだけ、Shift_JISのファイル名を付けてinlineで送っていました。
- loaderは`X-Content-Type-Options: nosniff`を送ります。

<a id="content-type-and-validation"></a>

## コンテンツタイプとバリデーション

- `file_content_type`は、アップロードされたファイルの内容から判定します。そのため、保存される値と`checkContentType`が調べる値が、ブラウザが送ったタイプと違うことがあります。
- `funcCheckFile`は、ファイルが存在しないと関数を呼ばずに失敗します。
- `'1mb'`のように小文字の単位で書いたファイルサイズを、その単位どおりに解釈します。以前はキロバイトとして解釈していました。

<a id="removed-and-deprecated-settings"></a>

## 削除された設定と非推奨の設定

| 設定 | 状態 |
| --- | --- |
| `Filebinder.secret` | 使わなくなりました。設定から削除してください。 |
| Labelヘルパーの`sessionKey` | 使わなくなり、指定しても無視します。 |
| `Filebinder.S3.key`、`Filebinder.S3.secret` | 非推奨。プラグインの設定かデフォルトの認証情報を使ってください。 |
| `Filebinder.S3.region` | 非推奨。プラグインの設定で`region`を指定してください。 |
