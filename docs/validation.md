# Validation

The behavior adds validation rules for the file fields. Use them in `$validate` like the other
rules of CakePHP:

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

## Rules

| Rule | Passes when |
| --- | --- |
| `['checkExtension', ['jpg', 'png']]` | The file name has one of the extensions. The check ignores case. A string is also accepted. |
| `['checkContentType', ['image/jpeg', 'image/png']]` | The content type of the file matches one of the types. The types are regular expressions. A string is also accepted. |
| `['checkMaxFileSize', '2MB']` | The file is not larger than the size. |
| `['checkMinFileSize', '1KB']` | The file is not smaller than the size. |
| `['checkFileSize', '2MB']` | Same as `checkMaxFileSize`. |
| `['notEmptyFile']` | A file is uploaded. |
| `['alphaNumericFileName']` | The file name has only letters, digits and dots. |
| `['funcCheckFile', 'checkIllegalCode']` | The function or model method returns true for the path of the uploaded file. |

## File Sizes

The size rules take a number of bytes, or a number with a unit:

| Unit | Example | Bytes |
| --- | --- | --- |
| `K` or `KB` | `'100KB'` | 102400 |
| `M` or `MB` | `'2MB'` | 2097152 |
| `G` or `GB` | `'1G'` | 1073741824 |
| `T` or `TB` | `'1TB'` | 1099511627776 |

The units are case-insensitive, so `'2mb'` is the same as `'2MB'`.

## Content Type

The Ring component detects the content type of an uploaded file from its contents, with the
fileinfo extension. It saves the type in `file_content_type`. It does not use the type that the
browser sent, because a user can change that type.

The behavior detects the type again when it saves the file. The stored type is always the
detected one, whatever the saved data say. When fileinfo cannot tell the type, the type is
`application/octet-stream`.

`checkContentType` checks the detected type. The loader action sends the files with this type and
with `X-Content-Type-Options: nosniff`.

## Checking the Contents

`funcCheckFile` calls a function or a model method with the path of the uploaded file. The rule
passes when the method returns true. It fails without calling the method when the file does not
exist.

This rule calls the `checkIllegalCode()` method of the model:

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

The method rejects the files that contain PHP code:

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
