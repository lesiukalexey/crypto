"""Build complete Kyiv-calendar backtest windows anchored at the data end."""

from __future__ import annotations

from calendar import monthrange
from datetime import date, datetime, timedelta, timezone
from zoneinfo import ZoneInfo

KYIV = ZoneInfo("Europe/Kyiv")
INTERVAL_DAYS = {"week": 7, "two_weeks": 14}


def subtract_month(value: date) -> date:
    year, month = value.year, value.month - 1
    if month == 0:
        year, month = year - 1, 12
    return date(year, month, min(value.day, monthrange(year, month)[1]))


def build_full_periods(start: datetime, end: datetime, interval: str) -> list[dict[str, str]]:
    """Return complete adjacent windows, anchored to the last common quote."""
    if interval not in (*INTERVAL_DAYS, "month"):
        raise ValueError("Unknown interval")
    if start.tzinfo is None or end.tzinfo is None:
        raise ValueError("Coverage bounds must be timezone-aware")
    start = start.astimezone(KYIV)
    cursor = end.astimezone(KYIV)
    reverse: list[tuple[datetime, datetime]] = []
    while True:
        if interval == "month":
            previous_day = subtract_month(cursor.date())
            previous = cursor.replace(year=previous_day.year, month=previous_day.month, day=previous_day.day)
        else:
            previous = cursor - timedelta(days=INTERVAL_DAYS[interval])
        if previous < start:
            break
        reverse.append((previous, cursor))
        cursor = previous
    periods = []
    for begin, end in reversed(reverse):
        begin_dt = begin
        end_dt = end
        begin_label = begin_dt.strftime("%d.%m.%Y") if begin_dt.time().hour == begin_dt.time().minute == begin_dt.time().second == 0 else begin_dt.strftime("%d.%m.%Y %H:%M")
        end_label = end_dt.strftime("%d.%m.%Y") if end_dt.time().hour == end_dt.time().minute == end_dt.time().second == 0 else end_dt.strftime("%d.%m.%Y %H:%M")
        periods.append({
            "start_day": begin_dt.isoformat(),
            "end_day_exclusive": end_dt.isoformat(),
            "start_utc": begin_dt.astimezone(ZoneInfo("UTC")).strftime("%Y-%m-%d %H:%M:%S.%f"),
            "end_utc": end_dt.astimezone(ZoneInfo("UTC")).strftime("%Y-%m-%d %H:%M:%S.%f"),
            "label": f"{begin_label} — {end_label}",
        })
    return periods
