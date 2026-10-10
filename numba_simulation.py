"""Numba-compiled simulation kernel for non-martingale ranking candidates."""

from __future__ import annotations

import math
import numpy as np
from numba import njit


@njit(cache=True)
def _first_ge(maximum: np.ndarray, size: int, length: int, start: int, target: float) -> int:
    if start < 0:
        start = 0
    if start >= length:
        return -1
    node = size + start
    if maximum[node] >= target:
        return start
    while node > 1:
        if node % 2 == 0:
            candidate = node + 1
            if maximum[candidate] >= target:
                while candidate < size:
                    candidate *= 2
                    if maximum[candidate] < target:
                        candidate += 1
                index = candidate - size
                return index if index < length else -1
        node //= 2
    return -1


@njit(cache=True)
def _first_le(minimum: np.ndarray, size: int, length: int, start: int, target: float) -> int:
    if start < 0:
        start = 0
    if start >= length:
        return -1
    node = size + start
    if minimum[node] <= target:
        return start
    while node > 1:
        if node % 2 == 0:
            candidate = node + 1
            if minimum[candidate] <= target:
                while candidate < size:
                    candidate *= 2
                    if minimum[candidate] > target:
                        candidate += 1
                index = candidate - size
                return index if index < length else -1
        node //= 2
    return -1


@njit(cache=True)
def _market_order_quantity(
    requested_notional: float,
    price: float,
    minimum_quantity: float,
    quantity_step: float,
    quantity_places: int,
    minimum_usdt: float,
    maximum_market_quantity: float,
) -> tuple[float, float, bool]:
    if requested_notional <= 0.0 or price <= 0.0:
        return 0.0, 0.0, False
    if quantity_step <= 0.0:
        return requested_notional / price, requested_notional, True
    quantity = math.floor(requested_notional / price / quantity_step + 1e-10) * quantity_step
    precision_scale = 10.0 ** quantity_places
    quantity = math.floor(quantity * precision_scale + 1e-8) / precision_scale
    actual_notional = quantity * price
    if quantity < minimum_quantity or actual_notional < minimum_usdt:
        return 0.0, 0.0, False
    if maximum_market_quantity > 0.0 and quantity > maximum_market_quantity:
        return 0.0, 0.0, False
    if quantity <= 0.0 or math.floor(quantity / quantity_step + 1e-8) * quantity_step != quantity:
        return 0.0, 0.0, False
    return quantity, actual_notional, True


@njit(cache=True)
def _lower_bound(values: np.ndarray, start: int, target: int) -> int:
    low = start
    high = len(values)
    while low < high:
        middle = (low + high) // 2
        if values[middle] < target:
            low = middle + 1
        else:
            high = middle
    return low


@njit(cache=True)
def _find_delayed_standard_exit(
    bids: np.ndarray,
    asks: np.ndarray,
    entry_index: int,
    is_short: bool,
    quantity: float,
    fee_rate: float,
    take_profit: float,
    stop_loss: float,
    take_confirmation_snapshots: int,
    stop_confirmation_snapshots: int,
    funding_event_indexes: np.ndarray,
    funding_mark_prices: np.ndarray,
    funding_rates: np.ndarray,
) -> tuple[int, bool]:
    has_take = not np.isnan(take_profit)
    has_stop = not np.isnan(stop_loss)
    due_take = -1
    due_stop = -1
    saw_crossing = False
    for index in range(entry_index + 1, len(bids)):
        entry_price = bids[entry_index] if is_short else asks[entry_index]
        exit_price = asks[index] if is_short else bids[index]
        if is_short:
            net = quantity * (entry_price * (1.0 - fee_rate) - exit_price * (1.0 + fee_rate))
        else:
            net = quantity * (exit_price * (1.0 - fee_rate) - entry_price * (1.0 + fee_rate))
        net += _standard_funding_cashflow(
            entry_index, index, quantity, is_short,
            funding_event_indexes, funding_mark_prices, funding_rates,
        )
        take_condition = has_take and net >= take_profit
        stop_condition = has_stop and net <= -stop_loss
        if take_condition and due_take < 0:
            due_take = index + take_confirmation_snapshots
            saw_crossing = True
        if stop_condition and due_stop < 0:
            due_stop = index + stop_confirmation_snapshots
            saw_crossing = True
        if due_take >= 0 and index >= due_take:
            if take_condition:
                return index, saw_crossing
            due_take = -1
        if due_stop >= 0 and index >= due_stop:
            if stop_condition:
                return index, saw_crossing
            due_stop = -1
    return -1, saw_crossing


@njit(cache=True)
def _standard_funding_cashflow(
    entry_index: int,
    exit_index: int,
    quantity: float,
    is_short: bool,
    event_indexes: np.ndarray,
    mark_prices: np.ndarray,
    rates: np.ndarray,
) -> float:
    cashflow = 0.0
    for event_index in range(len(event_indexes)):
        quote_index = event_indexes[event_index]
        if quote_index > exit_index:
            break
        if quote_index > entry_index:
            amount = quantity * mark_prices[event_index] * rates[event_index]
            cashflow += amount if is_short else -amount
    return cashflow


@njit(cache=True)
def _find_funded_standard_exit(
    bids: np.ndarray, bid_maximum: np.ndarray, bid_minimum: np.ndarray,
    asks: np.ndarray, ask_maximum: np.ndarray, ask_minimum: np.ndarray,
    tree_size: int, tree_length: int, entry_index: int, entry_price: float,
    quantity: float, entry_fee: float, is_short: bool, fee_rate: float,
    take_profit: float, stop_loss: float, event_indexes: np.ndarray,
    mark_prices: np.ndarray, rates: np.ndarray,
) -> tuple[int, int]:
    first_take = -1
    first_stop = -1
    accrued = 0.0
    start = entry_index + 1
    event = 0
    while event < len(event_indexes) and event_indexes[event] <= entry_index:
        event += 1
    while event < len(event_indexes):
        boundary = event_indexes[event]
        if boundary >= tree_length:
            break
        if boundary > start:
            if is_short:
                take_price = (entry_price * quantity - entry_fee + accrued - take_profit) / (quantity * (1.0 + fee_rate))
                stop_price = (entry_price * quantity - entry_fee + accrued + stop_loss) / (quantity * (1.0 + fee_rate))
                take = _first_le(ask_minimum, tree_size, tree_length, start, take_price) if not np.isnan(take_profit) else -1
                stop = _first_ge(ask_maximum, tree_size, tree_length, start, stop_price) if not np.isnan(stop_loss) else -1
            else:
                take_price = (entry_price * quantity + entry_fee + take_profit - accrued) / (quantity * (1.0 - fee_rate))
                stop_price = (entry_price * quantity + entry_fee - stop_loss - accrued) / (quantity * (1.0 - fee_rate))
                take = _first_ge(bid_maximum, tree_size, tree_length, start, take_price) if not np.isnan(take_profit) else -1
                stop = _first_le(bid_minimum, tree_size, tree_length, start, stop_price) if not np.isnan(stop_loss) else -1
            if take >= 0 and take < boundary and (first_take < 0 or take < first_take): first_take = take
            if stop >= 0 and stop < boundary and (first_stop < 0 or stop < first_stop): first_stop = stop
        while event < len(event_indexes) and event_indexes[event] == boundary:
            amount = quantity * mark_prices[event] * rates[event]
            accrued += amount if is_short else -amount
            event += 1
        start = boundary
    if start < tree_length:
        if is_short:
            take_price = (entry_price * quantity - entry_fee + accrued - take_profit) / (quantity * (1.0 + fee_rate))
            stop_price = (entry_price * quantity - entry_fee + accrued + stop_loss) / (quantity * (1.0 + fee_rate))
            take = _first_le(ask_minimum, tree_size, tree_length, start, take_price) if not np.isnan(take_profit) else -1
            stop = _first_ge(ask_maximum, tree_size, tree_length, start, stop_price) if not np.isnan(stop_loss) else -1
        else:
            take_price = (entry_price * quantity + entry_fee + take_profit - accrued) / (quantity * (1.0 - fee_rate))
            stop_price = (entry_price * quantity + entry_fee - stop_loss - accrued) / (quantity * (1.0 - fee_rate))
            take = _first_ge(bid_maximum, tree_size, tree_length, start, take_price) if not np.isnan(take_profit) else -1
            stop = _first_le(bid_minimum, tree_size, tree_length, start, stop_price) if not np.isnan(stop_loss) else -1
        if take >= 0 and (first_take < 0 or take < first_take): first_take = take
        if stop >= 0 and (first_stop < 0 or stop < first_stop): first_stop = stop
    return first_take, first_stop


@njit(cache=True)
def _martingale_funding_cashflow(
    exit_index: int, leg_count: int, leg_entry_indexes: np.ndarray,
    leg_quantities: np.ndarray, is_short: bool, event_indexes: np.ndarray,
    mark_prices: np.ndarray, rates: np.ndarray,
) -> float:
    cashflow = 0.0
    for event in range(len(event_indexes)):
        quote_index = event_indexes[event]
        if quote_index > exit_index:
            break
        for leg in range(leg_count):
            if quote_index > leg_entry_indexes[leg]:
                amount = leg_quantities[leg] * mark_prices[event] * rates[event]
                cashflow += amount if is_short else -amount
    return cashflow


@njit(cache=True)
def _find_funded_martingale_exit(
    bids: np.ndarray, bid_maximum: np.ndarray, bid_minimum: np.ndarray,
    asks: np.ndarray, ask_maximum: np.ndarray, ask_minimum: np.ndarray,
    tree_size: int, tree_length: int, entry_index: int, search_start: int, total_quantity: float,
    entry_cost: float, entry_fees: float, is_short: bool, fee_rate: float,
    take_profit: float, stop_loss: float, leg_count: int,
    leg_entry_indexes: np.ndarray, leg_quantities: np.ndarray,
    event_indexes: np.ndarray, mark_prices: np.ndarray, rates: np.ndarray,
) -> tuple[int, int]:
    first_take = -1
    first_stop = -1
    start = max(entry_index + 1, search_start)
    accrued = _martingale_funding_cashflow(
        start - 1, leg_count, leg_entry_indexes, leg_quantities, is_short,
        event_indexes, mark_prices, rates,
    )
    for event in range(len(event_indexes)):
        boundary = event_indexes[event]
        if boundary < start:
            continue
        if boundary >= tree_length:
            break
        if boundary > start:
            if is_short:
                take_price = (entry_cost - entry_fees + accrued - take_profit) / (total_quantity * (1.0 + fee_rate))
                stop_price = (entry_cost - entry_fees + accrued - stop_loss) / (total_quantity * (1.0 + fee_rate))
                take = _first_le(ask_minimum, tree_size, tree_length, start, take_price) if not np.isnan(take_profit) else -1
                stop = _first_ge(ask_maximum, tree_size, tree_length, start, stop_price) if not np.isnan(stop_loss) else -1
            else:
                take_price = (entry_cost + entry_fees + take_profit - accrued) / (total_quantity * (1.0 - fee_rate))
                stop_price = (entry_cost + entry_fees + stop_loss - accrued) / (total_quantity * (1.0 - fee_rate))
                take = _first_ge(bid_maximum, tree_size, tree_length, start, take_price) if not np.isnan(take_profit) else -1
                stop = _first_le(bid_minimum, tree_size, tree_length, start, stop_price) if not np.isnan(stop_loss) else -1
            if take >= 0 and take < boundary and (first_take < 0 or take < first_take): first_take = take
            if stop >= 0 and stop < boundary and (first_stop < 0 or stop < first_stop): first_stop = stop
        amount = 0.0
        for leg in range(leg_count):
            if boundary > leg_entry_indexes[leg]:
                amount += leg_quantities[leg] * mark_prices[event] * rates[event]
        accrued += amount if is_short else -amount
        start = boundary
    if start < tree_length:
        if is_short:
            take_price = (entry_cost - entry_fees + accrued - take_profit) / (total_quantity * (1.0 + fee_rate))
            stop_price = (entry_cost - entry_fees + accrued - stop_loss) / (total_quantity * (1.0 + fee_rate))
            take = _first_le(ask_minimum, tree_size, tree_length, start, take_price) if not np.isnan(take_profit) else -1
            stop = _first_ge(ask_maximum, tree_size, tree_length, start, stop_price) if not np.isnan(stop_loss) else -1
        else:
            take_price = (entry_cost + entry_fees + take_profit - accrued) / (total_quantity * (1.0 - fee_rate))
            stop_price = (entry_cost + entry_fees + stop_loss - accrued) / (total_quantity * (1.0 - fee_rate))
            take = _first_ge(bid_maximum, tree_size, tree_length, start, take_price) if not np.isnan(take_profit) else -1
            stop = _first_le(bid_minimum, tree_size, tree_length, start, stop_price) if not np.isnan(stop_loss) else -1
        if take >= 0 and (first_take < 0 or take < first_take): first_take = take
        if stop >= 0 and (first_stop < 0 or stop < first_stop): first_stop = stop
    return first_take, first_stop


@njit(cache=True)
def _find_delayed_martingale_exit(
    bids: np.ndarray,
    asks: np.ndarray,
    entry_index: int,
    is_short: bool,
    total_quantity: float,
    entry_cost: float,
    entry_fees: float,
    fee_rate: float,
    take_profit: float,
    stop_loss: float,
    take_confirmation_snapshots: int,
    stop_confirmation_snapshots: int,
    leg_entry_indexes: np.ndarray,
    leg_quantities: np.ndarray,
    leg_count: int,
    funding_event_indexes: np.ndarray,
    funding_mark_prices: np.ndarray,
    funding_rates: np.ndarray,
) -> tuple[int, int]:
    has_take = not np.isnan(take_profit)
    has_stop = not np.isnan(stop_loss)
    due_take = -1
    due_stop = -1
    for index in range(entry_index + 1, len(bids)):
        exit_price = asks[index] if is_short else bids[index]
        if is_short:
            net = entry_cost - total_quantity * exit_price - entry_fees - total_quantity * exit_price * fee_rate
        else:
            net = total_quantity * exit_price - entry_cost - entry_fees - total_quantity * exit_price * fee_rate
        net += _martingale_funding_cashflow(
            index, leg_count, leg_entry_indexes, leg_quantities, is_short,
            funding_event_indexes, funding_mark_prices, funding_rates,
        )
        take_condition = has_take and net >= take_profit
        stop_condition = has_stop and net <= stop_loss
        if take_condition and due_take < 0:
            due_take = index + take_confirmation_snapshots
        if stop_condition and due_stop < 0:
            due_stop = index + stop_confirmation_snapshots
        if due_take >= 0 and index >= due_take:
            if take_condition:
                return index, -1
            due_take = -1
        if due_stop >= 0 and index >= due_stop:
            if stop_condition:
                return -1, index
            due_stop = -1
    return -1, -1


@njit(cache=True)
def simulate_numba(
    bids: np.ndarray,
    asks: np.ndarray,
    bid_maximum: np.ndarray,
    bid_minimum: np.ndarray,
    ask_maximum: np.ndarray,
    ask_minimum: np.ndarray,
    tree_size: int,
    tree_length: int,
    signal_indexes: np.ndarray,
    signal_shorts: np.ndarray,
    start_balance: float,
    fee_rate: float,
    min_order_quantity: float,
    quantity_step: float,
    quantity_places: int,
    min_order_notional: float,
    max_market_order_quantity: float,
    funding_event_indexes: np.ndarray,
    funding_mark_prices: np.ndarray,
    funding_rates: np.ndarray,
    take_profit: float,
    stop_loss: float,
    take_confirmation_snapshots: int,
    stop_confirmation_snapshots: int,
) -> tuple[float, int]:
    balance = start_balance
    signal_cursor = 0
    count = len(bids)
    trades_closed = 0
    fee_factor = (1.0 + fee_rate) / (1.0 - fee_rate)
    inverse_fee_factor = (1.0 - fee_rate) / (1.0 + fee_rate)
    has_take = not np.isnan(take_profit)
    has_stop = not np.isnan(stop_loss)

    while balance > 0.0 and signal_cursor < len(signal_indexes):
        entry_index = signal_indexes[signal_cursor]
        is_short = signal_shorts[signal_cursor]
        signal_cursor += 1
        entry_price = bids[entry_index] if is_short else asks[entry_index]
        if entry_price <= 0.0:
            continue
        quantity, actual_notional, order_valid = _market_order_quantity(
            min(start_balance, balance / (1.0 + fee_rate)), entry_price, min_order_quantity,
            quantity_step, quantity_places, min_order_notional, max_market_order_quantity,
        )
        if not order_valid:
            return np.nan, 0

        first_take, first_stop = _find_funded_standard_exit(
            bids, bid_maximum, bid_minimum, asks, ask_maximum, ask_minimum,
            tree_size, tree_length, entry_index, entry_price, quantity,
            actual_notional * fee_rate, is_short, fee_rate, take_profit, stop_loss,
            funding_event_indexes, funding_mark_prices, funding_rates,
        )

        delayed_crossing = False
        if take_confirmation_snapshots > 0 or stop_confirmation_snapshots > 0:
            delayed_exit, delayed_crossing = _find_delayed_standard_exit(
                bids, asks, entry_index, is_short, quantity, fee_rate,
                take_profit, stop_loss, take_confirmation_snapshots, stop_confirmation_snapshots,
                funding_event_indexes, funding_mark_prices, funding_rates,
            )
            first_take = delayed_exit
            first_stop = -1

        has_crossing = delayed_crossing if take_confirmation_snapshots > 0 or stop_confirmation_snapshots > 0 else first_take >= 0 or first_stop >= 0
        if first_take >= 0 and first_stop >= 0:
            exit_index = min(first_take, first_stop)
        elif first_take >= 0:
            exit_index = first_take
        elif first_stop >= 0:
            exit_index = first_stop
        else:
            exit_index = count - 1

        exit_price = asks[exit_index] if is_short else bids[exit_index]
        if is_short:
            net_ratio = (entry_price * quantity * (1.0 - fee_rate) - exit_price * quantity * (1.0 + fee_rate))
        else:
            net_ratio = (exit_price * quantity * (1.0 - fee_rate) - entry_price * quantity * (1.0 + fee_rate))
        balance += net_ratio + _standard_funding_cashflow(
            entry_index, exit_index, quantity,
            is_short, funding_event_indexes, funding_mark_prices, funding_rates,
        )
        trades_closed += 1

        if not has_crossing:
            break
        signal_cursor = _lower_bound(signal_indexes, signal_cursor, exit_index + 1)

    return balance - start_balance, trades_closed


@njit(cache=True)
def simulate_standard_stateful_numba(
    bids: np.ndarray,
    asks: np.ndarray,
    bid_maximum: np.ndarray,
    bid_minimum: np.ndarray,
    ask_maximum: np.ndarray,
    ask_minimum: np.ndarray,
    tree_size: int,
    tree_length: int,
    signal_indexes: np.ndarray,
    signal_shorts: np.ndarray,
    start_balance: float,
    fee_rate: float,
    min_order_quantity: float,
    quantity_step: float,
    quantity_places: int,
    min_order_notional: float,
    max_market_order_quantity: float,
    funding_event_indexes: np.ndarray,
    funding_mark_prices: np.ndarray,
    funding_rates: np.ndarray,
    take_profit: float,
    stop_loss: float,
    closed_balance: float,
    signal_from: int,
    closed_trades: int,
    open_entry_index: int,
    open_is_short: bool,
    take_confirmation_snapshots: int,
    stop_confirmation_snapshots: int,
) -> tuple[float, int, float, int, int, bool]:
    """Resume a run with stake capped by initial and available balance."""
    balance = closed_balance
    count = tree_length
    fee_factor = (1.0 + fee_rate) / (1.0 - fee_rate)
    inverse_fee_factor = (1.0 - fee_rate) / (1.0 + fee_rate)
    has_take = not np.isnan(take_profit)
    has_stop = not np.isnan(stop_loss)
    signal_cursor = _lower_bound(signal_indexes, 0, signal_from)
    open_index = open_entry_index
    open_short = open_is_short

    if open_index >= 0:
        first_take = -1
        first_stop = -1
        entry_price = bids[open_index] if open_short else asks[open_index]
        quantity, actual_notional, order_valid = _market_order_quantity(
            min(start_balance, balance / (1.0 + fee_rate)), entry_price, min_order_quantity,
            quantity_step, quantity_places, min_order_notional, max_market_order_quantity,
        )
        if not order_valid:
            return np.nan, 0, balance, max(0, signal_from), -1, False
        first_take, first_stop = _find_funded_standard_exit(
            bids, bid_maximum, bid_minimum, asks, ask_maximum, ask_minimum,
            tree_size, count, open_index, entry_price, quantity,
            actual_notional * fee_rate, open_short, fee_rate, take_profit, stop_loss,
            funding_event_indexes, funding_mark_prices, funding_rates,
        )

        if take_confirmation_snapshots > 0 or stop_confirmation_snapshots > 0:
            delayed_exit, _ = _find_delayed_standard_exit(
                bids, asks, open_index, open_short, quantity, fee_rate,
                take_profit, stop_loss, take_confirmation_snapshots, stop_confirmation_snapshots,
                funding_event_indexes, funding_mark_prices, funding_rates,
            )
            first_take, first_stop = delayed_exit, -1

        if first_take >= 0 or first_stop >= 0:
            if first_take >= 0 and first_stop >= 0:
                exit_index = min(first_take, first_stop)
            elif first_take >= 0:
                exit_index = first_take
            else:
                exit_index = first_stop
            exit_price = asks[exit_index] if open_short else bids[exit_index]
            quantity, actual_notional, _ = _market_order_quantity(
                min(start_balance, balance / (1.0 + fee_rate)), entry_price, min_order_quantity,
                quantity_step, quantity_places, min_order_notional, max_market_order_quantity,
            )
            if open_short:
                net_ratio = entry_price * quantity * (1.0 - fee_rate) - exit_price * quantity * (1.0 + fee_rate)
            else:
                net_ratio = exit_price * quantity * (1.0 - fee_rate) - entry_price * quantity * (1.0 + fee_rate)
            balance += net_ratio + _standard_funding_cashflow(
                open_index, exit_index, quantity,
                open_short, funding_event_indexes, funding_mark_prices, funding_rates,
            )
            closed_trades += 1
            open_index = -1
            signal_cursor = _lower_bound(signal_indexes, signal_cursor, exit_index + 1)
            signal_from = exit_index + 1

    while open_index < 0 and balance > 0.0 and signal_cursor < len(signal_indexes):
        entry_index = signal_indexes[signal_cursor]
        is_short = signal_shorts[signal_cursor]
        signal_cursor += 1
        entry_price = bids[entry_index] if is_short else asks[entry_index]
        if entry_price <= 0.0:
            continue
        quantity, actual_notional, order_valid = _market_order_quantity(
            min(start_balance, balance / (1.0 + fee_rate)), entry_price, min_order_quantity,
            quantity_step, quantity_places, min_order_notional, max_market_order_quantity,
        )
        if not order_valid:
            return np.nan, 0, balance, max(0, signal_from), -1, False

        first_take, first_stop = _find_funded_standard_exit(
            bids, bid_maximum, bid_minimum, asks, ask_maximum, ask_minimum,
            tree_size, count, entry_index, entry_price, quantity,
            actual_notional * fee_rate, is_short, fee_rate, take_profit, stop_loss,
            funding_event_indexes, funding_mark_prices, funding_rates,
        )

        if take_confirmation_snapshots > 0 or stop_confirmation_snapshots > 0:
            delayed_exit, _ = _find_delayed_standard_exit(
                bids, asks, entry_index, is_short, quantity, fee_rate,
                take_profit, stop_loss, take_confirmation_snapshots, stop_confirmation_snapshots,
                funding_event_indexes, funding_mark_prices, funding_rates,
            )
            first_take, first_stop = delayed_exit, -1

        if first_take >= 0 and first_stop >= 0:
            exit_index = min(first_take, first_stop)
        elif first_take >= 0:
            exit_index = first_take
        elif first_stop >= 0:
            exit_index = first_stop
        else:
            open_index = entry_index
            open_short = is_short
            break

        exit_price = asks[exit_index] if is_short else bids[exit_index]
        quantity, actual_notional, _ = _market_order_quantity(
            min(start_balance, balance / (1.0 + fee_rate)), entry_price, min_order_quantity,
            quantity_step, quantity_places, min_order_notional, max_market_order_quantity,
        )
        if is_short:
            net_ratio = entry_price * quantity * (1.0 - fee_rate) - exit_price * quantity * (1.0 + fee_rate)
        else:
            net_ratio = exit_price * quantity * (1.0 - fee_rate) - entry_price * quantity * (1.0 + fee_rate)
        balance += net_ratio + _standard_funding_cashflow(
            entry_index, exit_index, quantity,
            is_short, funding_event_indexes, funding_mark_prices, funding_rates,
        )
        closed_trades += 1
        signal_cursor = _lower_bound(signal_indexes, signal_cursor, exit_index + 1)
        signal_from = exit_index + 1

    if open_index >= 0 and count > 0:
        entry_price = bids[open_index] if open_short else asks[open_index]
        exit_price = asks[count - 1] if open_short else bids[count - 1]
        quantity, actual_notional, _ = _market_order_quantity(
            min(start_balance, balance / (1.0 + fee_rate)), entry_price, min_order_quantity,
            quantity_step, quantity_places, min_order_notional, max_market_order_quantity,
        )
        if open_short:
            net_ratio = entry_price * quantity * (1.0 - fee_rate) - exit_price * quantity * (1.0 + fee_rate)
        else:
            net_ratio = exit_price * quantity * (1.0 - fee_rate) - entry_price * quantity * (1.0 + fee_rate)
        marked_balance = balance + net_ratio + _standard_funding_cashflow(
            open_index, count - 1, quantity,
            open_short, funding_event_indexes, funding_mark_prices, funding_rates,
        )
        return marked_balance - start_balance, closed_trades + 1, balance, max(0, signal_from), open_index, open_short

    return balance - start_balance, closed_trades, balance, max(0, count - 1, signal_from), -1, False


@njit(cache=True)
def simulate_standard_stateful_days_numba(
    bids: np.ndarray,
    asks: np.ndarray,
    bid_maximum: np.ndarray,
    bid_minimum: np.ndarray,
    ask_maximum: np.ndarray,
    ask_minimum: np.ndarray,
    tree_size: int,
    signal_indexes: np.ndarray,
    signal_shorts: np.ndarray,
    start_balance: float,
    fee_rate: float,
    min_order_quantity: float,
    quantity_step: float,
    quantity_places: int,
    min_order_notional: float,
    max_market_order_quantity: float,
    funding_event_indexes: np.ndarray,
    funding_mark_prices: np.ndarray,
    funding_rates: np.ndarray,
    take_profit: float,
    stop_loss: float,
    day_quote_counts: np.ndarray,
    closed_balance: float,
    signal_from: int,
    closed_trades: int,
    open_entry_index: int,
    open_is_short: bool,
    take_confirmation_snapshots: int,
    stop_confirmation_snapshots: int,
) -> tuple[np.ndarray, np.ndarray, np.ndarray, np.ndarray, np.ndarray, np.ndarray, np.ndarray]:
    day_count = len(day_quote_counts)
    pnls = np.empty(day_count, dtype=np.float64)
    trade_counts = np.empty(day_count, dtype=np.int64)
    balances = np.empty(day_count, dtype=np.float64)
    signal_positions = np.empty(day_count, dtype=np.int64)
    closed_counts = np.empty(day_count, dtype=np.int64)
    open_indexes = np.empty(day_count, dtype=np.int32)
    open_shorts = np.empty(day_count, dtype=np.bool_)
    current_balance = closed_balance
    current_signal_from = signal_from
    current_closed_trades = closed_trades
    current_open_index = open_entry_index
    current_open_short = open_is_short

    for day_index in range(day_count):
        quote_count = int(day_quote_counts[day_index])
        signal_count = _lower_bound(signal_indexes, 0, quote_count)
        result = simulate_standard_stateful_numba(
            bids, asks, bid_maximum, bid_minimum, ask_maximum, ask_minimum,
            tree_size, quote_count, signal_indexes[:signal_count], signal_shorts[:signal_count],
            start_balance, fee_rate, min_order_quantity, quantity_step,
            quantity_places, min_order_notional, max_market_order_quantity,
            funding_event_indexes, funding_mark_prices,
            funding_rates, take_profit, stop_loss,
            current_balance, current_signal_from, current_closed_trades,
            current_open_index, current_open_short, take_confirmation_snapshots, stop_confirmation_snapshots,
        )
        pnls[day_index], trade_counts[day_index] = result[0], result[1]
        current_balance, current_signal_from = result[2], result[3]
        current_open_index, current_open_short = result[4], result[5]
        current_closed_trades = result[1] - 1 if current_open_index >= 0 else result[1]
        balances[day_index] = current_balance
        signal_positions[day_index] = current_signal_from
        closed_counts[day_index] = current_closed_trades
        open_indexes[day_index] = current_open_index
        open_shorts[day_index] = current_open_short

    return pnls, trade_counts, balances, signal_positions, closed_counts, open_indexes, open_shorts


@njit(cache=True)
def _upper_bound(values: np.ndarray, start: int, target: int) -> int:
    low = start
    high = len(values)
    while low < high:
        middle = (low + high) // 2
        if values[middle] <= target:
            low = middle + 1
        else:
            high = middle
    return low


@njit(cache=True)
def simulate_martingale_numba(
    bids: np.ndarray,
    asks: np.ndarray,
    bid_maximum: np.ndarray,
    bid_minimum: np.ndarray,
    ask_maximum: np.ndarray,
    ask_minimum: np.ndarray,
    tree_size: int,
    tree_length: int,
    signal_indexes: np.ndarray,
    signal_shorts: np.ndarray,
    start_balance: float,
    fee_rate: float,
    funding_event_indexes: np.ndarray,
    funding_mark_prices: np.ndarray,
    funding_rates: np.ndarray,
    min_order_quantity: float,
    quantity_step: float,
    quantity_places: int,
    min_order_notional: float,
    max_market_order_quantity: float,
    take_profit: float,
    stop_loss: float,
    base_notional: float,
    mode: int,
    timing: int,
    attempts: int,
    target_policy: int,
    max_chain_loss: float,
    max_day_loss: float,
    day_ids: np.ndarray,
    take_confirmation_snapshots: int,
    stop_confirmation_snapshots: int,
) -> tuple[float, int, int, float, float]:
    balance = start_balance
    equity_peak = start_balance
    max_drawdown = 0.0
    worst_chain = np.inf
    signal_cursor = 0
    closed_orders = 0
    chains_started = 0
    daily_day = -1
    daily_pnl = 0.0
    count = len(bids)
    has_take = not np.isnan(take_profit)
    has_stop = not np.isnan(stop_loss)
    if base_notional <= 0.0:
        return 0.0, 0, 0, 0.0, 0.0

    while balance > 0.0 and signal_cursor < len(signal_indexes):
        entry_index = signal_indexes[signal_cursor]
        is_short = signal_shorts[signal_cursor]
        signal_cursor += 1
        entry_day = day_ids[entry_index]
        if entry_day != daily_day:
            daily_day = entry_day
            daily_pnl = 0.0
        if not np.isnan(max_day_loss) and max_day_loss > 0.0 and daily_pnl <= -max_day_loss:
            continue
        price = bids[entry_index] if is_short else asks[entry_index]
        if price <= 0.0:
            continue
        # Keep the planned initial stake tied to the starting balance, but do
        # not open a new chain larger than the currently available balance
        # after losses on earlier chains.
        notional = min(base_notional, balance / (1.0 + fee_rate))
        quantity, actual_notional, order_valid = _market_order_quantity(
            notional, price, min_order_quantity, quantity_step,
            quantity_places, min_order_notional, max_market_order_quantity,
        )
        if not order_valid:
            return np.nan, 0, 0, 0.0, 0.0
        if notional <= 0.0:
            break

        chain_start_balance = balance
        leg_prices = np.empty(attempts, dtype=np.float64)
        leg_quantities = np.empty(attempts, dtype=np.float64)
        leg_entry_indexes = np.empty(attempts, dtype=np.int64)
        leg_fees = np.empty(attempts, dtype=np.float64)
        leg_prices[0] = price
        leg_quantities[0] = quantity
        leg_entry_indexes[0] = entry_index
        leg_fees[0] = actual_notional * fee_rate
        leg_count = 1
        chains_started += 1
        position_notional = actual_notional
        chain_base_notional = actual_notional
        chain_realized_pnl = 0.0
        stage = 1

        while leg_count > 0:
            total_quantity = 0.0
            entry_cost = 0.0
            entry_fees = 0.0
            for leg in range(leg_count):
                total_quantity += leg_quantities[leg]
                entry_cost += leg_prices[leg] * leg_quantities[leg]
                entry_fees += leg_fees[leg]
            multiplier = position_notional / chain_base_notional if chain_base_notional > 0.0 else 1.0
            target_multiplier = multiplier if target_policy == 0 else 1.0
            target_amount = take_profit * target_multiplier if has_take else np.nan
            stop_amount = stop_loss * target_multiplier if has_stop else np.nan
            if is_short:
                take_price = (entry_cost - entry_fees - target_amount) / (total_quantity * (1.0 + fee_rate)) if has_take else np.nan
                stop_price = (entry_cost - entry_fees - stop_amount) / (total_quantity * (1.0 + fee_rate)) if has_stop else np.nan
                first_take = _first_le(ask_minimum, tree_size, tree_length, entry_index + 1, take_price) if has_take else -1
                first_stop = _first_ge(ask_maximum, tree_size, tree_length, entry_index + 1, stop_price) if has_stop else -1
            else:
                factor = total_quantity * (1.0 - fee_rate)
                take_price = (entry_cost + entry_fees + target_amount) / factor if has_take else np.nan
                stop_price = (entry_cost + entry_fees + stop_amount) / factor if has_stop else np.nan
                first_take = _first_ge(bid_maximum, tree_size, tree_length, entry_index + 1, take_price) if has_take else -1
                first_stop = _first_le(bid_minimum, tree_size, tree_length, entry_index + 1, stop_price) if has_stop else -1

            first_take, first_stop = _find_funded_martingale_exit(
                bids, bid_maximum, bid_minimum, asks, ask_maximum, ask_minimum,
                tree_size, tree_length, entry_index, entry_index + 1, total_quantity, entry_cost,
                entry_fees, is_short, fee_rate, target_amount,
                stop_amount if has_stop else np.nan, leg_count,
                leg_entry_indexes, leg_quantities, funding_event_indexes,
                funding_mark_prices, funding_rates,
            )

            if take_confirmation_snapshots > 0 or stop_confirmation_snapshots > 0:
                first_take, first_stop = _find_delayed_martingale_exit(
                    bids, asks, entry_index, is_short, total_quantity, entry_cost,
                    entry_fees, fee_rate, target_amount,
                    stop_amount if has_stop else np.nan, take_confirmation_snapshots, stop_confirmation_snapshots,
                    leg_entry_indexes, leg_quantities, leg_count,
                    funding_event_indexes, funding_mark_prices, funding_rates,
                )

            has_crossing = first_take >= 0 or first_stop >= 0
            first_risk = -1
            if not np.isnan(max_chain_loss) and max_chain_loss > 0.0:
                remaining_chain_risk = max_chain_loss + chain_realized_pnl
                if remaining_chain_risk <= 0.0:
                    first_risk = entry_index + 1
                else:
                    _, first_risk = _find_funded_martingale_exit(
                        bids, bid_maximum, bid_minimum, asks, ask_maximum, ask_minimum,
                        tree_size, tree_length, entry_index, entry_index + 1, total_quantity, entry_cost,
                        entry_fees, is_short, fee_rate, np.nan, -remaining_chain_risk,
                        leg_count, leg_entry_indexes, leg_quantities,
                        funding_event_indexes, funding_mark_prices, funding_rates,
                    )
            if first_take >= 0 and first_stop >= 0:
                exit_index = min(first_take, first_stop)
            elif first_take >= 0:
                exit_index = first_take
            elif first_stop >= 0:
                exit_index = first_stop
            else:
                exit_index = count - 1
            if first_risk >= 0 and first_risk <= exit_index:
                exit_index = first_risk
            first_day_risk = -1
            if not np.isnan(max_day_loss) and max_day_loss > 0.0:
                day_start = entry_index + 1
                while day_start < count and day_start <= exit_index:
                    segment_day = day_ids[day_start]
                    segment_end = _upper_bound(day_ids, day_start, segment_day) - 1
                    if segment_end > exit_index:
                        segment_end = exit_index
                    realized_for_day = daily_pnl if segment_day == daily_day else 0.0
                    remaining_day_risk = max_day_loss + realized_for_day + chain_realized_pnl
                    if remaining_day_risk <= 0.0:
                        first_day_risk = day_start
                        break
                    _, candidate_day_risk = _find_funded_martingale_exit(
                        bids, bid_maximum, bid_minimum, asks, ask_maximum, ask_minimum,
                        tree_size, segment_end + 1, entry_index, day_start, total_quantity,
                        entry_cost, entry_fees, is_short, fee_rate, np.nan,
                        -remaining_day_risk, leg_count, leg_entry_indexes, leg_quantities,
                        funding_event_indexes, funding_mark_prices, funding_rates,
                    )
                    if candidate_day_risk >= day_start and candidate_day_risk <= segment_end:
                        first_day_risk = candidate_day_risk
                        break
                    day_start = segment_end + 1
            if first_day_risk >= 0 and first_day_risk <= exit_index:
                exit_index = first_day_risk

            exit_quote = asks[exit_index] if is_short else bids[exit_index]
            if is_short:
                net = entry_cost - total_quantity * exit_quote - entry_fees - total_quantity * exit_quote * fee_rate
            else:
                net = total_quantity * exit_quote - entry_cost - entry_fees - total_quantity * exit_quote * fee_rate
            net += _martingale_funding_cashflow(
                exit_index, leg_count, leg_entry_indexes, leg_quantities, is_short,
                funding_event_indexes, funding_mark_prices, funding_rates,
            )

            transition_on_profit = mode >= 2
            trigger_transition = (
                (transition_on_profit and first_take >= 0 and first_take == exit_index)
                or (not transition_on_profit and first_stop >= 0 and first_stop == exit_index)
            )
            risk_trigger = (first_risk >= 0 and first_risk == exit_index) or (first_day_risk >= 0 and first_day_risk == exit_index)
            exit_day = day_ids[exit_index]
            if exit_day != daily_day:
                daily_day = exit_day
                daily_pnl = 0.0
            if risk_trigger or (
                not np.isnan(max_day_loss)
                and max_day_loss > 0.0
                and daily_pnl + chain_realized_pnl + net <= -max_day_loss
            ):
                balance += net
                daily_pnl += chain_realized_pnl + net
                closed_orders += leg_count
                leg_count = 0
                signal_cursor = _upper_bound(signal_indexes, signal_cursor, exit_index)
                break
            if first_take >= 0 and first_take == exit_index and not trigger_transition:
                balance += net
                daily_pnl += chain_realized_pnl + net
                closed_orders += leg_count
                leg_count = 0
                signal_cursor = _upper_bound(signal_indexes, signal_cursor, exit_index)
                break

            if trigger_transition and stage < attempts:
                signal_cursor = _upper_bound(signal_indexes, signal_cursor, exit_index)
                if mode == 0:
                    next_index = exit_index
                    next_short = is_short
                    requested = position_notional
                    available = max(0.0, balance + net - position_notional)
                    add_notional = min(requested, available / (1.0 + fee_rate))
                    if timing == 1:
                        add_notional = 0.0
                        found_signal = False
                        while signal_cursor < len(signal_indexes):
                            candidate_short = signal_shorts[signal_cursor]
                            if candidate_short == is_short:
                                next_index = signal_indexes[signal_cursor]
                                signal_cursor += 1
                                candidate_quote = asks[next_index] if is_short else bids[next_index]
                                if is_short:
                                    net = entry_cost - total_quantity * candidate_quote - entry_fees - total_quantity * candidate_quote * fee_rate
                                else:
                                    net = total_quantity * candidate_quote - entry_cost - entry_fees - total_quantity * candidate_quote * fee_rate
                                net += _martingale_funding_cashflow(
                                    next_index, leg_count, leg_entry_indexes, leg_quantities, is_short,
                                    funding_event_indexes, funding_mark_prices, funding_rates,
                                )
                                available = max(0.0, balance + net - position_notional)
                                add_notional = min(requested, available / (1.0 + fee_rate))
                                found_signal = True
                                break
                            signal_cursor += 1
                        if not found_signal:
                            if first_take >= 0:
                                end_quote = asks[first_take] if is_short else bids[first_take]
                                if is_short:
                                    take_net = entry_cost - total_quantity * end_quote - entry_fees - total_quantity * end_quote * fee_rate
                                else:
                                    take_net = total_quantity * end_quote - entry_cost - entry_fees - total_quantity * end_quote * fee_rate
                                take_net += _martingale_funding_cashflow(
                                    first_take, leg_count, leg_entry_indexes, leg_quantities, is_short,
                                    funding_event_indexes, funding_mark_prices, funding_rates,
                                )
                                balance += take_net
                                daily_pnl += chain_realized_pnl + take_net
                                closed_orders += leg_count
                                leg_count = 0
                                signal_cursor = _upper_bound(signal_indexes, 0, first_take)
                                break
                            end_quote = asks[count - 1] if is_short else bids[count - 1]
                            if is_short:
                                end_net = entry_cost - total_quantity * end_quote - entry_fees - total_quantity * end_quote * fee_rate
                            else:
                                end_net = total_quantity * end_quote - entry_cost - entry_fees - total_quantity * end_quote * fee_rate
                            end_net += _martingale_funding_cashflow(
                                count - 1, leg_count, leg_entry_indexes, leg_quantities, is_short,
                                funding_event_indexes, funding_mark_prices, funding_rates,
                            )
                            balance += end_net
                            closed_orders += leg_count
                            leg_count = 0
                            signal_cursor = len(signal_indexes)
                            break
                        if first_take >= 0 and first_take < next_index:
                            take_quote = asks[first_take] if is_short else bids[first_take]
                            if is_short:
                                take_net = entry_cost - total_quantity * take_quote - entry_fees - total_quantity * take_quote * fee_rate
                            else:
                                take_net = total_quantity * take_quote - entry_cost - entry_fees - total_quantity * take_quote * fee_rate
                            take_net += _martingale_funding_cashflow(
                                first_take, leg_count, leg_entry_indexes, leg_quantities, is_short,
                                funding_event_indexes, funding_mark_prices, funding_rates,
                            )
                            balance += take_net
                            daily_pnl += chain_realized_pnl + take_net
                            closed_orders += leg_count
                            leg_count = 0
                            signal_cursor = _upper_bound(signal_indexes, 0, first_take)
                            break
                    if next_index < count and add_notional > 0.0:
                        add_price = bids[next_index] if next_short else asks[next_index]
                        add_quantity, actual_add_notional, order_valid = _market_order_quantity(
                            add_notional, add_price, min_order_quantity, quantity_step,
                            quantity_places, min_order_notional, max_market_order_quantity,
                        )
                        if not order_valid:
                            return np.nan, 0, 0, 0.0, 0.0
                        if add_price > 0.0:
                            leg_prices[leg_count] = add_price
                            leg_quantities[leg_count] = add_quantity
                            leg_entry_indexes[leg_count] = next_index
                            leg_fees[leg_count] = actual_add_notional * fee_rate
                            leg_count += 1
                            position_notional += actual_add_notional
                            stage += 1
                            entry_index = next_index
                            continue
                elif mode == 2:
                    balance += net
                    chain_realized_pnl += net
                    closed_orders += leg_count
                    leg_count = 0
                    requested = position_notional * 2.0
                    next_index = exit_index
                    if timing == 1:
                        next_index = count
                        while signal_cursor < len(signal_indexes):
                            candidate_index = signal_indexes[signal_cursor]
                            candidate_short = signal_shorts[signal_cursor]
                            signal_cursor += 1
                            if candidate_index > exit_index and candidate_short == is_short:
                                next_index = candidate_index
                                break
                    available = max(0.0, balance)
                    next_notional = min(requested, available / (1.0 + fee_rate))
                    if next_index < count and next_notional > 0.0:
                        next_price = bids[next_index] if is_short else asks[next_index]
                        next_quantity, actual_next_notional, order_valid = _market_order_quantity(
                            next_notional, next_price, min_order_quantity, quantity_step,
                            quantity_places, min_order_notional, max_market_order_quantity,
                        )
                        if not order_valid:
                            return np.nan, 0, 0, 0.0, 0.0
                        if next_price > 0.0:
                            entry_index = next_index
                            position_notional = actual_next_notional
                            leg_prices[0] = next_price
                            leg_quantities[0] = next_quantity
                            leg_entry_indexes[0] = next_index
                            leg_fees[0] = actual_next_notional * fee_rate
                            leg_count = 1
                            stage += 1
                            continue
                    break
                else:
                    balance += net
                    chain_realized_pnl += net
                    closed_orders += 1
                    leg_count = 0
                    if (
                        (not np.isnan(max_chain_loss) and max_chain_loss > 0.0 and chain_realized_pnl <= -max_chain_loss)
                        or (not np.isnan(max_day_loss) and max_day_loss > 0.0 and daily_pnl + chain_realized_pnl <= -max_day_loss)
                    ):
                        daily_pnl += chain_realized_pnl
                        signal_cursor = _upper_bound(signal_indexes, signal_cursor, exit_index)
                        break
                    wanted = position_notional * 2.0
                    reverse_short = not is_short
                    next_index = exit_index
                    if timing == 1:
                        next_index = count
                        while signal_cursor < len(signal_indexes):
                            candidate_index = signal_indexes[signal_cursor]
                            candidate_short = signal_shorts[signal_cursor]
                            signal_cursor += 1
                            if candidate_index > exit_index and candidate_short == reverse_short:
                                next_index = candidate_index
                                break
                    available = max(0.0, balance)
                    reverse_notional = min(wanted, available / (1.0 + fee_rate))
                    if next_index < count and reverse_notional > 0.0:
                        reverse_price = bids[next_index] if reverse_short else asks[next_index]
                        reverse_quantity, actual_reverse_notional, order_valid = _market_order_quantity(
                            reverse_notional, reverse_price, min_order_quantity, quantity_step,
                            quantity_places, min_order_notional, max_market_order_quantity,
                        )
                        if not order_valid:
                            return np.nan, 0, 0, 0.0, 0.0
                        if reverse_price > 0.0:
                            is_short = reverse_short
                            entry_index = next_index
                            position_notional = actual_reverse_notional
                            leg_prices[0] = reverse_price
                            leg_quantities[0] = reverse_quantity
                            leg_entry_indexes[0] = next_index
                            leg_fees[0] = actual_reverse_notional * fee_rate
                            leg_count = 1
                            stage += 1
                            continue
                    signal_cursor = _upper_bound(signal_indexes, signal_cursor, exit_index)
                    break

            if leg_count > 0:
                balance += net
                daily_pnl += chain_realized_pnl + net
                closed_orders += leg_count
                leg_count = 0
                signal_cursor = _upper_bound(signal_indexes, signal_cursor, exit_index)
                break

        chain_result = balance - chain_start_balance
        if chain_result < worst_chain:
            worst_chain = chain_result
        if balance > equity_peak:
            equity_peak = balance
        drawdown = equity_peak - balance
        if drawdown > max_drawdown:
            max_drawdown = drawdown

    if chains_started == 0:
        worst_chain = 0.0
    return balance - start_balance, closed_orders, chains_started, max_drawdown, worst_chain


@njit(cache=True)
def simulate_martingale_batch_numba(
    bids: np.ndarray,
    asks: np.ndarray,
    bid_maximum: np.ndarray,
    bid_minimum: np.ndarray,
    ask_maximum: np.ndarray,
    ask_minimum: np.ndarray,
    tree_size: int,
    tree_length: int,
    signal_indexes: np.ndarray,
    signal_shorts: np.ndarray,
    start_balance: float,
    fee_rate: float,
    funding_event_indexes: np.ndarray,
    funding_mark_prices: np.ndarray,
    funding_rates: np.ndarray,
    min_order_quantity: float,
    quantity_step: float,
    quantity_places: int,
    min_order_notional: float,
    max_market_order_quantity: float,
    take_profits: np.ndarray,
    stop_losses: np.ndarray,
    base_notionals: np.ndarray,
    mode: int,
    timing: int,
    attempts: int,
    target_policies: np.ndarray,
    max_chain_loss: float,
    max_day_loss: float,
    day_ids: np.ndarray,
    take_confirmation_snapshots: int,
    stop_confirmation_snapshots: int,
) -> tuple[np.ndarray, np.ndarray, np.ndarray, np.ndarray, np.ndarray]:
    """Evaluate an exit grid in one compiled call using the exact scalar kernel."""
    pnls = np.empty(len(take_profits), dtype=np.float64)
    closed_orders = np.empty(len(take_profits), dtype=np.int64)
    chains = np.empty(len(take_profits), dtype=np.int64)
    max_drawdowns = np.empty(len(take_profits), dtype=np.float64)
    worst_chains = np.empty(len(take_profits), dtype=np.float64)
    for index in range(len(take_profits)):
        pnl, order_count, chain_count, max_drawdown, worst_chain = simulate_martingale_numba(
            bids, asks, bid_maximum, bid_minimum, ask_maximum, ask_minimum,
            tree_size, tree_length, signal_indexes, signal_shorts,
            start_balance, fee_rate, funding_event_indexes, funding_mark_prices, funding_rates,
            min_order_quantity, quantity_step, quantity_places,
            min_order_notional, max_market_order_quantity, take_profits[index], stop_losses[index],
            base_notionals[index], mode, timing, attempts,
            target_policies[index], max_chain_loss, max_day_loss, day_ids,
            take_confirmation_snapshots, stop_confirmation_snapshots,
        )
        pnls[index] = pnl
        closed_orders[index] = order_count
        chains[index] = chain_count
        max_drawdowns[index] = max_drawdown
        worst_chains[index] = worst_chain
    return pnls, closed_orders, chains, max_drawdowns, worst_chains
