<?php
class FilebinderSchema extends CakeSchema
{
    /**
     * Before callback
     *
     * @param array $event Schema object properties.
     * @return bool
     */
    public function before($event = [])
    {
        return true;
    }

    /**
     * After callback
     *
     * @param array $event Schema object properties.
     * @return void
     */
    public function after($event = [])
    {
    }
}
