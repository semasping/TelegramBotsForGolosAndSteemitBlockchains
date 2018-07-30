<?php

namespace App\Console\Commands;

use App\GolosBotsSettings;
use App\GolosVoterBot;
use App\Http\Controllers\GolosVoterBotController;
use App\semas\AdminNotify;
use App\semas\GolosApi;
use App\STBotsHelpers\Shares;
use Illuminate\Console\Command;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Jenssegers\Date\Date;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Laravel\Facades\Telegram;

class checkPostVoterBot extends Command
{
    use DispatchesJobs;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'GolosVoterBot:checkPosts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';
    private $bot_name = 'GolosVoterBot';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        $need = [];
        $posts1 = GolosVoterBot::where('status', 'vote-end')->get();
        dump('posts to check', $posts1->toArray());
        foreach ($posts1 as $post) {
            //dump('post to check', $post->toArray());
            $acc = $this->getSettingBot($post['chat_id'], 'GolosVoterBot', 'account');

            $break = false;
            $data = $post['data'];
            if (isset($data['do_vote'])&&$data['do_vote']==true) {
                $link = $post['link'];
                $result = explode('/@', $link);
                $permlink = explode("/", $result[1]);
                $permlink = $permlink[1];
                $votes = GolosApi::getPostVotes($post['author'], $permlink);
                $votes = collect($votes);
                $vote = $votes->where('voter', $acc);
                if ($vote->count() == 0) {
                    unset($data['do_vote']);
                    $need['do_vote'] = $post->link;

                }
                if ($vote->count() > 0) {
                    $data['do_vote_checked'] = true;
                }
                $post->data = $data;
                $post->save();

                //dump('dovote', $post->toArray());
            }
            if (isset($data['do_comment'])&&$data['do_comment']==true) {
                $comment = GolosApi::check_comment($post, $acc);
                if ($comment->count() == 0) {
                    unset($data['do_comment']);
                    $need['do_comment'] = $post->link;
                }
                if ($comment->count() > 0) {
                    $data['do_comment_checked'] = true;

                }
                $post->data = $data;
                $post->save();

                //dump('docоmment', $post->toArray());
            }
            if (isset($data['do_vote_checked']) && isset($data['do_comment_checked'])) {
                if ($data['do_vote_checked'] && $data['do_comment_checked']) {
                    $post['status'] = 'vote-with-upvote-and-comment';
                    $post->save();

                }
            } else {
                dump('neeeeddddd', $post->toArray());
                $need[] = $post->link;
            }
        }
        dump('neeeeeeeeesssssssssssddddddd', $need);
        if ( ! empty($need)) {
            AdminNotify::send('Needdddd' . print_r($need, true));
        }

        $posts = GolosVoterBot::where('status', 'vote-end')->get();
        //dump('vote-end', $posts->toArray());
        foreach ($posts as $post) {
            //dump($post->toArray());
            $break = false;
            $data = $post['data'];
            if ( ! isset($data['do_vote'])) {
                $this->dispatch((new \App\Jobs\GolosVoterBotDoVote($post))->onQueue(getenv('VOTE_BOT_QUEUE_KEY')));
                $break = true;
            }
            if ( ! isset($data['do_comment'])) {
                $this->dispatch((new \App\Jobs\GolosVoterBotDoComment($post))->onQueue(getenv('VOTE_BOT_QUEUE_KEY'))->delay(Date::now()->addSeconds(25)));
                $break = true;

            }
            if ($break) {
                break;
            }
        }


        $posts = GolosVoterBot::where('status', 'wait-more')->get();
        dump('wait-more', $posts->toArray());
        foreach ($posts as $post) {
            $data = collect($post->data);
            $stop = $data->get('vote_stop')['date'];
            echo $stop;
            if (Date::parse($stop)->lessThanOrEqualTo(Date::now())) {
                $mainVotersList = $this->getSettingBot($post['chat_id'], 'GolosVoterBot', 'main_voters_list', []);
                if (GolosVoterBotController::checkMainVotersList($data->get('vote', []), $mainVotersList)) {
                    $data = $post->data;
                    $data['avrg_vote'] = $this->getAvrgVote(collect($data)->get('vote', []));
                    if ($data['avrg_vote'] == 0) {
                        $post->status = 'Не оценивать';
                    } else {
                        $post->status = 'vote-end';
                    }

                    $post->data = $data;
                    $post->save();
                    $resp = Telegram::setAccessToken($this->getApiKeyBot())->editMessageReplyMarkup([
                        'inline_message_id' => $post->inline_message_id,
                        'reply_markup'      => $this->getKeyboard('text', collect([
                            'text' => "Среднее: " . GolosVoterBotController::getGrade($data['avrg_vote'])
                        ])),
                        //'text'              => 'удалено'
                    ]);
                }else{
                    $post->status = 'Не одобрено';
                    $post->save();
                    $resp = Telegram::setAccessToken($this->getApiKeyBot())->editMessageReplyMarkup([
                        'inline_message_id' => $post->inline_message_id,
                        'reply_markup'      => $this->getKeyboard('text', collect([
                            'text' => "Не одобрено куратором"
                        ])),
                        //'text'              => 'удалено'
                    ]);
                }
            }

        }


        $posts = GolosVoterBot::where('status', 'wait-30')->get();
        dump('wait-30', $posts->toArray());
        foreach ($posts as $post) {
            $voters_time_1 = $this->getSettingBot($post->chat_id, 'GolosVoterBot', 'voter_count_first_time');
            $data = $post->data;
            if (Date::parse($post->created_at)->addMinutes(30)->lessThanOrEqualTo(Date::now())) {
                if (isset($data['vote']) && count($data['vote']) >= $voters_time_1) {
                    $avrg = $this->getAvrgVote($data['vote']);
                    $resp = Telegram::setAccessToken($this->getApiKeyBot())->editMessageReplyMarkup([
                        'inline_message_id' => $post->inline_message_id,
                        'reply_markup'      => $this->getKeyboard('text', collect([
                            'text' => "Cреднее: " . GolosVoterBotController::getGrade($avrg)
                        ])),
                    ]);
                    $data['avrg_vote'] = $this->getAvrgVote($data['vote']);
                    if ($data['avrg_vote'] == 0) {
                        $post->status = 'Не оценивать';
                    } else {
                        $post->status = 'vote-end';
                    }

                } else {
                    $post->status = 'wait-more';
                    $hours_wait_time_2 = $this->getSettingBot($post->chat_id, 'GolosVoterBot', 'hours_count_second_time', 3);
                    $data = $post->data;
                    $data['vote_stop'] = Date::now()->addHours($hours_wait_time_2);
                }

                $post->data = $data;
                $post->save();
            }

        }

        $posts = GolosVoterBot::where('status', 'new')->get();
        dump('new', $posts->toArray());
        foreach ($posts as $post) {
            try {
                if (Date::parse($post->created_at)->addMinutes(1)->lessThanOrEqualTo(Date::now())) {
                    $post->status = 'error';
                    $post->save();
                }
            } catch (\Exception $e) {
                dump($e->getTrace()[0]);
                //AdminNotify::send('del new'.print_r($e->getTrace()[0][0], true));
            }

        }


    }

    private function getSettingBot($chat_id, $bot_name, $key, $def = '')
    {
        return Shares::getBotSettings($bot_name,$chat_id,$key,$def);
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
        return $av;//GolosVoterBotController::getGrade($av);

    }

    public function getApiKeyBot()
    {
        return getenv('TELEGRAM_BOT_TOKEN_' . $this->bot_name);
    }

    private function getKeyboard($string, $collect)
    {
        $keyboard['text'] = Keyboard::make()->inline()->row(Keyboard::inlineButton([
            'text'          => $collect->get('text'),
            'callback_data' => 'no'
        ]));

        return $keyboard[$string];
    }

    /**
     * @param $post
     * @param $acc
     *
     * @return array
     */
    private function check_comment($post, $acc)
    {
        $link = $post['link'];
        $result = explode('/@', $link);
        $permlink = explode("/", $result[1]);
        $permlink = $permlink[1];
        $comments = GolosApi::getComments($post['author'], $permlink);
        $comments = collect($comments);
        $comment = $comments->where('author', $acc);

        return $comment;
    }
}
