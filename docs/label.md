# Label Helper

The Label helper outputs an image, a link or a URL of a file. You pass it the file data that
`find()` returns in a file field.

## Adding the Helper

The [Ring component](ring.md) adds the `Filebinder.Label` helper to the controller. Without the
component, add the helper yourself:

```php
public $helpers = ['Filebinder.Label'];
```

## Images, Links and URLs

`image()` outputs an `img` tag:

```php
echo $this->Label->image($post['Post']['image'], ['alt' => 'Photo', 'noFile' => 'No image']);
```

`link()` outputs a link to the file:

```php
echo $this->Label->link($post['Post']['image'], ['title' => 'Download']);
```

`url()` returns the URL of the file:

```php
echo $this->Label->url($post['Post']['image']);
```

When there is no file, the three methods return the `noFile` option. Without `noFile`, they
return an empty string.

## Options

| Option | Description |
| --- | --- |
| `prefix` | Prefix of the file name, e.g. `thumb_` for a thumbnail saved next to the file. |
| `noFile` | Returned when there is no file. |
| `title` | Text of the link of `link()`. Default: the file name. |
| `S3` (or `s3`) | Return the URL of the file on S3 instead of the local file. |
| `url_prefix` | With `S3`, the prefix of the key. Default: the `urlPrefix` of the field, or `Filebinder.S3.urlPrefix`. |

The helper passes the other options to `HtmlHelper::image()`, `link()` or `url()`.

## URLs of the Files

The URL depends on where the file is:

| File | URL |
| --- | --- |
| Local file under the webroot | Its path under the webroot, e.g. `/files/Post/1/image/photo.jpg`. Anyone can open it. |
| Local file outside the webroot | A URL of the `loader` action of the plugin. The URL is signed for the session and expires after `Filebinder.expire`. |
| Uploaded file on a confirmation page, in `tmpPath` | A URL of the `loader` action, as above. |
| File on S3 with a public ACL (`public-read`, `public-read-write`) | The URL of the object, e.g. `https://<bucket>.s3.<region>.amazonaws.com/<key>`. |
| File on S3 with another ACL, e.g. `private` | A presigned URL of the object. It expires after `Filebinder.expire`. |

The helper outputs the S3 URLs only with the `S3` option:

```php
echo $this->Label->image($post['Post']['image'], ['S3' => true]);
```

The [S3 client](s3.md#choosing-the-client) builds the S3 URLs with the region or the endpoint
of its configuration. The bucket, the ACL, the key prefix and the region are those of the field
in `$bindFields` of the model, or else those of `Filebinder.S3`. The `loader` action is not used
for the files on S3.

To keep the files on S3 private, set the `acl` of the field, or `Filebinder.S3.acl`, to
`private`. The helper then outputs presigned URLs, which are short-lived. S3 checks their
signature, and the files do not go through your application.

## How the Loader Protects the Files

The `loader` action sends the local files that are outside the webroot. It checks two things in
the URL:

- the signature, in the `key` parameter
- the expiration time

The action does not check whether the user may see the record. The signature stands in for that
check. Your application decides which files a user may see: it checks the authorization in the
controller or the view, then renders the URLs with the Label helper.

The signature is an HMAC-SHA256 with the `Security.salt` of the application. It covers the model,
the ID, the field, the file name, the expiration time and the session ID. So only the URLs that
the application rendered for this session work. If someone changes the model, the ID, the field
or the file name of a URL, the loader answers 404.

Keep these rules in mind:

- Check the authorization before you render the link.
- A URL works only in the session that rendered it, until it expires. Anyone who holds that
  session can use it.
- The URLs expire after `Filebinder.expire`, one minute by default. Set a longer time, such as
  `'+10 minutes'`, if your pages need it.
- A new session ID, for example after a login, invalidates the earlier URLs.
- Keep `Security.salt` secret, and set it to a random value.
- Without `Security.salt` or a session, the Label helper throws a `CakeException` and the loader
  answers 404.

`Filebinder.S3.secret` is a different setting. It is the deprecated secret access key of AWS.
