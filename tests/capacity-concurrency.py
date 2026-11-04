#!/usr/bin/env python3
"""Staging-only live WordPress/InnoDB capacity stress test. NOT executed in this workspace.

Usage: python tests/capacity-concurrency.py http://127.0.0.1:8088/wp-json/pesm/v1 123
Run on an empty, open, general-admission test session, preferably capacity=5.
Does not purchase tickets; successful holds are released by the test.
"""
import concurrent.futures
import json
import secrets
import sys
import urllib.error
import urllib.request


def request(base, path, method='GET', data=None, token=None):
    headers = {'Content-Type': 'application/json'}
    if token:
        headers['X-PESM-Hold-Token'] = token
    req = urllib.request.Request(base.rstrip('/') + path,
                                 data=None if data is None else json.dumps(data).encode(),
                                 method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            return resp.status, json.load(resp)
    except urllib.error.HTTPError as error:
        return error.code, json.load(error)


def run(base, session):
    before_status, before = request(base, f'/sessions/{session}/capacity')
    assert before_status == 200, before
    available = before['available']
    assert 1 <= available <= 20, 'Use a small, clean test session (1..20 available).'
    def attempt(_):
        return request(base, '/capacity/holds', 'POST', {
            'session_id': session, 'quantity': 1,
            'idempotency_key': secrets.token_hex(32)
        })
    with concurrent.futures.ThreadPoolExecutor(max_workers=20) as pool:
        results = list(pool.map(attempt, range(20)))
    winners = [data for code, data in results if code == 200]
    codes = [code for code, _ in results]
    print('Initial capacity:', available, '| acquired:', len(winners), '| HTTP:', codes)
    try:
        assert len(winners) <= available, 'Oversell: more reservations accepted than capacity.'
        assert len({hold['hold_id'] for hold in winners}) == len(winners), 'Duplicate reservation IDs.'
        status, after = request(base, f'/sessions/{session}/capacity')
        assert status == 200 and after['available'] == available-len(winners), (status, after)
        assert after['held'] >= len(winners), after
        print('PASS: 20 concurrent acquisition attempts did not oversell (test environment only).')
    finally:
        for hold in winners:
            status, result = request(base, '/capacity/holds', 'DELETE',
                                     {'session_id': session}, hold['token'])
            if status != 200 or not result.get('released'):
                print('WARNING: manual cleanup needed for hold', hold['hold_id'])


if __name__ == '__main__':
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    run(sys.argv[1], int(sys.argv[2]))
