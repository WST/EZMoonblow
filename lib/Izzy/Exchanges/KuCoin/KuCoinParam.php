<?php

namespace Izzy\Exchanges\KuCoin;

/**
 * Constants for KuCoin API parameter keys.
 */
class KuCoinParam
{
	// ───────────────────────── Common order fields ─────────────────────────

	const string Symbol = 'symbol';

	const string Side = 'side';

	const string Size = 'size';

	const string Price = 'price';

	const string Leverage = 'leverage';

	const string Type = 'type';

	const string TimeInForce = 'timeInForce';

	const string ReduceOnly = 'reduceOnly';

	const string MarginMode = 'marginMode';

	const string ClientOid = 'clientOid';

	const string PositionSide = 'positionSide';

	const string CloseOrder = 'closeOrder';

	// ───────────────────────── ST-order (TP/SL) fields ─────────────────────────

	const string StopPriceType = 'stopPriceType';

	const string TriggerStopUpPrice = 'triggerStopUpPrice';

	const string TriggerStopDownPrice = 'triggerStopDownPrice';

	// ───────────────────────── Order response fields ─────────────────────────

	const string OrderId = 'orderId';

	const string Id = 'id';

	const string Status = 'status';

	const string IsActive = 'isActive';

	const string DealSize = 'dealSize';

	const string DealFunds = 'dealFunds';

	const string CancelExist = 'cancelExist';

	const string FilledSize = 'filledSize';

	// ───────────────────────── Position fields ─────────────────────────

	const string CurrentQty = 'currentQty';

	const string AvgEntryPrice = 'avgEntryPrice';

	const string UnrealisedPnl = 'unrealisedPnl';

	const string MarkPrice = 'markPrice';

	const string RealLeverage = 'realLeverage';

	const string CrossMode = 'crossMode';

	const string IsOpen = 'isOpen';

	// ───────────────────────── Contract spec fields ─────────────────────────

	const string Multiplier = 'multiplier';

	const string LotSize = 'lotSize';

	const string TickSize = 'tickSize';

	const string TakerFeeRate = 'takerFeeRate';

	const string MakerFeeRate = 'makerFeeRate';

	const string TakerFixFee = 'takerFixFee';

	const string MakerFixFee = 'makerFixFee';

	const string Turnover24h = 'turnover';

	const string Volume24h = 'volumeOf24h';

	// ───────────────────────── Balance fields ─────────────────────────

	const string Currency = 'currency';

	const string Available = 'available';

	const string Balance = 'balance';

	const string AccountEquity = 'accountEquity';

	const string AvailableBalance = 'availableBalance';

	// ───────────────────────── Candle / kline params ─────────────────────────

	const string Granularity = 'granularity';

	const string From = 'from';

	const string To = 'to';

	const string StartAt = 'startAt';

	const string EndAt = 'endAt';

	// ───────────────────────── Ticker fields ─────────────────────────

	const string Last = 'last';

	const string SymbolName = 'symbolName';

	// ───────────────────────── Order constant values ─────────────────────────

	const string TypeMarket = 'market';

	const string TypeLimit = 'limit';

	const string SideBuy = 'buy';

	const string SideSell = 'sell';

	const string GTC = 'GTC';

	const string IOC = 'IOC';

	const string FOK = 'FOK';

	// ───────────────────────── Spot candle array indices ─────────────────────────

	const int SpotCandleTime = 0;

	const int SpotCandleOpen = 1;

	const int SpotCandleClose = 2;

	const int SpotCandleHigh = 3;

	const int SpotCandleLow = 4;

	const int SpotCandleVolume = 5;

	// ───────────────────────── Futures kline array indices ─────────────────────────

	const int FuturesKlineTime = 0;

	const int FuturesKlineOpen = 1;

	const int FuturesKlineHigh = 2;

	const int FuturesKlineLow = 3;

	const int FuturesKlineClose = 4;

	const int FuturesKlineVolume = 5;

	// ───────────────────────── Pagination ─────────────────────────

	const string Items = 'items';

	const string TotalNum = 'totalNum';

	const string CurrentPage = 'currentPage';

	const string PageSize = 'pageSize';
}
