#!/usr/bin/env python3
"""Receive a tested DEV artifact over a restricted GitHub Actions SSH key.

Install this file as root-owned /usr/local/sbin/hoanam-github-dev-receiver.py.
Each authorized key pins exactly one component (be or fe) in its forced command.
Stdin is a 40-character source SHA, newline, then a gzip tar archive.
"""

import datetime
import fcntl
import hashlib
import json
import os
import pathlib
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.request

HOST = "14.225.37.158"
FE_URL = "https://khohoanamfe.lptech.info.vn"
BE_HEALTH = "http://127.0.0.1:8080/api/v1/health"
MAX_ARCHIVE_BYTES = 300 * 1024 * 1024
MAX_UNPACKED_BYTES = 900 * 1024 * 1024


def run(*args):
    result = subprocess.run(args, text=True, stdout=subprocess.PIPE,
                            stderr=subprocess.STDOUT, check=False)
    if result.returncode:
        raise RuntimeError(f"{args[0]} failed ({result.returncode}): {result.stdout[-2000:]}")
    return result.stdout


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def read_json(url):
    with urllib.request.urlopen(url, timeout=10) as response:
        return json.load(response)


def receive_archive(base):
    first = sys.stdin.buffer.readline(65).decode("ascii", errors="strict").strip()
    if not re.fullmatch(r"[0-9a-f]{40}", first):
        raise ValueError("Expected exact 40-character source SHA")
    with tempfile.NamedTemporaryFile(prefix="github-dev-", suffix=".tar.gz",
                                     dir=base / "releases", delete=False) as output:
        archive = pathlib.Path(output.name)
        try:
            size = 0
            while chunk := sys.stdin.buffer.read(1024 * 1024):
                size += len(chunk)
                if size > MAX_ARCHIVE_BYTES:
                    raise ValueError("Artifact exceeds size limit")
                output.write(chunk)
            if not size:
                raise ValueError("Empty artifact")
        except Exception:
            archive.unlink(missing_ok=True)
            raise
    return first, archive


def unpack(archive, base, sha):
    incoming = pathlib.Path(tempfile.mkdtemp(prefix=".incoming-", dir=base / "releases"))
    try:
        with tarfile.open(archive, "r:gz") as bundle:
            members = bundle.getmembers()
            total = 0
            for member in members:
                name = pathlib.PurePosixPath(member.name)
                if name.is_absolute() or ".." in name.parts or not (member.isfile() or member.isdir()):
                    raise ValueError(f"Unsafe archive member: {member.name}")
                total += member.size
                if total > MAX_UNPACKED_BYTES:
                    raise ValueError("Unpacked artifact exceeds size limit")
            bundle.extractall(incoming, members=members, filter="data")
        stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        release = base / "releases" / f"{sha}-github-{stamp}"
        if release.exists():
            raise FileExistsError(release)
        incoming.rename(release)
        return release
    finally:
        if incoming.exists():
            shutil.rmtree(incoming)


def point_frontend(base, target):
    pending = base / (".current-" + target.name)
    if pending.exists() or pending.is_symlink():
        pending.unlink()
    pending.symlink_to(os.path.relpath(target, base))
    os.replace(pending, base / "current")


def deploy_frontend(base, release, sha):
    manifest = json.loads((release / "build-manifest.json").read_text())
    health = json.loads((release / "health.json").read_text())
    if manifest.get("sourceSha") != sha or manifest.get("environment") != "dev":
        raise ValueError("Frontend artifact SHA/environment mismatch")
    if health.get("source_sha") != sha or not (release / "index.html").is_file():
        raise ValueError("Frontend health/index artifact mismatch")
    # mkdtemp creates the release root as 0700; nginx must traverse it.
    release.chmod(0o755)
    old = (base / "current").resolve(strict=True)
    point_frontend(base, release)
    try:
        for attempt in range(20):
            try:
                live = read_json(FE_URL + "/build-manifest.json")
                live_health = read_json(FE_URL + "/health.json")
                if live.get("sourceSha") == sha and live_health.get("source_sha") == sha:
                    return {"component": "fe", "sha": sha, "release": str(release),
                            "previous_release": str(old), "health": "ok"}
            except Exception:
                pass
            time.sleep(2)
        raise RuntimeError("Frontend live health did not reach target SHA")
    except Exception:
        point_frontend(base, old)
        raise


def migration_files(release):
    paths = list((release / "database" / "migrations").glob("*.php"))
    paths += list((release / "Modules").glob("*/database/migrations/*.php"))
    return {str(path.relative_to(release)): sha256(path) for path in paths}


def compose(model, *args):
    return run("docker", "compose", "-f", str(model), *args)


def wait_backend(sha):
    for attempt in range(60):
        try:
            result = read_json(BE_HEALTH)
            if result.get("data", {}).get("build", {}).get("sha") == sha:
                return
        except Exception:
            pass
        time.sleep(2)
    raise RuntimeError("Backend live health did not reach target SHA")


def deploy_backend(base, release, sha):
    if (release / ".ci-source-sha").read_text().strip() != sha:
        raise ValueError("Backend artifact SHA mismatch")
    # PHP-FPM runs as www-data inside the bind-mounted release.
    release.chmod(0o755)
    model = base / "compose.json"
    app_env = base / "shared" / "app.env"
    config = json.loads(model.read_text())
    services = ["backend", "nginx", "queue", "scheduler", "reverb"]
    old_paths = set()
    for name in services:
        old_paths.update(pathlib.Path(volume["source"]) for volume in
                         config["services"][name].get("volumes", [])
                         if isinstance(volume, dict) and volume.get("target") == "/var/www/html")
    if len(old_paths) != 1:
        raise ValueError("Backend services do not share one release")
    old = old_paths.pop().resolve(strict=True)
    if sha256(release / "composer.lock") != sha256(old / "composer.lock"):
        raise ValueError("composer.lock changed; vendor volume needs a separate reviewed release")
    if migration_files(release) != migration_files(old):
        raise ValueError("Migrations changed; database release needs a separate reviewed migration")
    if (old / "public" / "build").exists() and not (release / "public" / "build").exists():
        shutil.copytree(old / "public" / "build", release / "public" / "build")
    (release / ".env").touch()
    for path in ["vendor", "storage", "bootstrap/cache"]:
        (release / path).mkdir(parents=True, exist_ok=True)
    link = release / "public" / "storage"
    if not link.exists() and not link.is_symlink():
        link.symlink_to("../storage/app/public")
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup = base / "backups" / ("github-dev-" + stamp)
    backup.mkdir(mode=0o700)
    shutil.copy2(model, backup / "compose.json")
    shutil.copy2(app_env, backup / "app.env")
    changed = False
    stopped = False
    try:
        compose(model, "stop", "queue", "scheduler", "reverb")
        stopped = True
        for name in services:
            for volume in config["services"][name].get("volumes", []):
                if isinstance(volume, dict) and volume.get("target") == "/var/www/html":
                    volume["source"] = str(release)
        lines = [line for line in app_env.read_text().splitlines()
                 if not line.startswith(("APP_BUILD_SHA=", "APP_BUILD_REF=", "APP_BUILD_TIME="))]
        lines += [f"APP_BUILD_SHA={sha}", f"APP_BUILD_REF=dev@{sha}",
                  "APP_BUILD_TIME=" + datetime.datetime.now(datetime.timezone.utc).isoformat()]
        changed = True
        app_env.write_text("\n".join(lines) + "\n")
        model.write_text(json.dumps(config, indent=2) + "\n")
        compose(model, "up", "-d", "--force-recreate", *services)
        wait_backend(sha)
        run("docker", "exec", "-u", "www-data", "hoa_nam_dev-backend-1",
            "php", "artisan", "route:cache")
        wait_backend(sha)
        report = {"component": "be", "sha": sha, "release": str(release),
                  "previous_release": str(old), "backup": str(backup),
                  "health": "ok", "migration_run": False, "seed_run": False}
        (backup / "deployment.json").write_text(json.dumps(report, indent=2) + "\n")
        return report
    except Exception:
        if changed:
            shutil.copy2(backup / "compose.json", model)
            shutil.copy2(backup / "app.env", app_env)
            compose(model, "up", "-d", "--force-recreate", *services)
        elif stopped:
            compose(model, "up", "-d", "queue", "scheduler", "reverb")
        raise


def main():
    if len(sys.argv) != 2 or sys.argv[1] not in {"be", "fe"}:
        raise ValueError("Expected pinned component")
    component = sys.argv[1]
    base = pathlib.Path("/opt/wms-hoanam" if component == "be" else "/opt/wms-hoanam-fe")
    with open("/run/lock/hoanam-github-dev.lock", "w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        sha, archive = receive_archive(base)
        try:
            release = unpack(archive, base, sha)
            report = (deploy_backend(base, release, sha) if component == "be"
                      else deploy_frontend(base, release, sha))
            print(json.dumps(report, sort_keys=True), flush=True)
        finally:
            archive.unlink(missing_ok=True)


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(f"DEV_DEPLOY_FAILED: {error}", file=sys.stderr)
        sys.exit(1)
