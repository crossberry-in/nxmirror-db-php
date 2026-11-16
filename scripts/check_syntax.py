#!/usr/bin/env python3
"""Syntax checker for the nxmirror-db PHP SDK (no PHP CLI required).

Parses every .php file in the repository with tree-sitter-php and reports any
node of type ERROR (or MISSING), exiting nonzero when at least one is found.

Usage:
    python3 scripts/check_syntax.py
"""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent


def build_parser():
    """Return a tree-sitter parser for PHP, supporting both modern and legacy APIs."""
    # Modern API: py-tree-sitter >= 0.22 with the tree_sitter_php wheel.
    try:
        import tree_sitter_php
        from tree_sitter import Language, Parser

        factory = getattr(tree_sitter_php, "language_php", None) or tree_sitter_php.language
        language = Language(factory())
        try:
            return Parser(language)
        except TypeError:
            parser = Parser()
            parser.set_language(language)
            return parser
    except ImportError:
        pass

    # Legacy fallback: the tree_sitter_languages all-in-one package.
    try:
        from tree_sitter_languages import get_parser

        return get_parser("php")
    except ImportError:
        pass

    raise SystemExit(
        "tree-sitter PHP grammar not available. Install one of:\n"
        "  pip install tree-sitter tree-sitter-php\n"
        "  pip install tree_sitter_languages"
    )


def collect_errors(node):
    """Yield ERROR and MISSING nodes from a tree-sitter syntax tree."""
    if node.type == "ERROR" or node.is_missing:
        yield node
    for child in node.children:
        yield from collect_errors(child)


def main() -> int:
    parser = build_parser()
    files = sorted(
        path
        for path in ROOT.rglob("*.php")
        if "vendor" not in path.parts
    )
    if not files:
        print("No .php files found under", ROOT)
        return 1

    total_errors = 0
    for path in files:
        source = path.read_bytes()
        tree = parser.parse(source)
        errors = list(collect_errors(tree.root_node))
        label = "OK" if not errors else f"{len(errors)} parse error(s)"
        print(f"{label:<18} {path.relative_to(ROOT)}")
        for error in errors:
            line = source[: error.start_byte].count(b"\n") + 1
            snippet = (
                source[error.start_byte : error.start_byte + 90]
                .decode("utf-8", "replace")
                .replace("\n", " ")
            )
            print(f"    line {line}: [{error.type}] {snippet}")
        total_errors += len(errors)

    print()
    print(f"Checked {len(files)} PHP file(s) with tree-sitter-php: "
          f"{total_errors} parse error node(s) found.")
    return 1 if total_errors else 0


if __name__ == "__main__":
    sys.exit(main())
