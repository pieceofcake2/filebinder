<?php

App::uses('CakeTestModel', 'TestSuite/Fixture');

/**
 * Model of the tests, associated with FilebinderPost
 */
class FilebinderComment extends CakeTestModel
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

    /**
     * Passes the results of the associated finds to the Bindable behavior, which CakePHP calls only for
     * the primary model.
     *
     * @param mixed $results Results.
     * @param bool $primary Whether the model was queried directly.
     * @return mixed
     */
    public function afterFind($results, $primary = false)
    {
        if (!$primary) {
            return $this->Behaviors->Bindable->afterFind($this, $results, $primary);
        }

        return $results;
    }
}
