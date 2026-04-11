"""
DB Pruner
=========
Standalone script that trims the flight-analysis database when it exceeds 10 GB.
Deletes only the oldest records and only enough to get barely back under the limit,
then VACUUMs once to reclaim disk space.

Designed to run daily via cron:
    0 3 * * * cd /path/to/flight-analysis && /path/to/venv/bin/python pruner.py >> /tmp/pruner.log 2>&1
"""

import os
import sys
import fcntl
import sqlite3
from datetime import datetime, timezone
from pathlib import Path

DB_PATH   = Path(__file__).parent / "data" / "monitor.db"
LOCK_PATH = Path("/tmp/flight_pruner.lock")
SIZE_LIMIT = 10 * 1024 ** 3   # 10 GB in bytes

# Tables to prune in priority order (largest first).
# Each entry: (table_name, time_column)
PRUNE_TABLES = [
    ("aircraft_positions", "snapshot_time"),
    ("ew_contacts",        "snapshot_time"),
    ("snapshot_qc",        "snapshot_time"),
    ("alerts",             "timestamp"),
]


# ── Helpers ───────────────────────────────────────────────────────────────────

def _ts():
    return "[" + datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S") + "]"


def _log(msg):
    print(f"{_ts()} {msg}", flush=True)


def _db_data_size(conn):
    """Return actual data size in bytes using SQLite pragmas (not file size)."""
    page_size  = conn.execute("PRAGMA page_size").fetchone()[0]
    page_count = conn.execute("PRAGMA page_count").fetchone()[0]
    free_pages = conn.execute("PRAGMA freelist_count").fetchone()[0]
    return (page_count - free_pages) * page_size


def _db_file_size():
    return os.path.getsize(DB_PATH)


def _vacuum():
    """Run VACUUM on a fresh autocommit connection to reclaim disk space."""
    _log("Running VACUUM...")
    raw = sqlite3.connect(str(DB_PATH))
    raw.isolation_level = None
    raw.execute("VACUUM")
    raw.close()
    _log(f"VACUUM complete. File size: {_db_file_size() / (1024**3):.2f} GB")


# ── Core pruning logic ────────────────────────────────────────────────────────

def prune():
    if not DB_PATH.exists():
        _log(f"Database not found at {DB_PATH} — nothing to prune.")
        return

    file_size = _db_file_size()
    file_gb   = file_size / (1024 ** 3)

    if file_size < SIZE_LIMIT:
        _log(f"DB file at {file_gb:.2f} GB — under {SIZE_LIMIT / (1024**3):.0f} GB limit, no pruning needed.")
        return

    _log(f"DB file at {file_gb:.2f} GB — pruning needed.")

    # Phase 1: calculate and delete rows
    total_deleted = 0
    conn = sqlite3.connect(str(DB_PATH), check_same_thread=False)
    conn.execute("PRAGMA journal_mode=WAL")

    try:
        data_size = _db_data_size(conn)
        excess    = data_size - SIZE_LIMIT

        if excess <= 0:
            _log(f"Data size ({data_size / (1024**3):.2f} GB) is under limit — just needs VACUUM.")
        else:
            _log(f"Need to free ~{excess / (1024**2):.0f} MB of data.")

            # Count all rows once upfront
            row_counts = {}
            for t, _ in PRUNE_TABLES:
                row_counts[t] = conn.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0]
            total_rows = sum(row_counts.values())

            if total_rows == 0:
                _log("All tables are empty — nothing to prune.")
            else:
                for table, time_col in PRUNE_TABLES:
                    if excess <= 0:
                        break

                    row_count = row_counts[table]
                    if row_count == 0:
                        continue

                    est_bytes_per_row = data_size / total_rows

                    rows_to_delete = int((excess * 1.05) / est_bytes_per_row)
                    rows_to_delete = min(rows_to_delete, row_count - 1)

                    if rows_to_delete <= 0:
                        continue

                    cutoff_row = conn.execute(
                        f"SELECT {time_col} FROM {table} ORDER BY {time_col} ASC LIMIT 1 OFFSET ?",
                        (rows_to_delete,),
                    ).fetchone()

                    if cutoff_row is None:
                        continue

                    cutoff_time = cutoff_row[0]

                    cur = conn.execute(
                        f"DELETE FROM {table} WHERE {time_col} < ?",
                        (cutoff_time,),
                    )
                    deleted = cur.rowcount
                    conn.commit()

                    freed_est = deleted * est_bytes_per_row
                    excess   -= freed_est
                    total_deleted += deleted

                    _log(
                        f"  {table}: deleted {deleted:,} rows older than {cutoff_time} "
                        f"(~{freed_est / (1024**2):.0f} MB estimated)"
                    )
    finally:
        conn.close()

    # Phase 2: VACUUM on a separate connection (must be outside transaction)
    _vacuum()

    final_size = _db_file_size()
    _log(
        f"Done. Deleted {total_deleted:,} rows total. "
        f"DB file: {file_gb:.2f} GB → {final_size / (1024**3):.2f} GB"
    )


# ── Entry point ───────────────────────────────────────────────────────────────

def main():
    script_dir = os.path.dirname(os.path.abspath(__file__))
    os.chdir(script_dir)

    lock_file = open(LOCK_PATH, "w")
    try:
        fcntl.flock(lock_file, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        _log("Another pruner is already running — exiting.")
        lock_file.close()
        sys.exit(0)

    try:
        prune()
    except Exception as e:
        _log(f"[ERROR] Pruner failed: {e}")
        sys.exit(1)
    finally:
        fcntl.flock(lock_file, fcntl.LOCK_UN)
        lock_file.close()


if __name__ == "__main__":
    main()
