# EZMoonblow

This is a (very) simple (and still under construction) crypto trading bot project.

> **⚠️ WARNING:** Crypto trading  can lead to losses. Please do not attempt to use this code unless you know very well what you are doing. The author assumes no responsibility for any possible consequences related to the use of this code. 

## For Developers and AI Assistants

**📋 Important**: If you’re contributing to this project or working with AI assistance, please read [CONTRIBUTING.md](CONTRIBUTING.md) first. This file contains essential guidelines for code style, project structure, and architectural patterns that must be followed.

### Recommended development environment

* Operating system: Linux, FreeBSD, NetBSD, macOS
* IDE: PhpStorm
* Shell: bash

## Usage

### Software requirements

* Operating system: Linux, FreeBSD, NetBSD, macOS
* PHP version: 8.3 or greater
* MySQL version 5.7 or greater

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

## TODO

* Docker support
* Strategy backtesting
* TA module
* Support for more exchanges
* Web management interface
* User data support (user-defined indicators, strategies)
* Support for position hedging (having both Short and Long positions open at the same time)
* Configurable notifications with Telegram & XMPP support