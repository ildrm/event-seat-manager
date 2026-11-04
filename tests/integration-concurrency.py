#!/usr/bin/env python3
"""Run independent PHP workers against a disposable local WP database.

Usage: python3 tests/integration-concurrency.py /absolute/wordpress/path
Requires active WooCommerce, Event Seat Manager and WP_ENVIRONMENT_TYPE=local.
Creates fixture data; never run against a live site.
"""

import concurrent.futures
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile


wordpress = Path(sys.argv[1]).resolve()
integration = Path(__file__).resolve().with_name("integration-wp.php")
workers = int(sys.argv[2]) if len(sys.argv) > 2 else 20
if not 2 <= workers <= 40:
    raise SystemExit("Choose between 2 and 40 workers.")

env = dict(os.environ, PESM_RUN_INTEGRATION="1")
with tempfile.TemporaryDirectory(prefix="esm-concurrency-") as temp:
    driver = Path(temp) / "run.php"
    driver.write_text(
        "<?php\nrequire " + json.dumps(str(wordpress / "wp-load.php")) + ";\n"
        "$args = array_slice($argv, 1);\nrequire " + json.dumps(str(integration)) + ";\n"
    )

    def run(mode):
        result = subprocess.run(["php", str(driver), mode], env=env, capture_output=True, text=True, timeout=120)
        if result.returncode:
            raise RuntimeError(mode + ": " + result.stdout + result.stderr)
        try:
            return json.loads(result.stdout)
        except json.JSONDecodeError as error:
            raise RuntimeError(mode + " returned invalid JSON: " + result.stdout + result.stderr) from error

    seed = run("all")
    summaries = {}
    for mode, expected in [("worker-seat", 1), ("worker-capacity", 3), ("worker-gate", 1)]:
        with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as pool:
            results = list(pool.map(run, [mode] * workers))
        accepted = sum(result["ok"] for result in results)
        errors = {}
        for result in results:
            if not result["ok"]:
                code = result["error"]
                errors[code] = errors.get(code, 0) + 1
        if accepted != expected:
            raise RuntimeError(mode + ": expected " + str(expected) + " winners, received " + str(results))
        summaries[mode] = {"processes": workers, "accepted": accepted, "rejected": workers - accepted, "errors": errors}
    verified = run("verify-races")
    run("prepare-upgrade-race")
    with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as pool:
        upgrades = list(pool.map(run, ["worker-upgrade"] * workers))
    if not all(result["ok"] for result in upgrades):
        raise RuntimeError("Concurrent migration did not succeed for all workers: " + str(upgrades))
    migration = run("verify-upgrade")
    summaries["worker-upgrade"] = {"processes": workers, "accepted": len(upgrades), "rejected": 0}
    print(json.dumps({"sequential": seed, "concurrency": summaries, "verification": verified, "migration": migration}, indent=2))
