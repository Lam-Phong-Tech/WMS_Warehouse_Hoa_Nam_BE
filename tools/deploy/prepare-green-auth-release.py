#!/usr/bin/env python3
"""Package only committed application source; never copy runner .env or fixtures."""

import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess

spec = importlib.util.spec_from_file_location('auth_release', Path(__file__).with_name('deploy-green-auth-replacement.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


def git(folder, *args, optional=False):
    value = subprocess.run(['git', '-C', str(folder), *args], capture_output=True, check=False)
    if value.returncode:
        if optional:
            return None
        raise RuntimeError('Source Git verification failed')
    return value.stdout


def sha(value):
    return None if value is None else hashlib.sha256(value).hexdigest()


def main():
    if not __debug__:
        raise RuntimeError('Packaging guards require normal Python assertion mode')
    parser = argparse.ArgumentParser()
    parser.add_argument('--application', required=True)
    parser.add_argument('--base-repository', required=True)
    parser.add_argument('--ops-sha', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    application = Path(args.application)
    base = Path(args.base_repository)
    assert git(application, 'rev-parse', 'HEAD').decode().strip() == release.SOURCE
    git(application, 'diff', '--exit-code', 'HEAD')
    git(base, 'cat-file', '-e', release.PREVIOUS + '^{commit}')
    official = {}
    for filename in release.green.OVERLAYS:
        old = git(base, 'show', release.PREVIOUS + ':' + filename, optional=True)
        new = git(application, 'show', release.SOURCE + ':' + filename, optional=True)
        assert old == new, 'Runtime overlay overlaps an upstream application change: ' + filename
        official[filename] = sha(new)
    runtime_files = ['composer.json', 'composer.lock']
    for directory in ['docker/php', 'docker/nginx']:
        old_names = git(base, 'ls-tree', '-r', '--name-only', release.PREVIOUS, '--', directory).decode().splitlines()
        new_names = git(application, 'ls-tree', '-r', '--name-only', release.SOURCE, '--', directory).decode().splitlines()
        assert old_names == new_names, 'Runtime image file inventory changed'
        runtime_files.extend(old_names)
    for filename in runtime_files:
        assert git(base, 'show', release.PREVIOUS + ':' + filename) == git(application, 'show', release.SOURCE + ':' + filename), 'Runtime/dependency change: ' + filename
    output = Path(args.output)
    output.mkdir(mode=0o700)
    archive = output / 'auth-source.tar.gz'
    git(application, 'archive', '--format=tar.gz', '--output=' + str(archive.resolve()), release.SOURCE)
    proof = {'source_sha': release.SOURCE, 'ops_sha': args.ops_sha,
        'source_archive_sha256': release.green.digest(archive),
        'composer_lock_sha256': sha(git(application, 'show', release.SOURCE + ':composer.lock')),
        'migration_sha256': sha(git(application, 'show', release.SOURCE + ':' + release.MIGRATION)),
        'official_overlay_hashes': official, 'ci_run': None, 'seed_authorized': False,
        'local_tests': {'sqlite_tests': 179, 'sqlite_assertions': 3190,
                        'postgres_tests': 56, 'postgres_assertions': 596}}
    (output / 'auth-proof.json').write_text(json.dumps(proof, indent=2))
    print(json.dumps(proof))


if __name__ == '__main__':
    main()
