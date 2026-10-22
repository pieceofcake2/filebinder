# CHANGELOG

## 2.9.0 - Unreleased

First release of `pieceofcake2/filebinder`, a maintained fork of
[fusic/filebinder](https://github.com/fusic/filebinder) (2.0 branch). It replaces the
`fusic/filebinder` package. See [Migrating from fusic/filebinder](docs/README.md#migrating-from-fusic-filebinder).

### Requirements

- Requires PHP 8.1+ and pieceofcake2/cakephp 2.12+
- The AWS SDK for PHP is no longer required. The S3 storage needs
  [pieceofcake2/aws](https://github.com/pieceofcake2/aws) or
  [pieceofcake2/async-aws](https://github.com/pieceofcake2/async-aws) with `async-aws/simple-s3`

### S3 storage

- The S3 storage goes through `FilebinderS3StorageInterface`, with `FilebinderAwsS3Storage` (the AWS
  SDK for PHP of pieceofcake2/aws) and `FilebinderAsyncAwsS3Storage` (AsyncAws of
  pieceofcake2/async-aws)
- New `Filebinder.S3.client` setting (`'Aws'` or `'AsyncAws'`). When it is not set, the client of
  the loaded plugin is used, `AsyncAws` when both are loaded. The plugin must be loaded, also when the
  AWS SDK for PHP is installed: without it, saving to S3 throws a `FilebinderS3StorageException` that
  names the package to install and load (it returned `false` before, without uploading)
- The S3 clients are configured with the `Aws` configuration of pieceofcake2/aws, or the
  `AsyncAws` configuration of pieceofcake2/async-aws, so the credentials can come from the
  environment, an IAM role, etc. `Filebinder.S3.key`, `Filebinder.S3.secret` and
  `Filebinder.S3.region` are deprecated but still work and take precedence. Without
  `Filebinder.S3.key` and `Filebinder.S3.secret`, the files are now uploaded with the credentials
  of the plugin configuration (they were not uploaded to S3 before)
- The `region` of a bound field is used for all the S3 requests of the field; it was used only to
  get the file for `withObject`
- The S3 URLs of `LabelHelper` (`S3` option) come from `url()` of the S3 storage, so they follow
  the region or the endpoint of the client, and use `https`: the URL form may change (e.g.
  `https://<bucket>.s3.<region>.amazonaws.com/...` instead of `http://<bucket>.s3.amazonaws.com/...`).
  `urlPrefix` and `url_prefix` work as before; the `ssl` option is no longer used. Without
  `Filebinder.S3.bucket`, the URL is `null`
- For a file on S3 whose ACL is not public (anything but `public-read` and `public-read-write`, e.g.
  `private`), `LabelHelper` outputs a presigned URL that expires after `Filebinder.expire` (one
  minute by default). The S3 URLs use the bucket, ACL, `urlPrefix` and region of the field in
  `$bindFields`, then `Filebinder.S3`; they used only `Filebinder.S3.bucket` and
  `Filebinder.S3.urlPrefix`. New `presignedUrl()` in `FilebinderS3StorageInterface`
- New `s3Storage()` behavior method (`$this->Post->s3Storage('image')`) returns the S3 storage of a
  field, e.g. to get the URL of a file with `url()`
- The `Content-Type` of the files uploaded to S3 is detected from their contents with fileinfo,
  with both clients (the AWS SDK set it from the extension of the file before)

### Security

- The loader action sent any readable file for a tmp URL (model ID `0`) whose file name was
  changed to a path like `../config/database.php`: the file name was not signed. It now accepts
  only a plain base name of a file inside the tmp directory (checked with `realpath()`)
- The key of the loader URLs did not sign the file name, and was a SHA-1 of the concatenated parts
  with a secret kept in the session. Without `Filebinder.secret`, that secret was
  `Security::hash(time())`, which anyone can compute, and the Label helper used without the Ring
  component signed with an empty secret, so anyone could make the URLs. The key is now an
  HMAC-SHA256, with the `Security.salt` of the application, of the model, the ID, the field, the
  file name, the expiration time and the session ID, compared in constant time (`FilebinderSignature`).
  A URL works only in the session that rendered it, until it expires; a new session ID (e.g. after
  a login) invalidates the earlier URLs. Without `Security.salt` or a session, the helper throws a
  `CakeException` and the loader answers 404. URLs created before the upgrade stop working; they
  expire after `Filebinder.expire` (one minute by default)
- `Filebinder.secret` is no longer used and can be removed; the Ring component no longer keeps a
  secret in the session
- The file name in the `Content-Disposition` header of the loader was not escaped. Quotes,
  backslashes and control characters are removed from the quoted name, and the full name is sent
  in `filename*` (except to MSIE, which gets the Shift_JIS name as before)

### Fixes

- Replacing the file of a field, or deleting it with `delete_<field>`, failed with
  `Class "String" not found` (PHP 7+)
- `withObject` with the S3 storage failed (it used the AWS SDK 2 API), and left a temporary file in
  `/tmp`
- An S3 error during the upload was rethrown as a new `S3Exception` without its command, which
  fails with a `TypeError`; the original exception is now thrown
- A `beforeAttach` or `afterAttach` callback like `[$object, 'method']` failed with a `TypeError`
  on PHP 8
- `checkExtension` and `checkContentType` with a string parameter (`['checkExtension', 'png']`)
  failed with a `TypeError` on PHP 8
- File sizes with a lowercase unit (`'1mb'`) in `checkMaxFileSize`, `checkMinFileSize` and
  `checkFileSize` were read as kilobytes
- `funcCheckFile` called the function with a path that does not exist; it now fails
- Files restored from S3 were world-writable (`0666`); they now get `fileMode`, like the files
  restored from the database
- The loader action emitted warnings before the 404 response for a record that does not exist or
  has no file, a deprecation without a `User-Agent` header, and a notice without an output buffer
- The plugin no longer loads its own `vendor/autoload.php`

### Other

- PHPUnit 9.6 tests (with mocked S3 clients), PHP_CodeSniffer and PHPStan (level 5) in CI
- Documentation in English and Japanese
