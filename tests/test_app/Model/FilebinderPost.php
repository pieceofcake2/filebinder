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

    /**
     * Paths given to recordPath()
     *
     * @var array<string>
     */
    public $recordedPaths = [];

    /**
     * Records the path, for the callbacks and funcCheckFile given as a method name of the model.
     *
     * @param string $path File path.
     * @return bool
     */
    public function recordPath($path)
    {
        $this->recordedPaths[] = $path;

        return true;
    }
}
