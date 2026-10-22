# Upgrading from fusic/filebinder

This package replaces `fusic/filebinder`. The plugin name, the settings, the saved data and the
URLs stay the same. Some behavior changed, mostly in the S3 storage and in the loader.

The [CHANGELOG](https://github.com/pieceofcake2/filebinder/blob/2.x/CHANGELOG.md) lists the
fixes.

## Upgrade Steps

Replace the package:

```bash
composer remove fusic/filebinder
composer require pieceofcake2/filebinder
```

If you use the S3 storage, also install an S3 client plugin. For AsyncAws:

```bash
composer require pieceofcake2/async-aws async-aws/simple-s3
```

Or, for the AWS SDK for PHP:

```bash
composer require pieceofcake2/aws
```

Load the plugin, `CakePlugin::load('AsyncAws')` or `CakePlugin::load('Aws')`. See
[Installing an S3 Client](s3.md#installing-an-s3-client).

Then move the credentials and the region out of `Filebinder.S3.key`, `Filebinder.S3.secret` and
`Filebinder.S3.region`. Put them in the configuration of the plugin, `AsyncAws` or `Aws`. You can
also use the default credentials, such as an IAM role. The old settings still work and take
precedence, but they are deprecated. See [Client Configuration](s3.md#client-configuration).

## S3 Storage

- The S3 storage needs pieceofcake2/async-aws or pieceofcake2/aws. The plugin must be installed
  and loaded with `CakePlugin::load()`. The AWS SDK alone is not enough.
- Without a client plugin, saving a file to S3 throws a `FilebinderS3StorageException`. Before,
  the behavior saved the file locally, and `afterSave()` returned `false` without uploading it.
- Without `Filebinder.S3.key` and `Filebinder.S3.secret`, the behavior uploads the files with the
  credentials of the plugin configuration, or with the default credentials. Before, it did not
  upload them.
- The `region` of a field applies to all the S3 requests of the field.
- An S3 error during the upload throws the original exception of the SDK.
- Files restored from S3 get `fileMode`.
- The `Content-Type` of the files on S3 comes from their contents. The AWS SDK set it from the
  extension.

## S3 URLs of the Label Helper

- The S3 client builds the S3 URLs of the helper (the `S3` option). They use `https` and the
  region or the endpoint of the configuration. For example, a URL is now
  `https://<bucket>.s3.<region>.amazonaws.com/...` instead of `http://<bucket>.s3.amazonaws.com/...`.
- The `ssl` option is no longer used.
- The S3 URLs need an S3 client plugin. Without a bucket, the URL is `null`.
- The S3 URLs use the bucket, the `urlPrefix` and the region of the field.
- When the ACL of the field, or `Filebinder.S3.acl`, is not public, e.g. `private`, the URL is
  presigned.

## Loader

- The key of the loader URLs is now an HMAC with `Security.salt`. It covers the session and the
  file name. See [How the Loader Protects the Files](label.md#how-the-loader-protects-the-files).
- Loader URLs created before the upgrade stop working. They are short-lived (`Filebinder.expire`,
  one minute by default), so reload the pages that show them.
- A URL works only in the session that rendered it.
- For the uploaded files (model ID `0`), the loader sends only the files inside their tmp
  directory.
- The quoted file name in the `Content-Disposition` header has no quotes or control characters.
  The header has the full name in `filename*`.
- The loader sends the files as attachments to every browser. Before, MSIE got them inline, with a
  Shift_JIS file name.
- The loader sends `X-Content-Type-Options: nosniff`.

## Content Type and Validation

- `file_content_type` comes from the contents of the uploaded file. The stored value, and what
  `checkContentType` checks, may differ from the type that the browser sent.
- `funcCheckFile` fails when the file does not exist. It does not call the function.
- A file size with a lowercase unit, such as `'1mb'`, is read in that unit. Before, it was read in
  kilobytes.

## Removed and Deprecated Settings

| Setting | Status |
| --- | --- |
| `Filebinder.secret` | No longer used. Remove it from the configuration. |
| `sessionKey` of the Label helper | No longer used and ignored. |
| `Filebinder.S3.key`, `Filebinder.S3.secret` | Deprecated. Use the plugin configuration or the default credentials. |
| `Filebinder.S3.region` | Deprecated. Set `region` in the plugin configuration. |
