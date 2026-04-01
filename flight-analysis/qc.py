"""
Snapshot Quality Control (QC)
==============================
Evaluates each fetched snapshot for data integrity issues:
  - Low aircraft count (feed may be down or filtered)
  - High dropout vs prior snapshot (sudden data loss)
  - Low positional coverage (too many aircraft missing lat/lon)
  - Low type coverage (too many unidentified aircraft types)

Writes results to the snapshot_qc table for dashboard display.
"""

from datetime import datetime, timezone

# ── QC thresholds ─────────────────────────────────────────────────────────────

QC_MIN_AIRCRAFT     = 50    # Minimum aircraft to consider the feed healthy
QC_MAX_DROPOUT_PCT  = 0.40  # Max tolerable count drop vs previous snapshot
QC_MIN_COVERAGE_PCT = 0.55  # Minimum fraction of aircraft with lat/lon
QC_MIN_TYPE_PCT     = 0.50  # Minimum fraction of aircraft with a known type


# ── Table initialisation ──────────────────────────────────────────────────────

def init_qc_table(conn):
    """Create the snapshot_qc table if it doesn't exist."""
    conn.executescript("""
        CREATE TABLE IF NOT EXISTS snapshot_qc (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            snapshot_time       TEXT    NOT NULL UNIQUE,
            aircraft_count      INTEGER NOT NULL,
            aircraft_with_pos   INTEGER NOT NULL,
            aircraft_with_type  INTEGER NOT NULL,
            aircraft_with_flight INTEGER NOT NULL,
            ghost_count         INTEGER NOT NULL,
            coverage_pct        REAL    NOT NULL,
            type_coverage_pct   REAL    NOT NULL,
            dropout_vs_prev     REAL,
            quality_flag        TEXT    NOT NULL,
            fail_reasons        TEXT    NOT NULL
        );

        CREATE INDEX IF NOT EXISTS idx_qc_time ON snapshot_qc(snapshot_time);
    """)
    conn.commit()


# ── Core computation ──────────────────────────────────────────────────────────

def compute_snapshot_qc(snapshot: list, prev_count) -> dict:
    """
    Evaluate a snapshot for data quality issues.

    snapshot   : list of aircraft dicts from api.adsb.lol
    prev_count : aircraft_count from the last recorded snapshot, or None

    Returns a dict ready for insert_snapshot_qc().
    """
    now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S")
    aircraft_count = len(snapshot)

    # ── Per-field counts ──────────────────────────────────────────────────────
    aircraft_with_pos    = sum(1 for a in snapshot if a.get("lat") is not None and a.get("lon") is not None)
    aircraft_with_type   = sum(1 for a in snapshot if a.get("t") not in (None, ""))
    aircraft_with_flight = sum(1 for a in snapshot if a.get("flight") not in (None, ""))

    # Ghost: no flight callsign, no registration, no type
    ghost_count = sum(
        1 for a in snapshot
        if not a.get("flight") and not a.get("r") and not a.get("t")
    )

    # ── Derived metrics ───────────────────────────────────────────────────────
    coverage_pct      = aircraft_with_pos / aircraft_count if aircraft_count else 0.0
    type_coverage_pct = aircraft_with_type / aircraft_count if aircraft_count else 0.0

    dropout_vs_prev = None
    if prev_count:
        dropout_vs_prev = (prev_count - aircraft_count) / prev_count

    # ── Failure reasons ───────────────────────────────────────────────────────
    fail_reasons_list = []

    if aircraft_count < QC_MIN_AIRCRAFT:
        fail_reasons_list.append("LOW_COUNT")

    if dropout_vs_prev is not None and dropout_vs_prev > QC_MAX_DROPOUT_PCT:
        fail_reasons_list.append("HIGH_DROPOUT")

    if coverage_pct < QC_MIN_COVERAGE_PCT:
        fail_reasons_list.append("LOW_COVERAGE")

    if type_coverage_pct < QC_MIN_TYPE_PCT:
        fail_reasons_list.append("LOW_TYPE_COVERAGE")

    fail_reasons = ",".join(fail_reasons_list)

    # ── Quality flag ─────────────────────────────────────────────────────────
    if aircraft_count == 0:
        quality_flag = "FAILED"
    elif len(fail_reasons_list) >= 2:
        quality_flag = "POOR"
    elif len(fail_reasons_list) == 1:
        quality_flag = "DEGRADED"
    else:
        quality_flag = "OK"

    return {
        "snapshot_time":        now,
        "aircraft_count":       aircraft_count,
        "aircraft_with_pos":    aircraft_with_pos,
        "aircraft_with_type":   aircraft_with_type,
        "aircraft_with_flight": aircraft_with_flight,
        "ghost_count":          ghost_count,
        "coverage_pct":         round(coverage_pct, 4),
        "type_coverage_pct":    round(type_coverage_pct, 4),
        "dropout_vs_prev":      round(dropout_vs_prev, 4) if dropout_vs_prev is not None else None,
        "quality_flag":         quality_flag,
        "fail_reasons":         fail_reasons,
    }


# ── DB write ──────────────────────────────────────────────────────────────────

def insert_snapshot_qc(conn, qc_record: dict) -> None:
    """INSERT OR REPLACE a QC record (keyed on snapshot_time)."""
    conn.execute(
        """INSERT OR REPLACE INTO snapshot_qc
           (snapshot_time, aircraft_count, aircraft_with_pos, aircraft_with_type,
            aircraft_with_flight, ghost_count, coverage_pct, type_coverage_pct,
            dropout_vs_prev, quality_flag, fail_reasons)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        (
            qc_record["snapshot_time"],
            qc_record["aircraft_count"],
            qc_record["aircraft_with_pos"],
            qc_record["aircraft_with_type"],
            qc_record["aircraft_with_flight"],
            qc_record["ghost_count"],
            qc_record["coverage_pct"],
            qc_record["type_coverage_pct"],
            qc_record["dropout_vs_prev"],
            qc_record["quality_flag"],
            qc_record["fail_reasons"],
        ),
    )
    conn.commit()


# ── DB reads ──────────────────────────────────────────────────────────────────

def get_recent_qc(conn, hours: int = 24) -> list:
    """Return snapshot_qc rows from the last N hours as list of dicts."""
    cur = conn.execute(
        """SELECT * FROM snapshot_qc
           WHERE snapshot_time >= datetime('now', ? || ' hours')
           ORDER BY snapshot_time DESC""",
        (f"-{hours}",),
    )
    cols = [d[0] for d in cur.description]
    return [dict(zip(cols, row)) for row in cur.fetchall()]


def get_qc_summary(conn) -> dict:
    """
    Returns a summary dict with:
      latest_flag     — quality_flag of the most recent snapshot
      pct_ok_24h      — fraction of snapshots flagged OK in the last 24 h (0–1)
      avg_coverage_24h — mean coverage_pct over last 24 h
      avg_dropout_24h  — mean abs(dropout_vs_prev) over last 24 h (non-null rows)
    """
    cur = conn.execute(
        "SELECT quality_flag FROM snapshot_qc ORDER BY snapshot_time DESC LIMIT 1"
    )
    row = cur.fetchone()
    latest_flag = row[0] if row else None

    cur = conn.execute(
        """SELECT
               COUNT(*)                                             AS total,
               SUM(CASE WHEN quality_flag = 'OK' THEN 1 ELSE 0 END) AS ok_count,
               AVG(coverage_pct)                                   AS avg_cov,
               AVG(ABS(dropout_vs_prev))                           AS avg_drop
           FROM snapshot_qc
           WHERE snapshot_time >= datetime('now', '-24 hours')"""
    )
    row = cur.fetchone()
    total, ok_count, avg_cov, avg_drop = row if row else (0, 0, None, None)

    pct_ok_24h      = (ok_count / total) if total else None
    avg_coverage_24h = round(avg_cov, 4)  if avg_cov  is not None else None
    avg_dropout_24h  = round(avg_drop, 4) if avg_drop is not None else None

    return {
        "latest_flag":      latest_flag,
        "pct_ok_24h":       round(pct_ok_24h, 4) if pct_ok_24h is not None else None,
        "avg_coverage_24h": avg_coverage_24h,
        "avg_dropout_24h":  avg_dropout_24h,
    }
