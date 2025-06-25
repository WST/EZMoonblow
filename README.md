# EZMoonblow

This is a (very) simple (and still under construction) crypto trading bot project.

> **⚠️ WARNING:** Crypto trading  can lead to losses. Please do not attempt to use this code unless you know very well what you are doing. The author assumes no responsibility for any possible consequences related to the use of this code. 

## Usage

### Installing

```bash
git clone git@github.com:WST/EZMoonblow.git
cd EZMoonblow
composer install
cp config/config.xml.example config/config.xml
# edit your config.xml
./tasks/db/migrate
```

### Usage

* `./trader.php` for trading,
* `./analyzer.php` for collecting metrics,
* `./notifier.php` for sending Telegram notifications.

### Test system
* `./tasks/dev/run-tests` performs quick system check-up.

## TODO (maybe)

* Docker support
* TA module