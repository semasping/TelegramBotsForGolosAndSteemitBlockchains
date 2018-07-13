Telegram bots for Steem and Golos blockchains.


Contains:
* Bot for notification about new post in blockchains
* Bot for groping curations of posts


Usage:

Application use Redis and MongoDB for own needs. You have to install them on your server.

```bash
git clone https://github.com/semasping/TelegramBotsForGolosAndSteemitBlockchains

composer install

# setup setting in .env file:
# * db settings
# * telegram keys 

php artisan migrate
```







## License

My telegram bots is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
