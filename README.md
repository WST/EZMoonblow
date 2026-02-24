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

- 🤖 Multiple trading strategies:
    - **DCA** — RSI-based Dollar-Cost Averaging with configurable order grids, volume multipliers, and price deviation levels
    - **DCA (Long & Short)** — Two-Way Mode for holding simultaneous long and short positions
    - **Always-Long DCA** — DCA strategy with no signal filter, always enters long
    - **Single Entry** — mean-reversion and trend-following strategies (RSI, Bollinger Bands, MACD, Ichimoku Cloud, Logistic Regression)
- 📉 Technical indicators: RSI, MACD, Bollinger Bands, EMA, ADX, Ichimoku Cloud, ATR
- 🏢 Multi-exchange architecture (Bybit, Gate, KuCoin)
- 📈 Spot and futures market trading with configurable leverage
- 💰 Balance and equity tracking, real-time portfolio monitoring
- 📋 Task queue system for asynchronous operations
- 🔄 Multi-process exchange monitoring
- 📊 RRD-based chart generation and data storage
- 🛡️ Risk management: stop-loss, take-profit, breakeven lock, partial close, stop-loss cooldown

### Telegram Integration

- 📊 Interactive candlestick chart building for any trading pair
- 🏢 Multi-exchange support with easy switching
- 📈 Spot and futures market analysis
- ⏰ Various timeframes (1m, 3m, 5m, 15m, 30m, 1h, 2h, 4h, 6h, 12h, 1d, 1w, 1M)
- 🔔 Trade notifications (position opened, closed, DCA fill, liquidation)
- 💬 Interactive commands with inline buttons

### Backtesting System

- 🧪 Visual backtester with real-time SSE streaming — watch the simulation unfold on a candlestick chart
- 🎯 Configurable ticks-per-candle resolution (intra-candle price interpolation to avoid lookahead bias)
- 📊 Equity curve generation with RRD charts
- 💹 Comprehensive result metrics: PnL, win rate, max drawdown, Sharpe/Sortino ratios, exchange commissions
- 🔀 Parallel backtest execution via isolated position tables
- ⏹️ Abort running simulations from the UI
- 🧬 **Optimizer** — a daemon that periodically mutates strategy parameters and backtests them, notifying when a mutation improves PnL
- 🔍 **Screener** — a daemon that periodically picks random top-volume trading pairs from the exchange and backtests them with configured strategies

### Web Management Interface

- 📋 **Dashboard** — overview of the trading system state
- 💱 **Pairs** — configured trading pairs with live market data and DCA order grids
- 📂 **Positions** — currently open and pending positions
- 🧪 **Backtest** — visual backtester with strategy parameter editor, real-time chart, and order grid sidebar
- 📊 **Results** — backtest result history with filtering (Manual / Auto mode)
- 🧬 **Optimizations** — parameter improvement suggestions from the Optimizer
- 📈 **Candles** — candlestick chart viewer
- 🖥️ **System Status** — health of all daemon components (Trader, Analyzer, Notifier, Optimizer, Screener)
- 🔒 Authentication support

## Usage

### Software requirements

* Operating system: Linux, FreeBSD, NetBSD, macOS
* PHP version: 8.4 or greater
* MySQL version 5.7 or greater
* Docker deployments are recommended

### Installing (native)

```bash
git clone git@github.com:WST/EZMoonblow.git
cd EZMoonblow
composer install
cp config/config.xml.example config/config.xml
# edit your config.xml
./tasks/db/migrate
```

### Installing (Docker)

```bash
git clone git@github.com:WST/EZMoonblow.git
cd EZMoonblow
cp config/config.xml.example config/config.xml
cp docker-compose.yml.example docker-compose.yml
# edit your config.xml & docker-compose.yml
docker compose up
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

* `./trader.php` ← Main trading application
* `./analyzer.php` ← Metrics collection, chart generation, and periodic cleanup
* `./notifier.php` ← Telegram notifications and interactive bot
* `./optimizer.php` ← Automated strategy parameter optimization daemon
* `./screener.php` ← Random pair screening — backtests top-volume pairs with configured strategies

All five daemons are designed to run continuously. Their status is monitored on the System Status page of the web interface.

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

## TODO

* TA module
* Support for more exchanges
* User data support (user-defined indicators, strategies)
* Configurable notifications with Telegram & XMPP support
