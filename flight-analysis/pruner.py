"""
DB Pruner
=========
Standalone script that trims aircraft_positions when the database exceeds 10 GB.
Designed to run daily via cron:
    0 3 * * * cd /path/to/flight-analysis && /path/to/venv/bin/python pruner.py >> /tmp/pruner.log 2>&1
"""

import os
import sys
import sqlite3
from datetime import datetime, timezone
from pathlib import Path

DB_PATH   = Path(__file__).parent / "data" / "monitor.db"
LOCK_PATH = Path("/tmp/flight_pruner.lock")
SIZE_LIMIT = 10 * 1024 ** 3   # 10 GB in bytes


# ── Helpers ───────────────────────────────────────────────────────────────────

def _ts():
    """Current UTC timestamp string in [YYYY-MM-DDTHH:MM:SS] format."""
    return "[" + datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S") + "]"


def _log(msg):
    print(f"{_ts()} {msg}", flush=True)


def _db_size_gb():
    return os.path.getsize(DB_PATH) / (1024 ** 3)


def _db_size_bytes():
    return os.path.getsize(DB_PATH)


# ── Lock management ───────────────────────────────────────────────────────────

def _acquire_lock():
    """
    Returns True if we successfully acquired the lock, False if another
    recent process is running.  Stale locks (> 1 hour old) are deleted.
    """
    if LOCK_PATH.exists():
        age_seconds = datetime.now(timezone.utc).timestamp() - LOCK_PATH.stat().st_mtime
        if age_seconds < 3600:
            _log(f"Lock file exists and is {age_seconds:.0f}s old — another process may be running. Exiting.")
            return False
        _log(f"Stale lock file found ({age_seconds:.0f}s old) — removing and proceeding.")
        LOCK_PATH.unlink()

    LOCK_PATH.write_text(str(os.getpid()))
    return True


def _release_lock():
    if LOCK_PATH.exists():
        try:
            LOCK_PATH.unlink()
        except OSError:
            pass


# ── Core pruning logic ────────────────────────────────────────────────────────

def prune():
    if not DB_PATH.exists():
        _log(f"Database not found at {DB_PATH} — nothing to prune.")
        return

    size_bytes = _db_size_bytes()
    size_gb    = size_bytes / (1024 ** 3)

    if size_bytes < SIZE_LIMIT:
        _log(f"DB at {size_gb:.2f}G, no pruning needed.")
        return

    _log(f"DB at {size_gb:.2f}G, pruning needed.")

    conn = sqlite3.connect(str(DB_PATH), check_same_thread=False)
    conn.execute("PRAGMA journal_mode=WAL")

    try:
        # Determine the overall oldest snapshot_time before we start
        cur = conn.execute(
            "SELECT MIN(snapshot_time) FROM aircraft_positions"
        )
        row = cur.fetchone()
        oldest_ever = row[0] if row and row[0] else None

        if oldest_ever is None:
            _log("No rows in aircraft_positions — nothing to prune.")
            return

        size_before   = _db_size_bytes()
        cutoff_time   = None   # last snapshot_time we deleted

        while _db_size_bytes() >= SIZE_LIMIT:
            # Find the current oldest snapshot_time
            cur = conn.execute(
                "SELECT MIN(snapshot_time) FROM aircraft_positions"
            )
            row = cur.fetchone()
            oldest_snap = row[0] if row and row[0] else None

            if oldest_snap is None:
                _log("Ran out of rows to delete — DB still over limit.")
                break

            # Delete every record for that exact snapshot_time
            conn.execute(
                "DELETE FROM aircraft_positions WHERE snapshot_time = ?",
                (oldest_snap,),
            )
            conn.commit()
            cutoff_time = oldest_snap

        # VACUUM to reclaim disk space
        _log("Running VACUUM...")
        conn.execute("VACUUM")
        conn.commit()

        size_after  = _db_size_bytes()
        recovered_mb = (size_before - size_after) / (1024 ** 2)
        size_after_gb = size_after / (1024 ** 3)

        _log(
            f"Pruned positions from {oldest_ever} to {cutoff_time} — "
            f"recovered {recovered_mb:.2f}MB — DB now at {size_after_gb:.2f}G"
        )

    finally:
        conn.close()


# ── Entry point ───────────────────────────────────────────────────────────────

def main():
    # Support running from any working directory
    script_dir = os.path.dirname(os.path.abspath(__file__))
    os.chdir(script_dir)

    if not _acquire_lock():
        sys.exit(0)

    try:
        prune()
    except Exception as e:
        _log(f"[ERROR] Pruner failed: {e}")
        sys.exit(1)
    finally:
        _release_lock()


if __name__ == "__main__":
    main()
