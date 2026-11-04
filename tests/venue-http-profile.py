#!/usr/bin/env python3
"""Sequential HTTP baseline, separate from the barrier-start load benchmark."""
import argparse
import asyncio
import json
import runpy
import secrets
from pathlib import Path


async def profile(args):
    helpers = runpy.run_path(str(Path(__file__).with_name("venue-load.py")))
    client = helpers["Client"](args.base, 30)
    fixture = json.loads(args.fixture.read_text())
    session = fixture["session_id"]
    route = f"sessions/{session}"
    seat = fixture["contention_seat_id"]
    results = {}
    for kind in ("wordpress_preset_metadata", "availability_2000", "quote", "hold"):
        samples = []
        for _ in range(args.samples):
            if kind == "wordpress_preset_metadata":
                result = await client.request("presets")
            elif kind == "availability_2000":
                result = await client.request(route + "/availability", query={"tiles": fixture["tile_codes"][0]})
            elif kind == "quote":
                result = await client.request(route + "/quote", "POST", {"units": [{"type": "seat", "id": seat}]})
            else:
                result = await client.request(route + "/holds", "POST", {"units": [{"type": "seat", "id": seat}], "idempotency_key": secrets.token_hex(32)})
                if result["status"] == 201:
                    await client.request(route + "/holds", "DELETE", {"idempotency_key": secrets.token_hex(32)}, {"X-PESM-Hold-Token": result["data"]["hold_token"]})
            samples.append(result)
        results[kind] = helpers["summary"](samples)
    report = {"scope": "Sequential warm HTTP requests, excluding deliberate load bursts; local WordPress/WooCommerce bootstrap included.",
              "environment": {"replicas": 2, "php_workers_per_replica": 4, "proxy_upstream_slots": 8, "opcache_mib_per_replica": 128},
              "profiles": results}
    args.output.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--fixture", type=Path, required=True)
    parser.add_argument("--base", required=True)
    parser.add_argument("--samples", type=int, default=20)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    if args.samples < 1 or args.samples > 100:
        parser.error("samples must be 1..100")
    asyncio.run(profile(args))
