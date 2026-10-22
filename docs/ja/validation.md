# バリデーション

ビヘイビアは、ファイルのフィールド用のバリデーションルールを追加します。CakePHPのほかのルールと同じように、`$validate`に書いて使います。

```php
public $validate = [
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
```

<a id="rules"></a>

## ルール

| ルール | 成功する条件 |
| --- | --- |
| `['checkExtension', ['jpg', 'png']]` | ファイル名の拡張子が、指定したいずれかに一致する。大文字と小文字は区別しません。文字列でも指定できます。 |
| `['checkContentType', ['image/jpeg', 'image/png']]` | ファイルのコンテンツタイプが、指定したいずれかに一致する。タイプは正規表現として扱います。文字列でも指定できます。 |
| `['checkMaxFileSize', '2MB']` | ファイルが指定したサイズ以下である。 |
| `['checkMinFileSize', '1KB']` | ファイルが指定したサイズ以上である。 |
| `['checkFileSize', '2MB']` | `checkMaxFileSize`と同じ。 |
| `['notEmptyFile']` | ファイルがアップロードされている。 |
| `['alphaNumericFileName']` | ファイル名が文字、数字、ドットだけでできている。 |
| `['funcCheckFile', 'checkIllegalCode']` | アップロードされたファイルのパスを渡した関数やモデルのメソッドが、trueを返す。 |

<a id="file-sizes"></a>

## ファイルサイズ

サイズのルールに渡せるのは、バイト数か単位付きの数値です。

| 単位 | 例 | バイト数 |
| --- | --- | --- |
| `K`または`KB` | `'100KB'` | 102400 |
| `M`または`MB` | `'2MB'` | 2097152 |
| `G`または`GB` | `'1G'` | 1073741824 |
| `T`または`TB` | `'1TB'` | 1099511627776 |

単位の大文字と小文字は区別しません。`'2mb'`と`'2MB'`は同じ意味です。

<a id="content-type"></a>

## コンテンツタイプ

Ringコンポーネントは、アップロードされたファイルのコンテンツタイプを、fileinfo拡張でファイルの内容から判定します。判定したタイプは`file_content_type`に保存します。ブラウザが送ったタイプはユーザーが書き換えられるので使いません。

ビヘイビアは、ファイルを保存するときにもう一度タイプを判定します。保存するデータに何が入っていても、保存されるのは判定したタイプです。fileinfoでタイプがわからないときは`application/octet-stream`になります。

`checkContentType`が調べるのも、この判定したタイプです。loaderアクションは、このタイプと`X-Content-Type-Options: nosniff`ヘッダーを付けてファイルを送ります。

<a id="checking-the-contents"></a>

## ファイルの内容を調べる

`funcCheckFile`は、アップロードされたファイルのパスを渡して関数かモデルのメソッドを呼びます。メソッドがtrueを返せば成功です。ファイルが存在しないときは、メソッドを呼ばずに失敗します。

次のルールは、モデルの`checkIllegalCode()`メソッドを呼びます。

```php
public $validate = [
    'image' => [
        'illegalCode' => [
            'rule' => ['funcCheckFile', 'checkIllegalCode'],
            'allowEmpty' => true,
        ],
    ],
];
```

このメソッドは、PHPのコードを含むファイルを拒否します。

```php
/**
 * Checks that a file has no PHP code.
 *
 * @param string $filePath Path of the uploaded file.
 * @return bool
 */
public function checkIllegalCode($filePath)
{
    return !preg_match('/<\?php/i', (string)file_get_contents($filePath));
}
```
