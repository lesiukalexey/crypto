import numpy as np


def _first_ge(maximum, size, length, start, target):
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


def _first_le(minimum, size, length, start, target):
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


def _lower_bound(values, start, target):
    low = start
    high = len(values)
    while low < high:
        middle = (low + high) // 2
        if values[middle] < target:
            low = middle + 1
        else:
            high = middle
    return low


def simulate_standard(bids, asks, bid_maximum, bid_minimum, ask_maximum, ask_minimum,
                     tree_size, tree_length, signal_indexes, signal_shorts,
                     start_balance, fee_rate, take_profit, stop_loss):
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
            if has_take and take_profit < balance:
                take_price = entry_price * inverse_fee_factor * (1.0 - take_profit / balance)
                first_take = _first_le(ask_minimum, tree_size, tree_length, entry_index + 1, take_price)
            if has_stop:
                stop_price = entry_price * inverse_fee_factor * (1.0 + stop_loss / balance)
                first_stop = _first_ge(ask_maximum, tree_size, tree_length, entry_index + 1, stop_price)
        else:
            if has_take:
                take_price = entry_price * fee_factor * (1.0 + take_profit / balance)
                first_take = _first_ge(bid_maximum, tree_size, tree_length, entry_index + 1, take_price)
            if has_stop and stop_loss < balance:
                stop_price = entry_price * fee_factor * (1.0 - stop_loss / balance)
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
        balance *= 1.0 + net_ratio
        trades_closed += 1
        if not has_crossing:
            break
        signal_cursor = _lower_bound(signal_indexes, signal_cursor, exit_index + 1)
    return balance - start_balance, trades_closed


#pythran export simulate_standard(float64[] order(C), float64[] order(C), float64[] order(C), float64[] order(C), float64[] order(C), float64[] order(C), int, int, int64[] order(C), bool[] order(C), float, float, float, float)
