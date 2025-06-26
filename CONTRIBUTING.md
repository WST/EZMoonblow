# Contributor Rules for EZMoonblow Project

This document contains rules and guidelines for developers & AI assistants working with the EZMoonblow cryptocurrency trading system. Please follow these rules strictly when making any changes to the codebase.

## Code Style Rules

### 1. Brace Placement
- **Functions, loops, conditionals, try blocks**: Opening brace on the same line as the statement
  ```php
  if ($condition) {
      // code
  }
  
  function example() {
      // code
  }
  ```

- **Classes, enums, interfaces, traits**: Opening brace on a new line
  ```php
  class Example
  {
      // code
  }
  
  enum Status
  {
      // code
  }
  ```

### 2. Indentation
- Use **tabs** for indentation, not spaces
- Maintain consistent indentation throughout the project

### 3. File Structure
- End each file with exactly **one empty line** for convenient `cat` command output
- **Never** include closing PHP tag (`?>`) at the end of files

### 4. Comments and Documentation
- **All comments must be in English** - no Russian comments allowed.
- If Russian comments are found, translate them to English as part of the current task.
- Keep PHPDoc comments up-to-date with method signatures.
- When modifying method signatures, update corresponding PHPDoc documentation.
- **Use typographic quotes** (“curly quotes”) and apostrophes (’) instead of straight quotes (") and single quotes (') in comments and documentation.
- End the comments with a dot.

### 5. Comment Inheritance
- Place method comments as high as possible in the inheritance hierarchy
- If a method already has a comment in a parent class or interface, use `@inheritDoc` instead of duplicating

### 6. Method Design
- Avoid overly long methods
- Separate responsibilities appropriately
- Follow single responsibility principle

### 7. Type Hinting
- **Always use type hints** for all parameters and return values
- Include return type declarations for all methods and functions
- Use strict typing where possible

### 8. Money Handling
- **Always use the `Money` class** for monetary amounts
- Never use plain floats or integers for currency values
- This ensures proper currency handling and prevents precision issues

### 9. Functional Programming Approach
- **Prefer functional methods** over loops where possible:
  - Use `array_map()` instead of foreach loops for transformations
  - Use `array_reduce()` for aggregations
  - Use `array_walk()` for side effects
- **Use modern PHP features** for more concise code:
  - Null coalesce operator (`??`)
  - Null safe operator (`?->`)
  - Arrow functions (`fn()`) instead of `function()` where possible
  - Array destructuring
  - Match expressions over switch statements

## Project Structure

### Application Types

The project contains two types of applications:

#### Persistent Applications (Daemons)
```
├── trader.php      # Main trading application - central component
├── analyzer.php    # Chart generation and analysis
└── notifier.php    # Telegram notifications (trades, signals)
```

#### One-time Applications
Located in `tasks/` directory, organized by functionality:
```
tasks/
├── db/            # Database operations
│   ├── migrate    # Run migrations
│   └── shell      # Database shell
├── dev/           # Development tools
│   ├── run-server # Development server
│   └── run-tests  # Test runner
└── backtests/     # Future: backtesting tools
```

### Core Library Structure (`lib/Izzy/`)

```
lib/Izzy/
├── AbstractApplications/    # Base application classes
│   ├── ConsoleApplication.php
│   └── WebApplication.php
├── Chart/                  # Charting and visualization
│   ├── Chart.php
│   └── Image.php
├── Configuration/          # Configuration management
│   ├── Configuration.php
│   └── ExchangeConfiguration.php
├── Enums/                  # Enumerated types (avoid strings)
│   ├── MarketTypeEnum.php
│   ├── PositionDirectionEnum.php
│   └── TimeFrameEnum.php
├── Exchanges/              # Exchange drivers
│   ├── AbstractExchangeDriver.php
│   ├── Bybit.php
│   ├── Gate.php
│   └── KuCoin.php
├── Financial/              # Core financial components
│   ├── Candle.php
│   ├── IndicatorResult.php
│   ├── Market.php
│   ├── Money.php
│   ├── Pair.php
│   └── Position.php
├── Indicators/             # Technical indicators
│   ├── AbstractIndicator.php
│   ├── IndicatorFactory.php
│   └── RSI.php
├── Interfaces/             # Interface definitions
│   ├── ICandle.php
│   ├── IExchangeDriver.php
│   ├── IIndicator.php
│   ├── IMarket.php
│   ├── IPair.php
│   ├── IPosition.php
│   └── IStrategy.php
├── RealApplications/       # Concrete application implementations
│   ├── Analyzer.php
│   ├── Installer.php
│   ├── Notifier.php
│   └── Trader.php
├── Strategies/             # Trading strategies
│   ├── AbstractDCAStrategy.php
│   ├── DCASettings.php
│   ├── EZMoonblowAbstractDCA.php
│   ├── Strategy.php
│   └── StrategyFactory.php
├── System/                 # System utilities
│   ├── Database.php
│   ├── DatabaseMigrationManager.php
│   └── Logger.php
└── Traits/                 # Reusable functionality
    ├── HasMarketTypeTrait.php
    └── SingletonTrait.php
```

### Key Architectural Concepts

#### 1. Enum Usage
- Use enums in `lib/Izzy/Enums/` for all enumerated types.
- Avoid string literals for status, types, etc.
- Example: `MarketTypeEnum::SPOT` instead of `"spot"`.

#### 2. Traits for Shared Functionality.
- Place reusable functionality in `lib/Izzy/Traits/`.
- Use traits to avoid code duplication across classes.

#### 3. Interface-First Design
- Define interfaces in `lib/Izzy/Interfaces/`.
- Implement interfaces in concrete classes.
- This enables loose coupling and testability.

#### 4. Trading Strategies
- Located in `lib/Izzy/Strategies/`.
- Strategies interact with: `Market`, `Indicator`, `Strategy`, `Position`.
- **Important**: Strategies should not directly access exchange drivers.
- `Market` provides high-level trading terminal interface.
- `AbstractExchangeDriver` and implementations provide low-level exchange communication.

#### 5. Database Migrations
- **Critical**: This is a custom migration system, not a third-party one.
- Always examine `DatabaseMigrationManager` class before writing migrations.
- Study existing migrations in `migrations/` folder.
- Don’t write migrations “blindly” — understand the system first.

#### 6. Pair vs Market Distinction
```
Pair (Configuration)
├── Stores basic pair information
├── Configuration-oriented
├── Static data (ticker, timeframe, exchange)
└── No business logic

Market (Business Logic)
├── Contains trading business logic
├── Dynamic data (candles, indicators, positions)
├── Trading operations
└── Strategy integration
```

#### 7. Financial Components
- Core financial classes in `lib/Izzy/Financial/`
- Includes: `Money`, `Position`, `Candle`, `Market`, `Pair`
- These are the building blocks for all trading operations

#### 8. Charting System
- Located in `lib/Izzy/Chart/`
- Handles candlestick chart generation
- **Future**: Will support volumes, open interest, positions, DCA grids
- Extensible for new visualization features

### Development Guidelines

#### When Working with AI Assistant:
1. **Always** reference this file when making changes
2. **Translate** any Russian comments to English
3. **Update** PHPDoc when changing method signatures
4. **Follow** the established project structure
5. **Use** appropriate design patterns (interfaces, traits, enums)
6. **Maintain** separation of concerns between components

#### Common Pitfalls to Avoid:
- Don't confuse `Pair` and `Market` classes
- Don't write migrations without understanding the custom system
- Don't let strategies access exchange drivers directly
- Don't use strings instead of enums
- Don't forget to update documentation when changing code

---

**Remember**: This project follows a specific architecture designed for cryptocurrency trading. Always consider the impact of changes on the overall system design and maintain consistency with existing patterns.
