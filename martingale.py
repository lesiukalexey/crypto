"""Position sizing and replay helpers for martingale variants."""

from __future__ import annotations

import bisect
from typing import Any


def simulate_martingale(
    rows: list[dict[str, Any]],
    signal_indexes: list[int],
    signal_shorts: list[bool],
    bid_tree: Any,
    ask_tree: Any,
    start_balance: float,
    fee_rate: float,
    take_profit: float | None,
    stop_loss: float | None,
    mode: str,
    timing: str,
    attempts: int,
) -> tuple[float, int]:
    """Replay capped, 1x martingale exposure. Each opened order counts as a trade."""
    if mode not in ("simple", "reverse") or timing not in ("immediate", "rules") or not 2 <= attempts <= 10:
        raise ValueError("Invalid martingale options")
    balance = start_balance
    signal_cursor = 0
    closed_orders = 0
    count = len(rows)
    base_notional = start_balance / (2 ** (attempts - 1))

    while balance > 0 and signal_cursor < len(signal_indexes):
        entry_index = signal_indexes[signal_cursor]
        is_short = signal_shorts[signal_cursor]
        signal_cursor += 1
        price = float(rows[entry_index]["bid_price"] if is_short else rows[entry_index]["ask_price"])
        if price <= 0:
            continue
        notional = min(base_notional, balance / (1 + fee_rate))
        if notional <= 0:
            break
        legs: list[tuple[float, float, float]] = []  # entry price, quantity, entry fee
        position_notional = 0.0
        chain_base_notional = notional
        stage = 1
        leg_quantity = notional / price
        legs.append((price, leg_quantity, notional * fee_rate))
        position_notional += notional

        while legs:
            total_quantity = sum(leg[1] for leg in legs)
            entry_cost = sum(leg[0] * leg[1] for leg in legs)
            entry_fees = sum(leg[2] for leg in legs)
            multiplier = position_notional / chain_base_notional if chain_base_notional > 0 else 1.0
            target_amount = take_profit * multiplier if take_profit is not None else None
            stop_amount = stop_loss * multiplier if stop_loss is not None else None
            if is_short:
                price_tree = ask_tree
                take_price = (entry_cost - entry_fees - target_amount) / (total_quantity * (1 + fee_rate)) if target_amount is not None else None
                stop_price = (entry_cost - entry_fees - stop_amount) / (total_quantity * (1 + fee_rate)) if stop_amount is not None else None
                first_take = price_tree.first_le(entry_index + 1, take_price) if take_price is not None else None
                first_stop = price_tree.first_ge(entry_index + 1, stop_price) if stop_price is not None else None
            else:
                price_tree = bid_tree
                factor = total_quantity * (1 - fee_rate)
                take_price = (entry_cost + entry_fees + target_amount) / factor if target_amount is not None else None
                stop_price = (entry_cost + entry_fees + stop_amount) / factor if stop_amount is not None else None
                first_take = price_tree.first_ge(entry_index + 1, take_price) if take_price is not None else None
                first_stop = price_tree.first_le(entry_index + 1, stop_price) if stop_price is not None else None

            crossings = [index for index in (first_take, first_stop) if index is not None]
            exit_index = min(crossings) if crossings else count - 1
            def net_at(index: int) -> float:
                quote = float(rows[index]["ask_price"] if is_short else rows[index]["bid_price"])
                if is_short:
                    return entry_cost - total_quantity * quote - entry_fees - total_quantity * quote * fee_rate
                return total_quantity * quote - entry_cost - entry_fees - total_quantity * quote * fee_rate

            net = net_at(exit_index)

            if first_take is not None and first_take == exit_index:
                balance += net
                closed_orders += len(legs)
                legs.clear()
                signal_cursor = bisect.bisect_right(signal_indexes, exit_index, lo=signal_cursor)
                break

            if first_stop is not None and first_stop == exit_index and stage < attempts:
                signal_cursor = bisect.bisect_right(signal_indexes, exit_index, lo=signal_cursor)
                if mode == "simple":
                    next_index = exit_index
                    next_short = is_short
                    requested = position_notional
                    available = max(0.0, balance + net - position_notional)
                    add_notional = min(requested, available / (1 + fee_rate))
                    if timing == "rules":
                        add_notional = 0.0
                        found_signal = False
                        while signal_cursor < len(signal_indexes):
                            candidate_short = signal_shorts[signal_cursor]
                            if candidate_short == is_short:
                                next_index = signal_indexes[signal_cursor]
                                signal_cursor += 1
                                net = net_at(next_index)
                                available = max(0.0, balance + net - position_notional)
                                add_notional = min(requested, available / (1 + fee_rate))
                                found_signal = True
                                break
                            signal_cursor += 1
                        if not found_signal:
                            end_net = net_at(count - 1)
                            balance += end_net
                            closed_orders += len(legs)
                            legs.clear()
                            signal_cursor = len(signal_indexes)
                            break
                    if add_notional > 0 and next_index < count:
                        add_price = float(rows[next_index]["bid_price"] if next_short else rows[next_index]["ask_price"])
                        if add_price > 0:
                            add_quantity = add_notional / add_price
                            legs.append((add_price, add_quantity, add_notional * fee_rate))
                            position_notional += add_notional
                            stage += 1
                            entry_index = next_index
                            continue
                else:
                    balance += net
                    closed_orders += 1
                    legs.clear()
                    wanted = position_notional * 2
                    reverse_short = not is_short
                    next_index = exit_index
                    if timing == "rules":
                        next_index = count
                        while signal_cursor < len(signal_indexes):
                            candidate_index = signal_indexes[signal_cursor]
                            candidate_short = signal_shorts[signal_cursor]
                            signal_cursor += 1
                            if candidate_index > exit_index and candidate_short == reverse_short:
                                next_index = candidate_index
                                break
                    available = max(0.0, balance)
                    reverse_notional = min(wanted, available / (1 + fee_rate))
                    if next_index < count and reverse_notional > 0:
                        reverse_price = float(rows[next_index]["bid_price"] if reverse_short else rows[next_index]["ask_price"])
                        if reverse_price > 0:
                            is_short = reverse_short
                            entry_index = next_index
                            position_notional = reverse_notional
                            legs.append((reverse_price, reverse_notional / reverse_price, reverse_notional * fee_rate))
                            stage += 1
                            continue
                    signal_cursor = bisect.bisect_right(signal_indexes, exit_index, lo=signal_cursor)
                    break

            balance += net
            closed_orders += len(legs)
            legs.clear()
            signal_cursor = bisect.bisect_right(signal_indexes, exit_index, lo=signal_cursor)
            break

    return balance - start_balance, closed_orders
