<?php

namespace App\Http\Controllers;

ini_set('memory_limit', '128M');

use App\BotUserNot;
use App\GolosBlackListForBots;
use App\GolosBotPost;
use App\GolosBotsSettings;
use App\GolosVoterBot;
use App\semas\AdminNotify;
use App\semas\GolosApi;
use Exception;
use GrapheneNodeClient\Tools\Transliterator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Jenssegers\Date\Date;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Laravel\Facades\Telegram;
use ViewComponents\Grids\Component\Column;
use ViewComponents\Grids\Component\CsvExport;
use ViewComponents\Grids\Component\TableCaption;
use ViewComponents\Grids\Grid;
use ViewComponents\ViewComponents\Customization\CssFrameworks\BootstrapStyling;
use ViewComponents\ViewComponents\Data\ArrayDataProvider;
use ViewComponents\ViewComponents\Input\InputSource;

class GolosVoterBotController extends Controller
{
    //

    protected $bot_name = 'GolosVoterBot';
    private $message;
    private $chatid;
    private $text;
    private $message_id;
    private $update;
    private $from_id;
    private $telegram;
    private $id_inline_query;
    private $replay = false;
    private $replay_message;


    public function showPosts(Request $request)
    {
        $date[0] = Date::now()->subWeek(1)->format('d.m.Y');
        $date[1] = Date::now()->format('d.m.Y');
        $date_range = $request->get('d_from', false);
        if ($date_range) {
            $date = explode(' - ', $date_range);
        }

        $data = GolosVoterBot::whereNotIn('status', ['error'])
                             ->where('created_at', '>=', Date::parse($date[0]))
                             ->where('created_at', '<=', Date::parse($date[1])->endOfDay());
        $data = $data->get();
        //$data = $data->sortByDesc('created_at');

        //$data
        $count = 1;
        $day = '';
        $data = $data->map(function ($item) use (&$count, &$day) {
            $ni = $item;
            $ni_data = collect($item['data']);
            //dump($ni->toArray());
            $ni['vote'] = (($this->getGrade($ni_data->get('avrg_vote', ''))));
            $ni['vote_all'] = (collect($ni_data->get('vote'), []))->map(function ($item, $key) {
                return $key . ":" . GolosVoterBotController::getGrade($item);
            })->implode("<br>");
            $ni['title'] = ($ni_data->get('title'));
            $ni['tags'] = GolosApi::decodeTags(collect($ni_data->get('tags', [])));
            $postDay = Date::parse($item['created_at'])->format('Y-m-d');
            if ($day != $postDay) {
                $count = 1;
                $day = $postDay;
            } elseif (in_array($ni['status'], [
                'wait-30',
                'wait-more',
                'vote-with-upvote',
                'vote-with-upvote-and-comment',
                'vote-end'
            ])) {
                $count++;
                $ni['count'] = $count;
            }
            //dump($ni['status'],$ni->toArray());
            $ni['status'] = $this->getStatusText($item['status']);


            return $ni;
        });

        $provider = new ArrayDataProvider($data->toArray());
        $input = new InputSource($_GET);
// create grid
        $grid = new Grid($provider, // all components are optional, you can specify only columns
            [

                new Column('count', '№'),
                new Column('created_at', 'Старт голосования'),
                new Column('from_user', 'Инициатор'),
                new Column('status', "Статус"),
                new Column('vote', 'Результат'),
                new Column('vote_all', 'Кто голосовал'),
                new Column('author', "Автор"),
                new Column('title', 'Title'),
                new Column('link', "Ссылка"),
                new Column('updated_at', 'Изменения'),
                new Column('tags', 'Теги'),
                new CsvExport($input->option('csv')),
                //new Column('data'),
                /*                new Column('inline_message_id'),
                                new Column('result_id'),
                                new Column('chat_id'),
                                new Column('message_id'),*/
            ]);
        $customization = new BootstrapStyling();
        $customization->apply($grid);

        //echo $grid;
        if ($request->has('csv')) {
            header('Content-Encoding: windows-1251');
            header('Content-type: text/csv; charset=windows-1251');
            echo $grid;
        }else{

            return view('gvb-show', compact('date', 'grid'));
        }
    }

    public function getApiKeyBot()
    {
        return getenv('TELEGRAM_BOT_TOKEN_' . $this->bot_name);
    }

    public function setWebHook()
    {
        $url = URL::to('/_gvb_/webhook');
        dump($url);
        $response = Telegram::setAccessToken($this->getApiKeyBot())->setWebhook(['url' => $url]);
        dump($response);

        return 'set ok';
    }

    public function removeWebHook()
    {
        $response = Telegram::setAccessToken($this->getApiKeyBot())->removeWebhook();
        dump($response);
        return 'remove ok';
    }

    public function webHookUpdate()
    {


        $updates = Telegram::setAccessToken($this->getApiKeyBot())->getWebhookUpdates();
        //AdminNotify::send('GOLOS:' . $this->bot_name . ' получили данные. Первая фаза' . print_r($updates, true));
        //return response('', 200);

        try {
            if ($this->inlinePart($updates)) {

                return response('ok', 200);
            }
            if ($this->chosenInlineResultPart($updates)) {

                return response('ok', 200);
            }
            if ($this->callbackQueryPart($updates)) {

                return response('ok', 200);
            }

            $this->getMainData($updates);


            if ($this->startPart()) {
                response('ok', 200);
            }

            if ($this->showMenuPart()) {

                return response('ok', 200);
            }
            if ($this->showContactPart()) {

                return response('ok', 200);
            }

            if ($this->newPostPart($updates)) { // обработка поста сразу после отправки публикации в чате.

                return response('ok', 200);
            }

            if ($this->statusPart()) {

                return response('', 200);
            }
            if ($this->adminPart()) {

                return response('', 200);
            }
            if ($this->settingPart()) {
                response('ok', 200);
            }


            return response('ok', 200);
        } catch (Exception $e) {

            AdminNotify::send('GOLOS BOT CATCH whu' . $e->getMessage() . ' on line ' . $e->getLine() . ' oin file ' . $e->getFile() . '  print ' . print_r($updates,
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

    public function showMenuPart()
    {

        if (str_contains($this->message['text'], '/menu')) {


            $message = 'Меню бота для голосования за посты.' . chr(10) . chr(10);

            $message .= '/account_name' . chr(10);
            $message .= '/account_key' . chr(10);
            $message .= '/vote_wait_time_1 - ожидание голосов за первый период. Текущее значение:  По умолчанию 3 голоса' . chr(10);
            $message .= '/hours_wait_time_2 - часов_ожидания_за_второй_период. По умолчанию 3 часа' . chr(10);
            $message .= '/limit_posts_by_day - лимит_постов_в_день. По умолчанию 40' . chr(10);
            $message .= '/vote_blacklist - кого не поддерживать.' . chr(10);
            $message .= '/main_voters_list - Должен проголосовать хоть кто-нибудь из этого списка - иначе голосование не действительно .' . chr(10);
            $message .= '/comment_list_not_subscribe - комментарии для автора который Не подписан на аккаунт.' . chr(10);
            $message .= '/comment_list_subscribe - комментарии для автора который  подписан на аккаунт.' . chr(10);
            $message .= '/show_settings - Показать текущие настройки.' . chr(10);
            //$message .= '/setting_admins - ' . chr(10);
            //$message .= '/vote_blacklist - ' . chr(10);

            $message .= '/contact - как связаться с разработчиком бота.' . chr(10);

            $response = Telegram::setAccessToken($this->getApiKeyBot())->sendMessage([
                'chat_id' => $this->chatid,
                'text'    => $message
            ]);

            return true;
        }

        return false;

    }


    public function showContactPart()
    {
        if (str_contains($this->message['text'], '/contact')) {
            $message = 'Автор бота в телеграме: @Semasping https://t.me/Semasping' . chr(10);;
            $message .= 'Email: semasping@gmail.com' . chr(10);;

            $response = Telegram::setAccessToken($this->getApiKeyBot())->sendMessage([
                'chat_id' => $this->chatid,
                'text'    => $message
            ]);
        } else {
            return false;
        }
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
            if (isset($this->message['reply_to_message'])) {
                $this->replay_message = $this->message;
                $this->replay = true;
                $this->message = $this->message['reply_to_message'];
            }
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
                                                 ->count() <= 0) {

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
                                                 ->count() <= 0) {

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
        if (isset($this->update['message'])) {
            $mess = $this->update['message'];

            if ($chatid < 0 && isset($mess['entities'])) { //значит группа или супергруппа а так же содержится массив entities - то говорит о том что может быть комманда
                $bot_name = $this->bot_name;
                $chat_settings = Cache::remember('golos_chat_setting_' . $chatid, 10, function () {
                    return $this->getSettingBot('can_manage');
                });
                if ( ! $chat_settings) {
                    $chat_admins = Telegram::setAccessToken($this->getApiKeyBot())->getChatAdministrators([
                        'chat_id' => $chatid
                    ]);
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

                    $text = 'Ботом могут управлять только администраторы группы. Включена по умолчанию. Если вам нужно включить возможность управления любым участником - попросите администратора запустить команду /can_manage_all.' . chr(10) . chr(10);
                    $text .= 'Для настройки бота нажмите /account_name';

                    AdminNotify::send('set auto admin for ' . $this->chatid . ' more info ' . print_r($chat_admins, true));

                    return $this->sendText($text);
                }
                if ($chat_settings) {
                    //AdminNotify::send($this->from_id);
                    $data = $chat_settings;
                    if ($data['can_manage'] == 'admin') {
                        if ( ! in_array($mess['from']['id'], $data['admins'])) {
                            AdminNotify::send($mess['from']['id'] . ' only admin');
                            $text = 'Ботом могут управлять только администраторы группы. Если вам нужно включить возможность управления любым участником - попросите администратора запустить команду /can_manage_all';

                            AdminNotify::send('GOLOS test adminPart' . print_r($mess, true));

                            return $this->sendText($text);
                        }
                    }
                }

                //AdminNotify::send(print_r($chat_settings, true));
            }
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

    public function inlinePart($up)
    {

        try {
            if ( ! isset($up['inline_query'])) {
                return false;
            }

            if (isset($up['inline_query'])) {
                $inline_query = $up['inline_query'];
                $this->id_inline_query = $inline_query['id'];
                $query = $inline_query['query'];
                if ( ! $this->checkQuery($query)) {
                    return response('ok', 200);
                }
                //$this->answerInlineQuery('Обрабатываю ссылку',1);
                //sleep(1);
                $result = explode('/@', $query);
                $result = explode("/", $result[1]);

                $author = $result[0];
                $link = $result[1];
//нет данных о чате - значит мы не может получить настройки... переносим часть проверки в публикацию

                //AdminNotify::send($author);

                $author_info = $this->getAuthor($author);
                /*if ( ! $this->checkAuthor($author_info)) {
                    return response('ok', 200);
                }*/
                $author_name = $author_info['name'];
                $countPostsToday = $this->getCountPostToday();
                $countPostsToday = $countPostsToday + 1;

                $text = 'Номер поста сегодня: ' . $countPostsToday . chr(10) . 'Автор:"' . $author_name . '"' . chr(10) . 'link="' . $query . '"' . chr(10) . 'id=!1' . $this->id_inline_query . '!';


                $this->answerInlineQuery("Автор $author_name, Ссылка: $link, ID: $this->id_inline_query, Номер поста сегодня: $countPostsToday", $text, 300,
                    'no');

                //AdminNotify::send('GOLOS BOT CATCH 34 inlinePart() ' . "\n" . '  print ' . print_r($author_name, true));
            }


        } catch (Exception $e) {
            AdminNotify::send('GOLOS BOT CATCH inlinePart() ' . "\n" . $e->getMessage() . "\n" . 'Dump ' . $e->getLine() . $e->getFile());

            return response('ok', 200);
        }

        return response('ok', 200);
    }

    private function checkQuery($query)
    {
        //AdminNotify::send('GOLOS BOT CheckQuery() ' . "\n" . '  print ' . print_r($query,true));

        if (strpos($query, '/@') === false) {
            $this->answerInlineQuery('Ожидаю ссылку', '1', 300, 'no');

            return false;
        }

        return true;
    }

    private function answerInlineQuery($descr, $text, $cache = 300, $kb = 'voter')
    {
        /*
 *      * @var string      $params ['inline_query_id']
* @var array       $params ['results']
* @var int|null    $params ['cache_time']
* @var bool|null   $params ['is_personal']
* @var string|null $params ['next_offset']
* @var string|null $params ['switch_pm_text']
* @var string|null $params ['switch_pm_parameter']
 */
        //$keyboard = $this->getKeyboard($kb, collect($this->fillCount([])));
        $keyboard = $this->getKeyboard('text', collect(['text' => 'Обработка ']));
        /*$keyboard = $this->getKeyboard('ownData', collect([
            'text' => 'Обработка ',
            'data' => $text
        ]));*/

        $response = [
            "type"                  => "article",
            "id"                    => '1' . $this->id_inline_query,
            'title'                 => ' ',
            'description'           => $descr,
            "parse_mode"            => "markdown",
            //"message_text" => $text,
            "input_message_content" => ['message_text' => $text],
        ];
        if ($keyboard) {
            $response['reply_markup'] = $keyboard;
        }
        //AdminNotify::send('GOLOS BOT answerInlineQuery() '. $this->id_inline_query . "\n" . '  print ' . print_r('',true));

        $mess['inline_query_id'] = $this->id_inline_query;
        $mess['results'] = [$response];
        $mess['cache_time'] = $cache;
        $resp = Telegram::setAccessToken($this->getApiKeyBot())->answerInlineQuery($mess);
        AdminNotify::send('resp ' . print_r($resp, true));
        //return response('ok', 200);
    }

    /**
     * @param $author
     *
     * @return boolean
     */
    private function checkAuthor($author)
    {

        $r = $author['next_vesting_withdrawal'];
        if (Date::parse($r)->greaterThan(Date::now())) {
            //AdminNotify::send(print_r($r,true));
            $this->answerInlineQuery('Автор понижает силу голоса.', 'Автор: ' . $author['name'] . ' понижает силу голоса.', 300, 'no');


            return false;
        }

        return true;
    }

    private function getAuthor($author)
    {
        //AdminNotify::send('1ererr');
        $author_a = GolosApi::getAccountFull($author);
        //AdminNotify::send('2ererr');

        //AdminNotify::send(print_r($author_a[0],true));

        return $author_a[0];

    }

    private function callbackQueryPart($up)
    {
        try {
            if ( ! isset($up['callback_query'])) {
                return false;
            }
            AdminNotify::send(print_r('callbackQueryPart' . $up, true));

            $resp = false;
            $mess = [
                'callback_query_id' => $up['callback_query']['id'],
                'text'              => $this->getGrade($up['callback_query']['data']),
                'show_alert'        => false,
            ];
            if ($up['callback_query']['data'] == 'no') {
                $mess['text'] = '.';
                $resp = Telegram::setAccessToken($this->getApiKeyBot())->answerCallbackQuery($mess);
            }

            $post = GolosVoterBot::where('inline_message_id', $up['callback_query']['inline_message_id'])->first();
            $data = collect($post->data);
            $count = $this->fillCount($data->get('vote', []));
            //$count = $data->get('count', [0 => 0, 1 => 0, 2 => 0, 3 => 0]);
            $data = $data->toArray();
            $user = $up['callback_query']['from']['username'];
            $grade = $up['callback_query']['data'];

            $this->chatid = $post->chat_id;


            if ($up['callback_query']['data'] == 'stop') {
                $resp = Telegram::setAccessToken($this->getApiKeyBot())->editMessageReplyMarkup([
                    'inline_message_id' => $up['callback_query']['inline_message_id'],
                    'reply_markup'      => $this->getKeyboard('stop', collect(['user' => $user]))
                ]);
                $mess['text'] = 'Голосование остановлено';
                $data['stop'] = $user;
                $post->status = 'Голосование остановлено';
                $post->data = $data;
                $post->save();
                //return $resp;
            }
            if ($up['callback_query']['data'] == 'continue') {
                $resp = Telegram::setAccessToken($this->getApiKeyBot())->editMessageReplyMarkup([
                    'inline_message_id' => $up['callback_query']['inline_message_id'],
                    'reply_markup'      => $this->getKeyboard('voter', collect($count)),
                ]);
                $mess['text'] = 'Голосование продолжено';
                //return $resp;
                $data['continue'] = $user;
                $post->status = 'wait-more';
                $hours_wait_time_2 = $this->getSettingBot('hours_count_second_time', 3);
                $data = $post->data;
                $data['vote_stop'] = Date::now()->addHours($hours_wait_time_2);
                $post->data = $data;
                $post->data = $data;
                $post->save();
            }
            if ($up['callback_query']['data'] == 'del') {
                $resp = Telegram::setAccessToken($this->getApiKeyBot())->editMessageText([
                    'inline_message_id' => $up['callback_query']['inline_message_id'],
                    //'reply_markup' => ' ',
                    'text'              => 'Голосование удалил: ' . $user
                ]);
                $mess['text'] = 'Голосование удалено';
                $data['del'] = $user;
                $post->status = 'del';
                $post->data = $data;
                $post->save();
            }


            if ( ! $resp) {

                $data['vote'][$user] = $grade;

                $count = $this->fillCount($data['vote']);

                $resp = Telegram::setAccessToken($this->getApiKeyBot())->editMessageReplyMarkup([
                    'inline_message_id' => $up['callback_query']['inline_message_id'],
                    'reply_markup'      => $this->getKeyboard('voter', collect($count)),
                    //'text'              => 'удалено'
                ]);

            }

            $post->data = $data;
            $post->save();

            $resp = Telegram::setAccessToken($this->getApiKeyBot())->answerCallbackQuery($mess);

            return $resp;
        } catch (Exception $e) {
            AdminNotify::send('GOLOS BOT CATCH callbackQueryPart() ' . "\n" . $e->getMessage() . ' | ' . $e->getLine() . "\n" . 'Dump ' . print_r($up, true));

            return response('ok', 200);
        }
    }

    private function settingPart()
    {
        //login
        if (str_contains($this->message['text'], '/account_name')) {
            if ($this->replay) {
                $acc = $this->replay_message['text'];
                $acc = str_replace('@', '', $acc);
                $acc = mb_strtolower($acc);
                $acc = trim($acc);
                if ($this->setSettingBot(['account' => $acc])) {
                    $this->sendText('Аккаунт сохранен. Можете добавить ключ вызвав команду /account_key');
                }

                return true;
            }
            $text = 'Введите имя аккаунта: (/account_name)';
            $this->sendText($text, true, true);

            return true;
        }
        // key
        if (str_contains($this->message['text'], '/account_key')) {
            if ($this->replay) {
                $key = encrypt(trim($this->replay_message['text']));
                if ($this->setSettingBot(['key' => $key])) {
                    $this->sendText('Постинг ключ зашифрован и сохранен. Другие команды настроек можно посмотреть в /menu');
                }

                return true;
            }
            $text = 'Введите приватный постинг ключ аккаунта: (/account_key) . Сообщение после отправки лучше удалить, чтобы никто не увидел ваш ключ.';
            $this->sendText($text, true, true);

            return true;
        }
        // Сколько голосов в первые 30 минут
        if (str_contains($this->message['text'], '/vote_wait_time_1')) {
            if ($this->replay) {
                $value = (trim($this->replay_message['text']));
                if ($this->setSettingBot(['voter_count_first_time' => $value])) {
                    $this->sendText('Настройка сохранена. Другие команды настроек можно посмотреть в  /menu');
                }

                return true;
            }
            $text = 'Введите кол-во голосов для успешного голосование в первый период: (/vote_wait_time_1).' . ' Текущее значение:' . $this->getSettingBot('voter_count_first_time');
            $this->sendText($text, true, true);

            return true;
        }
        //Сколько часов ждать во второй период
        if (str_contains($this->message['text'], '/hours_wait_time_2')) {
            if ($this->replay) {
                $value = (trim($this->replay_message['text']));
                if ($this->setSettingBot(['hours_count_second_time' => $value])) {
                    $this->sendText('Настройка сохранена. Другие команды настроек можно посмотреть в  /menu');
                }

                return true;
            }
            $text = 'Введите кол-во часов для ожидания голосование во второй период: (/hours_wait_time_2).' . ' Текущее значение:' . $this->getSettingBot('hours_count_second_time');
            $this->sendText($text, true, true);

            return true;
        }
        //сколько постов в день обрабатывать
        if (str_contains($this->message['text'], '/limit_posts_by_day')) {
            if ($this->replay) {
                $value = (trim($this->replay_message['text']));
                if ($this->setSettingBot(['limit_post_by_day' => $value])) {
                    $this->sendText('Настройка сохранена. Другие команды настроек можно посмотреть в  /menu');
                }

                return true;
            }
            $text = 'Введите кол-во постов в день для поддержки: (/limit_posts_by_day).' . ' Текущее значение:' . $this->getSettingBot('limit_post_by_day', 25);
            $this->sendText($text, true, true);

            return true;
        }
        //blacklist за кого не голосовать
        if (str_contains($this->message['text'], '/vote_blacklist')) {
            if ($this->replay) {
                $text = str_replace("+", ',', $this->replay_message['text']);
                $text = str_replace("\n", ',', $text);
                $value = explode(',', $text);
                if ($this->setSettingBot(['vote_blacklist' => $value])) {
                    $this->sendText('Настройка сохранена. Другие команды настроек можно посмотреть в  /menu');
                }

                return true;
            }

            $text = 'Введите аккаунты за которые не голосовать: (/vote_blacklist). Список пересоздастся.' . "\n";
            $text .= 'Текущий список (скопируйте его и добавьте нужных): ' . "\n" . "\n" . collect($this->getSettingBot('vote_blacklist'))->implode("\n");
            $this->sendText($text, true, true);

            return true;
        }
        //commentlist не подписан автор
        if (str_contains($this->message['text'], '/comment_list_not_subscribe')) {
            if ($this->replay) {
                $value = explode("\n", $this->replay_message['text']);
                foreach ($value as $item) {
                    list($k, $v) = explode('>', $item);
                    $a[$k] = $v;
                }
                if ($this->setSettingBot(['comment_list_not_subscribe' => $a])) {
                    $this->sendText('Настройка сохранена. Другие команды настроек можно посмотреть в  /menu. ' . "\n" . 'Сохраненные значения:' . "\n" . '1>' . collect($this->getSettingBot('comment_list_not_subscribe'))->get(1) . "\n" . '2>' . collect($this->getSettingBot('comment_list_not_subscribe'))->get(2) . "\n" . '3>' . collect($this->getSettingBot('comment_list_not_subscribe'))->get(3),
                        false, false, ['disable_web_page_preview' => true, 'parse_mode' => 'html']);
                }

                return true;
            }

            $text = 'Введите комментарии которые нужно отправить если автор не подписан на ваш аккаунт: (/comment_list_not_subscribe). Список пересоздастся.' . "\n";
            $text .= 'Текущий список (скопируйте его и отредактируйте): ' . "\n" . "\n" . '1>' . collect($this->getSettingBot('comment_list_not_subscribe'))->get(1) . "\n" . '2>' . collect($this->getSettingBot('comment_list_not_subscribe'))->get(2) . "\n" . '3>' . collect($this->getSettingBot('comment_list_not_subscribe'))->get(3);
            $this->sendText($text, true, true);

            return true;
        }
        //commentlist подписан автор
        if (str_contains($this->message['text'], '/comment_list_subscribe')) {
            if ($this->replay) {
                $value = explode("\n", $this->replay_message['text']);
                foreach ($value as $item) {
                    list($k, $v) = explode('>', $item);
                    $a[$k] = $v;
                }
                if ($this->setSettingBot(['comment_list_subscribe' => $a])) {
                    $this->sendText('Настройка сохранена. Другие команды настроек можно посмотреть в  /menu. ' . chr(10) . 'Сохраненные значения:' . "\n" . '1>' . collect($this->getSettingBot('comment_list_subscribe'))->get(1) . "\n" . '2>' . collect($this->getSettingBot('comment_list_subscribe'))->get(2) . "\n" . '3>' . collect($this->getSettingBot('comment_list_subscribe'))->get(3),
                        false, false, ['disable_web_page_preview' => true, 'parse_mode' => 'html']);
                }

                return true;
            }

            $text = 'Введите комментарии которые нужно отправить если автор не подписан на ваш аккаунт: (/comment_list_subscribe). Список пересоздастся.' . "\n";
            $text .= 'Текущий список (скопируйте его и отредактируйте): ' . "\n" . "\n" . '1>' . collect($this->getSettingBot('comment_list_subscribe'))->get(1) . "\n" . '2>' . collect($this->getSettingBot('comment_list_subscribe'))->get(2) . "\n" . '3>' . collect($this->getSettingBot('comment_list_subscribe'))->get(3);
            $this->sendText($text, true, true);

            return true;
        }
        //белый список
        if (str_contains($this->message['text'], '/main_voters_list')) {
            if ($this->replay) {
                $text = str_replace("+", ',', $this->replay_message['text']);
                $text = str_replace("\n", ',', $text);
                $value = explode(',', $text);
                if ($this->setSettingBot(['main_voters_list' => $value])) {
                    $this->sendText('Настройка сохранена. Другие команды настроек можно посмотреть в  /menu');
                }

                return true;
            }
            $text = 'Введите ники в телеграм без которых голосование не действительно: (/main_voters_list). Список пересоздастся.' . "\n";
            $text .= 'Текущий список (скопируйте его и добавьте нужных): ' . "\n" . "\n" . collect($this->getSettingBot('main_voters_list'))->implode("\n");
            $this->sendText($text, true, true);

            return true;
        }

        //все настройки
        if (str_contains($this->message['text'], '/show_settings')) {
            $text[] = 'Все настройки :';
            $text[] = '<b>Аккаунт</b> : ' . $this->getSettingBot('account');
            $text[] = '<b>Постинг кей</b>: ' . collect($this->getSettingBot('key'), [false])->map(function ($item) {
                    if ($item) {
                        return 'Установлен';
                    } else {
                        return 'Не установлен';
                    }
                })->implode("\n");
            $text[] = '<b>Количество голосов за первый период</b> : ' . $this->getSettingBot('voter_count_first_time', 3);
            $text[] = '<b>Количество часов для второго периода</b> : ' . $this->getSettingBot('hours_count_second_time', 3);
            $text[] = '<b>Количество постов в день для поддержки</b> : ' . $this->getSettingBot('limit_posts_by_day', 25);
            $text[] = '<b>Черный список</b> : ' . "\n" . collect($this->getSettingBot('vote_blacklist'))->implode("\n");
            $text[] = '<b>Список Главных голосующих</b> : ' . "\n" . collect($this->getSettingBot('main_voters_list'))->implode("\n");
            $text[] = '<b>Комментарии для подписанных</b> : ' . "\n" . collect($this->getSettingBot('comment_list_subscribe'))->implode("\n");
            $text[] = '<b>Комментарии для НЕ подписанных</b> : ' . "\n" . collect($this->getSettingBot('comment_list_not_subscribe'))->implode("\n");
            $text = implode("\n" . "\n", $text);
            $this->sendText($text, false, false, ['disable_web_page_preview' => true, 'parse_mode' => 'html']);

            return true;
        }

        return false;
    }

    private function chosenInlineResultPart($updates)
    {
        if ( ! isset($updates['chosen_inline_result'])) {
            return false;
        }
        try {
            sleep(5);
            $re = $updates['chosen_inline_result'];
            $post = GolosVoterBot::where(['result_id' => $re['result_id']]);
            if ($post->count() == 0) {
                $resp = Telegram::setAccessToken($this->getApiKeyBot())->editMessageReplyMarkup([
                    'inline_message_id' => $re['inline_message_id'],
                    'reply_markup'      => $this->getKeyboard('text', collect(['text' => 'Ошибка. Повторите еще раз.'])),
                ]);
                AdminNotify::send('chosenInlineResultPart' . print_r($updates, true));

                return true;
            } else {
                $post = $post->get()->last();
            }
            $post->inline_message_id = $re['inline_message_id'];
            $data = $post->data;
            $data['query'] = $re['query'];
            $post->data = $data;
            $post->from_user = $re['from']['username'];
            $post->status = 'wait-30';
            $post->save();
            //AdminNotify::send('save good');
            $this->chatid = $post->chat_id;
            $this->message_id = $post->message_id;
            if ( ! $this->checkBlacklistAuthor($post->author)) {
                $resp = Telegram::setAccessToken($this->getApiKeyBot())->editMessageText([
                    'inline_message_id' => $post->inline_message_id,
                    'reply_markup'      => $this->getKeyboard('no', collect([])),
                    'text'              => 'Автор: ' . $post->author . ' в черном списке'
                ]);
                $post->status = 'В черном списке';
                $post->save();

                return true;
            }
            if ( ! $this->checkAlreadySupport($post->author)) {
                $resp = Telegram::setAccessToken($this->getApiKeyBot())->editMessageText([
                    'inline_message_id' => $post->inline_message_id,
                    'reply_markup'      => $this->getKeyboard('no', collect([])),
                    'text'              => 'Автор: ' . $post->author . ' уже поддержан сегодня'
                ]);
                $post->status = 'Уже поддержан';
                $post->save();

                return true;
            }
            if ( ! $this->checkPostTags($data['tags'])) {
                $resp = Telegram::setAccessToken($this->getApiKeyBot())->editMessageText([
                    'inline_message_id' => $post->inline_message_id,
                    'reply_markup'      => $this->getKeyboard('no', collect([])),
                    'text'              => 'Содержит тег #апвот50-50.'
                ]);
                $post->status = 'tag50';
                $post->save();

                return true;
            }
            if ( ! $this->checkLimitByDay()) {
                $resp = Telegram::setAccessToken($this->getApiKeyBot())->editMessageText([
                    'inline_message_id' => $post->inline_message_id,
                    'reply_markup'      => $this->getKeyboard('no', collect([])),
                    'text'              => 'Достигнут лимит поддержки в день'
                ]);
                $post->status = 'limit_support';
                $post->save();

                return true;
            }

            $count = $this->fillCount([]);
            $resp = Telegram::setAccessToken($this->getApiKeyBot())->editMessageReplyMarkup([
                'inline_message_id' => $re['inline_message_id'],
                'reply_markup'      => $this->getKeyboard('voter', collect($count)),
            ]);

            //AdminNotify::send(print_r($posts, true));

            return true;
        } catch (Exception $e) {
            AdminNotify::send('GOLOS BOT CATCH chosenInlineResultPart ' . $e->getMessage() . ' on line ' . $e->getLine() . ' oin file ' . $e->getFile() . '  print ' . print_r($updates,
                    true));

            return response('', 200);
        }
    }

    private function newPostPart($updates)
    {
        if (str_contains($this->text, 'id=!')) {
            //sleep(10);
            AdminNotify::send(print_r('#newPostPart' . $updates, true));
            preg_match('~!(.*?)!~', $this->text, $output);
            $id = $output['1'];
            $pc = GolosVoterBot::where('result_id', $id);
            if ($pc->count() > 0) {
                return true;
            }

            preg_match('~Автор:"(.*?)"~', $this->text, $output);
            $author = $output['1'];
            preg_match('~link="(.*?)"~', $this->text, $output);
            $link = $output['1'];
            $result = explode('/@', $link);
            $result = explode("/", $result[1]);

            $author = $result[0];
            //$link =
            $perm = $result[1];
            $content = GolosApi::getContent($author, $perm);
            //$content = $content;


            $posts = GolosVoterBot::firstOrCreate([
                'result_id'         => $id,
                'status'            => 'new',
                'from_user'         => '',
                'author'            => $author,
                'chat_id'           => $this->chatid,
                'message_id'        => $this->message_id,
                'inline_message_id' => '',
                'link'              => $link,
            ]);
            $data['title'] = $content['title'];
            $jd = json_decode($content['json_metadata']);
            $data['tags'] = collect($jd)->get('tags', []);
            $posts->data = $data;
            $posts->save();
            $post = $posts;


            return true;
        }
        if (str_contains($this->text, '/@')) {
            AdminNotify::send(print_r($updates, true));

            return true;
        }
    }

    /**
     * @param $kb
     *
     * @param \Illuminate\Support\Collection $data
     *
     * @return mixed
     * @internal param $count
     *
     * @internal param $keyboard
     */
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

    private function setSettingBot($array)
    {
        $settings = GolosBotsSettings::firstOrNew(['chat_id' => $this->chatid, 'bot_name' => $this->bot_name]);
        $data = $settings->data;
        foreach ($array as $key => $value) {

            $data[$key] = $value;
        }
        $settings->data = $data;

        return $settings->save();
    }

    /**
     * @param string $key :
     *                    account |
     *                    key |
     *                    voter_count_first_time |
     *                    hours_count_second_time |
     *                    limit_post_by_day |
     *                    vote_blacklist |
     *                    setting_admins
     *
     * @param string $def
     *
     * @return mixed
     */
    private function getSettingBot($key, $def = '')
    {

        $settings = GolosBotsSettings::where('chat_id', $this->chatid)->where('bot_name', $this->bot_name)->first();
        // AdminNotify::send(print_r($settings, true));
        try {
            $data = $settings->data;

        } catch (Exception $e) {
            $data = [];
        }
        $data = collect($data);
        // AdminNotify::send(print_r($data, true));

        return $data->get($key, $def);
    }

    private function checkBlacklistAuthor($author)
    {
        $blacklist = $this->getSettingBot('vote_blacklist', []);
        //AdminNotify::send(print_r($blacklist, true));
        if (in_array($author, $blacklist)) {
            //$this->answerInlineQuery('Автор находится в черном списке.', 'Автор: ' . $author . ' находится в черном списке.', 10, 'no');
            //AdminNotify::send(print_r($blacklist, true));
            return false;
        }

        return true;
    }

    private function checkAlreadySupport($author)
    {
        $post = GolosVoterBot::where('author', $author)
                             ->where('created_at', '>=', Date::today())
                             ->whereIn('status', ['wait-30', 'wait-more', 'vote-with-upvote', 'vote-with-upvote-and-comment', 'vote-end']);
        if ($post->count() > 1) {

            AdminNotify::send('checkAlreadySupport' . print_r($post->get()->toArray(), true));

            return false;
        }

        return true;

    }

    private function checkPostTags($tags)
    {
        if (in_array('ru--apvot50-50', $tags)) {

            return true;
        }

        return true;
    }

    private function getAvrgVote($count)
    {
        $count = collect($count);
        if ($count->count() != 0) {
            $av = round($count->sum() / $count->count());
        } else {
            $av = 'no';
        }

        //AdminNotify::send('v:'.$av.' a'.print_r($count,true));
        return $av;//$this->getGrade($av);

    }

    private function checkLimitByDay()
    {
        $limit = $this->getSettingBot('limit_post_by_day', 25);
        if ($this->getCountPostToday() > $limit) {
            return false;
        }

        return true;
    }

    public static function checkMainVotersList($votes, $mainVotersList)
    {
        //
        foreach ($mainVotersList as $value) {
            if (array_key_exists(trim($value), $votes)) {
                return true;
            }
            AdminNotify::send('checkMainVotersList:' . $value . print_r($votes, true));
        }

        return false;
    }

    public static function getCountPostToday()
    {
        $postCount = GolosVoterBot::where('created_at', '>=', Date::today())->whereIn('status', [
            'wait-30',
            'wait-more',
            'vote-with-upvote',
            'vote-with-upvote-and-comment',
            'vote-end'
        ])->get()->count();
        //AdminNotify::send('$postCount:' . print_r($postCount, true));
        if ( ! $postCount) {
            return 0;
        }

        return $postCount;

    }

    public function getStatusText($status)
    {
        $s = [
            'error'                        => 'Ошибка',
            'new'                          => 'Первичная Обработка',
            'wait-30'                      => 'Первые 30 минут',
            'wait-more'                    => 'Второй этап голосования',
            'vote-with-upvote'             => 'Среднее, Апвоут',
            'vote-with-upvote-and-comment' => 'Среднее, Апвоут, Коммент',
            'in_blacklist'                 => 'В черном списке',
            'limit_support'                => 'Лимит поддержки',
            'already'                      => 'Уже поддержан',
            'del'                          => 'Голосование удалено',
            'tag50'                        => 'Запрещенный тег',
        ];
        if (isset($s[$status])) {
            return $s[$status];

        } else {
            return $status;
        }

    }

    private function startPart()
    {
        if (str_contains($this->message['text'], '/start')) {
            $this->sendText('Установите главный аккаунт и другие настройки:  /menu');
            $this->setSettingBot([]);
            return true;
        }
    }


}
