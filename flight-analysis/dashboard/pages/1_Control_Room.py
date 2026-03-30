"""
Military Flight Activity Monitor — Control Room Mode
Full-screen, auto-refreshing tactical display. No sidebar.
Run from project root: streamlit run dashboard/app.py
Navigate to Control Room via the sidebar page list.
"""

import sqlite3
import time
import pandas as pd
import plotly.graph_objects as go
import streamlit as st
import streamlit.components.v1 as components
from pathlib import Path

# pages/ is one level deeper than dashboard/, so go up three levels to reach project root
DB_PATH = Path(__file__).parent.parent.parent / "data" / "monitor.db"

SEVERITY_COLOR = {
    "CRITICAL": "#FF4136",
    "HIGH":     "#FF851B",
    "MEDIUM":   "#FFDC00",
    "LOW":      "#7FDBFF",
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

QC_FLAG_COLOR = {
    "OK":       "#2ECC40",
    "DEGRADED": "#FFDC00",
    "POOR":     "#FF851B",
    "FAILED":   "#FF4136",
}

# ── CSS ───────────────────────────────────────────────────────────────────────

CONTROL_ROOM_CSS = """
<style>
  [data-testid="stSidebar"] { display: none !important; }
  .block-container { padding: 0.5rem 1.5rem !important; max-width: 100% !important; }
  .cr-kpi-label { font-size: 0.75rem; color: #888; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 2px; }
  .cr-kpi-value { font-size: 2.8rem; font-weight: 700; line-height: 1.1; }
  .cr-alert-critical { background: #1a0000; border-left: 4px solid #FF4136; padding: 0.4rem 0.8rem; margin-bottom: 4px; border-radius: 2px; }
  .cr-alert-high { background: #1a0900; border-left: 4px solid #FF851B; padding: 0.4rem 0.8rem; margin-bottom: 4px; border-radius: 2px; }
  .cr-ew-contact { background: #001a04; border-left: 4px solid #00FF41; padding: 0.3rem 0.8rem; margin-bottom: 4px; border-radius: 2px; font-size: 0.85rem; }
  .cr-countdown { font-size: 0.9rem; color: #666; font-variant-numeric: tabular-nums; text-align: right; }
  .cr-title { font-size: 1.3rem; font-weight: 700; color: #ccc; letter-spacing: 0.05em; }
</style>
"""

# ── Audio alert (Web Audio API — no external files) ───────────────────────────

AUDIO_ALERT_HTML = """
<script>
try {
    var ctx = new (window.AudioContext || window.webkitAudioContext)();
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.frequency.value = 880;
    gain.gain.setValueAtTime(0.3, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.5);
    osc.start(ctx.currentTime);
    osc.stop(ctx.currentTime + 0.5);
} catch(e) {}
</script>
"""

# ── Page config ───────────────────────────────────────────────────────────────

st.set_page_config(
    page_title="Control Room — Flight Monitor",
    page_icon="🎯",
    layout="wide",
    initial_sidebar_state="collapsed",
)

# ── DB Helpers ────────────────────────────────────────────────────────────────

def _conn():
    return sqlite3.connect(str(DB_PATH), check_same_thread=False)


@st.cache_data(ttl=60)
def load_positions(minutes: int = 60) -> pd.DataFrame:
    with _conn() as conn:
        return pd.read_sql(
            """SELECT * FROM aircraft_positions
               WHERE snapshot_time >= datetime('now', ? || ' minutes')
               ORDER BY hex, snapshot_time""",
            conn, params=(f"-{minutes}",),
        )


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
            return pd.DataFrame()


# ── KPI helper ────────────────────────────────────────────────────────────────

def _kpi(col, label: str, value, color: str = "#ffffff"):
    col.markdown(
        f'<div class="cr-kpi-label">{label}</div>'
        f'<div class="cr-kpi-value" style="color:{color}">{value}</div>',
        unsafe_allow_html=True,
    )


# ── Map builder ───────────────────────────────────────────────────────────────

def _build_map(positions: pd.DataFrame, alerts: pd.DataFrame, ew_df: pd.DataFrame) -> go.Figure:
    """Build the tactical map figure. Returns a go.Figure."""

    fig = go.Figure()

    if positions.empty:
        fig.update_layout(
            mapbox=dict(style="open-street-map", center=dict(lat=37.5, lon=-100.0), zoom=3),
            height=800,
            margin=dict(l=0, r=0, t=0, b=0),
            paper_bgcolor="#0e1117",
        )
        return fig

    latest_snap      = positions["snapshot_time"].max()
    latest_positions = positions[positions["snapshot_time"] == latest_snap]

    # Build alert hex map: hex -> alert_type
    alert_hex_map: dict[str, str] = {}
    if not alerts.empty:
        for _, row in alerts.iterrows():
            if row["aircraft_hexes"]:
                for h in row["aircraft_hexes"].split(","):
                    h = h.strip()
                    if h and h not in alert_hex_map:
                        alert_hex_map[h] = row["alert_type"]
    alert_hexes = set(alert_hex_map.keys())

    # Flight path lines — only alert aircraft ──────────────────────────────
    for hex_id in alert_hexes:
        ac_hist = positions[positions["hex"] == hex_id].sort_values("snapshot_time")
        if len(ac_hist) < 2 or ac_hist["lat"].isna().all():
            continue

        line_color = ALERT_LINE_COLOR.get(alert_hex_map.get(hex_id, ""), "#888888")
        label   = (ac_hist["flight"].dropna().iloc[-1]
                   if not ac_hist["flight"].dropna().empty else hex_id)
        ac_type = (ac_hist["type"].dropna().iloc[-1]
                   if not ac_hist["type"].dropna().empty else "—")

        fig.add_trace(go.Scattermapbox(
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

    # Non-alert aircraft dots ──────────────────────────────────────────────
    normal_ac = latest_positions[~latest_positions["hex"].isin(alert_hexes)]
    if not normal_ac.empty:
        fig.add_trace(go.Scattermapbox(
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

    # Alert aircraft dots ──────────────────────────────────────────────────
    alert_ac = latest_positions[latest_positions["hex"].isin(alert_hexes)]
    if not alert_ac.empty:
        fig.add_trace(go.Scattermapbox(
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

    # Alert centroid markers ───────────────────────────────────────────────
    if not alerts.empty:
        cen = alerts.dropna(subset=["centroid_lat", "centroid_lon"])
        if not cen.empty:
            fig.add_trace(go.Scattermapbox(
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
        ew_latest = ew_latest.drop_duplicates(subset=["hex"], keep="first")
        if not ew_latest.empty:
            ew_in_snap = ew_latest[ew_latest["hex"].isin(set(latest_positions["hex"]))]
            if ew_in_snap.empty:
                ew_in_snap = ew_latest
            fig.add_trace(go.Scattermapbox(
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

    fig.update_layout(
        mapbox=dict(
            style="open-street-map",
            center=dict(lat=center_lat, lon=center_lon),
            zoom=3,
        ),
        height=800,
        margin=dict(l=0, r=0, t=0, b=0),
        legend=dict(
            bgcolor="rgba(0,0,0,0.6)",
            font=dict(color="white"),
            x=0.01, y=0.99,
        ),
        paper_bgcolor="#0e1117",
    )

    return fig


# ── Session state init ────────────────────────────────────────────────────────

if "cr_last_refresh" not in st.session_state:
    st.session_state.cr_last_refresh        = time.time()
    st.session_state.cr_prev_critical_ids   = set()

# ── Guard: DB must exist ──────────────────────────────────────────────────────

if not DB_PATH.exists():
    st.error(f"Database not found at `{DB_PATH}`. Run `python monitor.py` first.")
    st.stop()

# ── Inject CSS ────────────────────────────────────────────────────────────────

st.markdown(CONTROL_ROOM_CSS, unsafe_allow_html=True)

# ── Load data ─────────────────────────────────────────────────────────────────

positions                                       = load_positions(60)
alerts                                          = load_alerts(100)
ew_df                                           = load_ew_contacts(hours=24)
last_update, ac_24h, ac_now, alerts_24h, nttr, uttr = load_kpis()
qc_summary, _                                   = load_qc_data()

# ── New CRITICAL detection ────────────────────────────────────────────────────

current_critical_ids = (
    set(alerts[alerts["severity"] == "CRITICAL"]["id"].tolist())
    if not alerts.empty and "id" in alerts.columns
    else set()
)
new_criticals = current_critical_ids - st.session_state.cr_prev_critical_ids
st.session_state.cr_prev_critical_ids = current_critical_ids

# ── Audio beep for new CRITICAL alerts ───────────────────────────────────────

if new_criticals:
    components.html(AUDIO_ALERT_HTML, height=0)

# ── Title row + countdown ─────────────────────────────────────────────────────

elapsed    = time.time() - st.session_state.cr_last_refresh
secs_left  = max(0, int(60 - elapsed))

title_col, countdown_col = st.columns([5, 1])
with title_col:
    st.markdown(
        '<span class="cr-title">🎯 CONTROL ROOM — Military Flight Activity Monitor</span>',
        unsafe_allow_html=True,
    )
with countdown_col:
    st.markdown(
        f'<div class="cr-countdown">Next refresh in <b>{secs_left}s</b></div>',
        unsafe_allow_html=True,
    )

# ── KPI Bar (8 columns) ───────────────────────────────────────────────────────

k1, k2, k3, k4, k5, k6, k7, k8 = st.columns(8)

_kpi(k1, "Aircraft Now",    ac_now)
_kpi(k2, "Aircraft 24h",    ac_24h)
_kpi(k3, "Alerts 24h",      alerts_24h, color="#FF851B" if alerts_24h > 0 else "#ffffff")
_kpi(k4, "NTTR Active 2h",  nttr)
_kpi(k5, "UTTR Active 2h",  uttr)

_ew_count = len(ew_df) if not ew_df.empty else 0
_kpi(k6, "EW/ISR 24h",      _ew_count, color="#00FF41" if _ew_count > 0 else "#ffffff")

_qc_flag  = qc_summary.get("latest_flag") or "—"
_qc_color = QC_FLAG_COLOR.get(_qc_flag, "#888888")
_kpi(k7, "Feed Quality",    _qc_flag, color=_qc_color)

_last_str = (last_update[11:16] + " UTC" if last_update and last_update != "—" else "—")
_kpi(k8, "Last Update",     _last_str)

st.markdown("---")

# ── Main layout: map (3/4) | alert feed (1/4) ─────────────────────────────────

map_col, feed_col = st.columns([3, 1])

# ── Map ───────────────────────────────────────────────────────────────────────

with map_col:
    if positions.empty:
        st.info("No position data yet. Run `python monitor.py` to start collecting.")
    else:
        fig_map = _build_map(positions, alerts, ew_df)
        st.plotly_chart(fig_map, use_container_width=True)
        st.caption(
            "⚫ Gray = military, no alert  |  🟠 Orange = in active alert  |  "
            "Colored lines = flight paths  |  Colored circles = alert centroids  |  "
            "🟢 Green triangle = EW / ISR"
        )

# ── Alert feed + EW contacts ──────────────────────────────────────────────────

with feed_col:
    st.markdown(
        '<div style="font-size:0.8rem; color:#aaa; text-transform:uppercase; '
        'letter-spacing:0.1em; margin-bottom:8px;">ALERT FEED</div>',
        unsafe_allow_html=True,
    )

    if alerts.empty:
        st.markdown(
            '<div style="color:#555; font-size:0.85rem;">No alerts.</div>',
            unsafe_allow_html=True,
        )
    else:
        # CRITICAL alerts — max 5
        criticals = alerts[alerts["severity"] == "CRITICAL"].head(5)
        for _, row in criticals.iterrows():
            summary_text = str(row.get("summary") or "").strip()
            if len(summary_text) > 100:
                summary_text = summary_text[:97] + "..."
            atype = str(row.get("alert_type") or "").replace("_", " ")
            st.markdown(
                f'<div class="cr-alert-critical">'
                f'<b style="color:#FF4136">CRITICAL</b> · {atype}<br>'
                f'<span style="font-size:0.8rem; color:#ccc">{summary_text}</span>'
                f'</div>',
                unsafe_allow_html=True,
            )

        # HIGH alerts — max 8
        highs = alerts[alerts["severity"] == "HIGH"].head(8)
        for _, row in highs.iterrows():
            summary_text = str(row.get("summary") or "").strip()
            if len(summary_text) > 100:
                summary_text = summary_text[:97] + "..."
            atype = str(row.get("alert_type") or "").replace("_", " ")
            st.markdown(
                f'<div class="cr-alert-high">'
                f'<b style="color:#FF851B">HIGH</b> · {atype}<br>'
                f'<span style="font-size:0.8rem; color:#ccc">{summary_text}</span>'
                f'</div>',
                unsafe_allow_html=True,
            )

    # EW contacts section
    st.markdown(
        '<div style="font-size:0.8rem; color:#aaa; text-transform:uppercase; '
        'letter-spacing:0.1em; margin-top:16px; margin-bottom:8px;">EW / ISR CONTACTS</div>',
        unsafe_allow_html=True,
    )

    if ew_df.empty:
        st.markdown(
            '<div style="color:#555; font-size:0.85rem;">No EW contacts.</div>',
            unsafe_allow_html=True,
        )
    else:
        top_ew = ew_df.head(5)
        for _, row in top_ew.iterrows():
            callsign   = str(row.get("flight") or row.get("hex") or "—").strip()
            confidence = str(row.get("ew_confidence") or "—").strip()
            role       = str(row.get("ew_role") or "—").strip()
            conf_color = (
                "#00FF41" if confidence == "CONFIRMED"
                else "#FFDC00" if confidence == "PROBABLE"
                else "#aaa"
            )
            st.markdown(
                f'<div class="cr-ew-contact">'
                f'<b style="color:#00FF41">{callsign}</b> '
                f'<span style="color:{conf_color}; font-size:0.75rem">[{confidence}]</span><br>'
                f'<span style="color:#aaa; font-size:0.75rem">{role}</span>'
                f'</div>',
                unsafe_allow_html=True,
            )

# ── Auto-refresh logic ────────────────────────────────────────────────────────

time.sleep(1)
elapsed = time.time() - st.session_state.cr_last_refresh
if elapsed >= 60:
    st.session_state.cr_last_refresh = time.time()
    st.cache_data.clear()
st.rerun()
