<?php
/**
 * Created by PhpStorm.
 * User: semasping (semasping@gmail.com)
 * Date: 08.07.2018
 * Time: 10:32
 */

namespace App\STCommands;


use Illuminate\Support\Collection;
use Telegram\Bot\Actions;
use Telegram\Bot\Commands\Command;
use Telegram\Bot\Keyboard\Keyboard;

class StartVoterBotCommand extends Command
{
    /**
     * @var string Command Name
     */
    protected $name = "start";

    /**
     * @var string Command Description
     */
    protected $description = "Start Command to get you started";

    /**
     * @inheritdoc
     */
    public function handle($arguments)
    {
        // This will send a message using `sendMessage` method behind the scenes to
        // the user/chat id who triggered this command.
        // `replyWith<Message|Photo|Audio|Video|Voice|Document|Sticker|Location|ChatAction>()` all the available methods are dynamically
        // handled when you replace `send<Method>` with `replyWith` and use the same parameters - except chat_id does NOT need to be included in the array.
        $this->replyWithMessage(['text' => 'Hello! Welcome to our bot, Here are our available commands:']);

        // This will update the chat status to typing...
        $this->replyWithChatAction(['action' => Actions::TYPING]);

        // This will prepare a list of available commands and send the user.
        // First, Get an array of all registered commands
        // They'll be in 'command-name' => 'Command Handler Class' format.
        $commands = $this->getTelegram()->getCommands();

        // Build the list
        $response = '';
        foreach ($commands as $name => $command) {
            $response .= sprintf('/%s - %s' . PHP_EOL, $name, $command->getDescription());
        }
        sleep(1);
        // Reply with the commands list
        $count= $this->fillCount([]);
        $this->replyWithMessage([
            'text' => $response,
            'reply_markup'      => $this->getKeyboard('voter', collect($count))
            ]);

        // Trigger another command dynamically from within this command
        // When you want to chain multiple commands within one or process the request further.
        // The method supports second parameter arguments which you can optionally pass, By default
        // it'll pass the same arguments that are received for this command originally.
        //$this->triggerCommand('subscribe');
    }

    private function getKeyboard($kb, Collection $data)
    {

        $keyboard['voter'] = Keyboard::make()
            ->inline()
            ->row(Keyboard::inlineButton([
                'text' => $this->getGrade(3) . ': ' . $data->get(3),
                'callback_data' => '3'
            ]), Keyboard::inlineButton(['text' => $this->getGrade(2) . ': ' . $data->get(2), 'callback_data' => '2']),
                Keyboard::inlineButton(['text' => $this->getGrade(1) . ': ' . $data->get(1), 'callback_data' => '1']))
            ->row(Keyboard::inlineButton(['text' => $this->getGrade(0) . ': ' . $data->get(0), 'callback_data' => '0']),
                Keyboard::inlineButton(['text' => 'Остановить', 'callback_data' => 'stop']));

        $keyboard['stop'] = Keyboard::make()
            ->inline()
            ->row(Keyboard::inlineButton(['text' => 'Продолжить', 'callback_data' => 'continue']),
                Keyboard::inlineButton(['text' => 'Удалить', 'callback_data' => 'del']))
            ->row(Keyboard::inlineButton(['text' => 'Голосование остановил: ' . $data->get('user'), 'callback_data' => 'no']));

        $keyboard['text'] = Keyboard::make()->inline()->row(Keyboard::inlineButton([
            'text'          => $data->get('text'),
            'callback_data' => 'no'
        ]));
        $keyboard['ownData'] = Keyboard::make()->inline()->row(Keyboard::inlineButton([
            'text'          => $data->get('text'),
            'callback_data' => $data->get('data')
        ]));
        $keyboard['no'] = false;

        return $keyboard[$kb];
    }

    public static function getGrade($key)
    {

        $grade = [
            'Не оценивать',
            'Славно',
            'Здорово',
            'Супер',
            'stop'     => 'Голосование остановлено',
            'continue' => 'Голосование продолжено',
            'del'      => 'Голосование удалено',
            'no'       => 'Нет'
        ];

        if ( ! isset($grade[$key])) {
            return $key;
        }

        return $grade[$key];
    }

    private function fillCount($votes)
    {
        $count = ([0 => 0, 1 => 0, 2 => 0, 3 => 0]);
        foreach ($votes as $vote) {
            if (isset($count[$vote])) {
                $count[$vote]++;
            }
        }

        return $count;
    }
}