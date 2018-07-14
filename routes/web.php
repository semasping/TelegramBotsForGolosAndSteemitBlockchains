<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

use App\MongoModels\PostForCuration;

Route::post('/weioth/webhook', 'GolosVoterBotController@webHookUpdate');

//можно сделать имя бота параметром. или base16 и выдергивать из базы. нам всё равно нужна будет куча ботов.
Route::get('/setwebhook', 'BoteController@setWebHook');
Route::get('/removewebhook', 'BoteController@removeWebHook');



Route::get('/', function () {
    return view('welcome');
});


Route::get('/test_mongo', function () {

    $post = new PostForCuration;
    //$post->set
    $post->url = 'ewetr';
    $post->save();
    \App\semas\AdminNotify::send('Test MongoDB^ '. print_r($post,true));
});




Route::get('/gvb/setwebhook', 'GolosVoterBotController@setWebHook');
Route::get('/gvb/removewebhook', 'GolosVoterBotController@removeWebHook');
Route::get('/gvb/show', 'GolosVoterBotController@showPosts');

Route::post('/_gvb_/webhook', 'GolosVoterBotController@webHookUpdate');