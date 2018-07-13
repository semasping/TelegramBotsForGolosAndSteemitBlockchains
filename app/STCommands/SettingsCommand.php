<?php
/**
 * Created by PhpStorm.
 * User: semasping (semasping@gmail.com)
 * Date: 08.07.2018
 * Time: 10:31
 */

namespace App\STCommands;

use Illuminate\Support\Collection;
use Telegram\Bot\Actions;
use Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Objects\CallbackQuery;

class SettingsCommand extends Command
{

    /**
     * @var string Command Name
     */
    protected $name = "settings";

    /**
     * @var string Command Description
     */
    protected $description = "test";


    /**
     * {@inheritdoc}
     */
    public function handle($arguments)
    {
        // TODO: Implement handle() method.
    }


}