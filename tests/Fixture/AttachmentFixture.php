<?php

class AttachmentFixture extends CakeTestFixture
{
    /**
     * Fields
     *
     * @var array
     */
    public $fields = [
        'id' => ['type' => 'integer', 'null' => false, 'default' => null, 'key' => 'primary'],
        'model' => ['type' => 'text', 'null' => true, 'default' => null],
        'model_id' => ['type' => 'integer', 'null' => true, 'default' => null],
        'parent_model_id' => ['type' => 'integer', 'null' => true, 'default' => null],
        'field_name' => ['type' => 'text', 'null' => true, 'default' => null],
        'file_name' => ['type' => 'text', 'null' => true, 'default' => null],
        'file_content_type' => ['type' => 'text', 'null' => true, 'default' => null],
        'file_size' => ['type' => 'text', 'null' => true, 'default' => null],
        'file_object' => ['type' => 'text', 'null' => true, 'default' => null],
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
            'id' => 100,
            'model' => 'FilebinderPost',
            'model_id' => 1,
            'field_name' => 'logo',
            'file_name' => 'logo.png',
            'file_content_type' => 'image/png',
            'file_size' => '771311',
            'file_object' => '',
            'created' => '2011-08-22 19:29:32',
            'modified' => '2011-08-22 19:29:32',
        ],
    ];
}
