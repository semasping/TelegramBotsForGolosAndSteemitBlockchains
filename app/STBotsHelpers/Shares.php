<?php
/**
 * Created by PhpStorm.
 * User: semasping (semasping@gmail.com)
 * Date: 30.07.2018
 * Time: 22:26
 */

namespace App\STBotsHelpers;


use App\GolosBotsSettings;
use Exception;

class Shares
{

    public static function getBotSettings($botName, $chatId, $key, $def)
    {
        $settings = GolosBotsSettings::where('chat_id', $chatId)->where('bot_name', $botName)->first();
        // AdminNotify::send(print_r($settings, true));
        try {
            $data = $settings->data;
        } catch (Exception $e) {
            $data = [];
        }
        $data =  collect($data);
        return $data->get($key, $def);
    }
}