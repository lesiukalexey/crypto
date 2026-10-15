"""Replay one fixed strategy independently for each requested comparison range."""
import json
import hashlib
import sys
from pathlib import Path
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo
import numpy as np
import pymysql
from app import load_config
from database import connect_mysql
from strategy_ranking import QuoteArrays, PriceTree, make_signals
from contract_rules import load_contract_specs, numba_market_order_rules
from futures_costs import load_historical_funding_rates
from numba_simulation import simulate_standard_stateful_numba


def replay(request):
    config = load_config(Path(__file__).with_name('config.json'))
    symbol=request['symbol'];params=request['params'];ranges=request['ranges'];kyiv=ZoneInfo('Europe/Kyiv')
    start=lambda day:datetime.fromisoformat(day).replace(tzinfo=kyiv).astimezone(timezone.utc)
    floor=start(ranges[0]['start_day'])-timedelta(days=2)
    end=start(ranges[-1]['end_day'])+timedelta(days=1)
    db=connect_mysql(config['mysql'])
    try:
        with db.cursor(pymysql.cursors.DictCursor) as cursor:
            cursor.execute('''SELECT received_at_utc,bid_price,ask_price,last_price FROM quote_snapshots
                WHERE category=%s AND symbol=%s AND received_at_utc>=%s AND received_at_utc<%s ORDER BY received_at_utc,id''',
                (config['category'],symbol,floor.replace(tzinfo=None),end.replace(tzinfo=None)))
            rows=cursor.fetchall()
    finally:db.close()
    if not rows:
        for r in ranges:r.update(return_percent=None,trade_count=0)
        return ranges
    q=QuoteArrays.from_rows(rows,float(params.get('slippage_bps',0)))
    if str(params.get('include_funding','1'))=='1':
        q.set_funding_rates(load_historical_funding_rates(config['category'],symbol,q.timestamps[0],q.timestamps[-1]),(q.bids+q.asks)/2)
    contract=load_contract_specs(config['category'])[symbol]
    identity=hashlib.sha256(json.dumps([request,rows,contract],sort_keys=True,default=str).encode()).hexdigest()
    cache=Path.home()/'.ai/home/.local/bitget-backtest-cache/range-replays';cache.mkdir(parents=True,exist_ok=True,mode=0o700)
    target=cache/(identity+'.json')
    if target.exists():return json.loads(target.read_text())
    b,a=PriceTree(q.bids),PriceTree(q.asks)
    rules=numba_market_order_rules(contract,leverage=1)
    balance=float(params.get('balance',100));fee=float(params.get('fee',.06))/100
    entry=params['entry_config'];exit=params['exit_config'];mode=params.get('exit_confirmation_mode','immediate');delay=int(params.get('exit_confirmation_snapshots',60))
    take=stop=np.nan
    parts=exit.split(':')
    if parts[0]=='fixed':take=balance*int(parts[1])/10000;stop=balance*int(parts[2])/10000
    elif parts[0]=='profit':take=balance*int(parts[1])/1000
    elif parts[0]=='loss':stop=balance*int(parts[1])/1000
    td=delay if mode in ('both_delayed','tp_delayed') else 0;sd=delay if mode in ('both_delayed','sl_delayed') else 0
    if entry.startswith('immediate:'):signals=shorts=None
    else:
        parts=entry.split(':');family='both'
        if parts[1] in ('long','short'):family=parts.pop(1)
        signals,shorts=make_signals(q,int(parts[2])/10,int(parts[1]),family,params.get('entry_signal_mode','exact'),params.get('entry_direction_mode','follow'))
        signals=np.asarray(signals,dtype=np.int64);shorts=np.asarray(shorts,dtype=np.bool_)
    for r in ranges:
        first=int(np.searchsorted(q.timestamps,start(r['start_day']).timestamp(),side='left'))
        count=int(np.searchsorted(q.timestamps,(start(r['end_day'])+timedelta(days=1)).timestamp(),side='left'))
        if first>=count:
            r.update(return_percent=None,trade_count=0);continue
        if signals is None:si=np.array([first],dtype=np.int64);sh=np.array([entry.endswith('short')],dtype=np.bool_)
        else:
            limit=np.searchsorted(signals,count);si=signals[:limit];sh=shorts[:limit]
        result=simulate_standard_stateful_numba(q.bids,q.asks,b.maximum,b.minimum,a.maximum,a.minimum,b.size,count,si,sh,balance,fee,*rules,q.funding_quote_indexes,q.funding_mark_prices,q.funding_rates,take,stop,balance,first,0,-1,False,td,sd,False,True)
        r.update(return_percent=float(result[0])/balance*100 if np.isfinite(result[0]) else None,trade_count=int(result[1])-(1 if result[4]>=0 else 0))
    # Atomic immutable cache publication, no existing saved points are changed.
    import os,tempfile
    fd,name=tempfile.mkstemp(dir=cache,prefix='.range-')
    with os.fdopen(fd,'w') as out:json.dump(ranges,out)
    os.replace(name,target)
    return ranges

if __name__=='__main__':
    print(json.dumps(replay(json.loads(Path(sys.argv[1]).read_text()))))
