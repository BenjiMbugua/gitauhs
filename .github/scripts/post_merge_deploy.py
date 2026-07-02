"""post_merge_deploy.py — trigger VPS deploy after a PR is squash-merged.

Called by layer2-lucious immediately after gh pr merge. Extracts the fleet
site from the PR title, then POSTs to the Jarvis API (localhost:8090). If
Jarvis is not running, logs a warning and exits 0 so the merge is never
rolled back.

Exit codes:
  0 — deployed (or no fleet site matched, or Jarvis unreachable — non-fatal)
  1 — unexpected error
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path


def info(msg: str) -> None:
    print(msg, flush=True)


def warn(msg: str) -> None:
    print(f"::warning::{msg}", flush=True)


def _load_config(config_path: str) -> dict:
    p = Path(config_path)
    if not p.exists():
        warn(f"config.json not found at {config_path} — skipping deploy")
        return {}
    try:
        return json.loads(p.read_text())
    except json.JSONDecodeError:
        warn(f"config.json at {config_path} is invalid JSON — skipping deploy")
        return {}


def _pr_title(pr_number: int) -> str:
    result = subprocess.run(
        ["gh", "pr", "view", str(pr_number), "--json", "title", "--jq", ".title"],
        capture_output=True, text=True, timeout=15,
    )
    if result.returncode != 0:
        warn(f"gh pr view {pr_number} failed: {result.stderr.strip()}")
        return ""
    return result.stdout.strip()


_DOMAIN_RE = re.compile(r"https?://([a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z]{2,})+)")


def _site_id_from_title(title: str, sites: dict) -> str | None:
    """Return the config.json site_id whose domain appears in the PR title."""
    for m in _DOMAIN_RE.finditer(title):
        domain = m.group(1).lower()
        for site_id, cfg in sites.items():
            configured = re.sub(r"^https?://", "", cfg.get("domain", site_id).lower())
            if domain == configured or domain == site_id.lower():
                return site_id
    return None


def _jarvis_deploy(site_id: str, actor: str, ref: str, base_url: str) -> bool:
    url = f"{base_url.rstrip('/')}/api/deploy/{site_id}?actor={actor}&ref={ref}"
    req = urllib.request.Request(url, method="POST")
    token = os.environ.get("JARVIS_DEPLOY_TOKEN", "")
    if token:
        req.add_header("Authorization", f"Bearer {token}")
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            body = json.loads(resp.read())
            info(f"deploy queued: {body}")
            return True
    except urllib.error.URLError as exc:
        warn(f"Jarvis unreachable at {base_url} ({exc}) — skipping auto-deploy for {site_id}")
        return False
    except Exception as exc:
        warn(f"Jarvis deploy call failed: {exc}")
        return False


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--pr", type=int, required=True, help="PR number just merged")
    p.add_argument("--config", default=os.environ.get("JARVIS_CONFIG", "config.json"))
    p.add_argument("--actor", default="review-gate")
    p.add_argument("--ref", default="main")
    p.add_argument("--jarvis-url", default=os.environ.get("JARVIS_URL", "http://localhost:8090"))
    args = p.parse_args()

    cfg = _load_config(args.config)
    sites = cfg.get("sites", {})
    if not sites:
        info("No fleet sites configured — skipping deploy")
        return 0

    title = _pr_title(args.pr)
    if not title:
        warn(f"Could not read title for PR #{args.pr} — skipping deploy")
        return 0

    info(f"PR #{args.pr}: {title!r}")

    site_id = _site_id_from_title(title, sites)
    if not site_id:
        info("No fleet site matched in PR title — this is a Jarvis-code PR; no VPS deploy needed")
        return 0

    info(f"Matched fleet site: {site_id} — triggering deploy")
    _jarvis_deploy(site_id, args.actor, args.ref, args.jarvis_url)
    return 0


if __name__ == "__main__":
    sys.exit(main())
