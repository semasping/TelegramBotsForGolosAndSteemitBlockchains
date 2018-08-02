/**
 * Created by semas on 08.11.2017.
 */

const args = require('yargs').argv;
const golos = require('golos-js');

//golos.config.set('websocket', 'wss://api.golos.cf');
golos.config.set('websocket', 'wss://ws.golos.io');
/*golos.config.set('address_prefix', 'GLS');
golos.config.set('chain_id', '782a3039b478c839e4cb0c941ff4eaeb7df40bdd68bd441afd444b9da763de12');*/


var account = args.acc; //<< сюда вписываем аккаунт робота который будет апвоутить посты по вызову пользователя
//var account = 'semasping'; //<< сюда вписываем аккаунт робота который будет апвоутить посты по вызову пользователя
var k = args.key; //<< сюда вписываем приватный постинг ключ робота
var body = args.body; //
//body = 'test2';

var parentAuthor = args.pa;
//parentAuthor = 'semasping';
var parentPermlink = args.pp;
//parentPermlink = 'skript-statistiki-akkaunta-v-0-2';



function comment(wwww) {
    var commentPermlink = golos.formatter.commentPermlink(parentAuthor.replace(/./g,''), parentPermlink);
    console.log(commentPermlink);
    var ce = golos.broadcast.comment(wwww, parentAuthor, parentPermlink, account, commentPermlink, '', body, '', function(err, result) {
        console.log(err, result);
    });

    console.log('re=' + ce);
    return ce;
}

comment(k);


function ex() {
    process.exit(1);
}
setTimeout(ex, 10000);




