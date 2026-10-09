#!/usr/bin/env python3
"""DEPRECATED — do not re-run for production catalogs.

This historically built da/es/fr/it/nb/nl/pl/sv via Argos machine translation.
Those catalogs are now curated to mother-tongue quality (see docs/l10n-native-style.md
and scripts/check-l10n-native-quality.php). Re-running Argos will trash that work.

Locales: da, es, fr, it, nb, nl, pl, sv (en/de already curated).
Uses offline Argos Translate with placeholder protection.
Writes JSON only — run php scripts/rebuild-l10n-js.php afterwards.
"""
from __future__ import annotations

import json
import pathlib
import re
import sys

if __name__ == "__main__" and "--force-argos" not in sys.argv:
	sys.stderr.write(
		"Refusing to run: Argos MT would overwrite mother-tongue catalogs.\n"
		"See docs/l10n-native-style.md. Pass --force-argos only for experiments.\n"
	)
	sys.exit(2)

from argostranslate import translate as argos_translate

ROOT = pathlib.Path(__file__).resolve().parents[1] / "l10n"
EN = json.loads((ROOT / "en.json").read_text())["translations"]

PLURALS = {
	"da": "nplurals=2; plural=(n != 1);",
	"es": "nplurals=2; plural=(n != 1);",
	"fr": "nplurals=2; plural=(n > 1);",
	"it": "nplurals=2; plural=(n != 1);",
	"nb": "nplurals=2; plural=(n != 1);",
	"nl": "nplurals=2; plural=(n != 1);",
	"pl": "nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);",
	"sv": "nplurals=2; plural=(n != 1);",
}

# Argos language codes (Norwegian Bokmål is "nb").
ARGOS = {
	"da": "da",
	"es": "es",
	"fr": "fr",
	"it": "it",
	"nb": "nb",
	"nl": "nl",
	"pl": "pl",
	"sv": "sv",
}

SKIP_AS_IS = {
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
	"Nextcloud",
	"GitHub",
	"Talk",
	"—",
	"–",
	"-",
}

PH = re.compile(r"(%%|%\d+\$[sd]|%[sd]|\{[^}]+\}|#\{[^}]+\})")


def placeholders(s: str) -> list[str]:
	return PH.findall(s)


def protect(s: str) -> tuple[str, list[str]]:
	parts: list[str] = []

	def repl(m: re.Match[str]) -> str:
		parts.append(m.group(0))
		return f"XPH{len(parts) - 1}X"

	return PH.sub(repl, s), parts


def unprotect(s: str, parts: list[str]) -> str:
	out = s
	for i, p in enumerate(parts):
		for token in (f"XPH{i}X", f"xph{i}x", f"Xph{i}X", f"XPH{i}x", f"Xph{i}x"):
			out = out.replace(token, p)
	return out


def intentionally_english(src: str) -> bool:
	if src in SKIP_AS_IS:
		return True
	if len(src) <= 3 and src.isupper():
		return True
	if "|" in src and re.fullmatch(r"[a-z0-9_|.\s]+", src):
		return True
	stripped = PH.sub("", src).strip(" \t—–-·.,:;|/\\")
	return stripped == ""


def write_json(lang: str, translations: dict[str, str]) -> None:
	full = {k: translations.get(k, EN[k]) for k in EN}
	ordered = {k: full[k] for k in EN}
	data = {"translations": ordered, "pluralForm": PLURALS[lang]}
	(ROOT / f"{lang}.json").write_text(
		json.dumps(data, ensure_ascii=False, indent=4) + "\n"
	)


def translate_one(to_code: str, text: str) -> str:
	if intentionally_english(text):
		return text
	want = placeholders(text)
	prot, parts = protect(text)
	tr = argos_translate.translate(prot, "en", to_code)
	if not tr:
		raise RuntimeError("empty")
	out = unprotect(tr, parts)
	got = placeholders(out)
	if got != want:
		if len(got) == len(want) and sorted(got) == sorted(want):
			return out
		# Soft recovery: restore placeholders from the English source order.
		# Argos occasionally drops or reorders protected tokens.
		if len(want) == 0:
			return out
		raise RuntimeError(f"ph want={want} got={got}")
	return out


def needs_work(existing: dict[str, str], key: str) -> bool:
	if key not in existing or not existing[key]:
		return True
	val = existing[key]
	src = EN[key]
	if val == src and not intentionally_english(src):
		return True
	return placeholders(val) != placeholders(src)


def build_lang(lang: str) -> None:
	path = ROOT / f"{lang}.json"
	existing = dict(json.loads(path.read_text()).get("translations") or {}) if path.exists() else {}
	result = {
		k: v
		for k, v in existing.items()
		if k in EN and v and not needs_work(existing, k)
	}
	todo = [k for k in EN if k not in result]
	to_code = ARGOS[lang]
	print(f"== {lang}: {len(todo)} to translate / {len(EN)} (argos en->{to_code}) ==", flush=True)
	if not todo:
		write_json(lang, result)
		print(f"DONE {lang}: nothing to do", flush=True)
		return

	done = 0
	fails = 0
	for k in todo:
		try:
			result[k] = translate_one(to_code, EN[k])
		except Exception as e:  # noqa: BLE001
			result[k] = EN[k]
			fails += 1
			print(f"  KEEP_EN [{lang}] {k[:70]} :: {e}", flush=True)
		done += 1
		if done % 100 == 0 or done == len(todo):
			write_json(lang, result)
			same = sum(1 for kk in EN if result.get(kk, EN[kk]) == EN[kk])
			print(
				f"  {lang} {done}/{len(todo)} checkpoint identical_to_en={same} fails={fails}",
				flush=True,
			)

	write_json(lang, result)
	same = sum(1 for k in EN if result.get(k, EN[k]) == EN[k])
	broken = [
		k for k in EN if placeholders(result.get(k, EN[k])) != placeholders(EN[k])
	]
	print(
		f"DONE {lang}: identical_to_en={same}/{len(EN)} "
		f"fails={fails} placeholder_broken={len(broken)}",
		flush=True,
	)
	if broken:
		raise SystemExit(3)


def main() -> int:
	langs = sys.argv[1:] or list(PLURALS)
	for lang in langs:
		if lang not in PLURALS:
			raise SystemExit(f"unknown lang {lang}")
		build_lang(lang)
	print("ALL REQUESTED LOCALES COMPLETE", flush=True)
	return 0


if __name__ == "__main__":
	raise SystemExit(main())
