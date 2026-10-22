# Ring Component

The Ring component prepares the uploaded files of a request for the behavior. It also keeps the
uploaded files in the session, for a form with a confirmation page.

## Adding the Component

Add the component to the controller:

```php
public $components = ['Session', 'Filebinder.Ring'];
```

The component also adds the [Label helper](label.md) to the controller.

## Saving Uploaded Files

Call `bindUp()` before you save the request data:

```php
$this->Ring->bindUp();
```

`bindUp()` does two things for each file field of the model:

1. It moves the uploaded file to the `tmpPath` of the field.
2. It replaces the upload data in the request data with the file data. The behavior saves these
   file data.

`bindUp()` uses the model of the controller. To use another model, pass its name:

```php
$this->Ring->bindUp('Post');
```

## Forms with a Confirmation Page

A form with a confirmation page has several requests. The user uploads the file on the form,
then sees the confirmation page, then saves. The uploaded file must survive these requests.

`bindDown()` keeps the file data of the request data in the session. It skips the fields with
validation errors:

```php
$this->Ring->bindDown();
```

On the next request, `bindUp()` restores the file data from the session when no new file is
uploaded. Call `bindUp()` on each request of the form.

To call `bindDown()` automatically before rendering, pass `true` as the second argument of
`bindUp()`:

```php
$this->Ring->bindUp(null, true);
```

The Label helper can show an uploaded file on the confirmation page before it is saved. See
[URLs of the Files](label.md#urls-of-the-files).

## Methods

| Method | Description |
| --- | --- |
| `bindUp($modelName = null, $autoBindDown = false)` | Moves the uploaded files of the request data to the `tmpPath` of the fields, and replaces them with their file data. When no file is uploaded, restores the file data that `bindDown()` kept in the session. With `$autoBindDown`, calls `bindDown()` before rendering. |
| `bindDown($modelName = null)` | Keeps the file data of the request data in the session, except for the fields with validation errors. |
