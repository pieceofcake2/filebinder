<?php

class FilebinderPostFixture extends CakeTestFixture
{
    /**
     * Fields
     *
     * @var array
     */
    public $fields = [
        'id' => ['type' => 'integer', 'null' => false, 'default' => null, 'key' => 'primary'],
        'title' => ['type' => 'text', 'null' => true, 'default' => null],
        'body' => ['type' => 'text', 'null' => true, 'default' => null],
        'created' => ['type' => 'datetime', 'null' => true, 'default' => null],
        'modified' => ['type' => 'datetime', 'null' => true, 'default' => null],
    ];

    /**
     * Records
     *
     * @var array
     */
    public $records = [
        [
            'id' => 1,
            'title' => 'Title',
            'body' => 'Filebinder.Bindable Test',
            'created' => '2011-08-23 17:44:58',
            'modified' => '2011-08-23 12:05:02',
        ],
        [
            'id' => 401,
            'title' => 'No Attachment',
            'body' => 'Filebinder.Bindable Test',
            'created' => '2011-08-23 17:44:58',
            'modified' => '2011-08-23 12:05:02',
        ],
    ];
}
