<?php
/**
 * Created by PhpStorm.
 * User: semasping (semasping@gmail.com)
 * Date: 06.11.2017
 * Time: 3:00
 */

namespace App\Jobs;

use App\GolosBotsSettings;
use App\GolosVoterBot;
use App\semas\AdminNotify;
use App\semas\GolosApi;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GolosVoterBotDoComment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $post;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($post)
    {
        $this->post = $post;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {

            $acc = $this->getSettingBot('account', '', $this->post['chat_id'], 'GolosVoterBot');
            dump('postcomment', $this->post->toArray());
            $chp = GolosVoterBot::where('id', $this->post->id)->get(['status'])->first()->toArray();
            $data = $this->post->data;
            $comment = GolosApi::check_comment($this->post, $acc);
            if ($chp['status'] == 'vote-end' && ! isset($data['do_comment']) && $this->post->data['avrg_vote']>0 && $comment->count()==0) {
                dump($chp);
                $key = decrypt($this->getSettingBot('key', '', $this->post->chat_id, 'GolosVoterBot'));
                $acc = $this->getSettingBot('account', '', $this->post->chat_id, 'GolosVoterBot');
                $link = $this->post->link;
                $result = explode('/@', $link);
                $permlink = explode("/", $result[1]);
                $permlink = $permlink[1];

                /** @var \Illuminate\Support\Collection $body */
                $body = $this->getComment();
                if ( ! empty($body)) {
                    if (GolosApi::comment($acc, $key, $permlink, $this->post->author, $body)) {

                        $data['do_comment'] = true;
                        $this->post->data = $data;
                        //$this->post->status = 'vote-with-upvote-and-comment';
                        $this->post->save();
                    } else {
                        AdminNotify::send('ЧТо то пошло не так #GolosVoterBotDoComment.' . print_r($this->post->toArray(), true));

                    }
                }else{
                    $data['do_comment'] = true;
                    $this->post->data = $data;
                    //$this->post->status = 'vote-with-upvote-and-comment';
                    $this->post->save();
                }
            }elseif ($comment->count()>0){
                $data['do_comment'] = true;
                $this->post->data = $data;
                //$this->post->status = 'vote-with-upvote-and-comment';
                $this->post->save();
            }


        } catch (Exception $e) {
            echo "Exception on " . "GolosVoterBotDoComment message: " . $e->getMessage() . '|' . $e->getLine() . '|' . $e->getCode();
            AdminNotify::send("Exception on " . "#GolosVoterBotDoComment message: " . $e->getMessage() . '|' . $e->getLine() . '|' . $e->getCode());

            return;
            //dump($this->ad);
        }
        echo "\n";
    }

    private function getSettingBot($key, $def = '', $chatid, $bot_name)
    {
        $settings = GolosBotsSettings::where('chat_id', $chatid)->where('bot_name', $bot_name)->first();
        // AdminNotify::send(print_r($settings, true));

        $data = $settings->data;
        $data = collect($data);

        // AdminNotify::send(print_r($data, true));

        return $data->get($key, $def);
    }

    private function getComment()
    {
        $type = 'comment_list_not_subscribe';
        $acc = $this->getSettingBot('account', '', $this->post->chat_id, 'GolosVoterBot');

        $follow = GolosApi::checkFollow($acc, $this->post->author);
        if ($follow) {
            $type = 'comment_list_subscribe';
        }
dump ($type);
        return collect($this->getSettingBot($type, '', $this->post->chat_id, 'GolosVoterBot'))->get($this->post->data['avrg_vote'], '');
    }
}