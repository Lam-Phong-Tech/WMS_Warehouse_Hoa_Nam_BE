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
import stat
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
    return {path.relative_to(release).as_posix(): sha256(path) for path in paths}


def check_reviewed_path_stat(path, result, *, directory):
    expected_kind = stat.S_ISDIR if directory else stat.S_ISREG
    if not expected_kind(result.st_mode) or result.st_uid != 0:
        raise ValueError(f"Reviewed migration receipt path must be root-owned and trusted: {path}")
    mode = stat.S_IMODE(result.st_mode)
    if (directory and mode & 0o022) or (not directory and (mode != 0o600 or result.st_nlink != 1)):
        raise ValueError(f"Reviewed migration receipt path has untrusted permissions or links: {path}")
    return result


def trusted_reviewed_path_stat(path, *, directory):
    return check_reviewed_path_stat(path, path.lstat(), directory=directory)


def reviewed_file_stat(fd):
    return os.fstat(fd)


def unique_receipt_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("Reviewed migration receipt contains duplicate JSON keys")
        result[key] = value
    return result


def read_reviewed_migration_receipt(base, sha):
    # Only the fixed operations directory OUTSIDE the incoming artifact is trusted.
    # Validate every ancestor: a secure file under an attacker-writable parent is
    # insufficient. Never follow receipt/directory symlinks or accept hardlinks.
    if not base.is_absolute() or '..' in base.parts or not re.fullmatch(r"[0-9a-f]{40}", sha):
        raise ValueError("Invalid reviewed migration receipt path or release identity")
    path = base / "operations" / "reviewed-migrations" / (sha + ".json")
    fd = None
    try:
        for parent in reversed(path.parents):
            trusted_reviewed_path_stat(parent, directory=True)
        expected = trusted_reviewed_path_stat(path, directory=False)
        flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0) | getattr(os, "O_NONBLOCK", 0)
        fd = os.open(path, flags)
        opened = check_reviewed_path_stat(path, reviewed_file_stat(fd), directory=False)
        if (expected.st_dev, expected.st_ino) != (opened.st_dev, opened.st_ino):
            raise ValueError("Reviewed migration receipt file identity changed during open")
        with os.fdopen(fd, "rb") as source:
            fd = None
            raw = source.read(65537)
        if len(raw) > 65536:
            raise ValueError("Reviewed migration receipt exceeds size limit")
        receipt = json.loads(raw.decode("utf-8"), object_pairs_hook=unique_receipt_object)
        return receipt, hashlib.sha256(raw).hexdigest()
    except (OSError, UnicodeError, json.JSONDecodeError) as error:
        raise ValueError("Missing or unreadable trusted reviewed migration receipt") from error
    finally:
        if fd is not None:
            os.close(fd)


def release_build_sha(release, label):
    try:
        marker = release / ".ci-source-sha"
        if not stat.S_ISREG(marker.lstat().st_mode):
            raise ValueError(f"Invalid {label} release build identity")
        value = marker.read_text().strip()
    except OSError as error:
        raise ValueError(f"Missing {label} release build identity") from error
    if not re.fullmatch(r"[0-9a-f]{40}", value):
        raise ValueError(f"Invalid {label} release build identity")
    return value


def verify_reviewed_additive_migrations(base, old, release, sha):
    previous = migration_files(old)
    incoming = migration_files(release)
    if previous == incoming:
        return None
    # A receipt can only acknowledge newly added, independently reviewed files.
    # Editing/removing even one historical migration is never approved here.
    if any(incoming.get(path) != digest for path, digest in previous.items()):
        raise ValueError("Existing migrations changed or removed; separate database release required")
    additions = {path: digest for path, digest in incoming.items() if path not in previous}
    receipt, receipt_digest = read_reviewed_migration_receipt(base, sha)
    keys = {"schema", "release_sha", "base_sha", "base_release", "status", "added_migrations", "applied_at", "verification"}
    if not isinstance(receipt, dict) or set(receipt) != keys:
        raise ValueError("Reviewed migration receipt schema does not match")
    if (receipt["schema"] != "hoanam-reviewed-additive-migrations-v1"
            or receipt["release_sha"] != sha or release_build_sha(release, "incoming") != sha
            or receipt["base_sha"] != release_build_sha(old, "base")
            or receipt["base_release"] != str(old.resolve(strict=True))):
        raise ValueError("Reviewed migration receipt release/base identity mismatch")
    if (receipt["status"] != "APPLIED" or receipt["verification"] != "MANUAL_MIGRATION_SUCCEEDED"
            or receipt["added_migrations"] != additions):
        raise ValueError("Reviewed migration receipt does not acknowledge the exact applied additions")
    try:
        if not isinstance(receipt["applied_at"], str):
            raise ValueError("Invalid timestamp type")
        applied = datetime.datetime.fromisoformat(receipt["applied_at"].replace("Z", "+00:00"))
        if (applied.tzinfo is None or applied.utcoffset() != datetime.timedelta(0)
                or applied > datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(minutes=5)):
            raise ValueError("Invalid applied timestamp")
    except (ValueError, TypeError) as error:
        raise ValueError("Reviewed migration receipt requires a valid past UTC applied_at") from error
    return {"receipt_sha256": receipt_digest, "release_sha": sha, "base_sha": receipt["base_sha"],
            "added_migrations": additions, "applied_at": receipt["applied_at"]}


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


def update_backend_build_identity(config, services, sha, built_at):
    # compose service.environment overrides shared/app.env. The reviewed P05
    # release put the previous SHA in every PHP service explicitly.
    for name in services:
        if name == "nginx":
            continue
        environment = config["services"][name].get("environment")
        if not isinstance(environment, dict) or environment.get("AUTO_MIGRATE") != "false":
            raise ValueError(f"AUTO_MIGRATE must remain disabled for {name}")
        environment["APP_BUILD_SHA"] = sha
        environment["APP_BUILD_REF"] = f"dev@{sha}"
        environment["APP_BUILD_TIME"] = built_at


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
    migration_review = verify_reviewed_additive_migrations(base, old, release, sha)
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
        built_at = datetime.datetime.now(datetime.timezone.utc).isoformat()
        update_backend_build_identity(config, services, sha, built_at)
        lines = [line for line in app_env.read_text().splitlines()
                 if not line.startswith(("APP_BUILD_SHA=", "APP_BUILD_REF=", "APP_BUILD_TIME="))]
        lines += [f"APP_BUILD_SHA={sha}", f"APP_BUILD_REF=dev@{sha}",
                  "APP_BUILD_TIME=" + built_at]
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
                  "health": "ok", "migration_run": False, "seed_run": False,
                  "reviewed_migration_receipt": migration_review}
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
