#!/usr/bin/env bash
# .claude/hooks/pre-commit-check.sh
#
# Fired by the PreToolUse hook in .claude/settings.json whenever the
# main agent attempts a `git commit`. Runs two fast static checks
# against the staged + unstaged diff:
#
#   1. Orphan FormField context (the bug from 287ce94 — `<FormItem>` /
#      `<FormLabel>` / `<FormControl>` outside any enclosing `<FormField>`,
#      which crashes useFormField() at mount).
#   2. Secret leakage (Anthropic / OpenAI / GitHub / Stripe keys,
#      plaintext passwords, generic api_key="..." in the diff).
#
# If ANY check fails, emit a `permissionDecision: deny` JSON payload
# that blocks the tool call and tells the agent why. Claude Code reads
# the JSON regardless of exit code, so we exit 0 in both branches —
# the deny decision lives entirely in the payload.
#
# The deeper checks (multi-tenant leak, ai-billing-gate bypass,
# buildFlowFromAgent desync, TS regressions) live in the
# code-reviewer subagent, not this fast hook. The hook surfaces a
# soft reminder pointing at the subagent when no P0 issue is found.
#
# This script is INTENTIONALLY narrow. It does not run tsc (too slow
# for a pre-commit hook); the code-reviewer subagent does that. It
# only catches the bugs that have actually shipped to prod and that
# `git diff` can detect with cheap regex.

set -uo pipefail

# Read the JSON payload from stdin (the matcher passed it through).
INPUT=$(cat)

# Extract the command being run.
CMD=$(printf '%s' "$INPUT" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d.get('tool_input',{}).get('command',''))" 2>/dev/null || echo "")

# Only fire on `git commit`. Skip status, diff, log, branch, push, fetch, etc.
# The space after `commit` matters — `git commit-tree` and similar are also commits.
case "$CMD" in
    *"git commit "*|*"git commit"|*"git commit -"*) ;;
    *) exit 0 ;;
esac

# We're in a git commit. Find the repo root from the cwd.
CWD=$(printf '%s' "$INPUT" | python3 -c "import sys, json; d=json.load(sys.stdin); print(d.get('cwd',''))" 2>/dev/null || pwd)
cd "$CWD" 2>/dev/null || exit 0

# Collect changed JS/TS files (staged + unstaged, deduplicated).
CHANGED=$(git diff --name-only HEAD 2>/dev/null | grep -E '\.(ts|tsx|js|jsx)$' || true)

ISSUES=()

# ── Check 1: orphan FormField context ───────────────────────────
# Per file: count opens vs closes of <FormField>, walk the file, and
# flag every <FormLabel|<FormItem|<FormControl that occurs while depth
# is zero.
if [ -n "$CHANGED" ]; then
    while IFS= read -r f; do
        [ -f "$f" ] || continue
        # Skip files that don't import FormItem/FormLabel/FormControl.
        grep -qE 'FormItem|FormLabel|FormControl' "$f" || continue
        ORPHANS=$(python3 - "$f" <<'PY'
import re, sys
fp = sys.argv[1]
with open(fp) as h: lines = h.read().splitlines()
depth = 0
hits = []
for i, ln in enumerate(lines, 1):
    depth += len(re.findall(r'<FormField\b', ln))
    if re.search(r'<(FormLabel|FormItem|FormControl)\b', ln):
        if depth == 0:
            hits.append(f"  {fp}:{i}")
    depth -= len(re.findall(r'</FormField>', ln))
for h in hits: print(h)
PY
)
        if [ -n "$ORPHANS" ]; then
            ISSUES+=("Orphan FormField context (will crash useFormField at mount):")
            while IFS= read -r line; do ISSUES+=("$line"); done <<< "$ORPHANS"
        fi
    done <<< "$CHANGED"
fi

# ── Check 2: secrets in the diff ────────────────────────────────
# Look at added lines (starting with `+`) only; skip diff headers
# (`+++ b/...`). The patterns are deliberately permissive — false
# positives are cheap (the user just removes the line and retries),
# false negatives are expensive (a key lands in the public repo).
#
# Exclude .claude/hooks/ and .claude/agents/ from the scan: those
# files literally contain secret-shaped regex patterns as part of
# their detection logic, and matching them is always a false positive.
DIFF=$(git diff HEAD -- ':(exclude).claude/hooks/' ':(exclude).claude/agents/' 2>/dev/null || true)
if [ -n "$DIFF" ]; then
    SECRET_HITS=$(printf '%s\n' "$DIFF" \
        | grep -E '^\+' \
        | grep -vE '^\+\+\+' \
        | grep -iE 'sk-ant-[a-zA-Z0-9_-]{20,}|sk-[a-zA-Z0-9_-]{40,}|ghp_[a-zA-Z0-9]{30,}|gho_[a-zA-Z0-9]{30,}|github_pat_[a-zA-Z0-9_]{40,}|sk_live_[a-zA-Z0-9]{20,}|sk_test_[a-zA-Z0-9]{20,}|aws_secret_access_key|password\s*[=:]\s*"[^"]{6,}"|api[_-]?key\s*[=:]\s*"[^"]{16,}"|bearer\s+[a-zA-Z0-9_-]{30,}|eyJ[a-zA-Z0-9_-]{20,}\.[a-zA-Z0-9_-]{20,}\.[a-zA-Z0-9_-]{20,}' \
        || true)
    if [ -n "$SECRET_HITS" ]; then
        ISSUES+=("Possible secret in staged diff:")
        while IFS= read -r line; do ISSUES+=("  $line"); done <<< "$SECRET_HITS"
    fi
fi

# ── Decision ────────────────────────────────────────────────────
if [ ${#ISSUES[@]} -gt 0 ]; then
    # Build a multi-line reason and emit the deny JSON.
    REASON="Pre-commit check blocked the commit:\n\n"
    for line in "${ISSUES[@]}"; do
        REASON+="${line}\n"
    done
    REASON+="\nFix these or invoke the code-reviewer subagent for a full audit, then retry the commit."

    # Use python to safely JSON-encode the reason (handles newlines and
    # whatever quotes appear in the matched code lines).
    python3 - "$REASON" <<'PY'
import json, sys
reason = sys.argv[1]
print(json.dumps({
    "hookSpecificOutput": {
        "hookEventName": "PreToolUse",
        "permissionDecision": "deny",
        "permissionDecisionReason": reason,
    }
}))
PY
    exit 0
fi

# No blockers — surface a soft reminder to run the deeper review.
cat <<'JSON'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","additionalContext":"pre-commit-check: static checks passed (no orphan FormField, no obvious secrets). For a deeper audit (multi-tenant leak, ai-billing-gate bypass, buildFlowFromAgent desync, TS regressions), invoke the code-reviewer subagent before this commit."}}
JSON
exit 0
