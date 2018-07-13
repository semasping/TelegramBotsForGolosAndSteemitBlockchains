<?php

namespace App\Jobs;

use App\BchBlock;
use App\semas\BchApi;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class BchProcessNewPostCreated implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    private $block_raw;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($block_raw)
    {
        $this->block_raw = $block_raw;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $post = $this->post;
            $tags = '';
            $chats_id = GolosNewPostUserBot::where('status_new_posts', GolosNewPostBotController::STATUS_NEW_POST_ON)->get();
            $jd = json_decode($this->post['json_metadata']);
            if (isset($jd->tags)) {
                $tags = $this->decodeTags($jd->tags);
            }
        } catch (Exception $e) {
            echo "Exception on " . " message: " . $e->getMessage() . '|' . $e->getLine() . '|' . $e->getCode() . "\n";

            // try to get this block later
            $this->release(5);

            exit;
        }

        //file_get_contents('https://hchk.io/952518ea-65f5-410d-979e-58a6d6b016f2'); //@stodo change number

    }
}
