"""
Military Flight Activity Monitor — Live Dashboard
Reads from data/monitor.db (SQLite). Refreshes every 60s.
Run from project root: streamlit run dashboard/app.py
"""

import sqlite3
import pandas as pd
import plotly.graph_objects as go
import streamlit as st
from pathlib import Path

DB_PATH = Path(__file__).parent.parent / "data" / "monitor.db"

SEVERITY_COLOR = {
    "CRITICAL": "#FF4136",
    "HIGH":     "#FF851B",
    "MEDIUM":   "#FFDC00",
    "LOW":      "#7FDBFF",
}
SEVERITY_EMOJI = {
    "CRITICAL": "🔴",
    "HIGH":     "🟠",
    "MEDIUM":   "🟡",
    "LOW":      "🔵",
}
ALERT_LINE_COLOR = {
    "PROBABLE_REFUELING_OP":    "#FF4136",
    "SENSITIVE_ASSET_ACTIVITY": "#B10DC9",
    "HOLDING_ORBIT":            "#FF851B",
    "ATTACK_PACKAGE":           "#FF4136",
    "FORMATION_ACTIVITY":       "#0074D9",
    "UNIDENTIFIED_CONTACTS":    "#B10DC9",
    "EW_ASSET_ACTIVITY":        "#00FF41",
}

st.set_page_config(
    page_title="Military Flight Monitor",
    page_icon="🛡️",
    layout="wide",
    initial_sidebar_state="expanded",
)

# ── DB Helpers ────────────────────────────────────────────────────────────────

def _conn():
    return sqlite3.connect(str(DB_PATH), check_same_thread=False)


@st.cache_data(ttl=60)
def load_positions(minutes: int) -> pd.DataFrame:
    with _conn() as conn:
        return pd.read_sql(
            """SELECT * FROM aircraft_positions
               WHERE snapshot_time >= datetime('now', ? || ' minutes')
               ORDER BY hex, snapshot_time""",
            conn, params=(f"-{minutes}",),
        )


@st.cache_data(ttl=60)
def load_qc_data():
    """
    Returns (qc_summary dict, qc_df DataFrame of last 24 h).
    Both may be empty/None if the snapshot_qc table doesn't exist yet.
    """
    with _conn() as conn:
        try:
            summary = {}
            cur = conn.cursor()

            cur.execute(
                "SELECT quality_flag FROM snapshot_qc ORDER BY snapshot_time DESC LIMIT 1"
            )
            row = cur.fetchone()
            summary["latest_flag"] = row[0] if row else None

            cur.execute(
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

            summary["pct_ok_24h"]       = (ok_count / total) if total else None
            summary["avg_coverage_24h"] = avg_cov
            summary["avg_dropout_24h"]  = avg_drop

            qc_df = pd.read_sql(
                """SELECT snapshot_time, aircraft_count, coverage_pct,
                          type_coverage_pct, dropout_vs_prev, quality_flag, fail_reasons
                   FROM snapshot_qc
                   WHERE snapshot_time >= datetime('now', '-24 hours')
                   ORDER BY snapshot_time ASC""",
                conn,
            )
            return summary, qc_df

        except Exception:
            # Table may not exist yet (first run before monitor.py)
            return {}, pd.DataFrame()


@st.cache_data(ttl=60)
def load_ew_contacts(hours: int = 24) -> pd.DataFrame:
    """Return EW contacts from the last N hours. Returns empty DataFrame on error."""
    with _conn() as conn:
        try:
            return pd.read_sql(
                """SELECT * FROM ew_contacts
                   WHERE snapshot_time >= datetime('now', '-' || ? || ' hours')
                   ORDER BY snapshot_time DESC""",
                conn, params=(hours,),
            )
        except Exception:
            # Table may not exist yet (first run before monitor.py has executed)
            return pd.DataFrame()


@st.cache_data(ttl=60)
def load_alt_profile(hex_list: tuple, minutes: int) -> pd.DataFrame:
    """Load position history for specific hexes for altitude charting."""
    with _conn() as conn:
        placeholders = ','.join('?' * len(hex_list))
        return pd.read_sql(
            f"""SELECT hex, flight, snapshot_time, alt_baro, gs, lat, lon
                FROM aircraft_positions
                WHERE hex IN ({placeholders})
                AND snapshot_time >= datetime('now', '-' || ? || ' minutes')
                ORDER BY hex, snapshot_time""",
            conn, params=list(hex_list) + [minutes]
        )


@st.cache_data(ttl=60)
def load_ew_for_hexes(hex_list: tuple) -> pd.DataFrame:
    """Load EW contact details for a specific set of hexes."""
    with _conn() as conn:
        try:
            placeholders = ','.join('?' * len(hex_list))
            return pd.read_sql(
                f"""SELECT * FROM ew_contacts
                    WHERE hex IN ({placeholders})
                    ORDER BY snapshot_time DESC""",
                conn, params=list(hex_list)
            )
        except Exception:
            return pd.DataFrame()


@st.cache_data(ttl=60)
def load_alerts(limit: int = 100) -> pd.DataFrame:
    with _conn() as conn:
        return pd.read_sql(
            "SELECT * FROM alerts ORDER BY timestamp DESC LIMIT ?",
            conn, params=(limit,),
        )


@st.cache_data(ttl=60)
def load_kpis():
    with _conn() as c:
        cur = c.cursor()

        cur.execute("SELECT MAX(snapshot_time) FROM aircraft_positions")
        last_update = cur.fetchone()[0] or "—"

        cur.execute(
            "SELECT COUNT(DISTINCT hex) FROM aircraft_positions "
            "WHERE snapshot_time >= datetime('now', '-24 hours')"
        )
        ac_24h = cur.fetchone()[0]

        cur.execute(
            "SELECT COUNT(*) FROM aircraft_positions "
            "WHERE snapshot_time = (SELECT MAX(snapshot_time) FROM aircraft_positions)"
        )
        ac_now = cur.fetchone()[0]

        cur.execute(
            "SELECT COUNT(*) FROM alerts WHERE timestamp >= datetime('now', '-24 hours')"
        )
        alerts_24h = cur.fetchone()[0]

        cur.execute(
            "SELECT COUNT(DISTINCT hex) FROM aircraft_positions "
            "WHERE region = 'NTTR' AND snapshot_time >= datetime('now', '-2 hours')"
        )
        nttr = cur.fetchone()[0]

        cur.execute(
            "SELECT COUNT(DISTINCT hex) FROM aircraft_positions "
            "WHERE region = 'UTTR' AND snapshot_time >= datetime('now', '-2 hours')"
        )
        uttr = cur.fetchone()[0]

    return last_update, ac_24h, ac_now, alerts_24h, nttr, uttr


# ── Guard: DB must exist ──────────────────────────────────────────────────────

if not DB_PATH.exists():
    st.error(f"Database not found at `{DB_PATH}`. Run `python monitor.py` first.")
    st.stop()

# ── Sidebar ───────────────────────────────────────────────────────────────────

st.sidebar.title("🛡️ Monitor Controls")
st.sidebar.markdown("---")

track_window = st.sidebar.selectbox(
    "Track History Window",
    [30, 60, 120, 240], index=1,
    format_func=lambda x: f"{x} min",
)
show_all_tracks = st.sidebar.checkbox(
    "Show all aircraft tracks",
    value=False,
    help="Draw paths for every aircraft, not just those in alerts",
)
sev_filter = st.sidebar.multiselect(
    "Alert Severity",
    ["CRITICAL", "HIGH", "MEDIUM", "LOW"],
    default=["CRITICAL", "HIGH", "MEDIUM", "LOW"],
)
st.sidebar.markdown("---")
if st.sidebar.button("🔄 Refresh Now"):
    st.cache_data.clear()
    st.rerun()
st.sidebar.caption("Auto-refreshes every 60 s")

# ── Load Data ─────────────────────────────────────────────────────────────────

positions                  = load_positions(track_window)
alerts                     = load_alerts()
ew_df                      = load_ew_contacts(hours=24)
last_update, ac_24h, ac_now, alerts_24h, nttr, uttr = load_kpis()
qc_summary, qc_df          = load_qc_data()

alerts_filtered = (
    alerts[alerts["severity"].isin(sev_filter)]
    if not alerts.empty else alerts
)

# Build set of hexes that appear in any alert
alert_hex_map: dict[str, str] = {}   # hex -> alert_type (for color)
if not alerts.empty:
    for _, row in alerts.iterrows():
        if row["aircraft_hexes"]:
            for h in row["aircraft_hexes"].split(","):
                h = h.strip()
                if h and h not in alert_hex_map:
                    alert_hex_map[h] = row["alert_type"]
alert_hexes = set(alert_hex_map.keys())

# ── Header ────────────────────────────────────────────────────────────────────

st.title("🛡️ Military Flight Activity Monitor")
st.caption(
    f"Live feed · api.adsb.lol/v2/mil · "
    f"Last poll: **{last_update} UTC** · Cron every 5 min"
)

# ── KPI Bar ───────────────────────────────────────────────────────────────────

QC_FLAG_COLOR = {
    "OK":       "#2ECC40",
    "DEGRADED": "#FFDC00",
    "POOR":     "#FF851B",
    "FAILED":   "#FF4136",
}

k1, k2, k3, k4, k5, k6, k7, k8 = st.columns(8)
k1.metric("Aircraft (24 h)",       ac_24h)
k2.metric("Current Snapshot",      ac_now)
k3.metric("Alerts (24 h)",         alerts_24h,
          delta="Active" if alerts_24h > 0 else None,
          delta_color="inverse")
k4.metric("NTTR Active (2 h)",     nttr)
k5.metric("UTTR Active (2 h)",     uttr)
k6.metric("Last Update",
          last_update[11:16] + " UTC" if last_update and last_update != "—" else "—")

# EW contacts KPI
_ew_count = len(ew_df) if not ew_df.empty else 0
k7.markdown(
    f"**EW / ISR (24 h)**  \n"
    f"<span style='color:#00FF41; font-size:1.4rem; font-weight:700'>{_ew_count}</span>",
    unsafe_allow_html=True,
)

# QC status KPI — render with colour via markdown since st.metric doesn't support colours
_qc_flag = qc_summary.get("latest_flag") or "—"
_qc_color = QC_FLAG_COLOR.get(_qc_flag, "#888888")
k8.markdown(
    f"**Feed Quality**  \n"
    f"<span style='color:{_qc_color}; font-size:1.4rem; font-weight:700'>{_qc_flag}</span>",
    unsafe_allow_html=True,
)

st.markdown("---")

# ── SECTION 1: LIVE MAP WITH FLIGHT PATHS ────────────────────────────────────

st.subheader("Live Operational Picture")

if positions.empty:
    st.info("No position data yet. Run `python monitor.py` to start collecting.")
else:
    latest_snap      = positions["snapshot_time"].max()
    latest_positions = positions[positions["snapshot_time"] == latest_snap]

    fig_map = go.Figure()

    # Flight path lines ─────────────────────────────────────────────────────
    track_hexes = alert_hexes if not show_all_tracks else set(positions["hex"].unique())

    for hex_id in track_hexes:
        ac_hist = positions[positions["hex"] == hex_id].sort_values("snapshot_time")
        if len(ac_hist) < 2 or ac_hist["lat"].isna().all():
            continue

        line_color = ALERT_LINE_COLOR.get(alert_hex_map.get(hex_id, ""), "#888888")
        label   = (ac_hist["flight"].dropna().iloc[-1]
                   if not ac_hist["flight"].dropna().empty else hex_id)
        ac_type = (ac_hist["type"].dropna().iloc[-1]
                   if not ac_hist["type"].dropna().empty else "—")

        fig_map.add_trace(go.Scattermapbox(
            lat=ac_hist["lat"].tolist(),
            lon=ac_hist["lon"].tolist(),
            mode="lines+markers",
            line=dict(color=line_color, width=2),
            marker=dict(size=4, color=line_color, opacity=0.6),
            name=f"{label} ({ac_type})",
            hovertemplate=(
                f"<b>{label}</b> ({ac_type})<br>"
                "Time: %{customdata[0]}<br>"
                "Alt: %{customdata[1]}<br>"
                "Speed: %{customdata[2]} kts<br>"
                "<extra></extra>"
            ),
            customdata=list(zip(
                ac_hist["snapshot_time"].str[11:16],
                ac_hist["alt_baro"].fillna("—"),
                ac_hist["gs"].fillna(0).round(0),
            )),
            legendgroup="tracks",
            showlegend=False,
        ))

    # Non-alert aircraft dots ───────────────────────────────────────────────
    normal_ac = latest_positions[~latest_positions["hex"].isin(alert_hexes)]
    if not normal_ac.empty:
        fig_map.add_trace(go.Scattermapbox(
            lat=normal_ac["lat"],
            lon=normal_ac["lon"],
            mode="markers",
            marker=dict(size=6, color="#888888", opacity=0.7),
            name="Military — no alert",
            hovertemplate=(
                "<b>%{customdata[0]}</b> (%{customdata[1]})<br>"
                "Alt: %{customdata[2]} · Speed: %{customdata[3]} kts<br>"
                "Hex: %{customdata[4]}<br><extra></extra>"
            ),
            customdata=list(zip(
                normal_ac["flight"].fillna("—"),
                normal_ac["type"].fillna("—"),
                normal_ac["alt_baro"].fillna("—"),
                normal_ac["gs"].fillna(0).round(0),
                normal_ac["hex"],
            )),
        ))

    # Alert aircraft dots ───────────────────────────────────────────────────
    alert_ac = latest_positions[latest_positions["hex"].isin(alert_hexes)]
    if not alert_ac.empty:
        fig_map.add_trace(go.Scattermapbox(
            lat=alert_ac["lat"],
            lon=alert_ac["lon"],
            mode="markers",
            marker=dict(size=12, color="#FF851B", opacity=1.0),
            name="In Active Alert",
            hovertemplate=(
                "<b>%{customdata[0]}</b> (%{customdata[1]})<br>"
                "Alt: %{customdata[2]} · Speed: %{customdata[3]} kts<br>"
                "Hex: %{customdata[4]}<br><extra></extra>"
            ),
            customdata=list(zip(
                alert_ac["flight"].fillna("—"),
                alert_ac["type"].fillna("—"),
                alert_ac["alt_baro"].fillna("—"),
                alert_ac["gs"].fillna(0).round(0),
                alert_ac["hex"],
            )),
        ))

    # Alert centroid markers ────────────────────────────────────────────────
    if not alerts.empty:
        cen = alerts.dropna(subset=["centroid_lat", "centroid_lon"])
        if not cen.empty:
            fig_map.add_trace(go.Scattermapbox(
                lat=cen["centroid_lat"],
                lon=cen["centroid_lon"],
                mode="markers",
                marker=dict(
                    size=18,
                    color=[SEVERITY_COLOR.get(s, "#888") for s in cen["severity"]],
                    opacity=0.85,
                ),
                name="Alert Centroids",
                hovertemplate=(
                    "<b>%{customdata[0]}</b><br>"
                    "Severity: %{customdata[1]}<br>"
                    "%{customdata[2]}<br><extra></extra>"
                ),
                customdata=list(zip(
                    cen["alert_type"].str.replace("_", " "),
                    cen["severity"],
                    cen["summary"].fillna(""),
                )),
            ))

    # EW / ISR contact markers — green triangles ───────────────────────────
    if not ew_df.empty:
        ew_latest = ew_df.dropna(subset=["lat", "lon"])
        # Keep only the most recent entry per hex so markers don't stack
        ew_latest = ew_latest.drop_duplicates(subset=["hex"], keep="first")
        if not ew_latest.empty:
            # Filter to hexes present in latest snapshot for accurate placement
            ew_in_snap = ew_latest[ew_latest["hex"].isin(set(latest_positions["hex"]))]
            if ew_in_snap.empty:
                ew_in_snap = ew_latest
            fig_map.add_trace(go.Scattermapbox(
                lat=ew_in_snap["lat"].tolist(),
                lon=ew_in_snap["lon"].tolist(),
                mode="markers",
                marker=dict(size=12, color="#00FF41", symbol="triangle", opacity=0.95),
                name="EW / ISR Contact",
                hovertemplate=(
                    "<b>%{customdata[0]}</b><br>"
                    "Role: %{customdata[1]}<br>"
                    "Confidence: %{customdata[2]}<br>"
                    "Basis: %{customdata[3]}<br>"
                    "<extra></extra>"
                ),
                customdata=list(zip(
                    ew_in_snap["flight"].fillna(ew_in_snap["hex"]),
                    ew_in_snap["ew_role"].fillna("—"),
                    ew_in_snap["ew_confidence"].fillna("—"),
                    ew_in_snap["ew_basis"].fillna("—"),
                )),
            ))

    center_lat = positions["lat"].dropna().mean()
    center_lon = positions["lon"].dropna().mean()

    fig_map.update_layout(
        mapbox=dict(
            style="open-street-map",
            center=dict(lat=center_lat, lon=center_lon),
            zoom=3,
        ),
        height=600,
        margin=dict(l=0, r=0, t=0, b=0),
        legend=dict(
            bgcolor="rgba(0,0,0,0.6)",
            font=dict(color="white"),
            x=0.01, y=0.99,
        ),
        paper_bgcolor="#0e1117",
    )

    st.plotly_chart(fig_map, use_container_width=True)
    st.caption(
        "⚫ Gray = military, no alert &nbsp;|&nbsp; 🟠 Orange = in active alert &nbsp;|&nbsp; "
        "Colored lines = flight paths leading to alert &nbsp;|&nbsp; "
        "Colored circles = alert centroids (red=CRITICAL, orange=HIGH, yellow=MEDIUM) &nbsp;|&nbsp; "
        "🟢 Green triangle = EW / ISR contact"
    )

st.markdown("---")

# ── SECTION 2: ALERTS TABLE ───────────────────────────────────────────────────

st.subheader("Alerts")

if alerts_filtered.empty:
    st.success("No alerts match current filter.")
else:
    display = alerts_filtered[
        ["timestamp", "severity", "alert_type", "summary", "detail", "region"]
    ].copy()
    display["severity"]   = display["severity"].map(lambda s: f"{SEVERITY_EMOJI.get(s,'')} {s}")
    display["alert_type"] = display["alert_type"].str.replace("_", " ")
    display["timestamp"]  = display["timestamp"].str[:16]

    st.dataframe(
        display,
        use_container_width=True,
        hide_index=True,
        column_config={
            "timestamp":  st.column_config.TextColumn("Time (UTC)", width="small"),
            "severity":   st.column_config.TextColumn("Severity",   width="small"),
            "alert_type": st.column_config.TextColumn("Type",       width="medium"),
            "summary":    st.column_config.TextColumn("Summary",    width="large"),
            "detail":     st.column_config.TextColumn("Detail",     width="large"),
            "region":     st.column_config.TextColumn("Region",     width="small"),
        },
    )

st.markdown("---")

# ── SECTION 3: DATA QUALITY MONITOR ──────────────────────────────────────────

st.subheader("Data Quality Monitor")

if qc_summary.get("latest_flag") is None:
    st.info("No QC data yet. Run `python monitor.py` to collect a snapshot.")
else:
    # ── 4 QC KPI columns ─────────────────────────────────────────────────────
    qc1, qc2, qc3, qc4 = st.columns(4)

    _flag  = qc_summary.get("latest_flag") or "—"
    _color = QC_FLAG_COLOR.get(_flag, "#888888")
    qc1.markdown(
        f"**Current QC**  \n"
        f"<span style='color:{_color}; font-size:1.4rem; font-weight:700'>{_flag}</span>",
        unsafe_allow_html=True,
    )

    _avg_cov = qc_summary.get("avg_coverage_24h")
    qc2.metric(
        "Coverage 24 h avg",
        f"{_avg_cov:.1%}" if _avg_cov is not None else "—",
    )

    _pct_ok = qc_summary.get("pct_ok_24h")
    qc3.metric(
        "Snapshots OK 24 h",
        f"{_pct_ok:.1%}" if _pct_ok is not None else "—",
    )

    _avg_drop = qc_summary.get("avg_dropout_24h")
    qc4.metric(
        "Avg Dropout",
        f"{_avg_drop:.1%}" if _avg_drop is not None else "—",
    )

    # ── Aircraft count over time ──────────────────────────────────────────────
    if not qc_df.empty:
        fig_qc = go.Figure()
        fig_qc.add_trace(go.Scatter(
            x=qc_df["snapshot_time"],
            y=qc_df["aircraft_count"],
            mode="lines+markers",
            line=dict(color="#7FDBFF", width=2),
            marker=dict(size=4),
            name="Aircraft count",
            hovertemplate="Time: %{x}<br>Count: %{y}<extra></extra>",
        ))
        fig_qc.update_layout(
            height=220,
            margin=dict(l=0, r=0, t=24, b=0),
            paper_bgcolor="#0e1117",
            plot_bgcolor="#0e1117",
            xaxis=dict(color="#aaa", showgrid=False, title=None),
            yaxis=dict(color="#aaa", gridcolor="#222", title="Aircraft"),
            title=dict(text="Aircraft Count — Last 24 h", font=dict(color="#ccc", size=13)),
        )
        st.plotly_chart(fig_qc, use_container_width=True)

    # ── Full QC log expander ──────────────────────────────────────────────────
    with st.expander("📋 Full QC Log (last 24 h)", expanded=False):
        if qc_df.empty:
            st.info("No QC records in the last 24 hours.")
        else:
            display_qc = qc_df.copy()
            display_qc["snapshot_time"]   = display_qc["snapshot_time"].str[:16]
            display_qc["coverage_pct"]    = display_qc["coverage_pct"].map(lambda v: f"{v:.1%}" if pd.notna(v) else "—")
            display_qc["type_coverage_pct"] = display_qc["type_coverage_pct"].map(lambda v: f"{v:.1%}" if pd.notna(v) else "—")
            display_qc["dropout_vs_prev"] = display_qc["dropout_vs_prev"].map(lambda v: f"{v:.1%}" if pd.notna(v) else "—")
            display_qc["fail_reasons"]    = display_qc["fail_reasons"].fillna("").replace("", "none")
            st.dataframe(
                display_qc,
                use_container_width=True,
                hide_index=True,
                column_config={
                    "snapshot_time":     st.column_config.TextColumn("Time (UTC)",      width="small"),
                    "aircraft_count":    st.column_config.NumberColumn("Count",          width="small"),
                    "coverage_pct":      st.column_config.TextColumn("Coverage",        width="small"),
                    "type_coverage_pct": st.column_config.TextColumn("Type Cov",        width="small"),
                    "dropout_vs_prev":   st.column_config.TextColumn("Dropout",         width="small"),
                    "quality_flag":      st.column_config.TextColumn("Flag",            width="small"),
                    "fail_reasons":      st.column_config.TextColumn("Fail Reasons",    width="medium"),
                },
            )

st.markdown("---")

# ── SECTION 3b: EW / ISR ACTIVITY ────────────────────────────────────────────

st.subheader("EW / ISR Activity")

if ew_df.empty:
    st.info("No EW/ISR contacts detected in the last 24 hours.")
else:
    _ew_confirmed = len(ew_df[ew_df["ew_confidence"] == "CONFIRMED"]) if "ew_confidence" in ew_df.columns else 0
    _ew_roles     = ew_df["ew_role"].nunique() if "ew_role" in ew_df.columns else 0

    ew_k1, ew_k2, ew_k3 = st.columns(3)
    ew_k1.metric("EW Contacts (24 h)", len(ew_df))
    ew_k2.metric("Confirmed",          _ew_confirmed)
    ew_k3.metric("Distinct Roles",     _ew_roles)

    _ew_display_cols = [
        c for c in
        ["snapshot_time", "flight", "type", "ew_role", "ew_confidence", "ew_basis", "alt_baro", "gs"]
        if c in ew_df.columns
    ]
    _ew_display = ew_df[_ew_display_cols].copy()
    if "snapshot_time" in _ew_display.columns:
        _ew_display["snapshot_time"] = _ew_display["snapshot_time"].str[:16]

    st.dataframe(
        _ew_display,
        use_container_width=True,
        hide_index=True,
        column_config={
            "snapshot_time":  st.column_config.TextColumn("Time (UTC)",    width="small"),
            "flight":         st.column_config.TextColumn("Callsign",      width="small"),
            "type":           st.column_config.TextColumn("Type",          width="small"),
            "ew_role":        st.column_config.TextColumn("EW Role",       width="large"),
            "ew_confidence":  st.column_config.TextColumn("Confidence",    width="small"),
            "ew_basis":       st.column_config.TextColumn("Basis",         width="large"),
            "alt_baro":       st.column_config.TextColumn("Alt",           width="small"),
            "gs":             st.column_config.NumberColumn("Speed (kts)", width="small"),
        },
    )

st.markdown("---")

# ── SECTION 4: ALERT DETAIL — CONTEXT-AWARE VISUALIZATION ───────────────────

import math
import re


def _circle_points(lat, lon, radius_nm, n_points=72):
    """Generate lat/lon points for a circle of radius_nm nautical miles around lat/lon."""
    points_lat = []
    points_lon = []
    for i in range(n_points + 1):
        angle = math.radians(i * 360 / n_points)
        dlat = (radius_nm / 60.0) * math.cos(angle)
        dlon = (radius_nm / 60.0) * math.sin(angle) / math.cos(math.radians(lat))
        points_lat.append(lat + dlat)
        points_lon.append(lon + dlon)
    return points_lat, points_lon


def _haversine_nm(lat1, lon1, lat2, lon2):
    """Haversine distance in nautical miles."""
    R = 3440.065  # Earth radius in nm
    phi1, phi2 = math.radians(lat1), math.radians(lat2)
    dphi = math.radians(lat2 - lat1)
    dlam = math.radians(lon2 - lon1)
    a = math.sin(dphi/2)**2 + math.cos(phi1)*math.cos(phi2)*math.sin(dlam/2)**2
    return 2 * R * math.asin(math.sqrt(a))


def _parse_orbit_radius(detail_text):
    """Try to extract radius in nm from alert detail string. Returns float or default 15.0."""
    m = re.search(r'r=([\d.]+)nm', detail_text or '')
    return float(m.group(1)) if m else 15.0


st.subheader("Alert Detail — Context-Aware Visualization")

if alerts.empty:
    st.info("No alerts to inspect yet.")
else:
    options = {
        f"{SEVERITY_EMOJI.get(row['severity'],'')} [{row['severity']}]  "
        f"{row['alert_type'].replace('_',' ')} — {str(row['summary'])[:70]}": idx
        for idx, row in alerts.head(20).iterrows()
    }
    chosen_label = st.selectbox("Select alert to inspect:", ["— select —"] + list(options.keys()))

    if chosen_label != "— select —":
        sel      = alerts.loc[options[chosen_label]]
        atype    = sel["alert_type"]

        # ── Info card (unchanged for all alert types) ───────────────────────
        col_info, col_meta = st.columns([3, 1])
        with col_info:
            st.markdown(
                f"**{SEVERITY_EMOJI.get(sel['severity'],'')} "
                f"{atype.replace('_',' ')}**  \n"
                f"{sel['summary']}  \n  \n"
                f"{sel['detail']}"
            )
        with col_meta:
            st.markdown(f"**Time:** {str(sel['timestamp'])[:16]} UTC")
            st.markdown(f"**Severity:** {sel['severity']}")
            st.markdown(f"**Region:** {sel.get('region') or 'Global'}")

        # ── Build hex list ──────────────────────────────────────────────────
        hex_list = [
            h.strip()
            for h in (sel["aircraft_hexes"] or "").split(",")
            if h.strip()
        ]

        if not hex_list:
            st.warning("No aircraft hex codes linked to this alert.")
        elif positions.empty:
            st.warning("No position history available yet.")
        else:
            alert_pos = positions[positions["hex"].isin(hex_list)]

            if alert_pos.empty:
                st.info(
                    "Position history for these aircraft has expired from the current window. "
                    "Extend the Track History Window in the sidebar."
                )
            else:
                det_lat = (
                    sel.get("centroid_lat")
                    if pd.notna(sel.get("centroid_lat")) and sel.get("centroid_lat") is not None
                    else alert_pos["lat"].dropna().mean()
                )
                det_lon = (
                    sel.get("centroid_lon")
                    if pd.notna(sel.get("centroid_lon")) and sel.get("centroid_lon") is not None
                    else alert_pos["lon"].dropna().mean()
                )

                # ════════════════════════════════════════════════════════════
                # PROBABLE_REFUELING_OP
                # ════════════════════════════════════════════════════════════
                if atype == "PROBABLE_REFUELING_OP":
                    st.caption("Tanker orbit + receiver tracks")

                    # First hex in list is assumed tanker (orbit aircraft)
                    tanker_hex   = hex_list[0]
                    receiver_hexes = hex_list[1:]

                    fig_det = go.Figure()

                    # Orbit circle overlay ───────────────────────────────────
                    if pd.notna(det_lat) and pd.notna(det_lon):
                        radius_nm = _parse_orbit_radius(sel.get("detail"))
                        circ_lats, circ_lons = _circle_points(det_lat, det_lon, radius_nm)
                        fig_det.add_trace(go.Scattermapbox(
                            lat=circ_lats,
                            lon=circ_lons,
                            mode="lines",
                            line=dict(color="#00BFFF", width=2),
                            name=f"Tanker orbit (~{radius_nm:.0f}nm)",
                            hoverinfo="skip",
                        ))

                    # Tanker track — blue ────────────────────────────────────
                    tanker_hist = alert_pos[alert_pos["hex"] == tanker_hex].sort_values("snapshot_time")
                    if not tanker_hist.empty and not tanker_hist["lat"].isna().all():
                        t_label = (
                            tanker_hist["flight"].dropna().iloc[-1]
                            if not tanker_hist["flight"].dropna().empty else tanker_hex
                        )
                        t_type = (
                            tanker_hist["type"].dropna().iloc[-1]
                            if not tanker_hist["type"].dropna().empty else "—"
                        )
                        sizes = [5] * (len(tanker_hist) - 1) + [14]
                        fig_det.add_trace(go.Scattermapbox(
                            lat=tanker_hist["lat"].tolist(),
                            lon=tanker_hist["lon"].tolist(),
                            mode="lines+markers",
                            line=dict(color="#00BFFF", width=3),
                            marker=dict(size=sizes, color="#00BFFF"),
                            name=f"Tanker: {t_label} ({t_type})",
                            hovertemplate=(
                                f"<b>TANKER {t_label}</b> ({t_type})<br>"
                                "Time: %{customdata[0]}<br>"
                                "Alt: %{customdata[1]}<br>"
                                "Speed: %{customdata[2]} kts<br>"
                                "<extra></extra>"
                            ),
                            customdata=list(zip(
                                tanker_hist["snapshot_time"].str[11:16],
                                tanker_hist["alt_baro"].fillna("—"),
                                tanker_hist["gs"].fillna(0).round(0),
                            )),
                        ))

                    # Receiver tracks — orange ───────────────────────────────
                    for hex_id in receiver_hexes:
                        ac_hist = alert_pos[alert_pos["hex"] == hex_id].sort_values("snapshot_time")
                        if ac_hist.empty or ac_hist["lat"].isna().all():
                            continue
                        label = (
                            ac_hist["flight"].dropna().iloc[-1]
                            if not ac_hist["flight"].dropna().empty else hex_id
                        )
                        ac_type = (
                            ac_hist["type"].dropna().iloc[-1]
                            if not ac_hist["type"].dropna().empty else "—"
                        )
                        sizes = [5] * (len(ac_hist) - 1) + [14]
                        fig_det.add_trace(go.Scattermapbox(
                            lat=ac_hist["lat"].tolist(),
                            lon=ac_hist["lon"].tolist(),
                            mode="lines+markers",
                            line=dict(color="#FF851B", width=3),
                            marker=dict(size=sizes, color="#FF851B"),
                            name=f"Receiver: {label} ({ac_type})",
                            hovertemplate=(
                                f"<b>RECEIVER {label}</b> ({ac_type})<br>"
                                "Time: %{customdata[0]}<br>"
                                "Alt: %{customdata[1]}<br>"
                                "Speed: %{customdata[2]} kts<br>"
                                "<extra></extra>"
                            ),
                            customdata=list(zip(
                                ac_hist["snapshot_time"].str[11:16],
                                ac_hist["alt_baro"].fillna("—"),
                                ac_hist["gs"].fillna(0).round(0),
                            )),
                        ))

                    fig_det.update_layout(
                        mapbox=dict(
                            style="open-street-map",
                            center=dict(lat=det_lat, lon=det_lon),
                            zoom=6,
                        ),
                        height=500,
                        margin=dict(l=0, r=0, t=0, b=0),
                        paper_bgcolor="#0e1117",
                        legend=dict(bgcolor="rgba(0,0,0,0.6)", font=dict(color="white")),
                    )
                    st.plotly_chart(fig_det, use_container_width=True)

                    # Altitude profile chart ──────────────────────────────────
                    alt_df = load_alt_profile(tuple(hex_list), track_window)
                    if not alt_df.empty:
                        # Convert alt_baro to numeric (may be "ground" or similar strings)
                        alt_df = alt_df.copy()
                        alt_df["alt_baro_num"] = pd.to_numeric(alt_df["alt_baro"], errors="coerce")
                        alt_df_valid = alt_df.dropna(subset=["alt_baro_num"])

                        if not alt_df_valid.empty:
                            fig_alt = go.Figure()
                            for hex_id in hex_list:
                                ac_alt = alt_df_valid[alt_df_valid["hex"] == hex_id].sort_values("snapshot_time")
                                if ac_alt.empty:
                                    continue
                                label = (
                                    ac_alt["flight"].dropna().iloc[-1]
                                    if not ac_alt["flight"].dropna().empty else hex_id
                                )
                                color = "#00BFFF" if hex_id == tanker_hex else "#FF851B"
                                fig_alt.add_trace(go.Scatter(
                                    x=ac_alt["snapshot_time"],
                                    y=ac_alt["alt_baro_num"],
                                    mode="lines+markers",
                                    line=dict(color=color, width=2),
                                    marker=dict(size=4, color=color),
                                    name=label,
                                    hovertemplate=(
                                        f"<b>{label}</b><br>"
                                        "Time: %{x}<br>"
                                        "Alt: %{y} ft<br>"
                                        "<extra></extra>"
                                    ),
                                ))
                            fig_alt.update_layout(
                                height=250,
                                margin=dict(l=0, r=0, t=28, b=0),
                                paper_bgcolor="#0e1117",
                                plot_bgcolor="#0e1117",
                                xaxis=dict(color="#aaa", showgrid=False, title=None),
                                yaxis=dict(color="#aaa", gridcolor="#222", title="Altitude (ft)"),
                                title=dict(
                                    text="Altitude Profile — Tanker (blue) vs Receivers (orange)",
                                    font=dict(color="#ccc", size=13),
                                ),
                                legend=dict(bgcolor="rgba(0,0,0,0.6)", font=dict(color="white")),
                            )
                            st.plotly_chart(fig_alt, use_container_width=True)

                    st.caption(
                        f"Showing last **{track_window} min** of position history. "
                        "Blue = tanker, Orange = receivers. Dashed circle = estimated orbit radius."
                    )

                # ════════════════════════════════════════════════════════════
                # FORMATION_ACTIVITY / ATTACK_PACKAGE
                # ════════════════════════════════════════════════════════════
                elif atype in ("FORMATION_ACTIVITY", "ATTACK_PACKAGE"):
                    fig_det = go.Figure()

                    # Per-snapshot scatter with time-based opacity ────────────
                    all_snaps = sorted(alert_pos["snapshot_time"].unique())
                    n_snaps   = len(all_snaps)

                    for snap_i, snap_ts in enumerate(all_snaps):
                        opacity = 0.2 + 0.8 * (snap_i / max(n_snaps - 1, 1))
                        snap_df = alert_pos[alert_pos["snapshot_time"] == snap_ts].dropna(subset=["lat", "lon"])
                        if snap_df.empty:
                            continue
                        is_latest = snap_ts == all_snaps[-1]
                        fig_det.add_trace(go.Scattermapbox(
                            lat=snap_df["lat"].tolist(),
                            lon=snap_df["lon"].tolist(),
                            mode="markers",
                            marker=dict(
                                size=8 if not is_latest else 12,
                                color="#0074D9",
                                opacity=opacity,
                            ),
                            name=snap_ts[11:16] if not is_latest else f"Latest ({snap_ts[11:16]})",
                            hovertemplate=(
                                "<b>%{customdata[0]}</b> (%{customdata[1]})<br>"
                                "Time: %{customdata[2]}<br>"
                                "Alt: %{customdata[3]}<br>"
                                "<extra></extra>"
                            ),
                            customdata=list(zip(
                                snap_df["flight"].fillna("—"),
                                snap_df["type"].fillna("—"),
                                snap_df["snapshot_time"].str[11:16],
                                snap_df["alt_baro"].fillna("—"),
                            )),
                            showlegend=is_latest,
                        ))

                    # Lines connecting aircraft at latest snapshot ────────────
                    latest_snap_df = alert_pos[
                        alert_pos["snapshot_time"] == all_snaps[-1]
                    ].dropna(subset=["lat", "lon"])

                    if len(latest_snap_df) >= 2:
                        lats_conn = []
                        lons_conn = []
                        pts = list(zip(latest_snap_df["lat"], latest_snap_df["lon"]))
                        for i in range(len(pts)):
                            for j in range(i + 1, len(pts)):
                                lats_conn += [pts[i][0], pts[j][0], None]
                                lons_conn += [pts[i][1], pts[j][1], None]
                        fig_det.add_trace(go.Scattermapbox(
                            lat=lats_conn,
                            lon=lons_conn,
                            mode="lines",
                            line=dict(color="#0074D9", width=1),
                            name="Formation geometry (latest)",
                            hoverinfo="skip",
                            opacity=0.6,
                        ))

                    fig_det.update_layout(
                        mapbox=dict(
                            style="open-street-map",
                            center=dict(lat=det_lat, lon=det_lon),
                            zoom=6,
                        ),
                        height=500,
                        margin=dict(l=0, r=0, t=0, b=0),
                        paper_bgcolor="#0e1117",
                        legend=dict(bgcolor="rgba(0,0,0,0.6)", font=dict(color="white")),
                    )
                    st.plotly_chart(fig_det, use_container_width=True)

                    # Formation spread metric ─────────────────────────────────
                    if len(latest_snap_df) >= 2:
                        pts = list(zip(latest_snap_df["lat"], latest_snap_df["lon"]))
                        max_dist = 0.0
                        for i in range(len(pts)):
                            for j in range(i + 1, len(pts)):
                                d = _haversine_nm(pts[i][0], pts[i][1], pts[j][0], pts[j][1])
                                if d > max_dist:
                                    max_dist = d
                        st.metric(
                            "Formation Spread (latest snapshot)",
                            f"{max_dist:.1f} nm",
                            help="Max distance between any two aircraft in the formation.",
                        )

                    st.caption(
                        f"Showing last **{track_window} min** of formation positions. "
                        "Older positions are more transparent. Lines show current formation geometry."
                    )

                # ════════════════════════════════════════════════════════════
                # HOLDING_ORBIT
                # ════════════════════════════════════════════════════════════
                elif atype == "HOLDING_ORBIT":
                    fig_det = go.Figure()

                    line_color = ALERT_LINE_COLOR.get(atype, "#FF851B")

                    # Orbit circle overlay ───────────────────────────────────
                    if pd.notna(det_lat) and pd.notna(det_lon):
                        radius_nm = _parse_orbit_radius(sel.get("detail"))
                        circ_lats, circ_lons = _circle_points(det_lat, det_lon, radius_nm)
                        fig_det.add_trace(go.Scattermapbox(
                            lat=circ_lats,
                            lon=circ_lons,
                            mode="lines",
                            line=dict(color=line_color, width=2),
                            name=f"Orbit circle (~{radius_nm:.0f}nm)",
                            hoverinfo="skip",
                        ))

                    # Determine CW/CCW from detail text
                    detail_text = sel.get("detail") or ""
                    direction   = "CW" if "CW" in detail_text.upper() else "CCW"

                    # Aircraft tracks ────────────────────────────────────────
                    for hex_id in hex_list:
                        ac_hist = alert_pos[alert_pos["hex"] == hex_id].sort_values("snapshot_time")
                        if ac_hist.empty or ac_hist["lat"].isna().all():
                            continue
                        label = (
                            ac_hist["flight"].dropna().iloc[-1]
                            if not ac_hist["flight"].dropna().empty else hex_id
                        )
                        ac_type = (
                            ac_hist["type"].dropna().iloc[-1]
                            if not ac_hist["type"].dropna().empty else "—"
                        )
                        sizes = [5] * (len(ac_hist) - 1) + [14]
                        fig_det.add_trace(go.Scattermapbox(
                            lat=ac_hist["lat"].tolist(),
                            lon=ac_hist["lon"].tolist(),
                            mode="lines+markers",
                            line=dict(color=line_color, width=3),
                            marker=dict(size=sizes, color=line_color),
                            name=f"{label} ({ac_type})",
                            hovertemplate=(
                                f"<b>{label}</b> ({ac_type})<br>"
                                "Time: %{customdata[0]}<br>"
                                "Alt: %{customdata[1]}<br>"
                                "Speed: %{customdata[2]} kts<br>"
                                "<extra></extra>"
                            ),
                            customdata=list(zip(
                                ac_hist["snapshot_time"].str[11:16],
                                ac_hist["alt_baro"].fillna("—"),
                                ac_hist["gs"].fillna(0).round(0),
                            )),
                        ))

                        # Direction arrow annotation — place at centroid
                        if pd.notna(det_lat) and pd.notna(det_lon):
                            arrow_symbol = "↻" if direction == "CW" else "↺"
                            fig_det.add_trace(go.Scattermapbox(
                                lat=[det_lat],
                                lon=[det_lon],
                                mode="text",
                                text=[f"{arrow_symbol} {direction}"],
                                textfont=dict(size=18, color=line_color),
                                name=f"Direction: {direction}",
                                hoverinfo="skip",
                                showlegend=False,
                            ))

                    fig_det.update_layout(
                        mapbox=dict(
                            style="open-street-map",
                            center=dict(lat=det_lat, lon=det_lon),
                            zoom=6,
                        ),
                        height=500,
                        margin=dict(l=0, r=0, t=0, b=0),
                        paper_bgcolor="#0e1117",
                        legend=dict(bgcolor="rgba(0,0,0,0.6)", font=dict(color="white")),
                    )
                    st.plotly_chart(fig_det, use_container_width=True)

                    # Cumulative heading chart ────────────────────────────────
                    if len(hex_list) == 1:
                        ac_hist = alert_pos[alert_pos["hex"] == hex_list[0]].sort_values("snapshot_time")
                        lats    = ac_hist["lat"].dropna().tolist()
                        lons    = ac_hist["lon"].dropna().tolist()

                        if len(lats) >= 2:
                            cum_headings = [0.0]
                            for k in range(1, len(lats)):
                                dlat = lats[k] - lats[k - 1]
                                dlon = lons[k] - lons[k - 1]
                                hdg  = math.degrees(math.atan2(dlon, dlat)) % 360
                                prev_hdg = (math.degrees(math.atan2(
                                    lons[k - 1] - (lons[k - 2] if k >= 2 else lons[k - 1]),
                                    lats[k - 1] - (lats[k - 2] if k >= 2 else lats[k - 1]),
                                )) % 360)
                                delta = (hdg - prev_hdg + 180) % 360 - 180
                                cum_headings.append(cum_headings[-1] + delta)

                            snap_times = ac_hist["snapshot_time"].tolist()[:len(cum_headings)]

                            fig_hdg = go.Figure()
                            fig_hdg.add_trace(go.Scatter(
                                x=snap_times,
                                y=cum_headings,
                                mode="lines+markers",
                                line=dict(color=line_color, width=2),
                                marker=dict(size=4, color=line_color),
                                name="Cumulative heading",
                                hovertemplate=(
                                    "Time: %{x}<br>"
                                    "Cumulative heading: %{y:.0f}°<br>"
                                    "<extra></extra>"
                                ),
                            ))
                            fig_hdg.update_layout(
                                height=220,
                                margin=dict(l=0, r=0, t=28, b=0),
                                paper_bgcolor="#0e1117",
                                plot_bgcolor="#0e1117",
                                xaxis=dict(color="#aaa", showgrid=False, title=None),
                                yaxis=dict(color="#aaa", gridcolor="#222", title="Cumulative Heading (°)"),
                                title=dict(
                                    text=f"Cumulative Heading Rotation — {direction} orbit",
                                    font=dict(color="#ccc", size=13),
                                ),
                            )
                            st.plotly_chart(fig_hdg, use_container_width=True)

                    st.caption(
                        f"Showing last **{track_window} min** of orbit track. "
                        f"Dashed circle = estimated orbit. Direction: **{direction}**."
                    )

                # ════════════════════════════════════════════════════════════
                # SENSITIVE_ASSET_ACTIVITY / UNIDENTIFIED_CONTACTS
                # ════════════════════════════════════════════════════════════
                elif atype in ("SENSITIVE_ASSET_ACTIVITY", "UNIDENTIFIED_CONTACTS"):
                    fig_det = go.Figure()

                    # Distinguish ghost hexes (no flight/reg/type) from cluster hexes
                    latest_alert_pos = alert_pos[
                        alert_pos["snapshot_time"] == alert_pos["snapshot_time"].max()
                    ]

                    ghost_mask = (
                        latest_alert_pos["flight"].isna() &
                        latest_alert_pos["registration"].isna() &
                        latest_alert_pos["type"].isna()
                    )
                    ghost_df   = latest_alert_pos[ghost_mask].dropna(subset=["lat", "lon"])
                    cluster_df = latest_alert_pos[~ghost_mask].dropna(subset=["lat", "lon"])

                    # Ghost X markers — purple ───────────────────────────────
                    if not ghost_df.empty:
                        fig_det.add_trace(go.Scattermapbox(
                            lat=ghost_df["lat"].tolist(),
                            lon=ghost_df["lon"].tolist(),
                            mode="markers",
                            marker=dict(size=14, color="#B10DC9", symbol="x"),
                            name="Ghost Contact",
                            hovertemplate=(
                                "<b>Ghost contact</b><br>"
                                "Hex: %{customdata[0]}<br>"
                                "Alt: %{customdata[1]}<br>"
                                "<extra></extra>"
                            ),
                            customdata=list(zip(
                                ghost_df["hex"],
                                ghost_df["alt_baro"].fillna("—"),
                            )),
                        ))

                    # Cluster aircraft — standard orange ─────────────────────
                    if not cluster_df.empty:
                        fig_det.add_trace(go.Scattermapbox(
                            lat=cluster_df["lat"].tolist(),
                            lon=cluster_df["lon"].tolist(),
                            mode="markers",
                            marker=dict(size=10, color="#FF851B", opacity=0.9),
                            name="Cluster Aircraft",
                            hovertemplate=(
                                "<b>%{customdata[0]}</b> (%{customdata[1]})<br>"
                                "Alt: %{customdata[2]} · Speed: %{customdata[3]} kts<br>"
                                "<extra></extra>"
                            ),
                            customdata=list(zip(
                                cluster_df["flight"].fillna("—"),
                                cluster_df["type"].fillna("—"),
                                cluster_df["alt_baro"].fillna("—"),
                                cluster_df["gs"].fillna(0).round(0),
                            )),
                        ))

                    # Lines from each ghost to nearest cluster centroid ───────
                    if not ghost_df.empty and not cluster_df.empty:
                        c_lat_cen = cluster_df["lat"].mean()
                        c_lon_cen = cluster_df["lon"].mean()
                        annotation_parts = []

                        for _, grow in ghost_df.iterrows():
                            g_lat, g_lon = grow["lat"], grow["lon"]
                            dist = _haversine_nm(g_lat, g_lon, c_lat_cen, c_lon_cen)
                            fig_det.add_trace(go.Scattermapbox(
                                lat=[g_lat, c_lat_cen],
                                lon=[g_lon, c_lon_cen],
                                mode="lines",
                                line=dict(color="#B10DC9", width=1),
                                hoverinfo="skip",
                                showlegend=False,
                            ))
                            annotation_parts.append(
                                f"Ghost {grow['hex']} — {dist:.1f} nm from nearest formation"
                            )

                        # Text annotation at cluster centroid
                        if annotation_parts:
                            fig_det.add_trace(go.Scattermapbox(
                                lat=[c_lat_cen],
                                lon=[c_lon_cen],
                                mode="text",
                                text=[annotation_parts[0]],
                                textfont=dict(size=11, color="#B10DC9"),
                                hoverinfo="skip",
                                showlegend=False,
                            ))

                    fig_det.update_layout(
                        mapbox=dict(
                            style="open-street-map",
                            center=dict(lat=det_lat, lon=det_lon),
                            zoom=6,
                        ),
                        height=500,
                        margin=dict(l=0, r=0, t=0, b=0),
                        paper_bgcolor="#0e1117",
                        legend=dict(bgcolor="rgba(0,0,0,0.6)", font=dict(color="white")),
                    )
                    st.plotly_chart(fig_det, use_container_width=True)
                    st.caption(
                        "Purple X = ghost/unidentified contact. "
                        "Orange = cluster aircraft. "
                        "Purple lines = ghost-to-formation distance."
                    )

                # ════════════════════════════════════════════════════════════
                # EW_ASSET_ACTIVITY
                # ════════════════════════════════════════════════════════════
                elif atype == "EW_ASSET_ACTIVITY":
                    fig_det = go.Figure()

                    # EW aircraft as green triangles ─────────────────────────
                    ew_latest = ew_df[ew_df["hex"].isin(hex_list)].drop_duplicates(
                        subset=["hex"], keep="first"
                    ).dropna(subset=["lat", "lon"])

                    if not ew_latest.empty:
                        fig_det.add_trace(go.Scattermapbox(
                            lat=ew_latest["lat"].tolist(),
                            lon=ew_latest["lon"].tolist(),
                            mode="markers",
                            marker=dict(size=14, color="#00FF41", symbol="triangle", opacity=0.95),
                            name="EW / ISR Contact",
                            hovertemplate=(
                                "<b>%{customdata[0]}</b><br>"
                                "Role: %{customdata[1]}<br>"
                                "Confidence: %{customdata[2]}<br>"
                                "Basis: %{customdata[3]}<br>"
                                "<extra></extra>"
                            ),
                            customdata=list(zip(
                                ew_latest["flight"].fillna(ew_latest["hex"]),
                                ew_latest["ew_role"].fillna("—"),
                                ew_latest["ew_confidence"].fillna("—"),
                                ew_latest["ew_basis"].fillna("—"),
                            )),
                        ))

                    # Nearby non-EW aircraft in standard colors ──────────────
                    nearby_ac = alert_pos[
                        (alert_pos["snapshot_time"] == alert_pos["snapshot_time"].max()) &
                        (~alert_pos["hex"].isin(hex_list))
                    ].dropna(subset=["lat", "lon"])

                    if not nearby_ac.empty:
                        fig_det.add_trace(go.Scattermapbox(
                            lat=nearby_ac["lat"].tolist(),
                            lon=nearby_ac["lon"].tolist(),
                            mode="markers",
                            marker=dict(size=8, color="#FF851B", opacity=0.8),
                            name="Nearby Aircraft",
                            hovertemplate=(
                                "<b>%{customdata[0]}</b> (%{customdata[1]})<br>"
                                "Alt: %{customdata[2]} · Speed: %{customdata[3]} kts<br>"
                                "<extra></extra>"
                            ),
                            customdata=list(zip(
                                nearby_ac["flight"].fillna("—"),
                                nearby_ac["type"].fillna("—"),
                                nearby_ac["alt_baro"].fillna("—"),
                                nearby_ac["gs"].fillna(0).round(0),
                            )),
                        ))

                    # EW track lines ─────────────────────────────────────────
                    for hex_id in hex_list:
                        ac_hist = alert_pos[alert_pos["hex"] == hex_id].sort_values("snapshot_time")
                        if ac_hist.empty or ac_hist["lat"].isna().all():
                            continue
                        label = (
                            ac_hist["flight"].dropna().iloc[-1]
                            if not ac_hist["flight"].dropna().empty else hex_id
                        )
                        fig_det.add_trace(go.Scattermapbox(
                            lat=ac_hist["lat"].tolist(),
                            lon=ac_hist["lon"].tolist(),
                            mode="lines",
                            line=dict(color="#00FF41", width=2),
                            name=f"EW track: {label}",
                            hoverinfo="skip",
                            opacity=0.5,
                        ))

                    fig_det.update_layout(
                        mapbox=dict(
                            style="open-street-map",
                            center=dict(lat=det_lat, lon=det_lon),
                            zoom=6,
                        ),
                        height=500,
                        margin=dict(l=0, r=0, t=0, b=0),
                        paper_bgcolor="#0e1117",
                        legend=dict(bgcolor="rgba(0,0,0,0.6)", font=dict(color="white")),
                    )
                    st.plotly_chart(fig_det, use_container_width=True)

                    # EW contacts detail table ────────────────────────────────
                    ew_detail = load_ew_for_hexes(tuple(hex_list))
                    if not ew_detail.empty:
                        st.markdown("**EW Contact Details**")
                        _ew_cols = [
                            c for c in
                            ["snapshot_time", "flight", "type", "ew_role",
                             "ew_confidence", "ew_basis", "alt_baro", "gs",
                             "in_orbit", "near_cluster"]
                            if c in ew_detail.columns
                        ]
                        _ew_disp = ew_detail[_ew_cols].copy()
                        if "snapshot_time" in _ew_disp.columns:
                            _ew_disp["snapshot_time"] = _ew_disp["snapshot_time"].str[:16]
                        st.dataframe(
                            _ew_disp,
                            use_container_width=True,
                            hide_index=True,
                            column_config={
                                "snapshot_time":  st.column_config.TextColumn("Time (UTC)",    width="small"),
                                "flight":         st.column_config.TextColumn("Callsign",      width="small"),
                                "type":           st.column_config.TextColumn("Type",          width="small"),
                                "ew_role":        st.column_config.TextColumn("EW Role",       width="large"),
                                "ew_confidence":  st.column_config.TextColumn("Confidence",    width="small"),
                                "ew_basis":       st.column_config.TextColumn("Basis",         width="large"),
                                "alt_baro":       st.column_config.TextColumn("Alt",           width="small"),
                                "gs":             st.column_config.NumberColumn("Speed (kts)", width="small"),
                                "in_orbit":       st.column_config.CheckboxColumn("In Orbit",  width="small"),
                                "near_cluster":   st.column_config.CheckboxColumn("Near Cluster", width="small"),
                            },
                        )

                    st.caption(
                        "Green triangles = EW/ISR contacts. "
                        "Orange = nearby aircraft. Green lines = EW track history."
                    )

                # ════════════════════════════════════════════════════════════
                # ALL OTHER ALERT TYPES — basic track display
                # ════════════════════════════════════════════════════════════
                else:
                    fig_det = go.Figure()
                    line_color = ALERT_LINE_COLOR.get(atype, "#FF851B")

                    for hex_id in hex_list:
                        ac_hist = alert_pos[alert_pos["hex"] == hex_id].sort_values("snapshot_time")
                        if ac_hist.empty or ac_hist["lat"].isna().all():
                            continue

                        label = (
                            ac_hist["flight"].dropna().iloc[-1]
                            if not ac_hist["flight"].dropna().empty else hex_id
                        )
                        ac_type = (
                            ac_hist["type"].dropna().iloc[-1]
                            if not ac_hist["type"].dropna().empty else "—"
                        )
                        sizes = [5] * (len(ac_hist) - 1) + [14]

                        fig_det.add_trace(go.Scattermapbox(
                            lat=ac_hist["lat"].tolist(),
                            lon=ac_hist["lon"].tolist(),
                            mode="lines+markers",
                            line=dict(color=line_color, width=3),
                            marker=dict(size=sizes, color=line_color),
                            name=f"{label} ({ac_type})",
                            hovertemplate=(
                                f"<b>{label}</b> ({ac_type})<br>"
                                "Time: %{customdata[0]}<br>"
                                "Alt: %{customdata[1]}<br>"
                                "Speed: %{customdata[2]} kts<br>"
                                "<extra></extra>"
                            ),
                            customdata=list(zip(
                                ac_hist["snapshot_time"].str[11:16],
                                ac_hist["alt_baro"].fillna("—"),
                                ac_hist["gs"].fillna(0).round(0),
                            )),
                        ))

                    fig_det.update_layout(
                        mapbox=dict(
                            style="open-street-map",
                            center=dict(lat=det_lat, lon=det_lon),
                            zoom=6,
                        ),
                        height=500,
                        margin=dict(l=0, r=0, t=0, b=0),
                        paper_bgcolor="#0e1117",
                        legend=dict(bgcolor="rgba(0,0,0,0.6)", font=dict(color="white")),
                    )
                    st.plotly_chart(fig_det, use_container_width=True)
                    st.caption(
                        f"Showing last **{track_window} min** of position history for "
                        f"**{len(hex_list)}** aircraft in this alert. "
                        "Larger dot = most recent position. Extend the window in the sidebar to see older tracks."
                    )

st.markdown("---")

# ── SECTION 5: RAW LATEST SNAPSHOT ───────────────────────────────────────────

with st.expander("📋 Latest Snapshot — Raw Positions", expanded=False):
    if positions.empty:
        st.info("No data yet.")
    else:
        snap_ts = positions["snapshot_time"].max()
        snap_df = positions[positions["snapshot_time"] == snap_ts].copy()
        snap_df["in_alert"] = snap_df["hex"].isin(alert_hexes)
        st.caption(f"Snapshot: **{snap_ts} UTC** · {len(snap_df)} aircraft")
        st.dataframe(
            snap_df[[
                "hex", "flight", "registration", "type",
                "alt_baro", "gs", "lat", "lon", "region", "in_alert",
            ]],
            use_container_width=True,
            hide_index=True,
        )
