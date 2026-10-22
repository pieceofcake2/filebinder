<?php

App::uses('CakeTestModel', 'TestSuite/Fixture');

/**
 * Model of the tests that saves its files at its own paths
 */
class FilebinderCustomPathPost extends CakeTestModel
{
    /**
     * Table
     *
     * @var string
     */
    public $useTable = 'filebinder_posts';

    /**
     * Behaviors
     *
     * @var array
     */
    public $actsAs = ['Filebinder.Bindable'];

    /**
     * Bound fields
     *
     * @var array
     */
    public $bindFields = [];

    /**
     * Returns the path of a file: custom/<model ID>/<field>-<file name>.
     *
     * @param array $data File data.
     * @return string
     */
    public function transferTo($data)
    {
        return 'custom/' . $data['model_id'] . '/' . $data['field_name'] . '-' . $data['file_name'];
    }
}
