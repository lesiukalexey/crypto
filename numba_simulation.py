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
def _find_delayed_standard_exit(
    bids: np.ndarray,
    asks: np.ndarray,
    entry_index: int,
    is_short: bool,
    start_balance: float,
    fee_rate: float,
    take_profit: float,
    stop_loss: float,
    take_confirmation_snapshots: int,
    stop_confirmation_snapshots: int,
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
            net = start_balance * (entry_price * (1.0 - fee_rate) - exit_price * (1.0 + fee_rate)) / (entry_price * (1.0 + fee_rate))
        else:
            net = start_balance * (exit_price * (1.0 - fee_rate) - entry_price * (1.0 + fee_rate)) / (entry_price * (1.0 + fee_rate))
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

        delayed_crossing = False
        if take_confirmation_snapshots > 0 or stop_confirmation_snapshots > 0:
            delayed_exit, delayed_crossing = _find_delayed_standard_exit(
                bids, asks, entry_index, is_short, start_balance, fee_rate,
                take_profit, stop_loss, take_confirmation_snapshots, stop_confirmation_snapshots,
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
    take_confirmation_snapshots: int,
    stop_confirmation_snapshots: int,
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

        if take_confirmation_snapshots > 0 or stop_confirmation_snapshots > 0:
            delayed_exit, _ = _find_delayed_standard_exit(
                bids, asks, open_index, open_short, start_balance, fee_rate,
                take_profit, stop_loss, take_confirmation_snapshots, stop_confirmation_snapshots,
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

        if take_confirmation_snapshots > 0 or stop_confirmation_snapshots > 0:
            delayed_exit, _ = _find_delayed_standard_exit(
                bids, asks, entry_index, is_short, start_balance, fee_rate,
                take_profit, stop_loss, take_confirmation_snapshots, stop_confirmation_snapshots,
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
            start_balance, fee_rate, take_profit, stop_loss,
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
) -> tuple[float, int, int]:
    balance = start_balance
    signal_cursor = 0
    closed_orders = 0
    chains_started = 0
    daily_day = -1
    daily_pnl = 0.0
    count = len(bids)
    has_take = not np.isnan(take_profit)
    has_stop = not np.isnan(stop_loss)
    if base_notional <= 0.0:
        return 0.0, 0, 0

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
        if notional <= 0.0:
            break

        leg_prices = np.empty(attempts, dtype=np.float64)
        leg_quantities = np.empty(attempts, dtype=np.float64)
        leg_fees = np.empty(attempts, dtype=np.float64)
        leg_prices[0] = price
        leg_quantities[0] = notional / price
        leg_fees[0] = notional * fee_rate
        leg_count = 1
        chains_started += 1
        position_notional = notional
        chain_base_notional = notional
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

            if take_confirmation_snapshots > 0 or stop_confirmation_snapshots > 0:
                first_take, first_stop = _find_delayed_martingale_exit(
                    bids, asks, entry_index, is_short, total_quantity, entry_cost,
                    entry_fees, fee_rate, target_amount,
                    stop_amount if has_stop else np.nan, take_confirmation_snapshots, stop_confirmation_snapshots,
                )

            has_crossing = first_take >= 0 or first_stop >= 0
            first_risk = -1
            if not np.isnan(max_chain_loss) and max_chain_loss > 0.0:
                remaining_chain_risk = max_chain_loss + chain_realized_pnl
                if remaining_chain_risk <= 0.0:
                    first_risk = entry_index + 1
                elif is_short:
                    risk_price = (entry_cost - entry_fees + remaining_chain_risk) / (total_quantity * (1.0 + fee_rate))
                    first_risk = _first_ge(ask_maximum, tree_size, tree_length, entry_index + 1, risk_price)
                else:
                    risk_price = (entry_cost + entry_fees - remaining_chain_risk) / (total_quantity * (1.0 - fee_rate))
                    first_risk = _first_le(bid_minimum, tree_size, tree_length, entry_index + 1, risk_price)
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
                    if is_short:
                        day_risk_price = (entry_cost - entry_fees + remaining_day_risk) / (total_quantity * (1.0 + fee_rate))
                        candidate_day_risk = _first_ge(ask_maximum, tree_size, tree_length, day_start, day_risk_price)
                    else:
                        day_risk_price = (entry_cost + entry_fees - remaining_day_risk) / (total_quantity * (1.0 - fee_rate))
                        candidate_day_risk = _first_le(bid_minimum, tree_size, tree_length, day_start, day_risk_price)
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
                            balance += take_net
                            daily_pnl += chain_realized_pnl + take_net
                            closed_orders += leg_count
                            leg_count = 0
                            signal_cursor = _upper_bound(signal_indexes, 0, first_take)
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
                        if next_price > 0.0:
                            entry_index = next_index
                            position_notional = next_notional
                            leg_prices[0] = next_price
                            leg_quantities[0] = next_notional / next_price
                            leg_fees[0] = next_notional * fee_rate
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
                daily_pnl += chain_realized_pnl + net
                closed_orders += leg_count
                leg_count = 0
                signal_cursor = _upper_bound(signal_indexes, signal_cursor, exit_index)
                break

    return balance - start_balance, closed_orders, chains_started


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
) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    """Evaluate an exit grid in one compiled call using the exact scalar kernel."""
    pnls = np.empty(len(take_profits), dtype=np.float64)
    closed_orders = np.empty(len(take_profits), dtype=np.int64)
    chains = np.empty(len(take_profits), dtype=np.int64)
    for index in range(len(take_profits)):
        pnl, order_count, chain_count = simulate_martingale_numba(
            bids, asks, bid_maximum, bid_minimum, ask_maximum, ask_minimum,
            tree_size, tree_length, signal_indexes, signal_shorts,
            start_balance, fee_rate, take_profits[index], stop_losses[index],
            base_notionals[index], mode, timing, attempts,
            target_policies[index], max_chain_loss, max_day_loss, day_ids,
            take_confirmation_snapshots, stop_confirmation_snapshots,
        )
        pnls[index] = pnl
        closed_orders[index] = order_count
        chains[index] = chain_count
    return pnls, closed_orders, chains
