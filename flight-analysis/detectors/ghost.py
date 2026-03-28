"""
Ghost detector — aircraft broadcasting position but suppressing all identity fields.
Callsign, registration, and type all null/empty = ghost contact.
"""


def detect_ghosts(snapshot):
    """
    snapshot: list of dicts from api.adsb.lol
    Returns list of ghost dicts and a severity string.
    """
    ghosts = []
    for a in snapshot:
        flight = (a.get("flight") or "").strip()
        registration = a.get("r")
        aircraft_type = a.get("t")

        if not flight and not registration and not aircraft_type:
            ghosts.append({
                "hex": a.get("hex"),
                "lat": a.get("lat"),
                "lon": a.get("lon"),
                "alt_baro": a.get("alt_baro"),
                "gs": a.get("gs"),
            })

    return ghosts


def ghost_severity(ghosts):
    if len(ghosts) >= 3:
        return "HIGH"
    elif len(ghosts) >= 1:
        return "LOW"
    return None
