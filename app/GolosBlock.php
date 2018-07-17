<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class GolosBlock extends Model
{
    protected $fillable = ['id','raw_transactions', 'status'];
}
