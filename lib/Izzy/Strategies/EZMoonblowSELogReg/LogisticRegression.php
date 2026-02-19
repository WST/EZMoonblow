<?php

namespace Izzy\Strategies\EZMoonblowSELogReg;

/**
 * Logistic Regression ML core.
 *
 * Ported from capissimo's Pine Script v4 indicator
 * "Machine Learning: Logistic Regression (v.3)".
 *
 * This is a simplified single-weight logistic regression trained via
 * gradient descent on each bar. The model classifies the current bar
 * as BUY or SELL based on the relationship between the price series
 * and a synthetic (non-linearly transformed) series.
 *
 * Key observation: in the original Pine Script, hypothesis is always
 * sigmoid(dot(X, 0.0, p)) = sigmoid(0) = 0.5, making the loss and
 * gradient constant across iterations. The weight therefore evolves
 * linearly: w = -lr * gradient * iterations. We exploit this to
 * compute the result in O(p) instead of O(p * iterations).
 */
class LogisticRegression
{
	/**
	 * Standard sigmoid activation: 1 / (1 + e^(-z)).
	 */
	public static function sigmoid(float $z): float {
		$z = max(-500.0, min(500.0, $z));
		return 1.0 / (1.0 + exp(-$z));
	}

	/**
	 * Train logistic regression and return [loss, prediction].
	 *
	 * Faithfully ports the Pine Script logistic_regression() function.
	 * Single scalar weight, gradient descent, cross-entropy loss.
	 *
	 * @param float[] $X Input series (base dataset, last $p values used).
	 * @param float[] $Y Target series (synthetic dataset, last $p values used).
	 * @param int $p Lookback window.
	 * @param float $lr Learning rate.
	 * @param int $iterations Training iterations.
	 * @return array{loss: float, prediction: float}
	 */
	public static function train(array $X, array $Y, int $p, float $lr, int $iterations): array {
		$count = min(count($X), count($Y));
		$start = max(0, $count - $p);
		$actualP = $count - $start;

		if ($actualP === 0) {
			return ['loss' => 0.0, 'prediction' => 0.5];
		}

		// hypothesis = sigmoid(dot(X, 0.0, p)) = sigmoid(0) = 0.5 (constant)
		$logH = log(0.5);
		$log1mH = log(0.5);

		// Cross-entropy loss (constant since hypothesis is fixed at 0.5).
		// Pine: -1/p * dot(dot(Y, log(h) + (1-Y), p), log(1-h), p)
		// Inner dot: sum(Y[i] * (log(0.5) + 1 - Y[i])) for last p elements
		$innerSum = 0.0;
		$gradSum = 0.0;
		$xSum = 0.0;
		for ($i = $start; $i < $count; $i++) {
			$innerSum += $Y[$i] * ($logH + 1.0 - $Y[$i]);
			$gradSum += $X[$i] * (0.5 - $Y[$i]);
			$xSum += $X[$i];
		}

		$loss = -1.0 / $actualP * $innerSum * $log1mH;

		// Gradient is constant: 1/p * sum(X[i] * (0.5 - Y[i]))
		$gradient = (1.0 / $actualP) * $gradSum;

		// After N iterations with constant gradient: w = -lr * gradient * N
		$w = -$lr * $gradient * $iterations;

		$prediction = self::sigmoid($w * $xSum);

		return ['loss' => $loss, 'prediction' => $prediction];
	}

	/**
	 * Generate a synthetic dataset from prices using log-returns.
	 *
	 * The original Pine Script formula `log(abs(price^2 - 1) + 0.5)` degenerates
	 * for small prices (e.g. PEPE ≈ 0.00001): since price² ≈ 0, the output is
	 * log(1.5) ≈ 0.405 for every bar regardless of price movement.
	 *
	 * We use log-returns instead: `log(price[i] / price[i-1])`. This is:
	 * - Scale-independent (works for PEPE at 0.00001 and BTC at 65000)
	 * - Captures actual price dynamics (momentum, mean-reversion)
	 * - Approximately normally distributed (standard assumption in quant finance)
	 *
	 * The first element uses 0.0 (no prior price available).
	 *
	 * @param float[] $prices Input price series (at least 2 elements).
	 * @return float[] Synthetic series of same length.
	 */
	public static function synthesize(array $prices): array {
		$result = [0.0];
		$count = count($prices);
		for ($i = 1; $i < $count; $i++) {
			if ($prices[$i - 1] > 0.0) {
				$result[] = log($prices[$i] / $prices[$i - 1]);
			} else {
				$result[] = 0.0;
			}
		}
		return $result;
	}

	/**
	 * Minimax normalization: scale a value into [min, max] based on
	 * the highest/lowest of the series over the last $p elements.
	 *
	 * Pine Script: (max - min) * (ds - lo) / (hi - lo) + min
	 *
	 * @param float $value Current value to normalize.
	 * @param float[] $series Recent values for hi/lo calculation.
	 * @param int $p Lookback period for hi/lo.
	 * @param float $min Target range minimum (typically lowest price).
	 * @param float $max Target range maximum (typically highest price).
	 * @return float Normalized value in [min, max].
	 */
	public static function minimax(float $value, array $series, int $p, float $min, float $max): float {
		$count = count($series);
		$start = max(0, $count - $p);
		$slice = array_slice($series, $start);

		if (empty($slice)) {
			return $value;
		}

		$hi = max($slice);
		$lo = min($slice);
		$range = $hi - $lo;

		if (abs($range) < 1e-12) {
			return ($min + $max) / 2.0;
		}

		return ($max - $min) * ($value - $lo) / $range + $min;
	}

	/**
	 * Compute Average True Range over $period candles.
	 *
	 * @param float[] $highs High prices.
	 * @param float[] $lows Low prices.
	 * @param float[] $closes Close prices.
	 * @param int $period ATR period.
	 * @return float ATR value, or 0 if not enough data.
	 */
	public static function atr(array $highs, array $lows, array $closes, int $period): float {
		$count = count($closes);
		if ($count < $period + 1) {
			return 0.0;
		}

		$trSum = 0.0;
		for ($i = $count - $period; $i < $count; $i++) {
			$tr = max(
				$highs[$i] - $lows[$i],
				abs($highs[$i] - $closes[$i - 1]),
				abs($lows[$i] - $closes[$i - 1]),
			);
			$trSum += $tr;
		}

		return $trSum / $period;
	}
}
