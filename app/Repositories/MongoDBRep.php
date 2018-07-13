<?php
/**
 * Created by PhpStorm.
 * User: semasping (semasping@gmail.com)
 * Date: 06.07.2018
 * Time: 22:10
 */

namespace App\Repositories;

use MongoDB;


class MongoDBRep
{
    public static function getMongoDbCollection($collection)
    {
        return (new MongoDB\Client)->selectCollection(getenv('MONGO_DB'), $collection);
    }
}