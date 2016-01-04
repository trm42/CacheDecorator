<?php

namespace App\Repositories\Stubs;
/**
 * Nothing fancy, just really simple array class to mock repository
 *
 *
 */
class StubRepository {

    protected $all = [
        1,
        2,
        3,
        4,
        5,
    ];

    public function all()
    {
        return $this->all;
    }

    public function find($i)
    {
        if ($i < 0 || $i > 19) {
            return false;
        }

        return $this->all[$i];
    }

    public function delete($i)
    {
        unset($this->all[$i]);

        return true;
    }

    public function insert() {
        $count = count($this->all) - 1;

        $last = $this->all[$count];

        $this->all[] = $last + 1;
        //\Log::debug('insert', [$count, $last]);
        return true;
    }

    /**
     * For testing how the excludes[] works in practice
     *
     */
    public function allWithoutCache()
    {
        return $this->all;
    }

    public function findMany($is = [])
    {
        $res = [];

        foreach($is as $val) {
            if(isset($this->all[$val])) {
                $res[] = $this->all[$val];
            }
        }

        return $res;
    }

    public function findManyWithout($params = [])
    {
        $res = [];

        $with = $params['with'];
        $without = $params['without'];

        foreach($with as $val) {
            if(!in_array($val, $without) && isset($this->all[$val]) ) {
                $res[] = $this->all[$val];
            }
        }

        return $res;

    }

}
