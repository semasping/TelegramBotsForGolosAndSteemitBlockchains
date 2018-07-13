<?php
/**
 * Created by PhpStorm.
 * User: semasping (semasping@gmail.com)
 * Date: 08.07.2018
 * Time: 22:43
 */

namespace App\Http\Controllers;


use App\semas\AdminNotify;
use Exception;
use Illuminate\Support\Facades\URL;
use Telegram\Bot\Laravel\Facades\Telegram;

class BoteController extends Controller
{

    protected $token = 'weioth';
    private $bot_name = 'golos_post_bot';

        public function getApiKeyBot()
    {
        return getenv('TELEGRAM_BOT_TOKEN_' . $this->bot_name);
    }

    public function setWebHook()
    {
        $url = URL::to('/'. $this->token .'/webhook');
        $response = Telegram::setAccessToken($this->getApiKeyBot())->setWebhook(['url' => $url]);
        dump($response);
        return 'ok';
    }

    public function removeWebHook()
    {
        $response = Telegram::setAccessToken($this->getApiKeyBot())->removeWebhook();
        dump($response);
        return 'ok';
    }

    public function webHookUpdate()
    {
        try {




            Telegram::setAccessToken($this->getApiKeyBot())->addCommands([
                \App\TCommands\Test2Command::class,
                \App\TCommands\TestTestTestCommand::class,
                \App\TCommands\Test3Command::class,
            ]);


            $updates = Telegram::setAccessToken($this->getApiKeyBot())->commandsHandler(true);

            if ($this->callbackQueryPart($updates)) {
                AdminNotify::send('CallBack: ' . print_r($updates, true));

                return response('ok', 200);
            }

            AdminNotify::send('test: ' . print_r($updates, true));
            return response('ok', 200);
        }catch (\Exception $e){
            AdminNotify::send('Error: ' . print_r($e, true));
            return response('ok', 200);
        }
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


}