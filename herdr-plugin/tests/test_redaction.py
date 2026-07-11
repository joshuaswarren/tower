"""Tests for redaction.py — pure-function, no I/O.

Asserts byte-level equivalence on the redacted text and exact replacement
counts. The chain is the last line of defense before tier-1 tails leave
the box, so this is the most security-sensitive module in the bridge.
"""

from __future__ import annotations

import os
import unittest

from tests import _path  # noqa: F401  -- bootstrap sys.path
from redaction import redact


class BuiltinPatternsTest(unittest.TestCase):
    def test_aws_access_key_id_redacted(self) -> None:
        text = "key=AKIAIOSFODNN7EXAMPLE rest"
        out, n = redact(text)
        self.assertIn("[REDACTED:aws_access_key_id]", out)
        self.assertNotIn("AKIAIOSFODNN7EXAMPLE", out)
        self.assertEqual(n, 1)

    def test_github_pat_redacted(self) -> None:
        token = "ghp_" + "a" * 36
        out, n = redact(f"Authorization: token {token}")
        self.assertIn("[REDACTED:github_pat]", out)
        self.assertNotIn(token, out)
        self.assertEqual(n, 1)

    def test_openai_sk_key_redacted(self) -> None:
        key = "sk-" + "x" * 40
        out, n = redact(f"openai={key}")
        self.assertIn("[REDACTED:sk_key]", out)
        self.assertNotIn(key, out)
        self.assertEqual(n, 1)

    def test_anthropic_key_redacted(self) -> None:
        key = "sk-ant-" + "y" * 40
        out, n = redact(f"anthropic_key={key}")
        self.assertIn("[REDACTED:anthropic_key]", out)
        self.assertNotIn(key, out)
        self.assertEqual(n, 1)

    def test_pem_block_redacted(self) -> None:
        text = (
            "-----BEGIN RSA PRIVATE KEY-----\n"
            "MIIEowIBAAKCAQEAabcd1234\n"
            "-----END RSA PRIVATE KEY-----\n"
        )
        out, n = redact(text)
        self.assertIn("[REDACTED:pem_private_key]", out)
        self.assertNotIn("MIIEowIBAAKCAQEAabcd1234", out)
        # Single block, single redaction.
        self.assertEqual(n, 1)

    def test_bearer_header_redacted(self) -> None:
        out, n = redact("Authorization: Bearer abcdefghijklmnop")
        self.assertIn("[REDACTED:bearer]", out)
        self.assertNotIn("abcdefghijklmnop", out)
        self.assertEqual(n, 1)

    def test_high_entropy_hex_redacted(self) -> None:
        token = "a" * 48  # 48 hex chars
        out, n = redact(f"hash={token} done")
        self.assertIn("[REDACTED:high_entropy_hex]", out)
        self.assertNotIn(token, out)
        self.assertEqual(n, 1)

    def test_no_secret_no_redaction(self) -> None:
        text = "Hello, world. Nothing sensitive here."
        out, n = redact(text)
        self.assertEqual(out, text)
        self.assertEqual(n, 0)

    def test_short_hex_not_redacted(self) -> None:
        # 8-char hex strings (commit shas) should NOT trigger the high_entropy rule.
        text = "commit=abcdef12"
        out, n = redact(text)
        self.assertEqual(out, text)
        self.assertEqual(n, 0)

    def test_multiple_patterns_counted_separately(self) -> None:
        text = "AKIAIOSFODNN7EXAMPLE ghp_" + "a" * 36
        out, n = redact(text)
        self.assertIn("[REDACTED:aws_access_key_id]", out)
        self.assertIn("[REDACTED:github_pat]", out)
        self.assertEqual(n, 2)


class EnvValueStrippingTest(unittest.TestCase):
    def test_environ_value_stripped_byte_level(self) -> None:
        # The bridge passes os.environ.values() into redact(). A value that
        # is actually present in the daemon's environment must come out of
        # the tail with that exact byte sequence absent.
        sentinel = "TOWER_BRIDGE_TEST_SECRET_VALUE_123456"
        # Simulate: operator exported this into the env; tail includes it.
        text = f"export FOO={sentinel}\nNext line\n"
        # Pre-condition: the sentinel must NOT be in extra_values as a
        # false positive — it must be exactly the env value we want to
        # strip. We pass it via extra_values explicitly.
        out, n = redact(text, extra_values=[sentinel])
        self.assertNotIn(sentinel, out, "sentinel value leaked through redaction")
        self.assertIn("[REDACTED:env]", out)
        self.assertEqual(n, 1)

    def test_environ_real_os_environ_value_stripped(self) -> None:
        # End-to-end: pass os.environ values directly, like the daemon does.
        os.environ.setdefault("HERDR_TEST_VAR_LONG_ENOUGH", "supervalue-1234567890abcdef")
        text = "the secret is supervalue-1234567890abcdef right here"
        out, n = redact(text, extra_values=list(os.environ.values()))
        self.assertNotIn("supervalue-1234567890abcdef", out)
        self.assertIn("[REDACTED:env]", out)
        self.assertGreaterEqual(n, 1)

    def test_short_env_value_ignored(self) -> None:
        # 5-char values are too short to redact safely.
        out, n = redact("the cat sat", extra_values=["cat"])
        self.assertEqual(out, "the cat sat")
        self.assertEqual(n, 0)

    def test_env_value_word_bounded(self) -> None:
        # Don't redact the secret when it's part of a longer token (word boundary).
        out, n = redact("the_supervalue-1234567890abcdefsuffix",
                        extra_values=["supervalue-1234567890abcdef"])
        self.assertEqual(out, "the_supervalue-1234567890abcdefsuffix")
        self.assertEqual(n, 0)


class UserDenyRegexesTest(unittest.TestCase):
    def test_user_regex_applied_last(self) -> None:
        # User regex matches something the built-ins missed.
        out, n = redact("internal-id: ABC-12345-XYZ", deny_regexes=[r"ABC-\d{4,}-XYZ"])
        self.assertIn("[REDACTED:user]", out)
        self.assertNotIn("ABC-12345-XYZ", out)
        self.assertEqual(n, 1)

    def test_invalid_user_regex_skipped(self) -> None:
        # A bad regex from config must NOT crash the daemon. It should be
        # silently dropped — operator will notice missing redactions and
        # fix the config.
        out, n = redact("the secret", deny_regexes=[r"(unclosed"])
        self.assertEqual(out, "the secret")
        self.assertEqual(n, 0)

    def test_user_regex_counted_with_builtins(self) -> None:
        out, n = redact("AKIAIOSFODNN7EXAMPLE SECRET-9876",
                        deny_regexes=[r"SECRET-\d+"])
        self.assertIn("[REDACTED:aws_access_key_id]", out)
        self.assertIn("[REDACTED:user]", out)
        self.assertEqual(n, 2)


class EdgeCasesTest(unittest.TestCase):
    def test_empty_text(self) -> None:
        self.assertEqual(redact(""), ("", 0))

    def test_byte_level_idempotent(self) -> None:
        # Redacting the already-redacted text should be a no-op (no re-replacement).
        text = "AKIAIOSFODNN7EXAMPLE"
        out1, _ = redact(text)
        out2, n2 = redact(out1)
        self.assertEqual(out1, out2)
        self.assertEqual(n2, 0)

    def test_combined_chain_count_accurate(self) -> None:
        text = (
            "AKIAIOSFODNN7EXAMPLE "
            "ghp_" + "a" * 36 + " "
            "supervalue-1234567890abcdef "
            "ABC-7777-XYZ"
        )
        _, n = redact(
            text,
            extra_values=["supervalue-1234567890abcdef"],
            deny_regexes=[r"ABC-\d{4}-XYZ"],
        )
        # aws + github + env + user = 4
        self.assertEqual(n, 4)
