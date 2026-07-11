# tower.bridge — dev Makefile.
# The bridge is stdlib-only at runtime; this Makefile uses nothing outside
# python3 + stdlib either, so it works on the bare LXCs.

PY ?= python3
SRC := tower_bridge.py redaction.py
TESTS := tests

.PHONY: test lint clean

lint:
	@for f in $(SRC); do \
	  $(PY) -m py_compile $$f && echo "  ok   $$f" || exit 1; \
	done

test: lint
	@PYTHONWARNINGS=error::ResourceWarning $(PY) -m unittest discover -v -s tests -t .

# Discover + run with verbose output. Stdlib unittest, no pip.
run-tests:
	@$(PY) -m unittest discover -v -s tests -t .

clean:
	rm -rf __pycache__ */__pycache__ tests/__pycache__ .pytest_cache
