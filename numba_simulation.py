"""Numba-compiled simulation kernel for non-martingale ranking candidates."""

from __future__ import annotations

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
    take_profit: float,
    stop_loss: float,
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

        first_take = -1
        first_stop = -1
        if is_short:
            if has_take and take_profit < start_balance:
                take_price = entry_price * (inverse_fee_factor - take_profit / start_balance)
                first_take = _first_le(ask_minimum, tree_size, tree_length, entry_index + 1, take_price)
            if has_stop:
                stop_price = entry_price * (inverse_fee_factor + stop_loss / start_balance)
                first_stop = _first_ge(ask_maximum, tree_size, tree_length, entry_index + 1, stop_price)
        else:
            if has_take:
                take_price = entry_price * fee_factor * (1.0 + take_profit / start_balance)
                first_take = _first_ge(bid_maximum, tree_size, tree_length, entry_index + 1, take_price)
            if has_stop and stop_loss < start_balance:
                stop_price = entry_price * fee_factor * (1.0 - stop_loss / start_balance)
                first_stop = _first_le(bid_minimum, tree_size, tree_length, entry_index + 1, stop_price)

        has_crossing = first_take >= 0 or first_stop >= 0
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
            net_ratio = (entry_price * (1.0 - fee_rate) - exit_price * (1.0 + fee_rate)) / (entry_price * (1.0 + fee_rate))
        else:
            net_ratio = (exit_price * (1.0 - fee_rate) - entry_price * (1.0 + fee_rate)) / (entry_price * (1.0 + fee_rate))
        balance += start_balance * net_ratio
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
    take_profit: float,
    stop_loss: float,
    closed_balance: float,
    signal_from: int,
    closed_trades: int,
    open_entry_index: int,
    open_is_short: bool,
) -> tuple[float, int, float, int, int, bool]:
    """Resume a fixed-initial-stake run, retaining any open position at the boundary."""
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
        if open_short:
            if has_take and take_profit < start_balance:
                target = entry_price * (inverse_fee_factor - take_profit / start_balance)
                first_take = _first_le(ask_minimum, tree_size, count, open_index + 1, target)
            if has_stop:
                target = entry_price * (inverse_fee_factor + stop_loss / start_balance)
                first_stop = _first_ge(ask_maximum, tree_size, count, open_index + 1, target)
        else:
            if has_take:
                target = entry_price * fee_factor * (1.0 + take_profit / start_balance)
                first_take = _first_ge(bid_maximum, tree_size, count, open_index + 1, target)
            if has_stop and stop_loss < start_balance:
                target = entry_price * fee_factor * (1.0 - stop_loss / start_balance)
                first_stop = _first_le(bid_minimum, tree_size, count, open_index + 1, target)

        if first_take >= 0 or first_stop >= 0:
            if first_take >= 0 and first_stop >= 0:
                exit_index = min(first_take, first_stop)
            elif first_take >= 0:
                exit_index = first_take
            else:
                exit_index = first_stop
            exit_price = asks[exit_index] if open_short else bids[exit_index]
            if open_short:
                net_ratio = (entry_price * (1.0 - fee_rate) - exit_price * (1.0 + fee_rate)) / (entry_price * (1.0 + fee_rate))
            else:
                net_ratio = (exit_price * (1.0 - fee_rate) - entry_price * (1.0 + fee_rate)) / (entry_price * (1.0 + fee_rate))
            balance += start_balance * net_ratio
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

        first_take = -1
        first_stop = -1
        if is_short:
            if has_take and take_profit < start_balance:
                target = entry_price * (inverse_fee_factor - take_profit / start_balance)
                first_take = _first_le(ask_minimum, tree_size, count, entry_index + 1, target)
            if has_stop:
                target = entry_price * (inverse_fee_factor + stop_loss / start_balance)
                first_stop = _first_ge(ask_maximum, tree_size, count, entry_index + 1, target)
        else:
            if has_take:
                target = entry_price * fee_factor * (1.0 + take_profit / start_balance)
                first_take = _first_ge(bid_maximum, tree_size, count, entry_index + 1, target)
            if has_stop and stop_loss < start_balance:
                target = entry_price * fee_factor * (1.0 - stop_loss / start_balance)
                first_stop = _first_le(bid_minimum, tree_size, count, entry_index + 1, target)

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
        if is_short:
            net_ratio = (entry_price * (1.0 - fee_rate) - exit_price * (1.0 + fee_rate)) / (entry_price * (1.0 + fee_rate))
        else:
            net_ratio = (exit_price * (1.0 - fee_rate) - entry_price * (1.0 + fee_rate)) / (entry_price * (1.0 + fee_rate))
        balance += start_balance * net_ratio
        closed_trades += 1
        signal_cursor = _lower_bound(signal_indexes, signal_cursor, exit_index + 1)
        signal_from = exit_index + 1

    if open_index >= 0 and count > 0:
        entry_price = bids[open_index] if open_short else asks[open_index]
        exit_price = asks[count - 1] if open_short else bids[count - 1]
        if open_short:
            net_ratio = (entry_price * (1.0 - fee_rate) - exit_price * (1.0 + fee_rate)) / (entry_price * (1.0 + fee_rate))
        else:
            net_ratio = (exit_price * (1.0 - fee_rate) - entry_price * (1.0 + fee_rate)) / (entry_price * (1.0 + fee_rate))
        marked_balance = balance + start_balance * net_ratio
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
    take_profit: float,
    stop_loss: float,
    day_quote_counts: np.ndarray,
    closed_balance: float,
    signal_from: int,
    closed_trades: int,
    open_entry_index: int,
    open_is_short: bool,
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
            start_balance, fee_rate, take_profit, stop_loss,
            current_balance, current_signal_from, current_closed_trades,
            current_open_index, current_open_short,
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
    take_profit: float,
    stop_loss: float,
    base_notional: float,
    mode: int,
    timing: int,
    attempts: int,
    target_policy: int,
) -> tuple[float, int]:
    balance = start_balance
    signal_cursor = 0
    closed_orders = 0
    count = len(bids)
    has_take = not np.isnan(take_profit)
    has_stop = not np.isnan(stop_loss)
    if base_notional <= 0.0:
        return 0.0, 0

    while balance > 0.0 and signal_cursor < len(signal_indexes):
        entry_index = signal_indexes[signal_cursor]
        is_short = signal_shorts[signal_cursor]
        signal_cursor += 1
        price = bids[entry_index] if is_short else asks[entry_index]
        if price <= 0.0:
            continue
        notional = base_notional
        if notional <= 0.0:
            break

        leg_prices = np.empty(attempts, dtype=np.float64)
        leg_quantities = np.empty(attempts, dtype=np.float64)
        leg_fees = np.empty(attempts, dtype=np.float64)
        leg_prices[0] = price
        leg_quantities[0] = notional / price
        leg_fees[0] = notional * fee_rate
        leg_count = 1
        position_notional = notional
        chain_base_notional = notional
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

            has_crossing = first_take >= 0 or first_stop >= 0
            if first_take >= 0 and first_stop >= 0:
                exit_index = min(first_take, first_stop)
            elif first_take >= 0:
                exit_index = first_take
            elif first_stop >= 0:
                exit_index = first_stop
            else:
                exit_index = count - 1

            exit_quote = asks[exit_index] if is_short else bids[exit_index]
            if is_short:
                net = entry_cost - total_quantity * exit_quote - entry_fees - total_quantity * exit_quote * fee_rate
            else:
                net = total_quantity * exit_quote - entry_cost - entry_fees - total_quantity * exit_quote * fee_rate

            if first_take >= 0 and first_take == exit_index:
                balance += net
                closed_orders += leg_count
                leg_count = 0
                signal_cursor = _upper_bound(signal_indexes, signal_cursor, exit_index)
                break

            if first_stop >= 0 and first_stop == exit_index and stage < attempts:
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
                                available = max(0.0, balance + net - position_notional)
                                add_notional = min(requested, available / (1.0 + fee_rate))
                                found_signal = True
                                break
                            signal_cursor += 1
                        if not found_signal:
                            end_quote = asks[count - 1] if is_short else bids[count - 1]
                            if is_short:
                                end_net = entry_cost - total_quantity * end_quote - entry_fees - total_quantity * end_quote * fee_rate
                            else:
                                end_net = total_quantity * end_quote - entry_cost - entry_fees - total_quantity * end_quote * fee_rate
                            balance += end_net
                            closed_orders += leg_count
                            leg_count = 0
                            signal_cursor = len(signal_indexes)
                            break
                    if add_notional > 0.0 and next_index < count:
                        add_price = bids[next_index] if next_short else asks[next_index]
                        if add_price > 0.0:
                            leg_prices[leg_count] = add_price
                            leg_quantities[leg_count] = add_notional / add_price
                            leg_fees[leg_count] = add_notional * fee_rate
                            leg_count += 1
                            position_notional += add_notional
                            stage += 1
                            entry_index = next_index
                            continue
                else:
                    balance += net
                    closed_orders += 1
                    leg_count = 0
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
                        if reverse_price > 0.0:
                            is_short = reverse_short
                            entry_index = next_index
                            position_notional = reverse_notional
                            leg_prices[0] = reverse_price
                            leg_quantities[0] = reverse_notional / reverse_price
                            leg_fees[0] = reverse_notional * fee_rate
                            leg_count = 1
                            stage += 1
                            continue
                    signal_cursor = _upper_bound(signal_indexes, signal_cursor, exit_index)
                    break

            if leg_count > 0:
                balance += net
                closed_orders += leg_count
                leg_count = 0
                signal_cursor = _upper_bound(signal_indexes, signal_cursor, exit_index)
                break

    return balance - start_balance, closed_orders
