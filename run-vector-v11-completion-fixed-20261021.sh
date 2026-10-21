#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

# VECTOR v1.1 completion run.
# Run as the normal WSL user from the traderbybit repository root.
# Scope: complete/verify VECTOR_OPENCODE_OVERNIGHT_FULL_EXECUTION_SPEC_EN.md.
# VECTOR v2.3 is explicitly out of scope and must not be executed.
# This is NOT an OS sandbox. Never launch with production credentials.

REPO="$(pwd -P)"
SPEC="$REPO/VECTOR_OPENCODE_OVERNIGHT_FULL_EXECUTION_SPEC_EN.md"
SESSION="${SESSION:-vector-v11-completion}"
MAX_HOURS="${MAX_HOURS:-10}"
MODEL="${MODEL:-}"

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
warn() { printf 'WARN: %s\n' "$*" >&2; }

[[ "$(id -u)" -ne 0 ]] || fail 'Do not run as root. Use the normal WSL user.'
[[ -d "$REPO/.git" || -f "$REPO/.git" ]] || fail 'Git repository not found. Run this from traderbybit root.'
[[ -f "$REPO/AGENTS.md" || -d "$REPO/research-go" ]] || fail 'Expected traderbybit project markers not found.'
[[ -f "$SPEC" ]] || fail "Missing required spec: $SPEC. Place VECTOR_OPENCODE_OVERNIGHT_FULL_EXECUTION_SPEC_EN.md in the repository root."
[[ "$SESSION" =~ ^[a-zA-Z0-9_-]+$ ]] || fail 'SESSION may contain only letters, digits, underscores, or hyphens.'
[[ "$MAX_HOURS" =~ ^[0-9]+([.][0-9]+)?$ ]] || fail 'MAX_HOURS must be a positive number, e.g. 10 or 0.5.'
awk -v h="$MAX_HOURS" 'BEGIN {exit !(h > 0 && h <= 24)}' || fail 'MAX_HOURS must be > 0 and <= 24.'

required=(git go php node npm psql tar sha256sum df timeout tmux opencode awk python3 realpath)
for cmd in "${required[@]}"; do
  command -v "$cmd" >/dev/null 2>&1 || fail "Missing required command: $cmd"
done

for cmd in composer shellcheck; do
  command -v "$cmd" >/dev/null 2>&1 || warn "Optional command '$cmd' is missing."
done

# Confirm the CLI contract before launching unattended work.
CLI_HELP="$(timeout 60s opencode run --help 2>&1)" || fail 'Unable to inspect opencode run --help.'
for flag in --auto --agent --file; do
  grep -Fq -- "$flag" <<< "$CLI_HELP" || fail "Installed OpenCode does not advertise required option $flag."
done
if [[ -n "$MODEL" ]]; then
  grep -Fq -- '--model' <<< "$CLI_HELP" || fail 'Installed OpenCode does not advertise --model.'
fi

if tmux has-session -t "$SESSION" 2>/dev/null; then
  fail "tmux session '$SESSION' already exists; refusing a duplicate worker."
fi

# Refuse concurrent OpenCode runs for this user to avoid competing file writes.
if pgrep -u "$(id -u)" -af '[o]pencode run' >/dev/null 2>&1; then
  pgrep -u "$(id -u)" -af '[o]pencode run' >&2 || true
  fail 'An OpenCode run already appears active for this user. Inspect it and wait for it to stop.'
fi

AVAIL_KB="$(df -Pk "$REPO" | awk 'END {print $4}')"
[[ "$AVAIL_KB" =~ ^[0-9]+$ ]] || fail 'Unable to determine available disk space.'
(( AVAIL_KB >= 5 * 1024 * 1024 )) || fail 'Less than 5 GiB free. Free space manually; this launcher never cleans files.'

SPEC_HASH="$(sha256sum "$SPEC" | awk '{print $1}')"
START_EPOCH="$(date -u +%s)"
DURATION_SECONDS="$(awk -v h="$MAX_HOURS" 'BEGIN {printf "%.0f", h*3600}')"
HARD_EPOCH=$(( START_EPOCH + DURATION_SECONDS ))
SOFT_EPOCH=$(( HARD_EPOCH - 3600 ))
(( SOFT_EPOCH >= START_EPOCH )) || SOFT_EPOCH="$START_EPOCH"
HARD_DEADLINE="$(date -u -d "@$HARD_EPOCH" +%FT%TZ)"
SOFT_DEADLINE="$(date -u -d "@$SOFT_EPOCH" +%FT%TZ)"
RUN_ID="vector-$(date -u +%Y%m%dT%H%M%SZ)-$$"
RUN_DIR="$REPO/reports/overnight/runs/$RUN_ID"
[[ ! -e "$RUN_DIR" ]] || fail "Run directory already exists: $RUN_DIR"
mkdir -p "$RUN_DIR"

LOG="$RUN_DIR/opencode-session.log"
PREFLIGHT="$RUN_DIR/PREFLIGHT.md"
SNAP="$RUN_DIR/worktree-snapshot-$RUN_ID.tar.gz"

# Preserve a baseline record of staged, unstaged, and untracked paths.
# Never print secret contents; only Git status and diff summaries are recorded.
{
  echo "# VECTOR v1.1 launcher preflight"
  echo "- Run ID: $RUN_ID"
  echo "- UTC start: $(date -u +%FT%TZ)"
  echo "- Repository: $REPO"
  echo "- User: $(whoami) (uid=$(id -u))"
  echo "- Branch: $(git -C "$REPO" branch --show-current)"
  echo "- Git HEAD: $(git -C "$REPO" rev-parse HEAD)"
  echo "- Spec: ${SPEC#"$REPO"/}"
  echo "- Spec SHA-256: $SPEC_HASH"
  echo "- OpenCode: $(opencode --version 2>/dev/null || echo unknown)"
  echo "- Go: $(go version 2>/dev/null || echo unavailable)"
  echo "- PHP: $(php -v 2>/dev/null | head -1 || echo unavailable)"
  echo "- Node: $(node --version 2>/dev/null || echo unavailable)"
  echo "- npm: $(npm --version 2>/dev/null || echo unavailable)"
  echo "- PostgreSQL client: $(psql --version 2>/dev/null || echo unavailable)"
  echo "- MAX_HOURS: $MAX_HOURS"
  echo "- Soft shutdown UTC: $SOFT_DEADLINE"
  echo "- Hard deadline UTC: $HARD_DEADLINE"
  echo "- Disk: $(df -h "$REPO" | tail -1)"
  echo
  echo "## Git status before launch"
  git -C "$REPO" status --short --untracked-files=all
  echo
  echo "## Unstaged diff summary"
  git -C "$REPO" diff --stat
  echo
  echo "## Staged diff summary"
  git -C "$REPO" diff --cached --stat
  echo
  echo "## Safety"
  echo "- This launcher is not an OS sandbox."
  echo "- No production access, deploy, live orders, production migrations, SSH/VPS operations, P-3 replay, or ECONOM/econom-group access is authorized."
  echo "- Only a positively verified isolated test database may be used for test migrations."
  echo "- The newer VECTOR v2.3 specification is out of scope."
} > "$PREFLIGHT"

# Snapshot source as a recovery aid. Never include .git, secrets, dependencies,
# prior run snapshots, or ECONOM/econom-group. Do not clean or alter any source.
if ! tar -czf "$SNAP" \
  --exclude='.git' \
  --exclude='node_modules' --exclude='vendor' \
  --exclude='.env' --exclude='.env.*' \
  --exclude='*.pem' --exclude='*.key' \
  --exclude='.ssh' --exclude='.aws' --exclude='.gnupg' \
  --exclude='ECONOM' --exclude='ECONOM/***' \
  --exclude='econom-group' --exclude='econom-group/***' \
  --exclude='reports/overnight' \
  --exclude='.cache' --exclude='coverage' --exclude='dist' \
  --exclude='*.sqlite' --exclude='*.sqlite3' \
  --exclude='*.db' \
  -C "$REPO" .; then
  fail "Snapshot creation failed. OpenCode was not started. Snapshot path: $SNAP"
fi
[[ -s "$SNAP" ]] || fail 'Snapshot is missing or empty.'
tar -tzf "$SNAP" >/dev/null || fail 'Snapshot integrity check failed. OpenCode was not started.'
SNAP_HASH="$(sha256sum "$SNAP" | awk '{print $1}')"
SNAP_SIZE="$(stat -c '%s' "$SNAP")"
printf '\n- Snapshot: %s\n- Snapshot SHA-256: %s\n- Snapshot bytes: %s\n' \
  "$SNAP" "$SNAP_HASH" "$SNAP_SIZE" >> "$PREFLIGHT"

# Auto-approval supports unattended local work but does not sandbox shell commands.
# Never rely on these rules as protection against arbitrary Bash commands.
OPENCODE_CONFIG_CONTENT="$(cat <<'JSON'
{"permission":{"*":"allow","external_directory":{"*":"deny","$HOME/go/**":"allow","$HOME/.cache/go-build/**":"allow","$HOME/.npm/**":"allow","$HOME/.cache/composer/**":"allow","/tmp/**":"allow"},"read":{"*":"allow","*.env":"deny","*.env.*":"deny","*.env.example":"allow","*.pem":"deny","*.key":"deny","$HOME/.ssh/**":"deny","$HOME/.aws/**":"deny","$HOME/.gnupg/**":"deny"},"edit":{"*":"allow","*.env":"deny","*.env.*":"deny","*.pem":"deny","*.key":"deny","$HOME/.ssh/**":"deny","$HOME/.aws/**":"deny"}}}
JSON
)"
printf '%s' "$OPENCODE_CONFIG_CONTENT" | python3 -c 'import sys,json; json.load(sys.stdin)' \
  || fail 'OpenCode permission configuration is invalid JSON.'

# Require explicit acknowledgement if this user can reach a shared Docker daemon.
# Even with acknowledgement, the task prompt prohibits Docker CLI/service changes.
if id -nG | tr ' ' '\n' | grep -qx docker; then
  [[ "${ALLOW_SHARED_DOCKER_RISK:-0}" == "1" ]] || \
    fail 'Docker-group access detected. After verifying isolated test services and removing production credentials, explicitly acknowledge with ALLOW_SHARED_DOCKER_RISK=1. This is not isolation.'
  warn 'Docker-group access acknowledged. Do not run Docker commands or alter any Docker resource during this task.'
fi

printf '%s\n' \
  "RUN_ID=$RUN_ID" \
  "SPEC=$SPEC" \
  "SPEC_SHA256=$SPEC_HASH" \
  "PREFLIGHT=$PREFLIGHT" \
  "SNAPSHOT=$SNAP" \
  "SNAPSHOT_SHA256=$SNAP_HASH" \
  "LOG=$LOG" > "$RUN_DIR/LAUNCH_INFO.txt"

TASK_PROMPT="Execute and complete the attached VECTOR v1.1 Unattended Local Engineering Execution Contract at $SPEC. Treat that exact file as the authoritative engineering scope. Do not execute any other specification. The newer VECTOR v2.3 specification is explicitly OUT OF SCOPE: do not implement it, start it, or use it as the current task. Do not start a next-stage feature after this contract. RUN_ID=$RUN_ID. Run-specific launcher evidence is in reports/overnight/runs/$RUN_ID/. Soft shutdown UTC=$SOFT_DEADLINE. Hard deadline UTC=$HARD_DEADLINE.

IMPLEMENTATION REQUIREMENT: work sequentially through all applicable phases and deliverables in the contract; do not stop after planning or merely write a report. Start with the required inventory and preflight, preserve the dirty worktree, inspect existing implementation before adding anything, and create durable phase checkpoints. Complete the PHP/PostgreSQL reliability fixes, Go profiling/optimization/parity work, P-1 sanity, P-2, P-5, P-4, P-6, all five research candidate families, A0-A3 in flag-off/paper-only mode, and final regression/evidence to the extent allowed by the contract and actual available prerequisites. For every blocked phase, record exact evidence and continue only safe independent work. Zero eligible strategies due to absent prospective history is an acceptable result; never synthesize forward history or weaken Gate 6. Do not claim incomplete phases are complete.

PRESERVE HISTORY: do not truncate, clear, or replace meaningful existing reports. Append updates to reports/overnight/RUN_STATUS.md, COMMAND_LOG.md, CHANGES.md, and BLOCKERS.md; keep older runs and checkpoints. Put run-specific command logs and checkpoints under reports/overnight/runs/$RUN_ID/ and do not put secrets in reports. Before each edit, inspect the current file and its existing diff. Never reset, clean, restore, checkout, overwrite, or discard unrelated user changes. Do not create a clean worktree that loses existing QIM/G1/P-1 work.

SAFETY: no live orders or exchange trade keys; no production deployment, production DB migrations, GHCR publishing, remote SSH/SCP, VPS changes, or manual GitHub Actions trigger. Do not access or modify ECONOM/econom-group or any related/shared resources. Do not run docker, docker compose, docker-compose, sudo, or alter containers, images, volumes, networks, or Docker services. Use only a positively verified isolated test PostgreSQL over localhost TCP; if isolation is unverified, do not migrate or mutate it, mark that gate BLOCKED, and continue safe independent work. Preserve exactly eight original primary paper accounts, their ledgers and balances; all autonomous adoption flags remain OFF. P-3 rejected-signal replay remains prohibited.

FINAL GIT CLOSEOUT — EXPLICIT LATER OWNER AUTHORIZATION: the owner now authorizes a normal scoped git commit and push for the verified work from this contract, superseding ONLY the original contract's prohibition on git commit/push. No other safety prohibition is lifted. Do not commit or push until the implementation and relevant regression checks are complete, the exact file list has been reviewed, no secrets or unrelated changes are included, and there are no unresolved failing tests in the files being committed. Document blocked checks honestly; if a blocked prerequisite prevents verifying a change, exclude that change from the commit. Do not stage everything blindly. Exclude this launcher, the original instruction/spec files, any v2.3 specification, run logs/checkpoints/snapshots, local databases, binaries, secrets, and unrelated staged or dirty files unless an explicit deliverable requires a particular report. Preserve unrelated pre-existing staged changes. If safe file-level separation is impossible, do not commit and explain why. Create a descriptive normal commit (no amend, no reset, no rebase, no force push). Before pushing, inspect the current branch, remote and .github/workflows. Push only if the current branch/remote are appropriate and no push-triggered workflow can deploy to production, publish images, or mutate production resources. If push could cause such effects or the workflow cannot be confidently evaluated, keep the safe local commit and report PUSH_BLOCKED; never trigger deployment to satisfy this task.

DEADLINE: when the soft deadline is reached, stop starting new development phases and use remaining time for tests relevant to completed changes, evidence, checksums, RUN_STATUS/CHANGES/BLOCKERS, NEXT_SESSION_PROMPT.md, and a final checkpoint. Do not exceed the external timeout. A phase can be DEFERRED/BLOCKED; report the exact continuation steps.

FINAL RESPONSE must follow the ten items required by the contract and include actual commands/exit codes, verified vs unverified requirements, performance before/after, PROFIT-1 gates, five candidate data sufficiency, A-series flags/Gate 6, safety, evidence paths, next actions, and (only if safe) commit SHA and push result. Never invent results."

ARGS=(timeout --signal=INT --kill-after=5m "${MAX_HOURS}h" opencode run "$TASK_PROMPT" --auto --agent build)
[[ -z "$MODEL" ]] || ARGS+=(--model "$MODEL")
# Keep --file and its argument last for OpenCode versions where --file is array-valued.
ARGS+=(--file "$SPEC")

# Build a dedicated worker script instead of nesting several layers of
# double-quoted shell strings. This prevents the launcher shell from expanding
# the worker's rc variable under `set -u`.
WORKER="$RUN_DIR/worker.sh"
{
  printf '%s\n' '#!/usr/bin/env bash' 'set -uo pipefail'
  printf 'cd %q\n' "$REPO"
  printf 'export OPENCODE_CONFIG_CONTENT=%q\n' "$OPENCODE_CONFIG_CONTENT"
  printf '%q ' "${ARGS[@]}"
  printf '>> %q 2>&1\n' "$LOG"
  cat <<'WORKER_EOF'
rc=$?
printf '# Finished UTC=%s exit=%s\n' "$(date -u +%FT%TZ)" "$rc" >> "$LOG"
exit "$rc"
WORKER_EOF
} > "$WORKER"
chmod 700 "$WORKER"

tmux new-session -d -s "$SESSION" "bash $(printf '%q' "$WORKER")" || fail 'tmux could not start OpenCode.'

printf 'Started: %s (MAX_HOURS=%s)\n' "$SESSION" "$MAX_HOURS"
printf 'RUN_ID: %s\nRun directory: %s\n' "$RUN_ID" "$RUN_DIR"
printf 'Authoritative spec: %s\nSHA-256: %s\n' "$SPEC" "$SPEC_HASH"
printf 'Log: %s\nPreflight: %s\nSnapshot: %s\n' "$LOG" "$PREFLIGHT" "$SNAP"
printf 'Watch: tail -f %q\n' "$LOG"
printf 'Attach: tmux attach -t %q\n' "$SESSION"
printf 'Check: tmux has-session -t %q && echo ACTIVE || echo STOPPED\n' "$SESSION"
printf 'Scope: VECTOR v1.1 completion only. VECTOR v2.3 is NOT to be executed.\n'
