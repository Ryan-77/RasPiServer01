"""
Correlator — combines raw detections (ghosts, clusters, orbits) into named events.
Each event maps to a row in the alerts table.
"""

import math
import reverse_geocoder as rg

EARTH_RADIUS_NM = 3440.065
ORBIT_CLUSTER_RADIUS_NM = 20    # tightened from 40 — real pre-contact refueling
                                 # stacks are within ~10-15nm of the tanker track;
                                 # 40nm was grouping aircraft at separate locations
GHOST_CLUSTER_RADIUS_NM = 60    # tightened from 150 — ghost must be in the same
                                 # airspace as the formation, not just the same region

# How far the tanker's most recent position may be from its historical orbit centroid
# before the orbit is considered stale. A tanker that has departed the orbit area
# should not trigger a refueling alert against a coincidentally nearby cluster.
ORBIT_STALE_NM = 35

# Receiver cluster speed range for refueling validation (kts).
# Receivers must be flying at tanker-compatible speeds. A cluster of slow props or
# trainers near a KC-135 orbit is not a refueling op.
REFUEL_SPEED_MIN_KTS = 180
REFUEL_SPEED_MAX_KTS = 380

# Tanker types — only these orbiting aircraft trigger PROBABLE_REFUELING_OP
TANKER_TYPES = {"KC135", "KC46", "KC10", "A330", "DC10"}

# Trainer types — routine circuits at bases; HOLDING_ORBIT alerts downgraded to LOW
TRAINER_TYPES = {"T38", "TEX2", "T45", "T6", "PC21", "HAWK", "MB339", "L159", "BE40"}

# Fighter/attack types required for ATTACK_PACKAGE — prevents civilian high-speed
# formations from triggering CRITICAL alerts
FIGHTER_TYPES = {
    "F16", "F35", "F15", "FA18", "F18", "EF2000", "GRPN", "F22",
    "A10", "F117", "B1", "B52", "B2", "B21",
}

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


def _activity_for_types(types):
    """Return the best activity description for a set of type codes."""
    best_match = None
    best_count = 0
    type_set = set(types)
    for keywords, description in TYPE_ACTIVITY:
        overlap = len(keywords & type_set)
        if overlap > best_count:
            best_count = overlap
            best_match = description
    return best_match or "military activity"


def _geocode_many(coords):
    """
    Batch-geocode a list of (lat, lon) tuples.
    Returns a list of location strings in the same order.
    Falls back to 'lat, lon' strings on error.
    """
    if not coords:
        return []
    try:
        results = rg.search(coords, verbose=False)
        out = []
        for i, result in enumerate(results):
            city    = result.get("name", "Unknown")
            cc      = result.get("cc", "??")
            country = CC_NAMES.get(cc, cc)
            out.append(f"{city}, {country} ({cc})")
        return out
    except Exception:
        return [f"{lat:.2f}, {lon:.2f}" for lat, lon in coords]


def _location_desc(lat, lon, types):
    """
    Returns a human-readable (location, activity) tuple for a single point.
    Used by callers that only need one lookup.
    """
    locs = _geocode_many([(lat, lon)])
    location = locs[0] if locs else f"{lat:.2f}, {lon:.2f}"
    activity = _activity_for_types(types)
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

    # ── Batch geocode all coordinates upfront (one rg.search call) ───────────
    _gc_coords = []   # (lat, lon) in insertion order
    _gc_tags   = []   # string tag for each coord, used to build lookup dict

    def _add_coord(tag, lat, lon):
        if lat is not None and lon is not None:
            _gc_coords.append((lat, lon))
            _gc_tags.append(tag)

    for i, o in enumerate(orbits):
        _add_coord(f"orbit_{i}", o["centroid_lat"], o["centroid_lon"])
    for i, c in enumerate(clusters):
        _add_coord(f"cluster_{i}", c["centroid_lat"], c["centroid_lon"])
    if ew_contacts:
        for i, e in enumerate(ew_contacts):
            if e.get("lat") is not None and e.get("lon") is not None:
                _add_coord(f"ew_{i}", e["lat"], e["lon"])
        # Centroid for POSSIBLE EW group
        possible_pre = [e for e in ew_contacts if e["ew_confidence"] == "POSSIBLE"]
        _pc_lats = [e["lat"] for e in possible_pre if e.get("lat") is not None]
        _pc_lons = [e["lon"] for e in possible_pre if e.get("lon") is not None]
        _pc_lat  = sum(_pc_lats) / len(_pc_lats) if _pc_lats else None
        _pc_lon  = sum(_pc_lons) / len(_pc_lons) if _pc_lons else None
        _add_coord("ew_possible_centroid", _pc_lat, _pc_lon)
    # Centroid for ghost group
    _ghost_lats = [g["lat"] for g in ghosts if g.get("lat") is not None]
    _ghost_lons = [g["lon"] for g in ghosts if g.get("lon") is not None]
    _ghost_clat = sum(_ghost_lats) / len(_ghost_lats) if _ghost_lats else None
    _ghost_clon = sum(_ghost_lons) / len(_ghost_lons) if _ghost_lons else None
    _add_coord("ghost_centroid", _ghost_clat, _ghost_clon)

    _gc_results = _geocode_many(_gc_coords)
    _gc_map = dict(zip(_gc_tags, _gc_results))

    def _loc(tag, lat, lon):
        """Return cached geocode result, or coordinate fallback."""
        return _gc_map.get(tag, f"{lat:.2f}, {lon:.2f}" if lat is not None and lon is not None else "unknown")

    # ── EW asset alerts — highest priority, processed first ──────────────────
    if ew_contacts:
        confirmed = [e for e in ew_contacts if e["ew_confidence"] == "CONFIRMED"]
        probable  = [e for e in ew_contacts if e["ew_confidence"] == "PROBABLE"]
        possible  = [e for e in ew_contacts if e["ew_confidence"] == "POSSIBLE"]

        for i, contact in enumerate(confirmed + probable):
            severity = "CRITICAL" if contact["ew_confidence"] == "CONFIRMED" else "HIGH"
            label    = contact.get("flight") or contact["hex"]
            lat      = contact.get("lat")
            lon      = contact.get("lon")
            # find original index in ew_contacts for cache key
            ew_idx   = ew_contacts.index(contact) if ew_contacts else i
            location = _loc(f"ew_{ew_idx}", lat, lon)

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

            location = _loc("ew_possible_centroid", c_lat, c_lon) if (c_lat is not None and c_lon is not None) else "multiple locations"

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
    # Only tanker-type aircraft qualify — non-tanker orbits fall through to HOLDING_ORBIT.
    # Three additional gates prevent false positives:
    #   1. Staleness — tanker's most recent position must still be near the orbit centroid.
    #      Orbits are detected from 60-min history; a tanker that left the area 45 min ago
    #      would still pass the orbit detector but is clearly not refueling anyone now.
    #   2. Proximity — cluster centroid must be within ORBIT_CLUSTER_RADIUS_NM (20nm).
    #   3. Speed — cluster avg speed must be in the refueling envelope (180–380 kts).
    #      A cluster of slow trainers or helicopters near a tanker orbit is not a refueling op.
    for oi, orbit in enumerate(orbits):
        orbit_type = (orbit.get("type") or "").upper().strip()
        if orbit_type not in TANKER_TYPES:
            continue

        # Gate 1 — staleness check. If last_lat/last_lon is available (added in circular.py),
        # verify the tanker is still in the orbit area. If it has departed, skip.
        last_lat = orbit.get("last_lat")
        last_lon = orbit.get("last_lon")
        if last_lat is not None and last_lon is not None:
            dist_from_centroid = _haversine_nm(
                last_lat, last_lon,
                orbit["centroid_lat"], orbit["centroid_lon"],
            )
            if dist_from_centroid > ORBIT_STALE_NM:
                continue  # tanker has left the orbit area — orbit is stale

        for ci, cluster in enumerate(clusters):
            # Gate 2 — proximity
            dist = _haversine_nm(
                orbit["centroid_lat"], orbit["centroid_lon"],
                cluster["centroid_lat"], cluster["centroid_lon"],
            )
            if dist > ORBIT_CLUSTER_RADIUS_NM:
                continue

            # Gate 3 — receiver speed in refueling envelope
            avg_gs = cluster.get("avg_gs")
            if avg_gs is not None and not (REFUEL_SPEED_MIN_KTS <= avg_gs <= REFUEL_SPEED_MAX_KTS):
                continue  # cluster flying at incompatible speed for refueling

            location = _loc(f"orbit_{oi}", orbit["centroid_lat"], orbit["centroid_lon"])
            activity = _activity_for_types(cluster["types"])
            types_str = ", ".join(cluster["types"]) if cluster["types"] else "unknown type"
            speed_str = f", avg speed {avg_gs:.0f}kts" if avg_gs else ""
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
                    f"Cluster: {cluster['count']} aircraft ({types_str}){speed_str}."
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
        location = _loc("ghost_centroid", g_lat, g_lon)
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
                f"60nm of {len(nearby_clusters)} cluster(s). "
                f"Ghost hexes: {', '.join(g['hex'] for g in nearby_ghosts)}."
            ),
            "aircraft_hexes": [g["hex"] for g in nearby_ghosts] +
                              [h for c in nearby_clusters for h in c["hex_list"]],
            "centroid_lat": g_lat,
            "centroid_lon": g_lon,
        })

    # ── Remaining orbits (no nearby cluster) ─────────────────────────────────
    # Severity is type- and quality-driven:
    #   TRAINER_TYPES doing circuits = LOW  (expected, routine at training bases)
    #   All others with >= 720° (2 full loops) = HIGH  (sustained, deliberate pattern)
    #   All others with < 720°  = MEDIUM  (one loop — worth noting but not urgent)
    # Tanker types without a matching cluster are always HIGH — a tanker orbiting
    # without receivers is still operationally significant.
    for oi, orbit in enumerate(orbits):
        if oi not in used_orbits:
            location = _loc(f"orbit_{oi}", orbit["centroid_lat"], orbit["centroid_lon"])
            orbit_type = (orbit.get("type") or "").upper().strip()
            cumulative = orbit.get("cumulative_heading_deg", 0)

            if orbit_type in TRAINER_TYPES:
                severity = "LOW"
            elif orbit_type in TANKER_TYPES or cumulative >= 720:
                severity = "HIGH"
            else:
                severity = "MEDIUM"

            events.append({
                "alert_type": "HOLDING_ORBIT",
                "severity": severity,
                "region": None,
                "summary": (
                    f"{orbit.get('flight') or orbit['hex']} in sustained "
                    f"{orbit['direction']} orbit near {location}"
                ),
                "detail": (
                    f"Location: {location}. "
                    f"Aircraft {orbit.get('flight') or orbit['hex']} "
                    f"({orbit.get('type') or 'unknown type'}) has completed "
                    f"{cumulative:.0f}° of heading rotation, "
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
        location = _loc(f"cluster_{ci}", cluster["centroid_lat"], cluster["centroid_lon"])
        activity = _activity_for_types(cluster["types"])
        types_str = ", ".join(cluster["types"]) if cluster["types"] else "unknown"

        type_set = set(cluster.get("types") or [])
        has_fighter = bool(type_set & FIGHTER_TYPES)
        if cluster["count"] >= 5 and avg_gs > 350 and has_fighter:
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
