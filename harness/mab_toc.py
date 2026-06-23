#!/usr/bin/env python3
"""
mab_toc.py — extract an issue's TABLE OF CONTENTS via Claude vision, to recover
the TRUE article->page-range map (the WP `mab_pages` ranges are often over-merged).

Renders a span of front-matter pages, asks the model to read the contents page(s),
and emits ordered entries {title, author, start_page, kind}. End page of each entry
is inferred as (next entry's start_page - 1); the last entry's end is left null.

Usage:
  python3 mab_toc.py --pdf "....pdf" --offset 6 --scan 3-7 [--out toc_v4i4.json]
  (--scan is in PRINTED page numbers; PDF page = printed + offset)
Env: ANTHROPIC_API_KEY
"""
import argparse, base64, json, os, subprocess, sys, tempfile
from pathlib import Path
import anthropic

DPI = 170
MODEL = os.environ.get("MAB_MODEL", "claude-sonnet-4-6")

TOC_TOOL = {
    "name": "emit_toc",
    "description": "Return the issue's table of contents as ordered entries.",
    "input_schema": {
        "type": "object",
        "properties": {
            "entries": {"type": "array", "items": {"type": "object", "properties": {
                "title": {"type": "string", "description": "The piece's title exactly as printed in the contents."},
                "author": {"type": "string", "description": "Byline if shown in the TOC, else empty."},
                "start_page": {"type": "integer", "description": "The PRINTED page number where the piece starts (as listed in the contents)."},
                "kind": {"type": "string", "description": "One of: feature, column, department, fiction, editorial, other."},
            }, "required": ["title", "start_page"]}},
            "calibration": {"type": "array", "description": (
                "For offset detection: for each provided image on which a PRINTED page-number (folio) is "
                "visible, report {image_index (1-based, in the order images were given), printed_number}. "
                "Give at least one; more is better."), "items": {"type": "object", "properties": {
                "image_index": {"type": "integer"}, "printed_number": {"type": "integer"}},
                "required": ["image_index", "printed_number"]}},
            "notes": {"type": "string"},
        },
        "required": ["entries"],
    },
}
SYSTEM = (
    "You are reading the printed Table of Contents page(s) of an issue of the running magazine "
    "Marathon & Beyond. Transcribe EVERY listed piece in the order printed, with its title, byline "
    "if shown, and the printed starting page number. Include features, columns, departments, fiction, "
    "and the editorial. Do not invent entries; only list what the contents page shows."
)

def b64(p): return base64.standard_b64encode(Path(p).read_bytes()).decode()

def render(pdf, lo, hi, outdir):
    subprocess.run(["pdftoppm","-f",str(lo),"-l",str(hi),"-r",str(DPI),"-png",pdf,str(Path(outdir)/"pg")], check=True)
    return sorted(Path(outdir).glob("pg-*.png"))

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--pdf", required=True)
    ap.add_argument("--offset", type=int, required=True)
    ap.add_argument("--scan", default="3-7", help="printed page range to scan for the TOC")
    ap.add_argument("--out", default="")
    args = ap.parse_args()
    lo, hi = (int(x) for x in args.scan.split("-"))
    plo, phi = lo + args.offset, hi + args.offset
    client = anthropic.Anthropic(api_key=os.environ["ANTHROPIC_API_KEY"])
    with tempfile.TemporaryDirectory() as td:
        pages = render(args.pdf, plo, phi, td)
        content = [{"type":"image","source":{"type":"base64","media_type":"image/png","data":b64(p)}} for p in pages]
        content.append({"type":"text","text":"These are the issue's front-matter pages. Find the contents page and emit the full TOC via emit_toc."})
        msg = client.messages.create(model=MODEL, max_tokens=4000, system=SYSTEM,
            tools=[TOC_TOOL], tool_choice={"type":"tool","name":"emit_toc"},
            messages=[{"role":"user","content":content}])
        toc = next(b.input for b in msg.content if b.type=="tool_use")
    # infer end pages
    ents = sorted(toc.get("entries", []), key=lambda e: e.get("start_page", 0))
    for i, e in enumerate(ents):
        e["end_page"] = (ents[i+1]["start_page"] - 1) if i+1 < len(ents) else None
    toc["entries"] = ents
    out = json.dumps(toc, indent=2, ensure_ascii=False)
    if args.out:
        Path(args.out).write_text(out)
    print(out)
    print(f"\n[tokens in={msg.usage.input_tokens} out={msg.usage.output_tokens}]", file=sys.stderr)

if __name__ == "__main__": main()
