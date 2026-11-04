#!/usr/bin/env python3
"""Integration check: requires a running WordPress installation with an OPEN session.

Usage:
  python tests/concurrency.py https://example.com/wp-json/pesm/v1 123 456
where 123=session_id and 456=seat_id in that session.

Network-accessing test. Never run against a live sale without explicit permission.
"""
import concurrent.futures
import json
import secrets
import sys
import urllib.error
import urllib.request


def call(base, path, method='GET', payload=None, headers=None):
    data = None if payload is None else json.dumps(payload).encode()
    req = urllib.request.Request(base.rstrip('/') + path, data=data, method=method,
                                 headers={'Content-Type': 'application/json', **(headers or {})})
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            return r.status, json.load(r)
    except urllib.error.HTTPError as err:
        return err.code, json.load(err)


def run(base, session, seat):
    def attempt(index):
        return call(base, '/holds', 'POST', {
            'session_id': session, 'seat_ids': [seat],
            'idempotency_key': secrets.token_hex(32)
        })

    with concurrent.futures.ThreadPoolExecutor(max_workers=20) as executor:
        outcomes = list(executor.map(attempt, range(20)))
    winning = [body for code, body in outcomes if code == 200]
    failures = [code for code, _ in outcomes if code != 200]
    print('attempts=20; successful_holds=', len(winning), '; failures=', failures)
    assert len(winning) == 1, 'Safety failure: expected exactly one successful hold'
    assert all(code == 409 for code in failures), 'Unexpected conflicting request status'
    status, released = call(base, '/holds', 'DELETE', {'session_id': session},
                            {'X-PESM-Hold-Token': winning[0]['token']})
    assert status == 200 and released.get('released'), 'Could not release winning hold'
    print('PASS concurrent hold conflict and release')


if __name__ == '__main__':
    if len(sys.argv) != 4:
        sys.exit(__doc__)
    run(sys.argv[1], int(sys.argv[2]), int(sys.argv[3]))
