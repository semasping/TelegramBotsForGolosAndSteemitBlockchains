<?php
/**
 * Created by PhpStorm.
 * User: semasping (semasping@gmail.com)
 * Date: 06.07.2018
 * Time: 18:48
 */

namespace App\MongoModels;

use Jenssegers\Date\Date;
use Jenssegers\Mongodb\Eloquent\Model as Eloquent;


/**
 * @property int $_id
 * @property Date $created_at
 * @property Date $updated_at
 */
class PostForCuration extends Eloquent
{
    protected $connection = 'mongodb';
    protected $collection = '123';

/*    public function __construct($tlgm_id, array $attributes = [])
    {
        parent::__construct($attributes);
        //dump($acc,$attributes);
        //$this->collection = 'account_transactions_'.$acc;
        $this->telegram_id = $tlgm_id;
    }*/

    /*function getTable() {
        self::$collection = 'tlgm_123';
    }*/
}