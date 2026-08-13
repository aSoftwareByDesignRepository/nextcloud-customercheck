#!/usr/bin/env python3
"""Merge FR/IT translation batch maps into l10n/fr.json and l10n/it.json.

Batch files live in scripts/_l10n_fr_it_batches/ as batch_NN.json:
  { "<en msgid>": { "fr": "...", "it": "..." }, ... }

Usage:
  python3 scripts/_write_fr_it_l10n.py          # merge all batches + write catalogs
  python3 scripts/_write_fr_it_l10n.py --check  # report coverage gaps only
"""
from __future__ import annotations

import argparse
import json
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
L10N = ROOT / "l10n"
BATCH_DIR = pathlib.Path(__file__).resolve().parent / "_l10n_fr_it_batches"

PH = re.compile(r"(%%|%\d+\$[sd]|%[sdn]|\{[^}]+\}|#\{[^}]+\})")

ALLOW_IDENTICAL = {
    "CustomerCheck",
    "CustomerCheck Today",
    "ProjectCheck",
    "TicketCheck",
    "InvoiceCheck",
    "BudgetCheck",
    "ArbeitszeitCheck",
    "DutyCheck",
    "DeskCheck",
    "MobilityCheck",
    "AudioCheck",
    "AGPL",
    "CSV",
    "QR",
    "ICS",
    "ISO",
    "OK",
    "UTC",
    "IANA",
    "HTTP",
    "JSON",
    "API",
    "MIME",
    "GDPR",
    "CUC2",
    "CUC2.…",
    "Nextcloud",
    "GitHub",
    "Talk",
    "CRM",
    "Deal",
    "Deals",
    "Check Partner",
    "IMAP",
    "SMTP",
    "PDF",
    "URL",
    "IBAN",
    "HTML",
    "OCR",
    "UUID",
    "Circle",
    "ID",
    "Status",
    "Name",
    "Info",
    "Export",
    "Import",
    "Filter",
    "Board",
    "Lead",
    "Partner",
    "Auto",
    "App",
    "Detail",
    "Details",
    "Feeds",
    "Blocker",
    "Champion",
    "Forecast",
    "Organisation",
    "Overhead",
    "Normal",
    "Continue",
    "Person",
    "Team",
    "System",
    "Tickets",
    "Timing",
    "Webhook",
    "Optional:",
    "Required:",
    "Selected:",
    "%1$s: %2$s. %3$s. %4$s",
    "—",
    "–",
    "-",
}


def placeholders(s: str) -> list[str]:
    return PH.findall(s)


def load_batches() -> dict[str, dict[str, str]]:
    merged: dict[str, dict[str, str]] = {}
    if not BATCH_DIR.is_dir():
        return merged
    for path in sorted(BATCH_DIR.glob("batch_*.json")):
        data = json.loads(path.read_text(encoding="utf-8"))
        for key, pair in data.items():
            if not isinstance(pair, dict) or "fr" not in pair or "it" not in pair:
                raise SystemExit(f"Bad entry in {path.name}: {key!r}")
            merged[key] = {"fr": pair["fr"], "it": pair["it"]}
    return merged


def write_catalog(locale: str, plural: str, translations: dict[str, str]) -> None:
    path = L10N / f"{locale}.json"
    payload = {"translations": translations, "pluralForm": plural}
    text = json.dumps(payload, ensure_ascii=False, indent=4) + "\n"
    path.write_text(text, encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()

    en = json.loads((L10N / "en.json").read_text(encoding="utf-8"))
    en_t: dict[str, str] = en["translations"]
    fr_plural = json.loads((L10N / "fr.json").read_text(encoding="utf-8"))["pluralForm"]
    it_plural = json.loads((L10N / "it.json").read_text(encoding="utf-8"))["pluralForm"]

    batches = load_batches()
    missing = [k for k in en_t if k not in batches]
    print(f"en keys: {len(en_t)}")
    print(f"batch keys: {len(batches)}")
    print(f"missing: {len(missing)}")
    if missing[:10]:
        print("first missing:", missing[:10])

    if args.check:
        return 0 if not missing else 1

    if missing:
        print("ERROR: incomplete batches — refuse to write", file=sys.stderr)
        return 1

    fr_out: dict[str, str] = {}
    it_out: dict[str, str] = {}
    ph_errors: list[str] = []
    for key in en_t:
        pair = batches[key]
        fr_s, it_s = pair["fr"], pair["it"]
        if placeholders(key) != placeholders(fr_s):
            ph_errors.append(f"fr PH mismatch: {key!r} -> {fr_s!r}")
        if placeholders(key) != placeholders(it_s):
            ph_errors.append(f"it PH mismatch: {key!r} -> {it_s!r}")
        fr_out[key] = fr_s
        it_out[key] = it_s

    if ph_errors:
        print("\n".join(ph_errors[:40]), file=sys.stderr)
        print(f"ERROR: {len(ph_errors)} placeholder mismatches", file=sys.stderr)
        return 1

    write_catalog("fr", fr_plural, fr_out)
    write_catalog("it", it_plural, it_out)
    print("Wrote l10n/fr.json and l10n/it.json")

    ident = []
    for key, en_s in en_t.items():
        if key in ALLOW_IDENTICAL or len(en_s) <= 3:
            continue
        if fr_out[key] == en_s:
            ident.append(("fr", key))
        if it_out[key] == en_s:
            ident.append(("it", key))
    print(f"identical-to-en (>3, non-allow): {len(ident)}")
    for loc, key in ident[:30]:
        print(f"  {loc}: {key[:100]}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
