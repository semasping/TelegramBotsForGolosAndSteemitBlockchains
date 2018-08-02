<?php
/**
 * Created by PhpStorm.
 * User: semasping (semasping@gmail.com)
 * Date: 06.11.2017
 * Time: 3:01
 */

namespace App\Jobs;

use App\GolosBotsSettings;
use App\GolosVoterBot;
use App\semas\AdminNotify;
use App\semas\GolosApi;
use App\STBotsHelpers\Shares;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GolosVoterBotDoVote implements ShouldQueue
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
            //dump('post',$this->post->toArray());
            $chp = GolosVoterBot::where('id',$this->post->id)->get()->first()->toArray();
            $data = $chp['data'];
            if ($chp['status']=='vote-end' && !isset($data['do_vote']) && $this->post->data['avrg_vote']>0) {
                dump($chp);
                $key = decrypt($this->getSettingBot('key', '', $this->post->chat_id, 'GolosVoterBot'));
                $acc = $this->getSettingBot('account', '', $this->post->chat_id, 'GolosVoterBot');
                $link = $this->post->link;
                $result = explode('/@', $link);
                $permlink = explode("/", $result[1]);
                $permlink = $permlink[1];
                dump($acc, $key, $permlink, $this->post->author);
                if (GolosApi::vote($acc, $key, $permlink, $this->post->author)) {
                    $data['do_vote'] = true;
                    $this->post->data = $data;
                    //$this->post->status = 'vote-with-upvote';
                    $this->post->save();
                } else {
                    AdminNotify::send('ЧТо то пошло не так. #GolosVoterBotDoVote' . print_r($this->post->toArray(), true));

                }
            }


        } catch (Exception $e) {
            echo "Exception on " . "GolosVoterBotDoVote message: " . $e->getMessage() . '|' . $e->getLine() . '|' . $e->getCode();
            AdminNotify::send("Exception on " . "#GolosVoterBotDoVote message: " . $e->getMessage() . '|' . $e->getLine() . '|' . $e->getCode());

            return;
            //dump($this->ad);
        }
        echo "\n";
    }

    private function getSettingBot($key, $def = '', $chatid, $bot_name)
    {
        return Shares::getBotSettings($bot_name,$chatid,$key,$def);

    }
}