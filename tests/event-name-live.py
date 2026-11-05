"""Opt-in integration fixture for a local Zabbix, using only its API and sender protocol."""
import argparse
import collections
import http.cookiejar
from html.parser import HTMLParser
import json
import os
import re
import socket
import struct
import time
import urllib.parse
import urllib.request

HOST = "CPA Event Name Test"
TEMPLATE = "CPA Event Name Template"
GROUP = "Custom Problem Analysis tests"
KEY = "eventlog[System,,Error,,,,skip]"
DESCRIPTION = "Isolated Custom Problem Analysis test fixture; synthetic Windows log events."


class Zabbix:
    def __init__(self, args):
        self.url = args.url.rstrip("/")
        self.user = os.environ.get("ZABBIX_USER", "Admin")
        self.password = os.environ.get("ZABBIX_PASSWORD", "zabbix")
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = None

    def api(self, method, params):
        headers = {"Content-Type": "application/json-rpc"}
        if self.token:
            headers["Authorization"] = "Bearer " + self.token
        body = json.dumps({"jsonrpc": "2.0", "id": 1, "method": method, "params": params}).encode()
        request = urllib.request.Request(self.url + "/api_jsonrpc.php", body, headers)
        with self.http.open(request, timeout=30) as response:
            result = json.load(response)
        if "error" in result:
            raise RuntimeError(f"{method}: {result['error']['data']}")
        return result["result"]

    def login(self):
        self.token = self.api("user.login", {"username": self.user, "password": self.password})

    def host(self):
        hosts = self.api("host.get", {"output": ["hostid", "description"], "filter": {"host": HOST}})
        if not hosts or hosts[0]["description"] != DESCRIPTION:
            raise RuntimeError("Run setup first; refusing to use a host not owned by this fixture.")
        return hosts[0]

    def events(self):
        return self.api("event.get", {"output": ["eventid", "name", "clock", "objectid", "r_eventid"],
            "selectAcknowledges": ["action"],
            "hostids": [self.host()["hostid"]], "source": 0, "object": 0, "value": 1,
            "sortfield": "eventid", "sortorder": "DESC"})

    def tab(self, eventid, tab, **params):
        body = urllib.parse.urlencode({"eventid": eventid, "tab": tab, **params}, doseq=True).encode()
        request = urllib.request.Request(self.url + "/zabbix.php?action=problemanalist.tab.get", body)
        with self.http.open(request, timeout=30) as response:
            result = json.load(response)
        if "error" in result:
            raise RuntimeError(str(result["error"]))
        return result


def setup(zabbix):
    def group(method, field):
        groups = zabbix.api(method + ".get", {"output": [field], "filter": {"name": GROUP}})
        return groups[0][field] if groups else zabbix.api(method + ".create", {"name": GROUP})[field + "s"][0]

    templates = zabbix.api("template.get", {"output": ["templateid", "description"], "filter": {"host": TEMPLATE}})
    if templates and templates[0]["description"] != DESCRIPTION:
        raise RuntimeError("The template name is already in use by another configuration.")
    if templates:
        templateid = templates[0]["templateid"]
    else:
        templateid = zabbix.api("template.create", {"host": TEMPLATE, "description": DESCRIPTION,
            "groups": [{"groupid": group("templategroup", "groupid")}]})["templateids"][0]
        zabbix.api("item.create", {"hostid": templateid, "name": "Synthetic Windows System event log",
            "key_": KEY, "type": 2, "value_type": 2, "delay": "0", "history": "7d", "trends": "0"})
        zabbix.api("trigger.create", {"description": "Synthetic Windows error notification",
            "event_name": "Error Notification - ID {ITEM.LOG.EVENTID}",
            "expression": f"logseverity(/{TEMPLATE}/{KEY})=4", "priority": 2, "type": 1, "manual_close": 1,
            "tags": [{"tag": "component", "value": "cpa-event-name-test"}]})
    hosts = zabbix.api("host.get", {"output": ["hostid", "description"], "filter": {"host": HOST}})
    if not hosts:
        zabbix.api("host.create", {"host": HOST, "description": DESCRIPTION,
            "groups": [{"groupid": group("hostgroup", "groupid")}], "templates": [{"templateid": templateid}]})
    host = zabbix.host()
    print(json.dumps({"host": HOST, "hostid": host["hostid"], "template": TEMPLATE, "templateid": templateid}))


def receive(connection, length):
    data = b""
    while len(data) < length:
        part = connection.recv(length - len(data))
        if not part:
            raise RuntimeError("Incomplete sender response")
        data += part
    return data


def push(args, log_id, count):
    for index in range(count):
        clock = time.time_ns()
        record = {"host": HOST, "key": KEY, "value": f"Synthetic Windows error {log_id}, sample {index + 1}",
            "clock": clock // 1_000_000_000, "ns": clock % 1_000_000_000,
            "timestamp": clock // 1_000_000_000, "source": "CPA synthetic Windows System",
            "severity": 4, "eventid": log_id}
        payload = json.dumps({"request": "sender data", "data": [record]}).encode()
        with socket.create_connection((args.server, args.port), timeout=10) as connection:
            connection.sendall(b"ZBXD\x01" + struct.pack("<Q", len(payload)) + payload)
            header = receive(connection, 13)
            if header[:5] != b"ZBXD\x01":
                raise RuntimeError("Unexpected sender response header")
            result = json.loads(receive(connection, struct.unpack("<Q", header[5:])[0]))
        if result.get("response") != "success" or not re.search(r"failed: 0(?:;|$)", result.get("info", "")):
            raise RuntimeError(str(result))
        time.sleep(0.1)
    print(f"Pushed {count} log values with Windows event ID {log_id}.")


class NativeEventList(HTMLParser):
    def __init__(self, body):
        super().__init__()
        self.active, self.eventid, self.ids = False, None, []
        self.feed(body)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'section' and attrs.get('id') == 'hat_eventlist':
            self.active = True
        if self.active and tag == 'tr':
            self.eventid = None
        if self.active and tag == 'a' and self.eventid is None:
            url = urllib.parse.urlparse(attrs.get('href', ''))
            if url.path == 'tr_events.php':
                self.eventid = urllib.parse.parse_qs(url.query)['eventid'][0]

    def handle_endtag(self, tag):
        if self.active and tag == 'tr' and self.eventid is not None:
            self.ids.append(self.eventid)
        if self.active and tag == 'section':
            self.active = False


class Timeline(HTMLParser):
    def __init__(self, body):
        super().__init__()
        self.ids, self.row, self.cell = [], [], None
        self.highlighted = []
        self.more, self.monthly_count = {}, 0
        self.feed(body)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if "data-timeline-event" in attrs:
            self.ids.append(attrs["data-timeline-event"])
            if 'row-selected' in attrs.get('class', '').split():
                self.highlighted.append(attrs['data-timeline-event'])
        if "data-timeline-more" in attrs:
            self.more = {"has_more": "hidden" not in attrs, "cursor": {
                "eventid": attrs["data-before-eventid"], "clock": attrs["data-before-clock"],
                "group": attrs["data-before-group"]}}
        if tag == "tr":
            self.row = []
        if tag == "td":
            self.cell = ""

    def handle_data(self, data):
        if self.cell is not None:
            self.cell += data

    def handle_endtag(self, tag):
        if tag == "td":
            self.row.append(self.cell)
            self.cell = None
        if tag == "tr" and len(self.row) == 3 and self.row[1].isdigit():
            self.monthly_count += int(self.row[1])


def verify(zabbix):
    login = urllib.parse.urlencode({"name": zabbix.user, "password": zabbix.password, "enter": "Sign in"}).encode()
    with zabbix.http.open(zabbix.url + "/index.php", login, timeout=15) as response:
        response.read()
    events = zabbix.events()
    def verify_native_order(event):
        url = zabbix.url + '/tr_events.php?' + urllib.parse.urlencode({
            'triggerid': event['objectid'], 'eventid': event['eventid']})
        with zabbix.http.open(url, timeout=20) as response:
            native = NativeEventList(response.read().decode())
        matching = [entry for entry in events if entry['objectid'] == event['objectid']
            and int(entry['eventid']) <= int(event['eventid'])]
        expected = sorted(matching, key=lambda entry: (int(entry['clock']), int(entry['eventid'])), reverse=True)[:20]
        assert native.ids == [entry['eventid'] for entry in expected], 'Native previous-20 list must remain chronological'

    def load_timeline(event):
        first = Timeline(zabbix.tab(event["eventid"], "timeline")["body"])
        ids, highlighted, timeline = list(first.ids), list(first.highlighted), first.more
        while timeline["has_more"]:
            timeline = zabbix.tab(event["eventid"], "timeline_events", before_eventid=timeline["cursor"]["eventid"],
                before_clock=timeline["cursor"]["clock"], before_group=timeline["cursor"]["group"])
            page = Timeline(timeline["body"])
            ids.extend(page.ids)
            highlighted.extend(page.highlighted)
        assert highlighted == [event["eventid"]], ("Selected Timeline event", event["eventid"], highlighted)
        return first, ids

    expected = collections.Counter((event["objectid"], event["name"]) for event in events)
    if len({name for _, name in expected}) < 2:
        raise RuntimeError("Push at least two different log IDs before verifying.")
    parent = events[0]
    foreign = next(event for event in events if event["name"] != parent["name"])
    try:
        zabbix.tab(parent["eventid"], "timeline_events", before_eventid=foreign["eventid"], before_clock=foreign["clock"])
    except RuntimeError as error:
        if "Recent event not found or access denied" not in str(error):
            raise
    else:
        raise AssertionError("Timeline accepted a cursor from another resolved name")
    representatives = list({(event["objectid"], event["name"]): event for event in reversed(events)}.values())
    counts = {}
    for start in range(0, len(representatives), 40):
        batch = representatives[start:start + 40]
        counts.update(zabbix.tab(batch[0]["eventid"], "recurrences",
            **{"recurrence_eventids[]": [entry["eventid"] for entry in batch]})["counts"])
    for event in representatives:
        name = event["name"]
        count = expected[(event["objectid"], name)]
        assert int(counts[event["eventid"]]) == count, ("Recurrences", name, counts)
        first, ids = load_timeline(event)
        assert first.monthly_count == count, ("Monthly Comparison", name, first.monthly_count, count)
        assert len(ids) == count and len(set(ids)) == count, ("Timeline", name, len(ids), count)
        by_id = {entry["eventid"]: entry for entry in events}
        assert all(by_id[eventid]["name"] == name for eventid in ids), ("Mixed timeline", name)
        matching = [entry for entry in events if (entry["objectid"], entry["name"]) == (event["objectid"], name)]
        def timeline_order(entry):
            problem = entry["r_eventid"] == "0" and not any(int(ack["action"]) & 1 for ack in entry["acknowledges"])
            return (not problem, -int(entry["clock"]), -int(entry["eventid"]))
        assert ids == [entry["eventid"] for entry in sorted(matching, key=timeline_order)], ("Timeline order", name)
        oldest = min(matching, key=lambda entry: int(entry["eventid"]))
        assert load_timeline(oldest)[1] == ids, ("Older problem must include newer occurrences", name)
        verify_native_order(event)
        verify_native_order(oldest)
        for period in ['3', '6', '12']:
            summary = zabbix.tab(event["eventid"], 'monthly', comparison_period=period)
            assert Timeline(summary['body']).monthly_count == count, ("Monthly period", name, period)
            assert 'data-timeline-event' not in summary['body'], "Period changes must return only the comparison"
        patterns = zabbix.tab(event["eventid"], "patterns")
        assert patterns["pattern_data"]["total"] == count, ("Patterns", name, patterns)
        older_patterns = zabbix.tab(oldest['eventid'], 'patterns')['pattern_data']
        expected_patterns = sum(older_patterns['fromTs'] <= int(entry['clock']) <= older_patterns['toTs'] for entry in matching)
        assert older_patterns['total'] == expected_patterns, ('Older problem patterns must include newer occurrences', name)
        print(f"Verified {name}: Recurrences={count}, Timeline={len(ids)}, Monthly Comparison={count}, Time Patterns={count}")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", default="http://127.0.0.1")
    parser.add_argument("--server", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=10051)
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("setup")
    sender = commands.add_parser("push")
    sender.add_argument("log_id", type=int)
    sender.add_argument("--count", type=int, default=1)
    commands.add_parser("verify")
    args = parser.parse_args()
    if args.command == "push":
        if args.count < 1 or not 0 <= args.log_id <= 4294967295:
            parser.error("Use a positive count and an unsigned 32-bit log ID.")
        push(args, args.log_id, args.count)
        return
    zabbix = Zabbix(args)
    zabbix.login()
    try:
        setup(zabbix) if args.command == "setup" else verify(zabbix)
    finally:
        zabbix.api("user.logout", [])


if __name__ == "__main__":
    main()
