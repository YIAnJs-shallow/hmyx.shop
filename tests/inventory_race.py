"""Two independent PHP/database connections contend for the final stock unit."""
import concurrent.futures
import json
import os
from pathlib import Path
import subprocess
import sys
import time

php = sys.argv[1]
root = Path(__file__).resolve().parent.parent
state = json.loads((root / 'tests/state.json').read_text())
env = dict(os.environ, DB_NAME=state['database'])

def run(*args):
    return subprocess.check_output([php, 'tests/race_worker.php', *map(str, args)], cwd=root, env=env).decode('utf-8').strip()

fixture = json.loads(run('setup'))
start = time.time() + 0.5
with concurrent.futures.ThreadPoolExecutor(2) as pool:
    jobs = [pool.submit(run, 'buy', user, fixture['product'], address, start)
            for user, address in zip([state['buyer'], state['other']], fixture['addresses'])]
    results = [job.result() for job in jobs]
assert sorted(results) == ['sold-out', 'success'], results
print('PASS: competing buyers produce exactly one successful checkout')
print('PASS:', run('check', fixture['product']))
