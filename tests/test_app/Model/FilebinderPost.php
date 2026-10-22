<?php

App::uses('CakeTestModel', 'TestSuite/Fixture');

/**
 * Model of the tests
 */
class FilebinderPost extends CakeTestModel
{
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
}
