#!/usr/bin/env python3
"""Partition PHPUnit discovery by file and verify executed case IDs, not estimates."""
import argparse
import collections
import copy
import json
import re
from pathlib import Path
import subprocess
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
REDIS_FILES = {"BinanceObservationRedisLockIntegrationTest.php", "PaperPipelineConcurrentWorkersIntegrationTest.php"}


def discover(path):
    result = {}
    for node in ET.parse(path).getroot().iter():
        if node.tag.split("}")[-1] == "testClass":
            file = "tests/" + node.attrib["file"].split("/tests/", 1)[1]
            result[file] = [child.attrib["id"] for child in node
                            if child.tag.split("}")[-1] == "testMethod"]
    if not result or any(not ids for ids in result.values()):
        raise ValueError("Empty or unsupported PHPUnit discovery")
    return result


def partition(files, timings):
    groups = {"unit": 2, "feature": 4, "postgres": 2, "redis": 1}
    lanes = {f"{group}-{i + 1}": [] for group, count in groups.items() for i in range(count)}
    loads = dict.fromkeys(lanes, 0.0)
    for file in sorted(files, key=lambda p: (-timings.get(p, len(files[p])), p)):
        if Path(file).name in REDIS_FILES:
            group = "redis"
        else:
            group = {"Unit": "unit", "Feature": "feature", "Integration": "postgres"}[file.split("/")[1]]
        lane = min((k for k in lanes if k.startswith(group + "-")), key=lambda k: (loads[k], k))
        lanes[lane].append(file)
        loads[lane] += timings.get(file, len(files[file]))
    if collections.Counter(p for paths in lanes.values() for p in paths) != collections.Counter(files.keys()):
        raise ValueError("Shard partition lost or duplicated a file")
    return lanes


def plan(discovery, output):
    files = discover(discovery)
    timings = json.loads((ROOT / "scripts/ci/php_timings.json").read_text())
    lanes = partition(files, timings)
    output.mkdir(parents=True, exist_ok=True)
    (output / "plan.json").write_text(json.dumps({"files": files, "lanes": lanes}, indent=2) + "\n")
    base = ET.parse(ROOT / "phpunit.xml")
    for lane, paths in lanes.items():
        if not paths:
            raise ValueError(f"Empty mandatory shard: {lane}")
        tree = copy.deepcopy(base)
        root = tree.getroot()
        root.set("bootstrap", str(ROOT / "vendor/autoload.php"))
        root.remove(root.find("testsuites"))
        suite = ET.SubElement(ET.SubElement(root, "testsuites"), "testsuite", name=lane)
        for path in sorted(paths):
            ET.SubElement(suite, "file").text = str(ROOT / path)
        source = root.find("source/include/directory")
        source.text = str(ROOT / "app")
        tree.write(output / f"{lane}.xml", encoding="utf-8", xml_declaration=True)
    print(json.dumps({"lane": list(lanes)}))


def verify(plan_path, reports):
    plan_data = json.loads(plan_path.read_text())
    expected = collections.Counter(case for ids in plan_data["files"].values() for case in ids)
    actual = collections.Counter()
    failures = []
    assertions = 0
    for lane in plan_data["lanes"]:
        path = reports / f"{lane}.xml"
        for case in ET.parse(path).getroot().iter("testcase"):
            method = re.sub(r' with data set "(.*)"$', r'#\1', case.attrib["name"])
            method = re.sub(r' with data set #(\d+)$', r'#\1', method)
            name = case.attrib["class"] + "::" + method
            actual[name] += 1
            assertions += int(case.get("assertions", "0"))
            if case.find("failure") is not None or case.find("error") is not None:
                failures.append(name)
            if case.find("skipped") is not None:
                # Existing opt-in public-network smoke is discovered on every run.
                if name != "Tests\\Integration\\BybitPublicApiIntegrationTest::test_public_linear_market_endpoints_match_the_documented_contract":
                    failures.append("Unexpected skip: " + name)
    missing, extra = expected - actual, actual - expected
    print(json.dumps({"discovered": sum(expected.values()), "executed": sum(actual.values()),
                      "assertions": assertions, "missing": dict(missing), "extra": dict(extra),
                      "failures": failures}, indent=2))
    if missing or extra or failures:
        raise ValueError("PHP regression inventory verification failed")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    p = commands.add_parser("plan")
    p.add_argument("discovery", type=Path)
    p.add_argument("output", type=Path)
    p = commands.add_parser("run")
    p.add_argument("lane")
    p.add_argument("--plan-dir", type=Path, default=Path("ci-plan"))
    p.add_argument("--reports", type=Path, default=Path("ci-results"))
    p = commands.add_parser("verify")
    p.add_argument("plan", type=Path)
    p.add_argument("reports", type=Path)
    args = parser.parse_args()
    if args.command == "plan":
        plan(args.discovery, args.output)
    elif args.command == "verify":
        verify(args.plan, args.reports)
    else:
        args.reports.mkdir(parents=True, exist_ok=True)
        # Regenerate paths for this runner; downloaded configs contain another runner's root.
        data = json.loads((args.plan_dir / "plan.json").read_text())
        if args.lane not in data["lanes"]:
            raise ValueError("Unknown shard")
        xml = args.plan_dir / f"{args.lane}.xml"
        tree = ET.parse(xml)
        for node in tree.getroot().iter():
            if node.tag == "phpunit":
                node.set("bootstrap", str(ROOT / "vendor/autoload.php"))
            if node.tag in ("file", "directory") and node.text:
                node.text = str(ROOT / ("tests/" + node.text.split("/tests/", 1)[1] if "/tests/" in node.text else "app"))
        tree.write(xml, encoding="utf-8", xml_declaration=True)
        subprocess.run(["php", "vendor/bin/phpunit", "-c", str(xml), "--colors=never", "--do-not-cache-result",
                        "--log-junit", str(args.reports / f"{args.lane}.xml")], cwd=ROOT, check=True)


if __name__ == "__main__":
    main()
