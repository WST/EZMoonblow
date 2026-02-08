# EZMoonblow

This is a (very) simple (and still under construction) crypto trading bot project.

> **⚠️ WARNING:** Crypto trading can lead to losses. Please do not attempt to use this code unless you know very well
> what you are doing. The author assumes no responsibility for any possible consequences related to the use of this code.

## For Developers and AI Assistants

**📋 Important**: If you're contributing to this project or working with AI assistance, please
read [CONTRIBUTING.md](CONTRIBUTING.md) first. This file contains essential guidelines for code style, project
structure, and architectural patterns that must be followed.

### Recommended development environment

* Operating system: Linux, FreeBSD, NetBSD, macOS
* IDE: PhpStorm
* Shell: bash

## Features

### Core Trading System

- 🤖 Automated cryptocurrency trading strategies (DCA, Long/Short)
- 🏢 Multi-exchange support (Bybit, Gate, KuCoin)
- 📈 Spot and futures market trading
- 📊 Real-time market analysis and metrics collection
- 📉 Technical indicators (RSI, extensible framework)
- 💰 Balance tracking and portfolio monitoring
- 📋 Task queue system for asynchronous operations
- 🔄 Multi-process exchange monitoring
- 📊 RRD-based chart generation and data storage

### Telegram Integration

- 📊 Interactive candlestick chart building for any trading pairs
- 🏢 Multi-exchange support with easy switching
- 📈 Spot and futures market analysis
- ⏰ Various timeframes (1m, 3m, 5m, 15m, 30m, 1h, 2h, 4h, 6h, 12h, 1d, 1w, 1M)
- 🔔 Strategy signal notifications
- 💬 Interactive commands with inline buttons

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

### Configuration

#### Telegram Bot Setup

Add Telegram configuration to `config/config.xml`:

```xml
<telegram>
    <token>YOUR_BOT_TOKEN</token>
    <chat_id>YOUR_CHAT_ID</chat_id>
</telegram>
```

#### Exchange Configuration

Configure exchanges with trading pairs:

```xml
<exchanges>
    <exchange name="Bybit" key="..." secret="..." enabled="yes" demo="yes">
        <spot>
            <pair ticker="BTC/USDT" timeframe="15m" monitor="yes" trade="no" />
            <pair ticker="ETH/USDT" timeframe="15m" monitor="yes" trade="no" />
        </spot>
        <futures>
            <pair ticker="SOL/USDT" timeframe="15m" monitor="yes" trade="no" leverage="5" />
        </futures>
    </exchange>
</exchanges>
```

### Running Applications

* `./trader.php` - Main trading application
* `./analyzer.php` - Metrics collection and analysis
* `./notifier.php` - **Telegram notifications and interactive bot**

### Telegram Bot Usage

The notifier application (`./notifier.php`) provides both notification and interactive bot functionality:

#### Interactive Menu (Recommended)

Use `/menu` for the most convenient experience:

1. **📊 Build Chart** - Step-by-step chart building:
    - Select exchange (Bybit, Gate, KuCoin)
    - Choose market type (Spot/Futures)
    - Pick trading pair
    - Select timeframe
    - Get instant chart

2. **❓ Help** - Get assistance

#### Basic Commands

- `/start` — Welcome message
- `/help` — Detailed command reference
- `/menu` — Interactive menu with buttons

### Test system

* `./tasks/dev/run-tests` performs quick system check-up.

## TODO

* Docker support
* TA module
* Support for more exchanges
* Web management interface
* User data support (user-defined indicators, strategies)
* Support for position hedging (having both Short and Long positions open at the same time)
* Configurable notifications with Telegram & XMPP support
