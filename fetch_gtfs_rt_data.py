#!/usr/bin/env python3
"""Build NFTA transit cache data from static GTFS plus optional GTFS-RT feeds."""

from __future__ import annotations

import argparse
import copy
import csv
import io
import json
import math
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from collections import defaultdict
from dataclasses import dataclass
from datetime import date, datetime, time, timedelta
from zoneinfo import ZoneInfo

from google.transit import gtfs_realtime_pb2


METERS_PER_MILE = 1609.344
EASTERN_TIMEZONE = ZoneInfo("America/New_York")
DEFAULT_LOOKAHEAD_MINUTES = 180
DEFAULT_GTFS_ZIP = "https://metro.nfta.com/__googletransit/google_transit.zip"
DEFAULT_RAIL_GTFS_ZIP = "https://metro.nfta.com/__googletransit/rail/google_transit.zip"


@dataclass(frozen=True)
class StopRecord:
    stop_id: str
    stop_name: str
    stop_lat: float
    stop_lon: float


@dataclass(frozen=True)
class RouteRecord:
    route_id: str
    route_short_name: str
    route_long_name: str
    route_type: str
    route_color: str


@dataclass(frozen=True)
class TripRecord:
    trip_id: str
    route_id: str
    service_id: str
    headsign: str
    direction_id: str


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Build GTFS-RT-based NFTA cache data from static GTFS and optional realtime feeds."
    )
    parser.add_argument("--lat", required=True, type=float, help="Latitude for the raw nearby cache")
    parser.add_argument("--lon", required=True, type=float, help="Longitude for the raw nearby cache")
    parser.add_argument("--radius-miles", required=True, type=float, help="Search radius in miles")
    parser.add_argument("--output", required=True, help="Output JSON cache path")
    parser.add_argument("--gtfs-zip", default=DEFAULT_GTFS_ZIP, help="Path to the static GTFS zip file")
    parser.add_argument(
        "--rail-gtfs-zip",
        default=DEFAULT_RAIL_GTFS_ZIP,
        help="Optional path to the rail-only static GTFS zip file",
    )
    parser.add_argument(
        "--trip-updates-url",
        default="",
        help="GTFS-RT Trip Updates URL or local .pb file path",
    )
    parser.add_argument(
        "--vehicle-positions-url",
        default="",
        help="GTFS-RT Vehicle Positions URL or local .pb file path",
    )
    parser.add_argument(
        "--alerts-url",
        default="",
        help="GTFS-RT Alerts URL or local .pb file path",
    )
    parser.add_argument(
        "--station-config",
        default="",
        help="Optional stations.json path; defaults to stations.json next to the output file",
    )
    parser.add_argument(
        "--lookahead-minutes",
        type=int,
        default=DEFAULT_LOOKAHEAD_MINUTES,
        help="How many minutes ahead to include in the cache",
    )
    parser.add_argument(
        "--now-epoch",
        type=int,
        default=0,
        help="Optional current Unix epoch override for testing",
    )
    return parser.parse_args()


def ensure_parent_dir(file_path: str) -> None:
    parent = os.path.dirname(os.path.abspath(file_path))
    if parent:
        os.makedirs(parent, exist_ok=True)


def slugify(value: str) -> str:
    lowered = value.strip().lower()
    slug_chars = [ch if ch.isalnum() else "-" for ch in lowered]
    slug = "".join(slug_chars)
    while "--" in slug:
        slug = slug.replace("--", "-")
    return slug.strip("-") or "station"


def miles_to_meters(miles: float) -> float:
    return miles * METERS_PER_MILE


def haversine_meters(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    earth_radius_m = 6371000.0
    lat1_rad = math.radians(lat1)
    lat2_rad = math.radians(lat2)
    delta_lat = math.radians(lat2 - lat1)
    delta_lon = math.radians(lon2 - lon1)

    a = (
        math.sin(delta_lat / 2.0) ** 2
        + math.cos(lat1_rad) * math.cos(lat2_rad) * math.sin(delta_lon / 2.0) ** 2
    )
    c = 2.0 * math.atan2(math.sqrt(a), math.sqrt(1.0 - a))
    return earth_radius_m * c


def normalize_url_or_path(value: str) -> str:
    normalized = value.strip()
    if not normalized:
        return ""
    if normalized.startswith(("http://", "https://")):
        return normalized
    return os.path.abspath(normalized)


def load_binary_source(source: str) -> bytes:
    if not source:
        return b""

    normalized = normalize_url_or_path(source)
    if normalized.startswith(("http://", "https://")):
        request = urllib.request.Request(
            normalized,
            headers={
                "Accept": "*/*",
                "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0 Safari/537.36",
            },
            method="GET",
        )
        try:
            with urllib.request.urlopen(request, timeout=20) as response:
                return response.read()
        except urllib.error.HTTPError as exc:
            body = exc.read().decode("utf-8", errors="replace").strip()
            raise RuntimeError(body or f"HTTP {exc.code} while fetching {normalized}") from exc
        except urllib.error.URLError as exc:
            raise RuntimeError(f"Network error while fetching {normalized}: {exc.reason}") from exc

    try:
        with open(normalized, "rb") as handle:
            return handle.read()
    except OSError as exc:
        raise RuntimeError(f"Unable to read {normalized}: {exc}") from exc


def load_gtfs_rows(zip_file: zipfile.ZipFile, member_name: str) -> list[dict[str, str]]:
    with zip_file.open(member_name) as handle:
        reader = csv.DictReader(io.TextIOWrapper(handle, encoding="utf-8-sig", newline=""))
        return [dict(row) for row in reader]


def load_optional_gtfs_rows(zip_file: zipfile.ZipFile, member_name: str) -> list[dict[str, str]]:
    try:
        return load_gtfs_rows(zip_file, member_name)
    except KeyError:
        return []


def open_gtfs_zip(source: str) -> tuple[zipfile.ZipFile, io.BytesIO | None]:
    normalized = normalize_url_or_path(source)
    if normalized.startswith(("http://", "https://")):
        payload = load_binary_source(normalized)
        buffer = io.BytesIO(payload)
        return zipfile.ZipFile(buffer), buffer
    return zipfile.ZipFile(normalized), None


def parse_stops(zip_file: zipfile.ZipFile) -> dict[str, StopRecord]:
    stops: dict[str, StopRecord] = {}
    for row in load_gtfs_rows(zip_file, "stops.txt"):
        stop_id = (row.get("stop_id") or "").strip()
        if not stop_id:
            continue
        try:
            stops[stop_id] = StopRecord(
                stop_id=stop_id,
                stop_name=(row.get("stop_name") or stop_id).strip(),
                stop_lat=float(row.get("stop_lat") or "0"),
                stop_lon=float(row.get("stop_lon") or "0"),
            )
        except ValueError:
            continue
    return stops


def parse_routes(zip_file: zipfile.ZipFile) -> dict[str, RouteRecord]:
    routes: dict[str, RouteRecord] = {}
    for row in load_gtfs_rows(zip_file, "routes.txt"):
        route_id = (row.get("route_id") or "").strip()
        if not route_id:
            continue
        routes[route_id] = RouteRecord(
            route_id=route_id,
            route_short_name=(row.get("route_short_name") or "").strip(),
            route_long_name=(row.get("route_long_name") or "").strip(),
            route_type=(row.get("route_type") or "").strip(),
            route_color=(row.get("route_color") or "").strip().lower(),
        )
    return routes


def parse_trips(zip_file: zipfile.ZipFile) -> dict[str, TripRecord]:
    trips: dict[str, TripRecord] = {}
    for row in load_gtfs_rows(zip_file, "trips.txt"):
        trip_id = (row.get("trip_id") or "").strip()
        if not trip_id:
            continue
        trips[trip_id] = TripRecord(
            trip_id=trip_id,
            route_id=(row.get("route_id") or "").strip(),
            service_id=(row.get("service_id") or "").strip(),
            headsign=(row.get("trip_headsign") or "").strip(),
            direction_id=(row.get("direction_id") or "").strip(),
        )
    return trips


def parse_calendar(zip_file: zipfile.ZipFile) -> list[dict[str, str]]:
    return load_optional_gtfs_rows(zip_file, "calendar.txt")


def parse_calendar_dates(zip_file: zipfile.ZipFile) -> list[dict[str, str]]:
    return load_optional_gtfs_rows(zip_file, "calendar_dates.txt")


def merge_stop_records(target: dict[str, StopRecord], source: dict[str, StopRecord]) -> None:
    target.update(source)


def merge_route_records(target: dict[str, RouteRecord], source: dict[str, RouteRecord]) -> None:
    target.update(source)


def merge_trip_records(target: dict[str, TripRecord], source: dict[str, TripRecord]) -> None:
    target.update(source)


def service_ids_for_date(
    target_date: date,
    calendar_rows: list[dict[str, str]],
    calendar_date_rows: list[dict[str, str]],
) -> set[str]:
    service_ids: set[str] = set()
    weekday_names = [
        "monday",
        "tuesday",
        "wednesday",
        "thursday",
        "friday",
        "saturday",
        "sunday",
    ]
    weekday_key = weekday_names[target_date.weekday()]
    target_key = target_date.strftime("%Y%m%d")

    for row in calendar_rows:
        service_id = (row.get("service_id") or "").strip()
        if not service_id:
            continue
        if (row.get(weekday_key) or "0").strip() != "1":
            continue
        start_date = (row.get("start_date") or "").strip()
        end_date = (row.get("end_date") or "").strip()
        if start_date and target_key < start_date:
            continue
        if end_date and target_key > end_date:
            continue
        service_ids.add(service_id)

    for row in calendar_date_rows:
        if (row.get("date") or "").strip() != target_key:
            continue
        service_id = (row.get("service_id") or "").strip()
        exception_type = (row.get("exception_type") or "").strip()
        if not service_id:
            continue
        if exception_type == "1":
            service_ids.add(service_id)
        elif exception_type == "2":
            service_ids.discard(service_id)

    return service_ids


def parse_gtfs_time_to_seconds(value: str) -> int | None:
    raw = value.strip()
    if not raw:
        return None
    pieces = raw.split(":")
    if len(pieces) != 3:
        return None
    try:
        hours = int(pieces[0])
        minutes = int(pieces[1])
        seconds = int(pieces[2])
    except ValueError:
        return None
    if minutes < 0 or minutes >= 60 or seconds < 0 or seconds >= 60 or hours < 0:
        return None
    return hours * 3600 + minutes * 60 + seconds


def route_mode_name(route_type: str) -> str:
    if route_type == "0":
        return "Light Rail"
    if route_type == "3":
        return "Bus"
    return "Transit"


def parse_trip_updates(source: str) -> tuple[dict[tuple[str, str], dict[str, object]], set[str]]:
    updates: dict[tuple[str, str], dict[str, object]] = {}
    canceled_trip_ids: set[str] = set()
    payload = load_binary_source(source)
    if not payload:
        return updates, canceled_trip_ids

    feed = gtfs_realtime_pb2.FeedMessage()
    feed.ParseFromString(payload)

    for entity in feed.entity:
        if not entity.HasField("trip_update"):
            continue
        trip_update = entity.trip_update
        trip_id = trip_update.trip.trip_id.strip()
        if not trip_id:
            continue
        if trip_update.trip.schedule_relationship == gtfs_realtime_pb2.TripDescriptor.CANCELED:
            canceled_trip_ids.add(trip_id)
            continue

        for stop_time_update in trip_update.stop_time_update:
            stop_id = stop_time_update.stop_id.strip()
            if not stop_id:
                continue
            if stop_time_update.schedule_relationship == gtfs_realtime_pb2.TripUpdate.StopTimeUpdate.SKIPPED:
                updates[(trip_id, stop_id)] = {"skipped": True}
                continue

            arrival = stop_time_update.arrival
            departure = stop_time_update.departure
            arrival_time = int(arrival.time) if arrival and arrival.time else 0
            departure_time = int(departure.time) if departure and departure.time else 0
            delay_seconds = 0
            if arrival and arrival.delay:
                delay_seconds = int(arrival.delay)
            elif departure and departure.delay:
                delay_seconds = int(departure.delay)

            updates[(trip_id, stop_id)] = {
                "arrival_time": arrival_time,
                "departure_time": departure_time,
                "delay_seconds": delay_seconds,
                "is_realtime": bool(arrival_time or departure_time or delay_seconds),
            }

    return updates, canceled_trip_ids


def parse_vehicle_positions(source: str) -> dict[str, dict[str, object]]:
    vehicles: dict[str, dict[str, object]] = {}
    payload = load_binary_source(source)
    if not payload:
        return vehicles

    feed = gtfs_realtime_pb2.FeedMessage()
    feed.ParseFromString(payload)

    for entity in feed.entity:
        if not entity.HasField("vehicle"):
            continue
        vehicle = entity.vehicle
        trip_id = vehicle.trip.trip_id.strip()
        if not trip_id:
            continue
        vehicles[trip_id] = {
            "vehicle_id": vehicle.vehicle.id.strip() if vehicle.vehicle.id else "",
            "vehicle_label": vehicle.vehicle.label.strip() if vehicle.vehicle.label else "",
            "timestamp": int(vehicle.timestamp) if vehicle.timestamp else 0,
            "stop_id": vehicle.stop_id.strip() if vehicle.stop_id else "",
            "current_status": int(vehicle.current_status),
            "latitude": float(vehicle.position.latitude) if vehicle.position.latitude else 0.0,
            "longitude": float(vehicle.position.longitude) if vehicle.position.longitude else 0.0,
            "bearing": float(vehicle.position.bearing) if vehicle.position.bearing else 0.0,
        }
    return vehicles


def parse_alerts(source: str) -> dict[str, list[dict[str, str]]]:
    alerts_by_route: dict[str, list[dict[str, str]]] = defaultdict(list)
    payload = load_binary_source(source)
    if not payload:
        return alerts_by_route

    feed = gtfs_realtime_pb2.FeedMessage()
    feed.ParseFromString(payload)

    for entity in feed.entity:
        if not entity.HasField("alert"):
            continue
        alert = entity.alert
        title = ""
        description = ""
        if alert.header_text.translation:
            title = alert.header_text.translation[0].text.strip()
        if alert.description_text.translation:
            description = alert.description_text.translation[0].text.strip()

        informed_routes = set()
        for informed_entity in alert.informed_entity:
            route_id = informed_entity.route_id.strip()
            if route_id:
                informed_routes.add(route_id)

        if not informed_routes:
            continue

        payload_item = {"title": title, "description": description}
        for route_id in informed_routes:
            alerts_by_route[route_id].append(payload_item)

    return alerts_by_route


def load_json_file(path: str) -> object:
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle)


def write_payload(output_path: str, payload: object) -> None:
    ensure_parent_dir(output_path)
    temp_path = output_path + ".tmp"
    with open(temp_path, "w", encoding="utf-8", newline="\n") as handle:
        json.dump(payload, handle, indent=2, ensure_ascii=False)
        handle.write("\n")
    os.replace(temp_path, output_path)


def station_route_tokens(station: dict) -> set[str]:
    tokens: set[str] = set()
    for route in station.get("routes", []):
        if route is None:
            continue
        tokens.add(str(route).strip().upper())
    return tokens


def route_matches_station(route: dict, station_routes: set[str]) -> bool:
    if not station_routes:
        return True

    candidates = {
        str(route.get("route_short_name", "")).strip().upper(),
        str(route.get("real_time_route_id", "")).strip().upper(),
        str(route.get("route_long_name", "")).strip().upper(),
    }
    candidates.discard("")
    return bool(candidates & station_routes)


def itinerary_within_station_distance(itinerary: dict, station_lat: float, station_lon: float, max_distance_meters: float) -> bool:
    closest_stop = itinerary.get("closest_stop") or {}
    stop_lat = closest_stop.get("stop_lat")
    stop_lon = closest_stop.get("stop_lon")
    if stop_lat is None or stop_lon is None:
        return False

    try:
        distance_meters = haversine_meters(station_lat, station_lon, float(stop_lat), float(stop_lon))
    except (TypeError, ValueError):
        return False

    return distance_meters <= max_distance_meters


def build_station_payload(network_payload: dict, station: dict) -> dict:
    station_name = str(station.get("name", "")).strip() or "Station"
    station_lat = float(station["latitude"])
    station_lon = float(station["longitude"])
    max_distance_meters = miles_to_meters(float(station.get("distance", 0)))
    station_routes = station_route_tokens(station)

    filtered_routes = []
    for route in network_payload.get("routes", []):
        if not isinstance(route, dict):
            continue
        if not route_matches_station(route, station_routes):
            continue

        itineraries = []
        for itinerary in route.get("itineraries", []):
            if not isinstance(itinerary, dict):
                continue
            if itinerary_within_station_distance(itinerary, station_lat, station_lon, max_distance_meters):
                itinerary_copy = copy.deepcopy(itinerary)
                if itinerary_copy.get("schedule_items"):
                    itineraries.append(itinerary_copy)

        if itineraries:
            route_copy = copy.deepcopy(route)
            route_copy["itineraries"] = itineraries
            filtered_routes.append(route_copy)

    return {
        "station": {
            "name": station_name,
            "latitude": str(station.get("latitude", "")),
            "longitude": str(station.get("longitude", "")),
            "distance": str(station.get("distance", "")),
            "routes": [str(route) for route in station.get("routes", [])],
        },
        "routes": filtered_routes,
    }


def write_station_files(network_payload: dict, output_path: str, station_config_path: str) -> list[str]:
    if not station_config_path or not os.path.isfile(station_config_path):
        return []

    stations = load_json_file(station_config_path)
    if not isinstance(stations, list):
        raise RuntimeError("stations.json must contain a JSON array")

    output_dir = os.path.dirname(os.path.abspath(output_path))
    written_files: list[str] = []
    for station in stations:
        if not isinstance(station, dict):
            continue
        if "latitude" not in station or "longitude" not in station or "distance" not in station:
            continue

        station_payload = build_station_payload(network_payload, station)
        station_name = str(station.get("name", "station"))
        station_file = os.path.join(output_dir, f"{slugify(station_name)}_gtfs_rt_transit_data.json")
        write_payload(station_file, station_payload)
        written_files.append(station_file)

    return written_files


def build_network_payload(
    gtfs_zip_paths: list[str],
    now_local: datetime,
    lookahead_minutes: int,
    trip_updates: dict[tuple[str, str], dict[str, object]],
    canceled_trip_ids: set[str],
    vehicle_positions: dict[str, dict[str, object]],
    alerts_by_route: dict[str, list[dict[str, str]]],
) -> dict:
    stops: dict[str, StopRecord] = {}
    routes: dict[str, RouteRecord] = {}
    trips: dict[str, TripRecord] = {}
    calendar_rows: list[dict[str, str]] = []
    calendar_date_rows: list[dict[str, str]] = []
    stop_time_rows: list[dict[str, str]] = []

    for gtfs_zip_path in gtfs_zip_paths:
        gtfs_zip, buffer = open_gtfs_zip(gtfs_zip_path)
        try:
            with gtfs_zip:
                merge_stop_records(stops, parse_stops(gtfs_zip))
                merge_route_records(routes, parse_routes(gtfs_zip))
                merge_trip_records(trips, parse_trips(gtfs_zip))
                calendar_rows.extend(parse_calendar(gtfs_zip))
                calendar_date_rows.extend(parse_calendar_dates(gtfs_zip))
                stop_time_rows.extend(load_gtfs_rows(gtfs_zip, "stop_times.txt"))
        finally:
            if buffer is not None:
                buffer.close()

    candidate_dates = {
        now_local.date() - timedelta(days=1),
        now_local.date(),
        (now_local + timedelta(minutes=lookahead_minutes)).date(),
    }
    service_ids_by_date = {
        candidate_date: service_ids_for_date(candidate_date, calendar_rows, calendar_date_rows)
        for candidate_date in candidate_dates
    }

    now_epoch = int(now_local.timestamp())
    latest_epoch = int((now_local + timedelta(minutes=lookahead_minutes)).timestamp())
    route_groups: dict[str, dict[str, object]] = {}

    itinerary_events: dict[tuple[str, str, str], list[dict[str, object]]] = defaultdict(list)

    for row in stop_time_rows:
            trip_id = (row.get("trip_id") or "").strip()
            stop_id = (row.get("stop_id") or "").strip()
            if not trip_id or not stop_id:
                continue
            if trip_id in canceled_trip_ids:
                continue

            trip = trips.get(trip_id)
            stop = stops.get(stop_id)
            if trip is None or stop is None or trip.route_id not in routes:
                continue

            arrival_seconds = parse_gtfs_time_to_seconds(row.get("arrival_time") or row.get("departure_time") or "")
            if arrival_seconds is None:
                continue

            matched_epoch: int | None = None
            for candidate_date, active_service_ids in service_ids_by_date.items():
                if trip.service_id not in active_service_ids:
                    continue
                candidate_datetime = datetime.combine(candidate_date, time.min, EASTERN_TIMEZONE) + timedelta(seconds=arrival_seconds)
                candidate_epoch = int(candidate_datetime.timestamp())
                if now_epoch <= candidate_epoch <= latest_epoch:
                    matched_epoch = candidate_epoch
                    break

            if matched_epoch is None:
                continue

            update = trip_updates.get((trip_id, stop_id), {})
            if update.get("skipped"):
                continue
            realtime_epoch = int(update.get("arrival_time") or update.get("departure_time") or 0)
            delay_seconds = int(update.get("delay_seconds") or 0)
            final_epoch = realtime_epoch or matched_epoch + delay_seconds
            if final_epoch < now_epoch:
                continue

            itinerary_key = (trip.route_id, trip.headsign or stop.stop_name, stop_id)
            event = {
                "arrival_time": final_epoch,
                "scheduled_arrival_time": matched_epoch,
                "delay_seconds": final_epoch - matched_epoch,
                "is_real_time": bool(update.get("is_realtime") or realtime_epoch or delay_seconds),
                "trip_id": trip_id,
            }
            vehicle = vehicle_positions.get(trip_id)
            if vehicle:
                event["vehicle"] = vehicle
            itinerary_events[itinerary_key].append(event)

    for (route_id, headsign, stop_id), events in itinerary_events.items():
        route = routes[route_id]
        stop = stops[stop_id]
        route_payload = route_groups.setdefault(
            route_id,
            {
                "route_id": route.route_id,
                "route_short_name": route.route_short_name,
                "route_long_name": route.route_long_name,
                "real_time_route_id": route.route_short_name or route.route_id,
                "route_color": route.route_color,
                "mode_name": route_mode_name(route.route_type),
                "alerts": copy.deepcopy(alerts_by_route.get(route_id, [])),
                "itineraries": [],
            },
        )

        deduped: list[dict[str, object]] = []
        seen_keys: set[tuple[int, str]] = set()
        for event in sorted(events, key=lambda item: int(item["arrival_time"])):
            dedupe_key = (int(event["arrival_time"]), str(event.get("trip_id") or ""))
            if dedupe_key in seen_keys:
                continue
            seen_keys.add(dedupe_key)
            deduped.append(event)

        route_payload["itineraries"].append(
            {
                "direction_headsign": headsign,
                "headsign": headsign,
                "closest_stop": {
                    "stop_id": stop.stop_id,
                    "stop_name": stop.stop_name,
                    "stop_lat": stop.stop_lat,
                    "stop_lon": stop.stop_lon,
                },
                "schedule_items": deduped,
            }
        )

    route_list = list(route_groups.values())
    for route in route_list:
        route["itineraries"].sort(
            key=lambda itinerary: min(
                (item.get("arrival_time", sys.maxsize) for item in itinerary.get("schedule_items", [])),
                default=sys.maxsize,
            )
        )
        for itinerary in route["itineraries"]:
            itinerary["schedule_items"] = itinerary.get("schedule_items", [])[:6]

    route_list.sort(
        key=lambda route: min(
            (
                item.get("arrival_time", sys.maxsize)
                for itinerary in route.get("itineraries", [])
                for item in itinerary.get("schedule_items", [])
            ),
            default=sys.maxsize,
        )
    )

    return {
        "generated_at_utc": datetime.now(tz=ZoneInfo("UTC")).isoformat(),
        "source": "gtfs-rt",
        "timezone": "America/New_York",
        "lookahead_minutes": lookahead_minutes,
        "routes": route_list,
    }


def filter_payload_by_center(payload: dict, lat: float, lon: float, radius_miles: float) -> dict:
    max_distance_meters = miles_to_meters(radius_miles)
    filtered_routes = []
    for route in payload.get("routes", []):
        if not isinstance(route, dict):
            continue
        itineraries = []
        for itinerary in route.get("itineraries", []):
            if not itinerary_within_station_distance(itinerary, lat, lon, max_distance_meters):
                continue
            itinerary_copy = copy.deepcopy(itinerary)
            if itinerary_copy.get("schedule_items"):
                itineraries.append(itinerary_copy)
        if itineraries:
            route_copy = copy.deepcopy(route)
            route_copy["itineraries"] = itineraries
            filtered_routes.append(route_copy)

    filtered_payload = copy.deepcopy(payload)
    filtered_payload["query"] = {
        "latitude": lat,
        "longitude": lon,
        "radius_miles": radius_miles,
    }
    filtered_payload["routes"] = filtered_routes
    return filtered_payload


def main() -> int:
    args = parse_args()
    if args.radius_miles <= 0:
        print("radius-miles must be greater than zero", file=sys.stderr)
        return 1
    if args.lookahead_minutes <= 0:
        print("lookahead-minutes must be greater than zero", file=sys.stderr)
        return 1

    gtfs_zip_paths = [normalize_url_or_path(args.gtfs_zip)]
    rail_gtfs_zip_path = normalize_url_or_path(args.rail_gtfs_zip)
    if rail_gtfs_zip_path:
        gtfs_zip_paths.append(rail_gtfs_zip_path)

    for gtfs_zip_path in gtfs_zip_paths:
        if not gtfs_zip_path.startswith(("http://", "https://")) and not os.path.isfile(gtfs_zip_path):
            print(f"Static GTFS zip not found: {gtfs_zip_path}", file=sys.stderr)
            return 1

    station_config_path = args.station_config.strip()
    if not station_config_path:
        station_config_path = os.path.join(os.path.dirname(os.path.abspath(args.output)), "stations.json")
    else:
        station_config_path = os.path.abspath(station_config_path)

    now_local = (
        datetime.fromtimestamp(args.now_epoch, tz=EASTERN_TIMEZONE)
        if args.now_epoch > 0
        else datetime.now(tz=EASTERN_TIMEZONE)
    )

    try:
        trip_updates, canceled_trip_ids = parse_trip_updates(args.trip_updates_url)
        vehicle_positions = parse_vehicle_positions(args.vehicle_positions_url)
        alerts_by_route = parse_alerts(args.alerts_url)
        network_payload = build_network_payload(
            gtfs_zip_paths=gtfs_zip_paths,
            now_local=now_local,
            lookahead_minutes=args.lookahead_minutes,
            trip_updates=trip_updates,
            canceled_trip_ids=canceled_trip_ids,
            vehicle_positions=vehicle_positions,
            alerts_by_route=alerts_by_route,
        )
        if not args.trip_updates_url.strip():
            network_payload["warning"] = "No GTFS-RT trip updates feed was provided; departures are schedule-based only."
        raw_payload = filter_payload_by_center(network_payload, args.lat, args.lon, args.radius_miles)
        write_payload(args.output, raw_payload)
        station_files = write_station_files(network_payload, args.output, station_config_path)
    except Exception as exc:  # pragma: no cover - CLI path
        print(str(exc), file=sys.stderr)
        return 1

    print(f"Saved raw GTFS-RT transit data to {args.output}")
    for station_file in station_files:
        print(f"Saved station transit data to {station_file}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())