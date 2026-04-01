"""
Ghost detector — aircraft broadcasting position but suppressing all identity fields.
Callsign, registration, and type all null/empty = ghost contact.

Filters applied before flagging:
  - Must be airborne (alt_baro not "ground" / 0 / None)
  - Must have a valid lat/lon position
  - Must be moving (gs >= MIN_GHOST_SPEED_KTS) — eliminates ramp equipment and
    data artifacts that park with no identity fields populated
"""

MIN_GHOST_SPEED_KTS = 50  # below this = stationary, taxiing, or data artifact


def detect_ghosts(snapshot):
    """
    snapshot: list of dicts from api.adsb.lol
    Returns list of ghost dicts.
    """
    ghosts = []
    for a in snapshot:
        flight = (a.get("flight") or "").strip()
        registration = a.get("r")
        aircraft_type = a.get("t")

        if not flight and not registration and not aircraft_type:
            # Must be airborne
            alt_baro = a.get("alt_baro")
            if alt_baro is None or str(alt_baro).lower() in ("ground", "", "0", "none"):
                continue

            # Must have a valid position
            lat = a.get("lat")
            lon = a.get("lon")
            if lat is None or lon is None:
                continue

            # Must be moving — eliminates ramp equipment and stale data artifacts
            gs = a.get("gs")
            if gs is None or gs < MIN_GHOST_SPEED_KTS:
                continue

            ghosts.append({
                "hex": a.get("hex"),
                "lat": lat,
                "lon": lon,
                "alt_baro": alt_baro,
                "gs": gs,
            })

    return ghosts


def ghost_severity(ghosts):
    if len(ghosts) >= 3:
        return "HIGH"
    elif len(ghosts) >= 1:
        return "LOW"
    return None
