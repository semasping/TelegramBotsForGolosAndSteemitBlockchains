<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * @property \Carbon\Carbon $created_at
 * @property int $id
 * @property \Carbon\Carbon $updated_at
 * @property array $data
 * @property string $author
 * @property string $link
 * @property string $status
 * @property string $inline_message_id
 * @property string $result_id
 * @property string $chat_id
 * @property string $message_id
 * @property string $from_user
 */
class GolosVoterBot extends Model
{
    protected $casts = [
        'data' => 'array',
    ];

    protected $fillable = array('author','link','data','status','inline_message_id','result_id','chat_id','message_id','from_user',);



}
