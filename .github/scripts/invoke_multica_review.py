#!/usr/bin/env python3
"""Invoke a Multica review agent against a GitHub PR.

Called by .github/workflows/review-gate.yml. Creates or reuses a Linear issue
for the review task first, then creates or reuses a Multica execution mirror
assigned to the named agent. The mirror is polled until a terminal state, and
the final verdict/status is synced back to Linear.

PRD §9.7 — pre-merge review gate, Layer 1 + Layer 2.

Exit codes:
    0 — agent posted APPROVE or COMMENT (or advisory non-blocking failure)
    1 — agent posted REQUEST_CHANGES, OR blocking failure
    2 — operational error (Linear/Multica unreachable, timeout, etc.)
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request
from typing import Any

POLL_INTERVAL_SECONDS = 10
POLL_TIMEOUT_SECONDS = 1800  # 30 min cap per reviewer
LINEAR_API_URL = "https://api.linear.app/graphql"
LINEAR_REVIEW_LABEL = os.environ.get("LINEAR_REVIEW_LABEL", "review-gate")


def _config_path() -> str:
    return os.environ.get("JARVIS_CONFIG") or os.path.normpath(
        os.path.join(os.path.dirname(__file__), "..", "..", "config.json")
    )


def _load_config() -> dict[str, Any]:
    config_path = _config_path()
    try:
        with open(config_path) as f:
            return json.load(f)
    except FileNotFoundError:
        print(
            f"::error::config.json not found at {config_path}. "
            "Set the JARVIS_CONFIG env var (or vars.JARVIS_CONFIG in GHA) "
            "to the absolute path of config.json on this runner.",
            file=sys.stderr,
        )
        sys.exit(2)
    except json.JSONDecodeError as exc:
        print(f"::error::config.json at {config_path} is not valid JSON: {exc}", file=sys.stderr)
        sys.exit(2)


CONFIG = _load_config()


def _load_agent_ids() -> dict[str, str]:
    """Load agent name -> agent_id mapping from config.json."""
    config_path = _config_path()
    config = CONFIG
    agents: dict = config.get("multica", {}).get("agents", {})
    if not agents:
        print(
            f"::error::config.json at {config_path} contains no multica.agents entries.",
            file=sys.stderr,
        )
        sys.exit(2)

    return {name: data["agent_id"] for name, data in agents.items() if "agent_id" in data}


AGENT_IDS = _load_agent_ids()


def err(msg: str) -> None:
    print(f"::error::{msg}", file=sys.stderr)


def warn(msg: str) -> None:
    print(f"::warning::{msg}", file=sys.stderr)


def info(msg: str) -> None:
    print(msg)


def _multica_run(*args: str, stdin: str | None = None) -> subprocess.CompletedProcess[str]:
    return subprocess.run(["multica", *args], input=stdin, capture_output=True, text=True)


def multica_json(*args: str, stdin: str | None = None, check: bool = True) -> dict[str, Any] | list[Any] | None:
    """Run a multica command with --output json and return parsed JSON."""
    result = _multica_run(*args, "--output", "json", stdin=stdin)
    if result.returncode != 0:
        if check:
            err(f"multica {' '.join(args)}: {result.stderr.strip()}")
            sys.exit(2)
        return None
    try:
        return json.loads(result.stdout)
    except json.JSONDecodeError:
        if check:
            err(f"multica {' '.join(args)} returned non-JSON: {result.stdout[:200]}")
            sys.exit(2)
        return None


def linear_api_key() -> str:
    api_key = os.environ.get("LINEAR_API_KEY")
    if not api_key:
        err("LINEAR_API_KEY not configured")
        sys.exit(2)
    return api_key


def linear_team_id() -> str:
    team_id = os.environ.get("LINEAR_TEAM_ID") or CONFIG.get("linear", {}).get("team_id")
    if not team_id:
        err("LINEAR_TEAM_ID not configured and config.json has no linear.team_id")
        sys.exit(2)
    return team_id


def linear_gql(query: str, variables: dict[str, Any] | None = None) -> dict[str, Any]:
    payload = json.dumps({"query": query, "variables": variables or {}}).encode()
    req = urllib.request.Request(
        LINEAR_API_URL,
        data=payload,
        headers={
            "Content-Type": "application/json",
            "Authorization": linear_api_key(),
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            data = json.loads(resp.read().decode())
    except urllib.error.HTTPError as exc:
        body = exc.read().decode(errors="replace")
        err(f"Linear HTTP {exc.code}: {body}")
        sys.exit(2)
    except urllib.error.URLError as exc:
        err(f"Linear request failed: {exc}")
        sys.exit(2)
    except json.JSONDecodeError as exc:
        err(f"Linear returned invalid JSON: {exc}")
        sys.exit(2)

    if data.get("errors"):
        err(f"Linear GraphQL errors: {data['errors']}")
        sys.exit(2)
    return data["data"]


def fetch_pr(pr_number: int) -> dict[str, Any]:
    """Return PR metadata via gh CLI."""
    fields = "number,title,url,headRefName,baseRefName,author,body,additions,deletions,changedFiles"
    result = subprocess.run(
        ["gh", "pr", "view", str(pr_number), "--json", fields],
        check=True, capture_output=True, text=True,
    )
    return json.loads(result.stdout)


def review_layer(apex: bool) -> str:
    return "2" if apex else "1"


def review_task_key(pr_number: int, agent_name: str, layer: str) -> str:
    return f"review-gate/pr-{pr_number}/{agent_name}/layer-{layer}"


def linear_title(agent_name: str, pr: dict[str, Any], layer: str) -> str:
    prefix = "Layer 2 apex review" if layer == "2" else f"Layer 1 {agent_name}"
    return f"[{prefix}] PR #{pr['number']} {review_task_key(pr['number'], agent_name, layer)}: {pr['title'][:70]}"


def ensure_linear_label(name: str) -> str:
    team_id = linear_team_id()
    query = """
    query Labels($teamId: ID!, $name: String!) {
      issueLabels(filter: {
        team: { id: { eq: $teamId } }
        name: { eq: $name }
      }) { nodes { id name } }
    }
    """
    result = linear_gql(query, {"teamId": team_id, "name": name})
    nodes = result["issueLabels"]["nodes"]
    if nodes:
        return nodes[0]["id"]

    mutation = """
    mutation LabelCreate($input: IssueLabelCreateInput!) {
      issueLabelCreate(input: $input) { success issueLabel { id name } }
    }
    """
    created = linear_gql(
        mutation,
        {"input": {"teamId": team_id, "name": name, "color": "#5e6ad2"}},
    )
    if not created["issueLabelCreate"]["success"]:
        err(f"failed to create Linear label {name}")
        sys.exit(2)
    return created["issueLabelCreate"]["issueLabel"]["id"]


def find_linear_issue(pr_number: int, agent_name: str, layer: str) -> dict[str, Any] | None:
    key = review_task_key(pr_number, agent_name, layer)
    query = """
    query ReviewIssues($teamId: ID!, $labelName: String!, $key: String!) {
      issues(first: 10, filter: {
        team: { id: { eq: $teamId } }
        labels: { name: { eq: $labelName } }
        title: { contains: $key }
      }) {
        nodes {
          id identifier title url description
          state { id name type }
          labels { nodes { name } }
        }
      }
    }
    """
    result = linear_gql(
        query,
        {"teamId": linear_team_id(), "labelName": LINEAR_REVIEW_LABEL, "key": key},
    )
    nodes = result["issues"]["nodes"]
    return nodes[0] if nodes else None


def linear_description(agent_name: str, pr: dict[str, Any], layer: str, apex: bool) -> str:
    merge_note = (
        "\n**Merge is handled automatically.** When this review resolves APPROVED, "
        "the workflow performs the squash merge. Do not run `gh pr merge` manually from the sandbox.\n"
        if apex
        else ""
    )
    return (
        f"Review task key: `{review_task_key(pr['number'], agent_name, layer)}`\n\n"
        f"**PR:** {pr['url']}\n"
        f"**Branch:** `{pr['headRefName']}` -> `{pr['baseRefName']}`\n"
        f"**Author:** {pr['author'].get('login', 'unknown')}\n"
        f"**Diff stat:** +{pr['additions']} / -{pr['deletions']} across {pr['changedFiles']} file(s)\n"
        f"**Review agent:** `{agent_name}`\n"
        f"**Review layer:** `{layer}`\n"
        f"\n## PR body\n\n{pr.get('body') or '_(no body)_'}\n"
        f"{merge_note}"
        f"\nCreated by `.github/workflows/review-gate.yml`."
    )


def create_linear_issue(agent_name: str, pr: dict[str, Any], layer: str, apex: bool) -> dict[str, Any]:
    label_id = ensure_linear_label(LINEAR_REVIEW_LABEL)
    mutation = """
    mutation IssueCreate($input: IssueCreateInput!) {
      issueCreate(input: $input) {
        success
        issue {
          id identifier title url description
          state { id name type }
          labels { nodes { name } }
        }
      }
    }
    """
    result = linear_gql(
        mutation,
        {
            "input": {
                "teamId": linear_team_id(),
                "title": linear_title(agent_name, pr, layer),
                "description": linear_description(agent_name, pr, layer, apex),
                "labelIds": [label_id],
                "priority": 2 if apex else 3,
            }
        },
    )
    if not result["issueCreate"]["success"]:
        err("Linear issueCreate returned success=false")
        sys.exit(2)
    return result["issueCreate"]["issue"]


def ensure_linear_issue(agent_name: str, pr: dict[str, Any], layer: str, apex: bool) -> dict[str, Any]:
    issue = find_linear_issue(pr["number"], agent_name, layer)
    if issue:
        info(f"Reusing Linear issue {issue['identifier']} for {review_task_key(pr['number'], agent_name, layer)}")
        return issue
    issue = create_linear_issue(agent_name, pr, layer, apex)
    info(f"Created Linear issue {issue['identifier']} for {review_task_key(pr['number'], agent_name, layer)}")
    return issue


def linear_comment(issue_id: str, body: str) -> None:
    mutation = """
    mutation CommentCreate($input: CommentCreateInput!) {
      commentCreate(input: $input) { success comment { id } }
    }
    """
    result = linear_gql(mutation, {"input": {"issueId": issue_id, "body": body}})
    if not result["commentCreate"]["success"]:
        warn(f"failed to add Linear comment to {issue_id}")


def find_linear_state(state_name: str | None = None, state_type: str | None = None) -> dict[str, Any] | None:
    query = """
    query States($teamId: String!) {
      team(id: $teamId) {
        states { nodes { id name type position } }
      }
    }
    """
    result = linear_gql(query, {"teamId": linear_team_id()})
    states = result["team"]["states"]["nodes"]
    if state_name:
        for state in states:
            if state["name"].lower() == state_name.lower():
                return state
    if state_type:
        typed = [state for state in states if state["type"] == state_type]
        if typed:
            return sorted(typed, key=lambda state: state.get("position", 0))[0]
    return None


def transition_linear(issue_id: str, state_id: str) -> None:
    mutation = """
    mutation IssueUpdate($id: String!, $stateId: String!) {
      issueUpdate(id: $id, input: { stateId: $stateId }) {
        success
        issue { id identifier state { id name type } }
      }
    }
    """
    result = linear_gql(mutation, {"id": issue_id, "stateId": state_id})
    if not result["issueUpdate"]["success"]:
        warn(f"failed to transition Linear issue {issue_id}")


def sync_linear_final(issue: dict[str, Any], mirror: dict[str, Any], verdict: str | None, blocking: bool) -> None:
    status = mirror.get("status", "unknown")
    identifier = mirror.get("identifier", mirror.get("id", "unknown"))
    body = (
        f"Review gate completed.\n\n"
        f"- Multica mirror: `{identifier}`\n"
        f"- Multica status: `{status}`\n"
        f"- Verdict: `{verdict or 'NONE'}`\n"
        f"- Blocking: `{str(blocking).lower()}`"
    )
    linear_comment(issue["id"], body)

    state_name = None
    state_type = None
    if verdict in {"APPROVED", "COMMENTED"}:
        state_name = os.environ.get("LINEAR_REVIEW_DONE_STATE_NAME")
        state_type = "completed"
    elif verdict == "CHANGES_REQUESTED":
        state_name = os.environ.get("LINEAR_REVIEW_CHANGES_STATE_NAME")
    elif status in {"cancelled", "blocked"}:
        state_name = os.environ.get("LINEAR_REVIEW_BLOCKED_STATE_NAME")
    # WEZ-7: in_review with no verdict — leave Linear issue open; don't transition to blocked

    state = find_linear_state(state_name=state_name, state_type=state_type) if state_name or state_type else None
    if state:
        transition_linear(issue["id"], state["id"])


def read_verdict_from_metadata(issue: dict[str, Any]) -> str | None:
    """Return the verdict the review agent pinned in Multica issue metadata."""
    return issue.get("metadata", {}).get("verdict")


def _issue_list_items(data: dict[str, Any] | list[Any] | None) -> list[dict[str, Any]]:
    if data is None:
        return []
    if isinstance(data, list):
        return [item for item in data if isinstance(item, dict)]
    for key in ("items", "data", "nodes"):
        items = data.get(key)
        if isinstance(items, list):
            return [item for item in items if isinstance(item, dict)]
    return []


def find_multica_review_issue(agent_name: str, pr_number: int, layer: str, linear_issue_id: str) -> dict[str, Any] | None:
    if agent_name not in AGENT_IDS:
        return None
    data = multica_json(
        "issue", "list",
        "--assignee-id", AGENT_IDS[agent_name],
        "--metadata", f"pr_number={pr_number}",
        "--metadata", f"review_agent={agent_name}",
        "--metadata", f"review_layer={layer}",
        check=False,
    )
    issues = _issue_list_items(data)
    for issue in issues:
        # WEZ-10: never reuse a cancelled issue (left behind by a GHA run cancellation)
        if issue.get("status") == "cancelled":
            continue
        # WEZ-8: only reuse an issue that is explicitly linked to THIS Linear issue;
        # never fall back to issues[0] which could belong to a different author's run
        metadata = issue.get("metadata", {})
        if metadata.get("linear_issue_id") == linear_issue_id:
            return issue
    return None


def review_metadata(agent_name: str, pr: dict[str, Any], layer: str, linear_issue: dict[str, Any]) -> dict[str, str]:
    return {
        "linear_issue_id": linear_issue["id"],
        "linear_identifier": linear_issue["identifier"],
        "pr_number": str(pr["number"]),
        "pr_url": pr["url"],
        "review_layer": layer,
        "review_agent": agent_name,
    }


def set_multica_metadata(issue: dict[str, Any], metadata: dict[str, str]) -> None:
    issue_id = issue["id"]
    for key, value in metadata.items():
        r = _multica_run("issue", "metadata", "set", issue_id, "--key", key, "--value", value)
        if r.returncode != 0:
            warn(f"Could not set metadata.{key} on {issue_id[:8]}: {r.stderr.strip()}")
    issue.setdefault("metadata", {}).update(metadata)


def multica_description(agent_name: str, pr: dict[str, Any], layer: str, apex: bool, linear_issue: dict[str, Any]) -> str:
    merge_note = (
        "\n**Merge is handled automatically.** When you set `verdict=APPROVED` and mark this issue done, "
        "the `layer2-lucious` workflow job runs `gh pr merge --squash --delete-branch` for you. "
        "Do NOT run `gh pr merge` yourself.\n"
        if apex
        else ""
    )
    return (
        f"**Linear issue:** {linear_issue['identifier']} ({linear_issue['url']})\n"
        f"**Review task key:** `{review_task_key(pr['number'], agent_name, layer)}`\n"
        f"**PR:** {pr['url']}\n"
        f"**Branch:** `{pr['headRefName']}` -> `{pr['baseRefName']}`\n"
        f"**Author:** {pr['author'].get('login', 'unknown')}\n"
        f"**Diff stat:** +{pr['additions']} / -{pr['deletions']} across {pr['changedFiles']} file(s)\n"
        f"\n## PR body\n\n{pr.get('body') or '_(no body)_'}\n"
        f"\n## Review instructions\n\n"
        f"1. `gh pr diff {pr['number']}` to read the changes\n"
        f"2. `gh pr view {pr['number']}` for full context + linked issues\n"
        f"3. Apply your standing review instructions (per Multica agent config)\n"
        f"4. Post verdict via `gh pr review {pr['number']} --body \"...\" --approve|--request-changes|--comment`\n"
        f"5. **Set your verdict in this issue's metadata before marking done:**\n"
        f"   `multica issue metadata set <this-issue-id> --key verdict --value APPROVED`\n"
        f"   Valid values: `APPROVED`, `CHANGES_REQUESTED`, `COMMENTED`\n"
        f"   This is the authoritative signal; GitHub reviews alone are not read back.\n"
        f"{merge_note}"
        f"\nTriggered by `.github/workflows/review-gate.yml` on PR {pr['number']}."
    )


def create_review_issue(agent_name: str, pr: dict[str, Any], apex: bool, linear_issue: dict[str, Any]) -> dict[str, Any]:
    if agent_name not in AGENT_IDS:
        err(f"unknown agent: {agent_name}")
        sys.exit(2)
    layer = review_layer(apex)
    existing = find_multica_review_issue(agent_name, pr["number"], layer, linear_issue["id"])
    if existing:
        set_multica_metadata(existing, review_metadata(agent_name, pr, layer, linear_issue))
        label = existing.get("identifier", existing.get("id", "")[:8])
        info(f"Reusing Multica issue {label} for {review_task_key(pr['number'], agent_name, layer)}")
        return existing

    title_prefix = "[Layer 2 apex review]" if apex else f"[Layer 1 {agent_name}]"
    title = f"{title_prefix} PR #{pr['number']}: {pr['title'][:80]}"
    priority = "high" if apex else "medium"
    issue = multica_json(
        "issue", "create",
        "--title", title,
        "--description-stdin",
        "--assignee-id", AGENT_IDS[agent_name],
        "--priority", priority,
        "--allow-duplicate",
        stdin=multica_description(agent_name, pr, layer, apex, linear_issue),
    )
    if not isinstance(issue, dict):
        err("multica issue create returned no data")
        sys.exit(2)

    set_multica_metadata(issue, review_metadata(agent_name, pr, layer, linear_issue))

    return issue


def poll_issue_until_terminal(issue_id: str) -> dict[str, Any]:
    """Block until the Multica issue reaches a terminal state."""
    terminal = {"done", "cancelled", "blocked", "in_review"}
    deadline = time.monotonic() + POLL_TIMEOUT_SECONDS
    while time.monotonic() < deadline:
        issue = multica_json("issue", "get", issue_id)
        if not isinstance(issue, dict):
            err(f"multica issue get {issue_id} returned no issue data")
            sys.exit(2)
        status = issue.get("status")
        if status in terminal:
            return issue
        time.sleep(POLL_INTERVAL_SECONDS)
    err(f"timeout after {POLL_TIMEOUT_SECONDS}s waiting for Multica issue {issue_id}")
    sys.exit(2)


def write_github_output(verdict: str | None) -> None:
    gh_output = os.environ.get("GITHUB_OUTPUT")
    if gh_output:
        with open(gh_output, "a") as f:
            f.write(f"verdict={verdict or 'NONE'}\n")


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--agent", required=True, choices=sorted(AGENT_IDS))
    p.add_argument("--pr", type=int, required=True)
    p.add_argument("--author-kind", choices=["human", "multica"], required=True)
    p.add_argument(
        "--blocking-for",
        default="human,multica",
        help="Comma-separated list of author kinds for which this reviewer blocks (others advisory)",
    )
    p.add_argument("--apex", action="store_true", help="Layer 2 apex reviewer (Lucious); triggers merge on APPROVE")
    args = p.parse_args()

    layer = review_layer(args.apex)
    blocking_kinds = {k.strip() for k in args.blocking_for.split(",") if k.strip()}
    is_blocking = args.author_kind in blocking_kinds

    info(
        f"Invoking {args.agent} on PR #{args.pr} "
        f"(layer={layer}, author={args.author_kind}, blocking={is_blocking})"
    )

    pr = fetch_pr(args.pr)
    linear_issue = ensure_linear_issue(args.agent, pr, layer, args.apex)
    mirror = create_review_issue(args.agent, pr, apex=args.apex, linear_issue=linear_issue)
    mirror_label = mirror.get("identifier", mirror["id"][:8])
    info(f"Multica issue {mirror_label} assigned to {args.agent}")
    info(f"Polling every {POLL_INTERVAL_SECONDS}s for completion (timeout {POLL_TIMEOUT_SECONDS}s)...")

    final = poll_issue_until_terminal(mirror["id"])
    final_label = final.get("identifier", mirror_label)
    final_status = final.get("status", "")
    info(f"Multica issue {final_label} reached status={final_status}")

    verdict = read_verdict_from_metadata(final)
    info(f"Multica issue {final_label} metadata.verdict: {verdict}")
    write_github_output(verdict)

    # WEZ-7: in_review with no verdict means the agent is still actively reviewing.
    # The issue reached a terminal poll state but the agent hasn't committed a verdict yet.
    # Treat as non-blocking advisory — the concurrency group will restart the run on next push.
    if final_status == "in_review" and verdict is None:
        warn(f"{args.agent} reached in_review with no verdict — advisory exit (concurrency group will re-run on next push)")
        sync_linear_final(linear_issue, final, None, False)
        return 0

    sync_linear_final(linear_issue, final, verdict, is_blocking)

    if verdict == "APPROVED":
        info(f"::notice::{args.agent} APPROVED PR #{args.pr}")
        return 0
    if verdict == "COMMENTED":
        info(f"::notice::{args.agent} COMMENTED (non-blocking)")
        return 0
    if verdict == "CHANGES_REQUESTED":
        if is_blocking:
            err(f"{args.agent} requested changes (blocking for {args.author_kind} authors)")
            return 1
        warn(f"{args.agent} requested changes (advisory for {args.author_kind} authors)")
        return 0
    if is_blocking:
        err(f"{args.agent} did not set a verdict in metadata (blocking)")
        return 1
    warn(f"{args.agent} did not set a verdict in metadata (advisory)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
