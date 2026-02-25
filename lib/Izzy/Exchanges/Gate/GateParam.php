<?php

namespace Izzy\Exchanges\Gate;

/**
 * Constants for Gate.io API v4 parameter keys.
 */
class GateParam
{
	const string Contract = 'contract';

	const string CurrencyPair = 'currency_pair';

	const string Size = 'size';

	const string Price = 'price';

	const string Side = 'side';

	const string Amount = 'amount';

	const string Type = 'type';

	const string Tif = 'tif';

	const string Text = 'text';

	const string ReduceOnly = 'reduce_only';

	const string Close = 'close';

	const string AutoSize = 'auto_size';

	const string Iceberg = 'iceberg';

	const string Status = 'status';

	const string Id = 'id';

	const string Left = 'left';

	const string FillPrice = 'fill_price';

	const string Settle = 'settle';

	const string Interval = 'interval';

	const string Limit = 'limit';

	const string From = 'from';

	const string To = 'to';

	const string Last = 'last';

	const string MarkPrice = 'mark_price';

	const string EntryPrice = 'entry_price';

	const string UnrealisedPnl = 'unrealised_pnl';

	const string Leverage = 'leverage';

	const string Mode = 'mode';

	const string Currency = 'currency';

	const string Available = 'available';

	const string Total = 'total';

	const string QuantoMultiplier = 'quanto_multiplier';

	const string OrderPriceRound = 'order_price_round';

	const string OrderSizeMin = 'order_size_min';

	const string OrderSizeMax = 'order_size_max';

	const string MakerFeeRate = 'maker_fee_rate';

	const string TakerFeeRate = 'taker_fee_rate';

	const string PositionIdx = 'position_idx';

	const string Volume24hQuote = 'volume_24h_quote';

	const string Name = 'name';

	const string DualMode = 'dual_mode';

	const string CrossLeverageLimit = 'cross_leverage_limit';

	const string Turnover24h = 'volume_24h_settle';

	const string InDualMode = 'in_dual_mode';

	const string FinishAs = 'finish_as';

	const string OrderType = 'order_type';

	const string Initial = 'initial';

	const string Trigger = 'trigger';

	const string StrategyType = 'strategy_type';

	const string PriceType = 'price_type';

	const string Rule = 'rule';

	const string Expiration = 'expiration';

	const string TypeMarket = 'market';

	const string TypeLimit = 'limit';

	const string SideBuy = 'buy';

	const string SideSell = 'sell';

	const string CandleTime = 't';

	const string CandleOpen = 'o';

	const string CandleHigh = 'h';

	const string CandleLow = 'l';

	const string CandleClose = 'c';

	const string CandleVolume = 'v';

	const int SpotCandleTime = 0;

	const int SpotCandleVolume = 1;

	const int SpotCandleClose = 2;

	const int SpotCandleHigh = 3;

	const int SpotCandleLow = 4;

	const int SpotCandleOpen = 5;
}
