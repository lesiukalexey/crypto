"""Position sizing and replay helpers for martingale variants."""

from __future__ import annotations

import bisect
from typing import Any


def martingale_base_notional(
    start_balance: float,
    fee_rate: float,
    stop_loss: float | None,
    mode: str,
    attempts: int,
    target_policy: str = "scaled",
) -> float:
    """Size the first order so the full geometric progression fits at 1x."""
    if mode not in ("simple", "reverse", "simple_profit", "reverse_profit") or not 2 <= attempts <= 10:
        raise ValueError("Invalid martingale options")
    if target_policy not in ("scaled", "fixed"):
        raise ValueError("Invalid martingale exit target policy")
    final_scale = 2 ** (attempts - 1)
    if mode.endswith("_profit") or stop_loss is None:
        return start_balance / final_scale

    risk = abs(stop_loss)
    if mode in ("simple", "simple_profit"):
        previous_scale = 2 ** (attempts - 2)
        reserved_loss = risk * (previous_scale if target_policy == "scaled" else 1)
        required_balance_per_base = final_scale + fee_rate * previous_scale
    else:
        reserved_loss = risk * ((final_scale - 1) if target_policy == "scaled" else attempts - 1)
        required_balance_per_base = final_scale * (1 + fee_rate)
    return max(0.0, (start_balance - reserved_loss) / required_balance_per_base)


def simulate_martingale(
    quotes: Any,
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
    target_policy: str = "scaled",
    max_chain_loss: float | None = None,
    max_day_loss: float | None = None,
) -> tuple[float, int, int]:
    """Replay capped, 1x martingale exposure. Each opened order counts as a trade."""
    if mode not in ("simple", "reverse", "simple_profit", "reverse_profit") or timing not in ("immediate", "rules") or not 2 <= attempts <= 10:
        raise ValueError("Invalid martingale options")
    balance = start_balance
    signal_cursor = 0
    closed_orders = 0
    chains_started = 0
    daily_day = None
    daily_pnl = 0.0
    count = len(quotes)
    if target_policy not in ("scaled", "fixed"):
        raise ValueError("Invalid martingale exit target policy")
    base_notional = martingale_base_notional(start_balance, fee_rate, stop_loss, mode, attempts, target_policy)
    if base_notional <= 0:
        return 0.0, 0, 0

    while balance > 0 and signal_cursor < len(signal_indexes):
        entry_index = signal_indexes[signal_cursor]
        is_short = signal_shorts[signal_cursor]
        signal_cursor += 1
        entry_day = int(quotes.local_days[entry_index]) if hasattr(quotes, "local_days") else 0
        if entry_day != daily_day:
            daily_day = entry_day
            daily_pnl = 0.0
        if max_day_loss is not None and max_day_loss > 0 and daily_pnl <= -max_day_loss:
            continue
        price = quotes.bids[entry_index] if is_short else quotes.asks[entry_index]
        if price <= 0:
            continue
        notional = min(base_notional, balance / (1 + fee_rate))
        if notional <= 0:
            break
        legs: list[tuple[float, float, float]] = []  # entry price, quantity, entry fee
        position_notional = 0.0
        chain_base_notional = notional
        chain_realized_pnl = 0.0
        stage = 1
        leg_quantity = notional / price
        legs.append((price, leg_quantity, notional * fee_rate))
        chains_started += 1
        position_notional += notional

        while legs:
            total_quantity = sum(leg[1] for leg in legs)
            entry_cost = sum(leg[0] * leg[1] for leg in legs)
            entry_fees = sum(leg[2] for leg in legs)
            multiplier = position_notional / chain_base_notional if chain_base_notional > 0 else 1.0
            target_multiplier = multiplier if target_policy == "scaled" else 1.0
            target_amount = take_profit * target_multiplier if take_profit is not None else None
            stop_amount = stop_loss * target_multiplier if stop_loss is not None else None
            if is_short:
                price_tree = ask_tree
                take_price = (entry_cost - entry_fees - target_amount) / (total_quantity * (1 + fee_rate)) if target_amount is not None else None
                stop_price = (entry_cost - entry_fees - stop_amount) / (total_quantity * (1 + fee_rate)) if stop_amount is not None else None
                first_take = price_tree.first_le(entry_index + 1, take_price) if take_price is not None else None
                first_stop = price_tree.first_ge(entry_index + 1, stop_price) if stop_price is not None else None
                remaining_chain_risk = None if max_chain_loss is None or max_chain_loss <= 0 else max_chain_loss + chain_realized_pnl
                risk_price = None if remaining_chain_risk is None else (entry_cost - entry_fees + remaining_chain_risk) / (total_quantity * (1 + fee_rate))
                first_risk = price_tree.first_ge(entry_index + 1, risk_price) if risk_price is not None and risk_price > 0 else None
            else:
                price_tree = bid_tree
                factor = total_quantity * (1 - fee_rate)
                take_price = (entry_cost + entry_fees + target_amount) / factor if target_amount is not None else None
                stop_price = (entry_cost + entry_fees + stop_amount) / factor if stop_amount is not None else None
                first_take = price_tree.first_ge(entry_index + 1, take_price) if take_price is not None else None
                first_stop = price_tree.first_le(entry_index + 1, stop_price) if stop_price is not None else None
                remaining_chain_risk = None if max_chain_loss is None or max_chain_loss <= 0 else max_chain_loss + chain_realized_pnl
                risk_price = None if remaining_chain_risk is None else (entry_cost + entry_fees - remaining_chain_risk) / factor
                first_risk = price_tree.first_le(entry_index + 1, risk_price) if risk_price is not None and risk_price > 0 else None

            crossings = [index for index in (first_take, first_stop, first_risk) if index is not None]
            exit_index = min(crossings) if crossings else count - 1
            def net_at(index: int) -> float:
                quote = quotes.asks[index] if is_short else quotes.bids[index]
                if is_short:
                    return entry_cost - total_quantity * quote - entry_fees - total_quantity * quote * fee_rate
                return total_quantity * quote - entry_cost - entry_fees - total_quantity * quote * fee_rate

            first_day_risk = None
            day_ids = getattr(quotes, "local_days", None)
            if max_day_loss is not None and max_day_loss > 0 and day_ids is not None:
                day_start = entry_index + 1
                while day_start < count and day_start <= exit_index:
                    current_day = int(day_ids[day_start])
                    segment_end = day_start
                    while segment_end + 1 < count and int(day_ids[segment_end + 1]) == current_day:
                        segment_end += 1
                    segment_end = min(segment_end, exit_index)
                    realized_for_day = daily_pnl if current_day == daily_day else 0.0
                    remaining_day_risk = max_day_loss + realized_for_day + chain_realized_pnl
                    if remaining_day_risk <= 0:
                        first_day_risk = day_start
                        break
                    for candidate_index in range(day_start, segment_end + 1):
                        if realized_for_day + chain_realized_pnl + net_at(candidate_index) <= -max_day_loss:
                            first_day_risk = candidate_index
                            break
                    if first_day_risk is not None:
                        break
                    day_start = segment_end + 1
            if first_day_risk is not None and first_day_risk <= exit_index:
                exit_index = first_day_risk
            risk_trigger = (first_risk is not None and first_risk == exit_index) or (first_day_risk is not None and first_day_risk == exit_index)

            net = net_at(exit_index)
            exit_day = int(quotes.local_days[exit_index]) if hasattr(quotes, "local_days") else entry_day
            if exit_day != daily_day:
                daily_day = exit_day
                daily_pnl = 0.0
            if risk_trigger or (
                max_day_loss is not None and max_day_loss > 0
                and daily_pnl + chain_realized_pnl + net <= -max_day_loss
            ):
                balance += net
                daily_pnl += chain_realized_pnl + net
                closed_orders += len(legs)
                legs.clear()
                signal_cursor = bisect.bisect_right(signal_indexes, exit_index, lo=signal_cursor)
                break

            transition_on_profit = mode.endswith("_profit")
            trigger_transition = (
                (transition_on_profit and first_take is not None and first_take == exit_index)
                or (not transition_on_profit and first_stop is not None and first_stop == exit_index)
            )
            if first_take is not None and first_take == exit_index and not trigger_transition:
                balance += net
                daily_pnl += chain_realized_pnl + net
                closed_orders += len(legs)
                legs.clear()
                signal_cursor = bisect.bisect_right(signal_indexes, exit_index, lo=signal_cursor)
                break

            if trigger_transition and stage < attempts:
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
                            # The chain remains open while waiting. A profit
                            # target reached before the next compatible signal
                            # closes it at that quote instead of waiting for a
                            # later signal or the range endpoint.
                            if first_take is not None:
                                balance += net_at(first_take)
                                daily_pnl += chain_realized_pnl + net_at(first_take)
                                closed_orders += len(legs)
                                legs.clear()
                                signal_cursor = bisect.bisect_right(signal_indexes, first_take)
                                break
                            end_net = net_at(count - 1)
                            balance += end_net
                            closed_orders += len(legs)
                            legs.clear()
                            signal_cursor = len(signal_indexes)
                            break
                        if first_take is not None and first_take < next_index:
                            balance += net_at(first_take)
                            daily_pnl += chain_realized_pnl + net_at(first_take)
                            closed_orders += len(legs)
                            legs.clear()
                            signal_cursor = bisect.bisect_right(signal_indexes, first_take)
                            break
                    if add_notional > 0 and next_index < count:
                        add_price = quotes.bids[next_index] if next_short else quotes.asks[next_index]
                        if add_price > 0:
                            add_quantity = add_notional / add_price
                            legs.append((add_price, add_quantity, add_notional * fee_rate))
                            position_notional += add_notional
                            stage += 1
                            entry_index = next_index
                            continue
                elif mode == "simple_profit":
                    balance += net
                    chain_realized_pnl += net
                    closed_orders += len(legs)
                    legs.clear()
                    requested = position_notional * 2
                    next_index = exit_index
                    if timing == "rules":
                        next_index = count
                        while signal_cursor < len(signal_indexes):
                            candidate_index = signal_indexes[signal_cursor]
                            candidate_short = signal_shorts[signal_cursor]
                            signal_cursor += 1
                            if candidate_index > exit_index and candidate_short == is_short:
                                next_index = candidate_index
                                break
                    add_notional = min(requested, max(0.0, balance) / (1 + fee_rate))
                    if next_index < count and add_notional > 0:
                        add_price = quotes.bids[next_index] if is_short else quotes.asks[next_index]
                        if add_price > 0:
                            position_notional = add_notional
                            entry_index = next_index
                            legs.append((add_price, add_notional / add_price, add_notional * fee_rate))
                            stage += 1
                            continue
                    break
                else:
                    balance += net
                    chain_realized_pnl += net
                    closed_orders += 1
                    legs.clear()
                    if (
                        (max_chain_loss is not None and max_chain_loss > 0 and chain_realized_pnl <= -max_chain_loss)
                        or (max_day_loss is not None and max_day_loss > 0 and daily_pnl + chain_realized_pnl <= -max_day_loss)
                    ):
                        daily_pnl += chain_realized_pnl
                        signal_cursor = bisect.bisect_right(signal_indexes, exit_index, lo=signal_cursor)
                        break
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
                        reverse_price = quotes.bids[next_index] if reverse_short else quotes.asks[next_index]
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
            daily_pnl += chain_realized_pnl + net
            closed_orders += len(legs)
            legs.clear()
            signal_cursor = bisect.bisect_right(signal_indexes, exit_index, lo=signal_cursor)
            break

    return balance - start_balance, closed_orders, chains_started
