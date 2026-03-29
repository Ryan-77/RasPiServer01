"""
Correlator — combines raw detections (ghosts, clusters, orbits) into named events.
Each event maps to a row in the alerts table.
"""

import math
import reverse_geocoder as rg

EARTH_RADIUS_NM = 3440.065
ORBIT_CLUSTER_RADIUS_NM = 100   # orbit + cluster within this = refueling candidate
GHOST_CLUSTER_RADIUS_NM = 150   # ghost must be this close to a cluster to correlate

CC_NAMES = {
    "US": "United States", "AU": "Australia", "JP": "Japan", "GB": "United Kingdom",
    "CA": "Canada", "DE": "Germany", "FR": "France", "KR": "South Korea",
    "IT": "Italy", "NL": "Netherlands", "NO": "Norway", "SE": "Sweden",
    "DK": "Denmark", "BE": "Belgium", "GR": "Greece", "TR": "Turkey",
    "PL": "Poland", "ES": "Spain", "PT": "Portugal", "NZ": "New Zealand",
    "SA": "Saudi Arabia", "AE": "UAE", "IL": "Israel", "IN": "India",
    "SG": "Singapore", "TH": "Thailand", "PH": "Philippines", "QA": "Qatar",
}

# Each entry: (set of type codes that match, description string)
TYPE_ACTIVITY = [
    ({"KC135", "KC46", "KC10", "A330", "DC10"},        "aerial refueling"),
    ({"F16", "F35", "F15", "FA18", "F18", "EF2000",
      "GRPN", "F22"},                                   "fighter operations"),
    ({"PC21", "HAWK", "T45", "T38", "MB339", "L159"},  "advanced jet trainer activity"),
    ({"H60", "H53S", "H47", "CH47", "MH60"},           "helicopter assault/transport ops"),
    ({"P1", "P8", "P3", "ATL2"},                        "maritime patrol"),
    ({"C130", "C30J", "C17", "C5M", "A400"},           "airlift/logistics"),
    ({"BE20", "B350", "RC12", "E8", "E3CF", "E7"},     "ISR/reconnaissance"),
    ({"B52", "B1", "B2", "B21"},                        "strategic bomber operations"),
]


def _haversine_nm(lat1, lon1, lat2, lon2):
    lat1, lon1, lat2, lon2 = map(math.radians, [lat1, lon1, lat2, lon2])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    a = math.sin(dlat / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin(dlon / 2) ** 2
    return 2 * EARTH_RADIUS_NM * math.asin(math.sqrt(a))


def _location_desc(lat, lon, types):
    """
    Returns a human-readable location + activity string.
    e.g. "San Diego, United States (US) — helicopter assault/transport ops"
    """
    try:
        result = rg.search([(lat, lon)], verbose=False)[0]
        city = result.get("name", "Unknown")
        cc = result.get("cc", "??")
        country = CC_NAMES.get(cc, cc)
        location = f"{city}, {country} ({cc})"
    except Exception:
        location = f"{lat:.2f}, {lon:.2f}"

    # Match types against activity lookup (longest intersection wins)
    best_match = None
    best_count = 0
    type_set = set(types)
    for keywords, description in TYPE_ACTIVITY:
        overlap = len(keywords & type_set)
        if overlap > best_count:
            best_count = overlap
            best_match = description

    activity = best_match or "military activity"
    return location, activity


def correlate(ghosts, clusters, orbits, ew_contacts=None):
    """
    Returns list of alert dicts ready for db.insert_alert().
    Each dict: alert_type, severity, region, summary, detail,
               aircraft_hexes, centroid_lat, centroid_lon
    """
    events = []
    used_orbits = set()
    used_clusters = set()

    # ── EW asset alerts — highest priority, processed first ──────────────────
    if ew_contacts:
        confirmed = [e for e in ew_contacts if e["ew_confidence"] == "CONFIRMED"]
        probable  = [e for e in ew_contacts if e["ew_confidence"] == "PROBABLE"]
        possible  = [e for e in ew_contacts if e["ew_confidence"] == "POSSIBLE"]

        for contact in confirmed + probable:
            severity = "CRITICAL" if contact["ew_confidence"] == "CONFIRMED" else "HIGH"
            label    = contact.get("flight") or contact["hex"]
            lat      = contact.get("lat")
            lon      = contact.get("lon")

            location = f"{lat:.2f}, {lon:.2f}" if lat is not None and lon is not None else "unknown"
            try:
                import reverse_geocoder as rg
                if lat is not None and lon is not None:
                    result = rg.search([(lat, lon)], verbose=False)[0]
                    city   = result.get("name", "Unknown")
                    cc     = result.get("cc", "??")
                    country = CC_NAMES.get(cc, cc)
                    location = f"{city}, {country} ({cc})"
            except Exception:
                pass

            basis = contact.get("ew_basis") or "type/behavioral match"
            events.append({
                "alert_type":     "EW_ASSET_ACTIVITY",
                "severity":       severity,
                "region":         None,
                "summary": (
                    f"{contact['ew_confidence']} EW/ISR contact: {label} "
                    f"— {contact['ew_role']} near {location}"
                ),
                "detail": (
                    f"Location: {location}. "
                    f"Aircraft {label} ({contact.get('type') or 'unknown type'}) "
                    f"classified as {contact['ew_role']}. "
                    f"Confidence: {contact['ew_confidence']}. Basis: {basis}. "
                    f"Alt: {contact.get('alt_baro') or '—'}, "
                    f"Speed: {contact.get('gs') or '—'} kts."
                ),
                "aircraft_hexes": [contact["hex"]],
                "centroid_lat":   lat,
                "centroid_lon":   lon,
            })

        if possible:
            lats = [e["lat"] for e in possible if e.get("lat") is not None]
            lons = [e["lon"] for e in possible if e.get("lon") is not None]
            c_lat = sum(lats) / len(lats) if lats else None
            c_lon = sum(lons) / len(lons) if lons else None

            location = "multiple locations"
            try:
                import reverse_geocoder as rg
                if c_lat is not None and c_lon is not None:
                    result = rg.search([(c_lat, c_lon)], verbose=False)[0]
                    city   = result.get("name", "Unknown")
                    cc     = result.get("cc", "??")
                    country = CC_NAMES.get(cc, cc)
                    location = f"{city}, {country} ({cc})"
            except Exception:
                pass

            events.append({
                "alert_type":     "EW_ASSET_ACTIVITY",
                "severity":       "MEDIUM",
                "region":         None,
                "summary": (
                    f"{len(possible)} POSSIBLE EW/ISR contact(s) detected "
                    f"near {location}"
                ),
                "detail": (
                    f"Location: {location}. "
                    f"{len(possible)} aircraft with behavioral indicators consistent "
                    f"with EW/ISR mission profiles (score 0.35–0.54). "
                    f"Hexes: {', '.join(e['hex'] for e in possible)}."
                ),
                "aircraft_hexes": [e["hex"] for e in possible],
                "centroid_lat":   c_lat,
                "centroid_lon":   c_lon,
            })

    # ── Orbit + nearby cluster = probable refueling or CAP ───────────────────
    for oi, orbit in enumerate(orbits):
        for ci, cluster in enumerate(clusters):
            dist = _haversine_nm(
                orbit["centroid_lat"], orbit["centroid_lon"],
                cluster["centroid_lat"], cluster["centroid_lon"],
            )
            if dist <= ORBIT_CLUSTER_RADIUS_NM:
                location, activity = _location_desc(
                    orbit["centroid_lat"], orbit["centroid_lon"], cluster["types"]
                )
                types_str = ", ".join(cluster["types"]) if cluster["types"] else "unknown type"
                events.append({
                    "alert_type": "PROBABLE_REFUELING_OP",
                    "severity": "CRITICAL",
                    "region": None,
                    "summary": (
                        f"Orbit + {cluster['count']}-ship cluster {dist:.0f}nm away "
                        f"near {location} — possible refueling op or CAP"
                    ),
                    "detail": (
                        f"Location: {location} | Activity: {activity}. "
                        f"Orbit: {orbit.get('flight') or orbit['hex']} "
                        f"({orbit.get('type') or 'unknown'}) at "
                        f"{orbit['centroid_lat']:.3f}, {orbit['centroid_lon']:.3f}, "
                        f"radius {orbit['radius_nm']}nm, {orbit['direction']}. "
                        f"Cluster: {cluster['count']} aircraft ({types_str}), "
                        f"avg speed {cluster['avg_gs']:.0f}kts."
                        if cluster.get("avg_gs") else ""
                    ),
                    "aircraft_hexes": [orbit["hex"]] + cluster["hex_list"],
                    "centroid_lat": orbit["centroid_lat"],
                    "centroid_lon": orbit["centroid_lon"],
                })
                used_orbits.add(oi)
                used_clusters.add(ci)

    # ── Ghost(s) within 150nm of a cluster = sensitive asset activity ─────────
    ghost_cluster_pairs = []
    for ghost in ghosts:
        g_lat, g_lon = ghost.get("lat"), ghost.get("lon")
        if g_lat is None or g_lon is None:
            continue
        for cluster in clusters:
            dist = _haversine_nm(g_lat, g_lon, cluster["centroid_lat"], cluster["centroid_lon"])
            if dist <= GHOST_CLUSTER_RADIUS_NM:
                ghost_cluster_pairs.append((ghost, cluster, dist))

    if ghost_cluster_pairs:
        nearby_ghosts = list({p[0]["hex"]: p[0] for p in ghost_cluster_pairs}.values())
        nearby_clusters = list({id(p[1]): p[1] for p in ghost_cluster_pairs}.values())
        g_lat = sum(g["lat"] for g in nearby_ghosts if g.get("lat")) / len(nearby_ghosts)
        g_lon = sum(g["lon"] for g in nearby_ghosts if g.get("lon")) / len(nearby_ghosts)
        location, _ = _location_desc(g_lat, g_lon, [])
        events.append({
            "alert_type": "SENSITIVE_ASSET_ACTIVITY",
            "severity": "HIGH",
            "region": None,
            "summary": (
                f"{len(nearby_ghosts)} unidentified contact(s) near formation "
                f"at {location}"
            ),
            "detail": (
                f"Location: {location}. "
                f"{len(nearby_ghosts)} ghost contact(s) (no callsign/reg/type) within "
                f"150nm of {len(nearby_clusters)} cluster(s). "
                f"Ghost hexes: {', '.join(g['hex'] for g in nearby_ghosts)}."
            ),
            "aircraft_hexes": [g["hex"] for g in nearby_ghosts] +
                              [h for c in nearby_clusters for h in c["hex_list"]],
            "centroid_lat": g_lat,
            "centroid_lon": g_lon,
        })

    # ── Remaining orbits (no nearby cluster) ─────────────────────────────────
    for oi, orbit in enumerate(orbits):
        if oi not in used_orbits:
            location, _ = _location_desc(orbit["centroid_lat"], orbit["centroid_lon"], [])
            events.append({
                "alert_type": "HOLDING_ORBIT",
                "severity": "HIGH",
                "region": None,
                "summary": (
                    f"{orbit.get('flight') or orbit['hex']} in sustained "
                    f"{orbit['direction']} orbit near {location}"
                ),
                "detail": (
                    f"Location: {location}. "
                    f"Aircraft {orbit.get('flight') or orbit['hex']} "
                    f"({orbit.get('type') or 'unknown type'}) has completed "
                    f"{orbit['cumulative_heading_deg']:.0f}° of heading rotation, "
                    f"radius {orbit['radius_nm']}nm."
                ),
                "aircraft_hexes": [orbit["hex"]],
                "centroid_lat": orbit["centroid_lat"],
                "centroid_lon": orbit["centroid_lon"],
            })

    # ── Remaining clusters ────────────────────────────────────────────────────
    for ci, cluster in enumerate(clusters):
        if ci in used_clusters:
            continue
        avg_gs = cluster.get("avg_gs") or 0
        location, activity = _location_desc(
            cluster["centroid_lat"], cluster["centroid_lon"], cluster["types"]
        )
        types_str = ", ".join(cluster["types"]) if cluster["types"] else "unknown"

        if cluster["count"] >= 5 and avg_gs > 350:
            events.append({
                "alert_type": "ATTACK_PACKAGE",
                "severity": "HIGH",
                "region": None,
                "summary": (
                    f"{cluster['count']}-ship high-speed formation near {location} "
                    f"— possible strike package"
                ),
                "detail": (
                    f"Location: {location} | Activity: {activity}. "
                    f"{cluster['count']} aircraft ({types_str}) at {avg_gs:.0f}kts avg."
                ),
                "aircraft_hexes": cluster["hex_list"],
                "centroid_lat": cluster["centroid_lat"],
                "centroid_lon": cluster["centroid_lon"],
            })
        else:
            speed_str = f", avg speed {avg_gs:.0f}kts" if avg_gs else ""
            events.append({
                "alert_type": "FORMATION_ACTIVITY",
                "severity": "MEDIUM",
                "region": None,
                "summary": (
                    f"{cluster['count']}-ship formation near {location}"
                ),
                "detail": (
                    f"Location: {location} | Activity: {activity}. "
                    f"{cluster['count']} aircraft ({types_str}){speed_str}."
                ),
                "aircraft_hexes": cluster["hex_list"],
                "centroid_lat": cluster["centroid_lat"],
                "centroid_lon": cluster["centroid_lon"],
            })

    # ── Ghost-only alert (no nearby clusters) ────────────────────────────────
    if ghosts and not ghost_cluster_pairs:
        ghost_hexes = [g["hex"] for g in ghosts]
        severity = "HIGH" if len(ghosts) >= 3 else "LOW"
        events.append({
            "alert_type": "UNIDENTIFIED_CONTACTS",
            "severity": severity,
            "region": None,
            "summary": f"{len(ghosts)} ghost contact(s) — no callsign, registration, or type",
            "detail": (
                f"{len(ghosts)} aircraft broadcasting position only. "
                f"Hexes: {', '.join(ghost_hexes)}."
            ),
            "aircraft_hexes": ghost_hexes,
            "centroid_lat": None,
            "centroid_lon": None,
        })

    return events
