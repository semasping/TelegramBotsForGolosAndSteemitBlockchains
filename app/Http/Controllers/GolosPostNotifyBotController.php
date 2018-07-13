<?php

namespace App\Http\Controllers;

use App\BotUserNot;
use App\GolosBlackListForBots;
use App\GolosBotPost;
use App\GolosBotsSettings;
use App\semas\AdminNotify;
use App\semas\GolosApi;
use Carbon\Carbon;
use Exception;
use GrapheneNodeClient\Tools\Transliterator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Jenssegers\Date\Date;
use function Sodium\add;
use Telegram\Bot\Laravel\Facades\Telegram;

class GolosPostNotifyBotController extends Controller
{
    //

    protected $bot_name = 'GolosPostNotifyBot';
    private $message;
    private $chatid;
    private $text;
    private $message_id;
    private $update;
    private $from_id;


    public function getApiKeyBot()
    {
        return getenv('TELEGRAM_BOT_TOKEN_' . $this->bot_name);
    }

    public function setWebHook()
    {
        $url = URL::to('/_gpnb_/webhook');
        $response = Telegram::setAccessToken($this->getApiKeyBot())->setWebhook(['url' => $url]);

        return $response;
    }

    public function removeWebHook()
    {
        $response = Telegram::setAccessToken($this->getApiKeyBot())->removeWebhook();
        dd($response);
        //return $response;
    }

    public function webHookUpdate()
    {
        $updates = Telegram::setAccessToken($this->getApiKeyBot())->getWebhookUpdates();
        if (isset($updates['message'])) {
            $message = $updates['message'];
        } elseif (isset($updates['edited_message'])) {
            $message = $updates['edited_message'];
        } elseif (isset($updates['channel_post'])) {
            $message = $updates['channel_post'];
        } else {
            //AdminNotify::send('GOLOS хренькакаято webHookUpdate' . print_r($updates, true));

            return response('', 200);
        }

        if ( ! isset($message['text'])) {
            AdminNotify::send('GOLOS хренькакаято webHookUpdate2' . print_r($updates, true));

            return response('', 200);
        }

        try {
            $this->getMainData($updates);
            if ($this->statusPart()) {
                return response('', 200);
            }
            if ($this->adminPart()) {
                return response('', 200);
            }
            if ($this->blackListTagPart()) {
                return response('', 200);
            }
            if ($this->blackListAuthorPart()) {
                return response('', 200);
            }

            $chatid = $message['chat']['id'];

            $text = $message['text'];
            $message_id = $message['message_id'];

            $telegram = 1;
            /* $response = Telegram::setAccessToken($this->getApiKeyBot())->sendMessage([
                     'chat_id'      => $chatid,
                     'text'         => 'Бот на тех обслуживании. Повторите свой запрос позже.',
                     //'reply_markup' => json_encode((object)array('force_reply' => true),true)
                 ]);
                 exit;*/

            if (isset($message['reply_to_message'])) {
                $this->processAnswer($updates);
            } else {
                $stext = $text;
                if (str_contains($text, '/deletetag')) {
                    $stext = '/deletetag';
                }
                if (str_contains($text, '/start')) {
                    $stext = '/start';
                }
                if (str_contains($text, '/addtag')) {
                    $stext = '/addtag';
                }
                if (str_contains($text, '/forbiddentag')) {
                    $stext = '/forbiddentag';
                }
                if (str_contains($text, '/delete_forbidden_tag')) {
                    $stext = '/delete_forbidden_tag';
                }
                if (str_contains($text, '/taglist')) {
                    $stext = '/taglist';
                }
                if (str_contains($text, '/menu')) {
                    $stext = '/menu';
                }
                if (str_contains($text, '/contact')) {
                    $stext = '/contact';
                }
                if (str_contains($text, '/steemtest')) {
                    $stext = '/steemtest';
                }
                switch ($stext) {
                    case '/start':
                        $response = Telegram::setAccessToken($this->getApiKeyBot())->sendMessage([
                            'chat_id'      => $chatid,
                            'text'         => 'Введите нажмите на команду /addtag - бот вас попросит ввести метку(тег).',
                            'reply_markup' => json_encode((object)array('force_reply' => false), true)
                        ]);
                    break;
                    case '/addtag':
                        $response = Telegram::setAccessToken($this->getApiKeyBot())->sendMessage([
                            'chat_id'             => $chatid,
                            'text'                => 'Введите метку(тег) для слежения.',
                            'reply_to_message_id' => $message_id,
                            'reply_markup'        => json_encode((object)array('force_reply' => true, 'selective' => true), true)
                        ]);
                    break;
                    case '/taglist':
                        $tags = BotUserNot::select('tag')->where('chat_id', $chatid)->where('bot_name', $this->bot_name)->get()->implode('tag', "\n");
                        $response = Telegram::setAccessToken($this->getApiKeyBot())->sendMessage([
                            'chat_id'             => $chatid,
                            'reply_to_message_id' => $message_id,
                            'text'                => 'Ваши метки.' . "\n" . $tags,
                            //'reply_markup' => json_encode((object)array('force_reply' => true),true)
                        ]);
                    break;
                    case '/deletetag':
                        $tags = BotUserNot::select('tag')->where('chat_id', $chatid)->where('bot_name', $this->bot_name)->get()->implode('tag', "\n");
                        $response = Telegram::setAccessToken($this->getApiKeyBot())->sendMessage([
                            'chat_id'             => $chatid,
                            'reply_to_message_id' => $message_id,
                            'text'                => '/deletetag: Ваши метки.' . "\n" . $tags . "\n" . 'Введите метку для удаления - как она указана выше в списке',
                            'reply_markup'        => json_encode((object)array('force_reply' => true, 'selective' => true), true)
                        ]);
                    break;
                    case '/menu':
                        $this->showMenu($telegram, $chatid);
                    break;
                    case '/website':
                        $this->showWebsite($telegram, $chatid);
                    break;
                    case '/contact';
                        $this->showContact($telegram, $chatid);
                    break;
                    case '/steemtest':
                        $mess[] = preg_match('/[а-я]*$/ui', '123');
                        AdminNotify::send(preg_match('/[а-я]*$/ui', $text));

                        return;
                    break;

                    default:
                        $info = 'Я не понимаю вас. Выберите пунт меню.' . $text;
                        $this->showMenu($telegram, $chatid, $info);
                }
            }
        } catch (Exception $e) {
            AdminNotify::send('GOLOS BOT CATCH ' . $e->getMessage() . ' on line ' . $e->getLine() . ' oin file ' . $e->getFile() . '  print ' . print_r($updates,
                    true));

            return response('', 200);
        }
    }

    /*
     * for telegram menu
     * addtag - добавить новый тег или несколько (через запятую)
     * taglist - Выводит список ваших тегов
     * deletetag - Выводит список тегов с вопросом: какой удалить 1 тег или несколько (через запятую)
     * add_forbidden_tag - Добавить тег в список исключений(blacklist)
     * list_forbidden_tag - Выводит список тегов в blacklist`е
     * delete_forbidden_tag - Выводит список тегов в blacklist`е с вопросом: какой удалить тег из blacklist`a (можно указать несколько - через запятую)
     * add_forbidden_author - Добавить автора в список исключений(blacklist)
     * list_forbidden_author - Выводит список авторов в blacklist`е
     * delete_forbidden_author - Выводит список авторов в blacklist`е с вопросом: какого автора удалить из blacklist`a (можно указать несколько - через запятую)
     * contact - контактная информация автора
     * menu - список команд
     */

    public function showMenu($telegram, $chatid, $info = null)
    {
        $message = '';
        if ($info !== null) {
            $message .= $info . chr(10);
        }
        //$message .= '/website' . chr(10);
        $message .= '/addtag - Добавить новый тег или несколько (через запятую)' . chr(10);
        $message .= '/taglist - Выводит список ваших тегов' . chr(10);
        $message .= '/deletetag - Выводит список тегов с вопросом: какой удалить 1 тег или несколько (через запятую). ' . chr(10) . chr(10);
        $message .= '/add_forbidden_tag - Добавить тег в список исключений(blacklist) ' . chr(10);
        $message .= '/list_forbidden_tag - Выводит список тегов в blacklist`е ' . chr(10);
        $message .= '/delete_forbidden_tag - Выводит список тегов в blacklist`е с вопросом: какой удалить тег из blacklist`a (можно указать несколько - через запятую). ' . chr(10) . chr(10);
        $message .= '/add_forbidden_author - Добавить автора в список исключений(blacklist) ' . chr(10);
        $message .= '/list_forbidden_author - Выводит список авторов в blacklist`е ' . chr(10);
        $message .= '/delete_forbidden_author - Выводит список авторов в blacklist`е с вопросом: какого автора удалить из blacklist`a (можно указать несколько - через запятую). ' . chr(10) . chr(10);
        $message .= '/can_manage_all - управлять может любой.' . chr(10);
        $message .= '/can_manage_only_admin - управлять может только админ. ' . chr(10);
        $message .= '/contact' . chr(10);

        $response = Telegram::setAccessToken($this->getApiKeyBot())->sendMessage([
            'chat_id' => $chatid,
            'text'    => $message
        ]);
    }

    public function showWebsite($telegram, $chatid)
    {
        $message = 'http://google.com';

        $response = Telegram::setAccessToken($this->getApiKeyBot())->sendMessage([
            'chat_id' => $chatid,
            'text'    => $message
        ]);
    }

    public function showContact($telegram, $chatid)
    {
        $message = 'semasping@gmail.com' . chr(10);;
        $message .= 'Чат бота: @gPostNotifyBot_group https://t.me/gPostNotifyBot_group' . chr(10);;
        $message .= 'Автор бота в телеграме: @Semasping https://t.me/Semasping' . chr(10);;

        $response = Telegram::setAccessToken($this->getApiKeyBot())->sendMessage([
            'chat_id' => $chatid,
            'text'    => $message
        ]);
    }

    public function processAnswer($updates)
    {
        if (isset($updates['message'])) {
            $message = $updates['message'];
        } elseif (isset($updates['edited_message'])) {
            $message = $updates['edited_message'];
        } elseif (isset($updates['channel_post'])) {
            $message = $updates['channel_post'];
        } else {
            AdminNotify::send('GOLOS хренькакаято processAnswer' . print_r($updates, true));

            return;
        }

        $chatid = $message['chat']['id'];
        $text = $message['text'];
        $old_message = $message['reply_to_message'];
        $old_text = $old_message['text'];
        if (str_contains($old_text, '/deletetag:')) {
            $old_text = 'Удаление';
        }
        if (str_contains($old_text, '/forbiddentag:')) {
            $old_text = 'forbiddentag';
        }
        if (str_contains($old_text, '/delete_forbidden_tag:')) {
            $old_text = 'delete-forbidden-tag';
        }
        switch ($old_text) {
            case 'Введите свой логин.':
                /*$client = new Client();
                $res    = $client->request( 'GET', 'https://ws.golos.io', [
                    'id'     => 2,
                    'method' => 'get_accounts',
                    'params' => [[ 'semasping' ]]
                ] );
                echo $res->getStatusCode();
// "200"
                //dd( $res->getHeader( 'content-type' ));
// 'application/json; charset=utf8'
                echo ($res->getBody());*/

                /* $command = new GetFollowersCommand(InitConnector::getConnector(ConnectorInterface::PLATFORM_STEEMIT));

                 $commandQuery = new CommandQueryData();
                 $commandQuery->setParamByKey('0', 'semasping');
                 $commandQuery->setParamByKey('1', '');
                 $commandQuery->setParamByKey('2', 'blog');
                 $commandQuery->setParamByKey('3', 100);
                 //$commandQuery->setParamByKey( '3', 100 );

                 $content = $command->execute($commandQuery);
                 dd($content);*/

                /*$command = new GetAccountCommand( InitConnector::getConnector( ConnectorInterface::PLATFORM_STEEMIT ) );

                $commandQuery = new CommandQueryData();
                $commandQuery->setParamByKey( '0',  ['semasping'] );

                $content = $command->execute( $commandQuery );
                dd ( $content );*/
            break;

            case 'Введите метку(тег) для слежения.':
                $text = str_replace("+", ',', $text);
                $text = str_replace("\n", ',', $text);
                $tags = explode(',', $text);
                foreach ($tags as $tag) {
                    $text = $this->checkTag($tag);
                    if (BotUserNot::where('chat_id', '=', $chatid)->where('tag', $text)->where('bot_name', $this->bot_name)->count() <= 0) {

                        $not = new BotUserNot();
                        $not->chat_id = $chatid;
                        $not->tag = $text;
                        $not->bot_name = $this->bot_name;
                        $not->save();
                        $mess[] = 'Метка "*' . $text . '*" добавлена.';
                    } else {
                        $mess[] = 'Метка "*' . $text . '*" уже есть в списке.';
                    }
                }
                $mess[] = 'Слежение работает. /menu -для вызова списка команд';

                $response = Telegram::setAccessToken($this->getApiKeyBot())->sendMessage([
                    'chat_id'    => $chatid,
                    'parse_mode' => 'Markdown',
                    'text'       => implode("\n", $mess),
                ]);
            break;

            case 'Удаление':
                $text = str_replace("+", ',', $text);
                $text = str_replace("\n", ',', $text);
                $tags = explode(',', $text);
                foreach ($tags as $tag) {
                    $chid = BotUserNot::where('chat_id', '=', $chatid)->where('tag', $tag)->where('bot_name', $this->bot_name);
                    if ($chid->count() > 0) {
                        $ftag = $chid->delete();
                        $textm = $tag . ' удален';
                    } else {
                        $textm = 'нет такого тега';
                    }

                    $response = Telegram::setAccessToken($this->getApiKeyBot())->sendMessage([
                        'chat_id' => $chatid,
                        'text'    => $textm,
                        //'reply_markup' => json_encode((object)array('force_reply' => true),true)
                    ]);
                }
            break;

            /*case 'delete-forbidden-tag':
                $text = str_replace("+", ',', $text);
                $text = str_replace("\n", ',', $text);
                $tags = explode(',', $text);
                foreach ($tags as $tag) {
                    $chid = GolosBlackListForBots::where('chat_id', '=', $chatid)
                                                 ->where('text_for_block', $tag)
                                                 ->where('type', 'tag')
                                                 ->where('bot_name', $this->bot_name);
                    if ($chid->count() > 0) {
                        $ftag = $chid->delete();
                        $textm = $tag . ' удален из blacklist`a';
                    } else {
                        $textm = 'нет такого тега';
                    }
                    $response = Telegram::setAccessToken($this->getApiKeyBot())->sendMessage([
                        'chat_id' => $chatid,
                        'text'    => $textm,
                        //'reply_markup' => json_encode((object)array('force_reply' => true),true)
                    ]);
                }
            break;

            case 'forbiddentag':
                $text = str_replace("+", ',', $text);
                $text = str_replace("\n", ',', $text);
                $tags = explode(',', $text);
                foreach ($tags as $tag) {
                    $text = $this->checkTag($tag);
                    if (GolosBlackListForBots::where('bot_name', $this->bot_name)
                                             ->where('chat_id', '=', $chatid)
                                             ->where('text_for_block', $text)
                                             ->where('type', 'tag')
                                             ->count() <= 0
                    ) {

                        $not = new GolosBlackListForBots();
                        $not->chat_id = $chatid;
                        $not->text_for_block = $text;
                        $not->bot_name = $this->bot_name;
                        $not->type = 'tag';
                        $not->save();
                        $mess[] = 'Метка "*' . $text . '*" добавлена в исключения.';
                    } else {
                        $mess[] = 'Метка "*' . $text . '*" уже есть в списке исключений.';
                    }
                }
                $mess[] = 'Слежение работает. /menu -для вызова списка команд';

                $response = Telegram::setAccessToken($this->getApiKeyBot())->sendMessage([
                    'chat_id'    => $chatid,
                    'parse_mode' => 'Markdown',
                    'text'       => implode("\n", $mess),
                ]);
            break;*/
        }
    }

    public function checkTag($text)
    {
        $text = mb_strtolower($text);

        $text = str_replace(' ', '', $text);
        $text = str_replace('#', '', $text);

        if (preg_match('/[а-яё]/ui', $text) == 1) {
            $text = 'ru--' . Transliterator::encode($text, Transliterator::LANG_RU);
        }

        return $text;
    }

    function getMainData($update)
    {
        $this->update = $update;
        if (isset($update['message'])) {
            $this->message = $update['message'];
        } elseif (isset($update['edited_message'])) {
            $this->message = $update['edited_message'];
        } elseif (isset($update['channel_post'])) {
            $this->message = $update['channel_post'];
        } else {
            AdminNotify::send('GOLOS хренькакаято getMainData() ' . print_r($update, true));

            return;
        }

        if ( ! isset($this->message['text'])) {
            AdminNotify::send('GOLOS хренькакаято getMainData() ' . print_r($update, true));

            return;
        }

        try {
            $this->chatid = $this->message['chat']['id'];
            $this->text = $this->message['text'];
            $this->message_id = $this->message['message_id'];
            $this->from_id = $this->message['from']['id'];

        } catch (Exception $e) {
            AdminNotify::send('GOLOS BOT CATCH getMainData() ' . "\n" . $e->getMessage() . "\n" . 'Dump ' . print_r($update, true));

            return;
        }

    }

    function sendText($text, $force_reply = false, $reply = false, $additional = [])
    {
        try {
            $send = [
                'chat_id' => $this->chatid,
                'text'    => $text,
            ];
            if ($reply && $this->message_id != null) {
                $send['reply_to_message_id'] = $this->message_id;
            }
            if ($force_reply) {
                $send['reply_markup'] = json_encode((object)array('force_reply' => true, 'selective' => true), true);
            }
            foreach ($additional as $key => $value) {
                $send[$key] = $value;
            }
            $response = Telegram::setAccessToken($this->getApiKeyBot())->sendMessage($send);

            return response('', 200);
        } catch (Exception $e) {
            AdminNotify::send('GOLOS BOT CATCH sendText() ' . $e->getMessage() . "\n" . '  print ' . dump($this->update));

            return false;
        }
    }


    private function blackListAuthorPart()
    {
        if (isset($this->message['reply_to_message'])) {
            $old_message = $this->message['reply_to_message'];
            $old_text = $old_message['text'];
            $old_stext = '';
            if (str_contains($old_text, '/add_forbidden_author:')) {
                $old_stext = 'add_forbidden_author';
            }
            if (str_contains($old_text, '/delete_forbidden_author:')) {
                $old_stext = 'delete_forbidden_author';
            }
            switch ($old_stext) {

                case 'delete_forbidden_author':
                    $text = str_replace("+", ',', $this->text);
                    $text = str_replace("\n", ',', $text);
                    $tags = explode(',', $text);
                    foreach ($tags as $tag) {
                        $chid = GolosBlackListForBots::where('chat_id', '=', $this->chatid)
                                                     ->where('text_for_block', $tag)
                                                     ->where('type', 'author')
                                                     ->where('bot_name', $this->bot_name);
                        if ($chid->count() > 0) {
                            $ftag = $chid->delete();
                            $textm = $tag . ' удален из blacklist`a';
                        } else {
                            $textm = 'нет такого тега';
                        }

                        return $this->sendText($textm);
                    }
                break;

                case 'add_forbidden_author':
                    $text = str_replace("+", ',', $this->text);
                    $text = str_replace("\n", ',', $text);
                    $tags = explode(',', $text);
                    foreach ($tags as $tag) {
                        $text = $this->checkTag($tag);
                        $text = str_replace('@', '', $text);
                        if (GolosBlackListForBots::where('bot_name', $this->bot_name)
                                                 ->where('chat_id', '=', $this->chatid)
                                                 ->where('text_for_block', $text)
                                                 ->where('type', 'author')
                                                 ->count() <= 0
                        ) {

                            $not = new GolosBlackListForBots();
                            $not->chat_id = $this->chatid;
                            $not->text_for_block = $text;
                            $not->bot_name = $this->bot_name;
                            $not->type = 'author';
                            $not->save();
                            $mess[] = 'Автор "*' . $text . '*" добавлен в исключения.';
                        } else {
                            $mess[] = 'Автор "*' . $text . '*" уже есть в списке исключений.';
                        }
                    }
                    $mess[] = 'Слежение работает. /menu -для вызова списка команд';

                    return $this->sendText(implode("\n", $mess));

                break;
            }
        } else {
            $stext = $this->text;
            if (str_contains($this->text, '/add_forbidden_author')) {
                $stext = '/add_forbidden_author';
            }
            if (str_contains($this->text, '/delete_forbidden_author')) {
                $stext = '/delete_forbidden_author';
            }
            if (str_contains($this->text, '/list_forbidden_author')) {
                $stext = '/list_forbidden_author';
            }

            switch ($stext) {
                case '/add_forbidden_author':
                    $text = '/add_forbidden_author: Введите автора для исключения. Если в посте будет от указанного автора, то уведомление о посте вам не прийдет.';

                    return $this->sendText($text, true, true);

                break;
                case '/list_forbidden_author':
                    $tags = GolosBlackListForBots::select('text_for_block')
                                                 ->where('chat_id', $this->chatid)
                                                 ->where('bot_name', $this->bot_name)
                                                 ->where('type', 'author')
                                                 ->get()
                                                 ->implode('text_for_block', "\n");
                    $text = 'Ваш black list авторов.' . "\n" . $tags . "\n";

                    return $this->sendText($text);
                break;
                case '/delete_forbidden_author':
                    $tags = GolosBlackListForBots::select('text_for_block')
                                                 ->where('chat_id', $this->chatid)
                                                 ->where('bot_name', $this->bot_name)
                                                 ->where('type', 'author')
                                                 ->get()
                                                 ->implode('text_for_block', "\n");
                    $text = '/delete_forbidden_author: Ваш black list авторов.' . "\n" . $tags . "\n" . ' Введите автора для удаления из blacklist`a.';

                    return $this->sendText($text, true, true);
                break;
            }
        }

        return false;
    }

    private function blackListTagPart()
    {
        if (isset($this->message['reply_to_message'])) {
            $old_message = $this->message['reply_to_message'];
            $old_text = $old_message['text'];
            $old_stext = '';
            if (str_contains($old_text, '/add_forbidden_tag:')) {
                $old_stext = 'forbidden-tag';
            }
            if (str_contains($old_text, '/delete_forbidden_tag:')) {
                $old_stext = 'delete-forbidden-tag';
            }
            switch ($old_stext) {

                case 'delete-forbidden-tag':
                    $text = str_replace("+", ',', $this->text);
                    $text = str_replace("\n", ',', $text);
                    $tags = explode(',', $text);
                    foreach ($tags as $tag) {
                        $chid = GolosBlackListForBots::where('chat_id', '=', $this->chatid)
                                                     ->where('text_for_block', $tag)
                                                     ->where('type', 'tag')
                                                     ->where('bot_name', $this->bot_name);
                        if ($chid->count() > 0) {
                            $ftag = $chid->delete();
                            $textm = $tag . ' удален из blacklist`a';
                        } else {
                            $textm = 'нет такого тега';
                        }

                        return $this->sendText($textm);
                    }
                break;

                case 'forbidden-tag':
                    $text = str_replace("+", ',', $this->text);
                    $text = str_replace("\n", ',', $text);
                    $tags = explode(',', $text);
                    foreach ($tags as $tag) {
                        $text = $this->checkTag($tag);
                        if (GolosBlackListForBots::where('bot_name', $this->bot_name)
                                                 ->where('chat_id', '=', $this->chatid)
                                                 ->where('text_for_block', $text)
                                                 ->where('type', 'tag')
                                                 ->count() <= 0
                        ) {

                            $not = new GolosBlackListForBots();
                            $not->chat_id = $this->chatid;
                            $not->text_for_block = $text;
                            $not->bot_name = $this->bot_name;
                            $not->type = 'tag';
                            $not->save();
                            $mess[] = 'Метка "*' . $text . '*" добавлена в исключения.';
                        } else {
                            $mess[] = 'Метка "*' . $text . '*" уже есть в списке исключений.';
                        }
                    }
                    $mess[] = 'Слежение работает. /menu -для вызова списка команд';

                    return $this->sendText(implode("\n", $mess));

                break;
            }
        } else {
            $stext = $this->text;
            if (str_contains($this->text, '/add_forbidden_tag')) {
                $stext = '/forbidden_tag';
            }
            if (str_contains($this->text, '/delete_forbidden_tag')) {
                $stext = '/delete_forbidden_tag';
            }
            if (str_contains($this->text, '/list_forbidden_tag')) {
                $stext = '/list_forbidden_tag';
            }

            switch ($stext) {
                case '/forbidden_tag':
                    $text = '/add_forbidden_tag: Введите метку(тег) для исключения. Если в посте будет указанный тег то уведомление о посте вам не прийдет.';

                    return $this->sendText($text, true, true);

                break;
                case '/list_forbidden_tag':
                    $tags = GolosBlackListForBots::select('text_for_block')
                                                 ->where('chat_id', $this->chatid)
                                                 ->where('bot_name', $this->bot_name)
                                                 ->where('type', 'tag')
                                                 ->get()
                                                 ->implode('text_for_block', "\n");
                    $text = 'Ваш black list тегов.' . "\n" . $tags . "\n";

                    return $this->sendText($text);
                break;
                case '/delete_forbidden_tag':
                    $tags = GolosBlackListForBots::select('text_for_block')
                                                 ->where('chat_id', $this->chatid)
                                                 ->where('bot_name', $this->bot_name)
                                                 ->where('type', 'tag')
                                                 ->get()
                                                 ->implode('text_for_block', "\n");
                    $text = '/delete_forbidden_tag: Ваш black list тегов.' . "\n" . $tags . "\n" . ' Введите метку(тег) для удаления из blacklist`a.';

                    return $this->sendText($text, true, true);
                break;
            }
        }

        return false;
    }

    public function adminPart()
    {
        $chatid = $this->chatid;
        if ($chatid < 0 && isset($this->message['entities'])) { //значит группа или супергруппа а так же содержится массив entities - то говорит о том что может быть комманда
            $bot_name = $this->bot_name;
            $chat_settings = Cache::remember('3golos_chat_setting_' . $chatid, 10, function () use ($chatid, $bot_name) {
                return GolosBotsSettings::where('chat_id', $chatid)->where('bot_name', $bot_name)->first();
            });
            if ( ! $chat_settings) {
                $chat_admins = Telegram::setAccessToken($this->getApiKeyBot())->getChatAdministrators([
                    'chat_id' => $chatid
                ]);
                //AdminNotify::send(print_r($chat_admins, true));
                if ( ! is_array($chat_admins)) {
                    $chat_admins = ($chat_admins->getDecodedBody())['result'];
                }
                foreach ($chat_admins as $admin) {
                    $data['admins'][] = $admin['user']['id'];

                }
                //$
                $data['can_manage'] = 'admin';
                $chat_settings = new GolosBotsSettings();
                $chat_settings->chat_id = $this->chatid;
                $chat_settings->bot_name = $this->bot_name;
                $chat_settings->data = $data;
                $chat_settings->save();

                $text = 'Новая функция: Ботом могут управлять только администраторы группы. Включена по умолчанию. Если вам нужно включить возможность управления любым участником - попросите администратора запустить команду /can_manage_all';

                AdminNotify::send('set auto admin for ' . $this->chatid . ' more info ' . print_r($chat_admins, true));

                return $this->sendText($text);
            }
            if ($chat_settings) {
                //AdminNotify::send($this->from_id);
                $data = $chat_settings->data;
                if ($data['can_manage'] == 'admin') {
                    if ( ! in_array($this->from_id, $data['admins'])) {
                        //AdminNotify::send($this->from_id. ' only admin');
                        $text = 'Ботом могут управлять только администраторы группы. Если вам нужно включить возможность управления любым участником - попросите администратора запустить команду /can_manage_all';

                        AdminNotify::send('GOLOS test adminPart' . print_r($this->message, true));

                        return $this->sendText($text);
                    }
                }
            }

            //AdminNotify::send(print_r($chat_settings, true));
        }


        $stext = $this->text;
        if (str_contains($this->text, '/can_manage_only_admin')) {
            $stext = '/can_manage_only_admin';
        }
        if (str_contains($this->text, '/can_manage_all')) {
            $stext = '/can_manage_all';
        }

        switch ($stext) {

            case '/can_manage_only_admin':
                $chat_settings = GolosBotsSettings::where('chat_id', $this->chatid)->where('bot_name', $this->bot_name)->first();
                $data = $chat_settings->data;
                $data['can_manage'] = 'admin';
                $chat_settings->data = $data;
                $chat_settings->save();
                $text = 'can_manage_only_admin: Ботом могу управлять только Администраторы группы. Чтобы разрешить всем используйте - /can_manage_all';

                return $this->sendText($text);
            break;
            case '/can_manage_all':
                $chat_settings = GolosBotsSettings::where('chat_id', $this->chatid)->where('bot_name', $this->bot_name)->first();
                $data = $chat_settings->data;
                $data['can_manage'] = 'all';
                $chat_settings->data = $data;
                $chat_settings->save();
                $text = 'can_manage_all: Ботом могут управлять все! Чтобы разрешить только администраторам используйте - /can_manage_only_admin';

                return $this->sendText($text);
            break;
        }
    }

    public function statusPart()
    {
        if (isset($this->message['reply_to_message'])) {
            $old_message = $this->message['reply_to_message'];
            $old_text = $old_message['text'];
            switch ($old_text) {
                case '/status':
                    $this->getStatusBot();

                    return true;
                break;
            }
        } else {
            $stext = $this->text;
            switch ($stext) {
                case '/status':
                    $this->getStatusBot();

                    return true;
                break;
            }
        }

        return false;
    }

    public function getStatusBot()
    {
        $current_data = GolosApi::GetDynamicGlobalProperties();
        $current_block = $current_data['result']['head_block_number'];
        $post = GolosBotPost::latest()->first();

        $date = 'Последний пост отправлен ' . Date::parse($post->created_at)->diffForHumans();
        $mess = [$date, $post, $current_block];
        $count_all = BotUserNot::all()->count();
        $count_chats = BotUserNot::all()->groupBy('chat_id')->implode('chat_id', "\n");
        $mess[] = $count_all;
        $mess[] = $count_chats;
        //$mess[] = implode(" \n",$count_chats);

        $text = implode("\n", $mess);
        $this->sendText($text, false, false, ['parse_mode' => 'html']);

        return;
    }

    public function sendServiceMessage(){
        $this->chatid = '';
        $text = 'Сегодня произошел небольшй сбой в базе данных и некоторые данные были повреждены. Проверьте настройки тегов для отслеживания командой /taglist. Если тегов нет - в поиске по этому чату вы можете найти ваши предыдущие настройки по тексту "addtag" или по тексту "добавлена."! '."\n".'Извините за предоставленные неудобства.';
        $re = $this->sendText($text, false, false, ['parse_mode' => 'html']);
        dump($re);
    }


}
