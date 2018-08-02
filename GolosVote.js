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
var k = args.key; //<< сюда вписываем приватный постинг ключ робота
var percent = 10000; // voter power (сила апа)


var parentAuthorAns = args.pa;
var parentPermlinkAns = args.pp;



function vote(wwww) {
    var re = golos.broadcast.vote(wwww, account, parentAuthorAns, parentPermlinkAns, percent, function (err, result) {
        if (err) console.log(err, result);
    });  //голосуем за пост;
    console.log('re=' + re);
    return re;
}

vote(k);


function ex() {
    process.exit(1);
}
setTimeout(ex, 10000);
