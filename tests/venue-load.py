#!/usr/bin/env python3
"""HTTP acceptance harness. Fixture/credentials stay private; output contains aggregate evidence only."""
import argparse
import asyncio
import collections
import json
import math
import os
import platform
import secrets
import ssl
import time
from pathlib import Path
from urllib.parse import urlencode, urlsplit


def percentile(values, fraction=.95):
    if not values:
        return None
    values = sorted(values)
    return round(values[max(0, math.ceil(len(values) * fraction) - 1)], 2)


class Client:
    def __init__(self, base, timeout):
        self.url = urlsplit(base)
        self.timeout = timeout

    async def request(self, route, method="GET", body=None, headers=None, query=None):
        started = time.monotonic()
        writer = None
        try:
            use_plain = "rest_route=" in self.url.query
            path = self.url.path.rstrip("/")
            if use_plain:
                params = {"rest_route": "/pesm/v2/" + route.lstrip("/")}
                params.update(query or {})
                path += "?" + urlencode(params)
            else:
                path += "/" + route.lstrip("/")
                if query:
                    path += "?" + urlencode(query)
            port = self.url.port or (443 if self.url.scheme == "https" else 80)
            context = ssl.create_default_context() if self.url.scheme == "https" else None
            reader, writer = await asyncio.wait_for(asyncio.open_connection(self.url.hostname, port, ssl=context), self.timeout)
            data = b"" if body is None else json.dumps(body, separators=(",", ":")).encode()
            request_headers = {"Host": self.url.netloc, "Connection": "close", "Accept": "application/json", "Content-Length": str(len(data))}
            if body is not None:
                request_headers["Content-Type"] = "application/json"
            request_headers.update(headers or {})
            request = f"{method} {path or '/'} HTTP/1.1\r\n" + "".join(f"{k}: {v}\r\n" for k, v in request_headers.items()) + "\r\n"
            writer.write(request.encode() + data)
            await writer.drain()
            async with asyncio.timeout(self.timeout):
                status_line = await reader.readline()
                status = int(status_line.split(b" ", 2)[1])
                response_headers = {}
                for _ in range(100):
                    line = await reader.readline()
                    if line in (b"\r\n", b"\n", b""):
                        break
                    key, value = line.decode("latin1").split(":", 1)
                    response_headers[key.lower()] = value.strip()
                if "chunked" in response_headers.get("transfer-encoding", ""):
                    chunks = []
                    length = 0
                    while True:
                        size = int((await reader.readline()).split(b";", 1)[0], 16)
                        if not size:
                            await reader.readline()
                            break
                        length += size
                        if length > 16 * 1024 * 1024:
                            raise ValueError("response_too_large")
                        chunks.append(await reader.readexactly(size))
                        await reader.readexactly(2)
                    raw = b"".join(chunks)
                elif "content-length" in response_headers:
                    size = int(response_headers["content-length"])
                    if size > 16 * 1024 * 1024:
                        raise ValueError("response_too_large")
                    raw = await reader.readexactly(size)
                else:
                    chunks = []
                    length = 0
                    while True:
                        piece = await reader.read(65536)
                        if not piece:
                            break
                        length += len(piece)
                        if length > 16 * 1024 * 1024:
                            raise ValueError("response_too_large")
                        chunks.append(piece)
                    raw = b"".join(chunks)
            try:
                decoded = json.loads(raw)
                if not isinstance(decoded, dict):
                    decoded = {"code": "invalid_json_shape"}
            except (ValueError, UnicodeError):
                decoded = {"code": "non_json_response"}
            return {"status": status, "data": decoded, "bytes": len(raw), "ms": (time.monotonic() - started) * 1000}
        except Exception as error:
            return {"status": 0, "data": {"code": type(error).__name__}, "bytes": 0, "ms": (time.monotonic() - started) * 1000}
        finally:
            if writer:
                writer.close()
                try:
                    await writer.wait_closed()
                except Exception:
                    pass


async def burst(calls):
    ready = asyncio.Event()

    async def one(call):
        await ready.wait()
        return await call()

    tasks = [asyncio.create_task(one(call)) for call in calls]
    await asyncio.sleep(0)
    ready.set()
    return await asyncio.gather(*tasks)


def summary(results):
    return {"requests": len(results), "successful": sum(200 <= r["status"] < 300 for r in results),
            "status_counts": dict(collections.Counter(str(r["status"]) for r in results)),
            "error_codes": dict(collections.Counter(r["data"].get("code", "unknown") for r in results if not 200 <= r["status"] < 300)),
            "p50_ms": percentile([r["ms"] for r in results], .5), "p95_ms": percentile([r["ms"] for r in results]),
            "p99_ms": percentile([r["ms"] for r in results], .99), "max_response_bytes": max([r["bytes"] for r in results], default=0)}


async def run(args):
    fixture = json.loads(args.fixture.read_text())
    client = Client(args.base, args.timeout)
    session = fixture["session_id"]
    route = f"sessions/{session}"
    count = min(args.clients, len(fixture["seat_ids"]))
    overview = await client.request(route + "/layout")
    tile = await client.request(route + "/tiles/" + fixture["tile_codes"][0])
    inventory_list = await client.request(route + "/units", query={"limit": 100})
    keys = [secrets.token_hex(32) for _ in range(count)]
    seats = fixture["seat_ids"][:count]
    hold_calls = [lambda seat=seat, key=key: client.request(route + "/holds", "POST", {"units": [{"type": "seat", "id": seat}], "idempotency_key": key}) for seat, key in zip(seats, keys)]
    availability_calls = [lambda n=n: client.request(route + "/availability", query={"tiles": fixture["tile_codes"][n % len(fixture["tile_codes"])]}) for n in range(100)]
    print(f"Starting {count} simultaneous hold attempts and 100 scoped availability reads.", flush=True)
    mixed = await burst(hold_calls + availability_calls)
    holds, availability = mixed[:count], mixed[count:]
    success = [(i, r) for i, r in enumerate(holds) if r["status"] == 201]
    replays = await burst([lambda i=i: client.request(route + "/holds", "POST", {"units": [{"type": "seat", "id": seats[i]}], "idempotency_key": keys[i]}) for i, _ in success[:10]])
    print(f"Starting {len(success)} simultaneous dedicated checkout attempts.", flush=True)
    checkout = await burst([lambda i=i, result=result: client.request(route + "/checkout", "POST",
        {"first_name": "Load", "last_name": f"Guest{i}", "phone": "0912" + str(i).zfill(7), "email": f"venue-load-{fixture['run_id']}-{i}@example.invalid"},
        {"X-PESM-Hold-Token": result["data"]["hold_token"]}) for i, result in success])
    private_purchases = []
    for (i, hold), purchase in zip(success, checkout):
        if 200 <= purchase["status"] < 300:
            private_purchases.append({"seat_id": seats[i], "hold_token": hold["data"]["hold_token"], **purchase["data"]})
    contention = await burst([lambda: client.request(route + "/holds", "POST", {"units": [{"type": "seat", "id": fixture["contention_seat_id"]}], "idempotency_key": secrets.token_hex(32)}) for _ in range(50)])
    winners = [r for r in contention if r["status"] == 201]
    for winner in winners:
        await client.request(route + "/holds", "DELETE", {"idempotency_key": secrets.token_hex(32)}, {"X-PESM-Hold-Token": winner["data"]["hold_token"]})
    group = fixture["group"]
    group_race = await burst([lambda n=n: client.request(route + "/holds", "POST", {"units": [{"type": "group", "id": group["group_code"]}] if n % 2 else [{"type": "seat", "id": group["seat_ids"][0]}], "idempotency_key": secrets.token_hex(32)}) for n in range(40)])
    group_winners = [r for r in group_race if r["status"] == 201]
    for winner in group_winners:
        await client.request(route + "/holds", "DELETE", {"idempotency_key": secrets.token_hex(32)}, {"X-PESM-Hold-Token": winner["data"]["hold_token"]})
    ticket_results = await burst([lambda purchase=purchase: client.request("tickets", headers={"X-PESM-Guest-Token": purchase["guest_access_token"]}, query={"order_id": purchase["order_id"]}) for purchase in private_purchases[:20]])
    scans = []
    for result, operator in zip(ticket_results, fixture["operators"]):
        if result["status"] != 200:
            continue
        credential = next((t["qr_payload"] for line in result["data"].get("lines", []) for t in line.get("tickets", []) if t.get("qr_payload")), None)
        if credential:
            scans.append((credential, operator, secrets.token_hex(32)))
    print(f"Starting {len(scans)} authenticated gate operators behind the same source IP.", flush=True)
    gates = await burst([lambda qr=qr, op=op, key=key: client.request("gate/check-in", "POST", {"qr_payload": qr, "gate": "Load gate", "request_key": key, "quantity": 1}, {"Cookie": op["cookie"], "X-WP-Nonce": op["nonce"]}) for qr, op, key in scans])
    gate_replay = None
    gate_duplicate = None
    if scans:
        qr, op, key = scans[0]
        headers = {"Cookie": op["cookie"], "X-WP-Nonce": op["nonce"]}
        gate_replay = await client.request("gate/check-in", "POST", {"qr_payload": qr, "gate": "Load gate", "request_key": key, "quantity": 1}, headers)
        gate_duplicate = await client.request("gate/check-in", "POST", {"qr_payload": qr, "gate": "Load gate", "request_key": secrets.token_hex(32), "quantity": 1}, headers)
    fixture["purchases"] = private_purchases
    args.fixture.write_text(json.dumps(fixture, indent=2))
    os.chmod(args.fixture, 0o600)
    report = {"passed": False, "schema": 19, "capacity": fixture["capacity"], "concurrent_purchase_attempts": count,
        "gate_operators": len(scans), "hold_p95_ms": percentile([r["ms"] for r in holds]),
        "availability_p95_ms": percentile([r["ms"] for r in availability]), "gate_p95_ms": percentile([r["ms"] for r in gates]),
        "environment": fixture["environment"] | {"client": platform.platform(), "url_profile": args.base.split("?")[0]},
        "overview": {"status": overview["status"], "bytes": overview["bytes"], "contains_seat_array": "seats" in overview["data"]},
        "tile": {"status": tile["status"], "seats": len(tile["data"].get("seats", [])), "bytes": tile["bytes"]},
        "list": {"status": inventory_list["status"], "items": len(inventory_list["data"].get("items", []))},
        "holds": summary(holds), "checkout": summary(checkout), "availability": summary(availability), "gates": summary(gates),
        "hold_replay_successes": sum(r["status"] == 201 and r["data"].get("idempotent_replay") is True for r in replays),
        "contention": summary(contention) | {"winners": len(winners)}, "group_member_contention": summary(group_race) | {"winners": len(group_winners)},
        "gate_replay_status": gate_replay["status"] if gate_replay else None,
        "gate_duplicate_code": gate_duplicate["data"].get("code") if gate_duplicate else None,
        "issued_order_count": len(private_purchases), "integration_passed": False,
        "note": "Run the guarded SQL verification, merge its results, and assess latency targets before recording acceptance."}
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(report, indent=2))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--fixture", type=Path, required=True)
    parser.add_argument("--base", required=True, help="REST base, including ?rest_route= for plain permalinks")
    parser.add_argument("--clients", type=int, default=1000)
    parser.add_argument("--timeout", type=float, default=180)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    if args.clients < 1 or args.clients > 1000:
        parser.error("clients must be 1..1000")
    try:
        import resource
        soft, hard = resource.getrlimit(resource.RLIMIT_NOFILE)
        resource.setrlimit(resource.RLIMIT_NOFILE, (min(max(soft, 4096), hard), hard))
    except (ImportError, OSError, ValueError):
        pass
    asyncio.run(run(args))


if __name__ == "__main__":
    main()
