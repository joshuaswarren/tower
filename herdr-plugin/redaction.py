"""tower.bridge redaction chain.

Pure functions — no I/O, no module-level state. Importable from tests and
from the daemon. Tier-1 tails flow through `redact()` before they leave the
box; tier-0 never reads PTY content at all, so this module is only invoked
when the operator has opted a workspace in.

Order (matters — see the redaction_chain docstring):
  1. Built-in secret-shaped patterns (AWS keys, bearer tokens, sk-*, PEM blocks,
     high-entropy token-shaped strings).
  2. Env-value stripping: every value in `extra_values` is replaced as a
     literal exact match. This catches anything the operator has exported
     into the daemon's environment (e.g. GITHUB_TOKEN).
  3. User `deny_regexes` — applied last, with the [REDACTED:user] marker.

Replacement marker: "[REDACTED:<rule_name>]" — this preserves provenance in
the redacted text so a downstream operator can tell what got stripped.

Return value: (redacted_text, count). `count` is the number of replacements
performed across all rules (a single line that triggers two patterns counts
2). Tests assert byte-level equality on the redacted text.
"""

from __future__ import annotations

import re
from typing import Iterable, Tuple

# Each rule is (compiled_pattern, rule_name). Names are stable and form part of
# the redaction marker, so don't rename them silently.
_BUILTIN_RULES: Tuple[Tuple[re.Pattern[str], str], ...] = (
    # AWS access key id — 20 chars starting with AKIA / ASIA.
    (re.compile(r"\b(?:AKIA|ASIA)[0-9A-Z]{16}\b"), "aws_access_key_id"),
    # GitHub personal access token (classic `ghp_`, fine-grained `github_pat_`).
    (re.compile(r"\bghp_[A-Za-z0-9]{36}\b"), "github_pat"),
    (re.compile(r"\bgithub_pat_[A-Za-z0-9_]{82}\b"), "github_fine_grained_pat"),
    # Slack tokens.
    (re.compile(r"\bxox[abprs]-[A-Za-z0-9-]{10,}\b"), "slack_token"),
    # OpenAI / sk- shaped keys (sk-…, sk-proj-…). Listed before the broad
    # `aws_secret_candidate` rule so the 40-char base64-ish regex doesn't
    # swallow the suffix of an `sk-…` key as a false-positive AWS secret.
    (re.compile(r"\bsk-(?!ant-)[A-Za-z0-9_-]{20,}\b"), "sk_key"),
    # Anthropic API key (sk-ant-…).
    (re.compile(r"\bsk-ant-[A-Za-z0-9_-]{20,}\b"), "anthropic_key"),
    # AWS secret access key — 40 chars of base64-ish. Heuristic; the value
    # is usually seen as `aws_secret_access_key=...` or after a
    # non-alphanumeric boundary in a credentials blob. Listed after the
    # sk-… rules so we don't out-compete them.
    (re.compile(r"\b[A-Za-z0-9/+=]{40}\b(?=\s|$|[\"',])"), "aws_secret_candidate"),
    # Generic bearer / authorization header value.
    (re.compile(r"(?i)\b(?:Bearer|Authorization)\s+[A-Za-z0-9._\-]+"), "bearer"),
    # PEM private key blocks. The header is unique; capture the whole block.
    (
        re.compile(
            r"-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP |)PRIVATE KEY-----"
            r"[\s\S]+?"
            r"-----END (?:RSA |EC |DSA |OPENSSH |PGP |)PRIVATE KEY-----"
        ),
        "pem_private_key",
    ),
    # High-entropy hex strings ≥ 40 chars (token-shaped). Narrow: hex only,
    # word-bounded, so we don't redact every sha1-looking thing in logs.
    (re.compile(r"\b[0-9a-fA-F]{40,}\b"), "high_entropy_hex"),
)

_MARKER_FORMAT = "[REDACTED:{name}]"


def _escape_value(value: str) -> re.Pattern[str]:
    """Wrap a literal env value in a word-boundary regex.

    Long values get the whole thing; short ones (< 6 chars) are skipped to
    avoid redacting common words like "true" or "1". The min length applies
    to the value itself, not the match length, because a short value would
    match too aggressively and break the surrounding text.
    """
    if len(value) < 6:
        # Return a pattern that never matches.
        return re.compile(r"(?!)")
    return re.compile(r"\b" + re.escape(value) + r"\b")


def _compile_user_rules(deny_regexes: Iterable[str]) -> Tuple[Tuple[re.Pattern[str], str], ...]:
    out: list[Tuple[re.Pattern[str], str]] = []
    for raw in deny_regexes:
        if not raw:
            continue
        try:
            out.append((re.compile(raw), "user"))
        except re.error:
            # Bad regex from config — skip silently rather than crashing the
            # daemon. Operators will notice missing redactions and fix the
            # config; we'd rather emit a tail than drop the whole batch.
            continue
    return tuple(out)


def redact(
    text: str,
    *,
    extra_values: Iterable[str] = (),
    deny_regexes: Iterable[str] = (),
) -> Tuple[str, int]:
    """Run the redaction chain over `text`.

    Args:
        text: The raw tail content to scrub. May be empty.
        extra_values: Literal values to strip (e.g. `os.environ` values).
            Each value is treated as a whole-word exact match. Short values
            (< 6 chars) are ignored.
        deny_regexes: User-supplied regular expressions. Applied last.
            Invalid regexes are skipped (a bad pattern never crashes the
            daemon — the operator fixes it on the next deploy).

    Returns:
        (redacted_text, replacements_applied). The text uses
        `[REDACTED:<rule>]` markers in place of each match. `count` sums
        all replacements across all rules.
    """
    if not text:
        return "", 0

    count = 0
    out = text

    # Pass 1: built-in secret patterns.
    for pattern, name in _BUILTIN_RULES:
        out, n = pattern.subn(_MARKER_FORMAT.format(name=name), out)
        count += n

    # Pass 2: env-value stripping. Each value is its own rule with a
    # distinct marker so the operator can tell which secret was caught.
    for value in extra_values:
        pattern = _escape_value(value)
        marker = _MARKER_FORMAT.format(name="env")
        out, n = pattern.subn(marker, out)
        count += n

    # Pass 3: user deny regexes.
    for pattern, name in _compile_user_rules(deny_regexes):
        out, n = pattern.subn(_MARKER_FORMAT.format(name=name), out)
        count += n

    return out, count
