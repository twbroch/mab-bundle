#!/usr/bin/env python3
"""
mab_offline.py — render + Claude-vision extraction ONLY (no WordPress calls).

BigScoots' WAF blocks non-browser requests to marathonhandbook.com, so WP
read/write happens through the signed-in browser tab instead. This script does
the part that needs no WP: render source PDF pages -> Claude vision -> clean
structured article JSON (+ cropped figure PNGs) on disk. The browser side then
reads these JSONs and pushes them.

Input: a --spec JSON:
{
  "pdf": "/path/to/issue.pdf",
  "offset": 6,                      # PDF page = printed page + offset
  "articles": [ {"id":905251, "pages":"41-46", "title_now":"ous It's a..."}, ... ]
}

Output (in --out, default ./mab_out):
  <id>.json         {id, old_title, title, subtitle, author, body_html (with [[FIGURE:n]]),
                     figures:[{file, caption, page_index, bbox}], notes, content_len}
  <id>_figN.png     cropped figure images (uploaded later via the browser)

Env: ANTHROPIC_API_KEY
Usage:
  python3 mab_offline.py --spec spec.json --only 905251,905252
  python3 mab_offline.py --spec spec.json            # all in spec
"""
import argparse, base64, json, os, re, subprocess, sys, tempfile, time
from pathlib import Path
import anthropic

DPI = 170
MAX_PAGES_PER_CALL = 4          # keep each request's input tokens under low TPM caps
MODEL = os.environ.get("MAB_MODEL", "claude-opus-4-8")
PACE_SECONDS = float(os.environ.get("MAB_PACE", "8"))   # gap between calls

EXTRACT_TOOL = {
    "name": "emit_article",
    "description": "Return the cleaned, structured article extracted from the page images.",
    "input_schema": {
        "type": "object",
        "properties": {
            "title": {"type": "string", "description": "The real headline, corrected: no OCR garble, no leading fragments, proper Title Case."},
            "subtitle": {"type": "string", "description": "Deck/standfirst if present, else empty."},
            "author": {"type": "string", "description": "Byline author(s), else empty."},
            "body_html": {"type": "string", "description": (
                "Article body as clean semantic HTML: <p>, <h2>/<h3>, <ul>/<li>, real <table>. "
                "Put [[FIGURE:n]] on its own line where each figure belongs (1-based, matching figures[]). "
                "EXCLUDE ads, subscription/order forms, addresses, running heads, page numbers, 'MIN READ', "
                "and any text belonging to a DIFFERENT article. Fix OCR errors; de-hyphenate line breaks.")},
            "figures": {"type": "array", "items": {"type": "object", "properties": {
                "page_index": {"type": "integer", "description": "0-based index into the images in THIS call."},
                "bbox": {"type": "array", "items": {"type": "number"}, "description": "[x0,y0,x1,y1] fractions 0..1 (left,top,right,bottom)."},
                "caption": {"type": "string"}}, "required": ["page_index", "bbox"]},
                "description": "Photos/illustrations belonging to THIS article (skip ads)."},
            "notes": {"type": "string", "description": "Anything uncertain, else empty."},
        },
        "required": ["title", "body_html", "figures"],
    },
}
SYSTEM = (
    "You are digitizing scanned pages of the running magazine Marathon & Beyond into a clean web archive. "
    "You are given the page images for ONE article in order. Reconstruct ONLY that article faithfully. "
    "Reading order across multi-column layouts must be correct. Reproduce tables as real HTML tables with "
    "accurate numbers. Preserve the author's words; fix only OCR damage. Ruthlessly drop ads, order forms, "
    "addresses, running heads, page numbers, and any column/table text that clearly belongs to a neighbouring article."
)

def log(*a): print(*a, file=sys.stderr, flush=True)
def b64(p): return base64.standard_b64encode(Path(p).read_bytes()).decode()

def render(pdf, lo, hi, outdir):
    subprocess.run(["pdftoppm","-f",str(lo),"-l",str(hi),"-r",str(DPI),"-png",pdf,str(Path(outdir)/"pg")], check=True)
    return sorted(Path(outdir).glob("pg-*.png"))

def extract(client, images):
    content = [{"type":"image","source":{"type":"base64","media_type":"image/png","data":b64(p)}} for p in images]
    content.append({"type":"text","text":"Extract this article per the emit_article tool. The images above are its pages in order."})
    last = None
    for attempt in range(8):
        try:
            msg = client.messages.create(model=MODEL, max_tokens=16000, system=SYSTEM,
                tools=[EXTRACT_TOOL], tool_choice={"type":"tool","name":"emit_article"},
                messages=[{"role":"user","content":content}])
            for b in msg.content:
                if b.type=="tool_use" and b.name=="emit_article": return b.input, msg.usage
            raise RuntimeError("no emit_article")
        except anthropic.RateLimitError as e:
            wait = 60
            try: wait = int(e.response.headers.get("retry-after", "60")) + 2
            except Exception: pass
            log(f"   rate-limited; sleeping {wait}s (attempt {attempt+1}/8)")
            time.sleep(wait); last = e
        except (anthropic.APIStatusError, anthropic.APIConnectionError) as e:
            log(f"   API error ({type(e).__name__}); retrying in 15s"); time.sleep(15); last = e
    raise last or RuntimeError("extract failed")

def crop(pdf, pdf_page, bbox, outpath):
    pw, ph = int(6*DPI), int(9*DPI)
    x0,y0,x1,y1 = bbox
    x,y = max(0,int(x0*pw)), max(0,int(y0*ph))
    w,h = max(1,int((x1-x0)*pw)), max(1,int((y1-y0)*ph))
    prefix = str(outpath).rsplit(".",1)[0]
    subprocess.run(["pdftoppm","-f",str(pdf_page),"-l",str(pdf_page),"-r",str(DPI),
                    "-x",str(x),"-y",str(y),"-W",str(w),"-H",str(h),"-png","-singlefile",pdf,prefix], check=True)
    return prefix+".png"

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--spec", required=True)
    ap.add_argument("--out", default="./mab_out")
    ap.add_argument("--only", default="")
    ap.add_argument("--limit", type=int, default=0)
    args = ap.parse_args()
    spec = json.loads(Path(args.spec).read_text())
    pdf, offset = spec["pdf"], int(spec["offset"])
    arts = spec["articles"]
    if args.only:
        keep = {int(x) for x in args.only.split(",")}
        arts = [a for a in arts if int(a["id"]) in keep]
    if args.limit: arts = arts[:args.limit]
    Path(args.out).mkdir(parents=True, exist_ok=True)
    client = anthropic.Anthropic(api_key=os.environ["ANTHROPIC_API_KEY"])
    tot_in=tot_out=0
    summary=[]
    for a in arts:
        pid=int(a["id"]); m=re.match(r"\s*(\d+)\s*(?:[-–]\s*(\d+))?", a["pages"])
        lo=int(m.group(1)); hi=int(m.group(2)) if m.group(2) else lo
        plo,phi=lo+offset,hi+offset
        log(f"[{pid}] printed {lo}-{hi} -> PDF {plo}-{phi}  ({a.get('title_now','')[:45]})")
        with tempfile.TemporaryDirectory() as td:
            pages=render(pdf,plo,phi,td)
            chunks=[pages[i:i+MAX_PAGES_PER_CALL] for i in range(0,len(pages),MAX_PAGES_PER_CALL)]
            merged=None; base=0
            for chunk in chunks:
                art,usage=extract(client,chunk)
                tot_in+=usage.input_tokens; tot_out+=usage.output_tokens
                for f in art.get("figures",[]): f["page_index"]=f.get("page_index",0)+base
                base+=len(chunk)
                if merged is None: merged=art
                else:
                    merged["body_html"]+="\n"+art.get("body_html","")
                    merged.setdefault("figures",[]).extend(art.get("figures",[]))
                    if art.get("notes"): merged["notes"]=(merged.get("notes","")+" "+art["notes"]).strip()
                time.sleep(PACE_SECONDS)
            # crop figures to disk
            figs=[]
            for idx,f in enumerate(merged.get("figures",[]),1):
                try:
                    pg=plo+f.get("page_index",0)
                    fp=crop(pdf,pg,f["bbox"],Path(args.out)/f"{pid}_fig{idx}.png")
                    figs.append({"file":os.path.basename(fp),"caption":f.get("caption",""),"page_index":f.get("page_index",0),"bbox":f["bbox"]})
                except Exception as e:
                    log(f"   fig{idx} crop failed: {e}")
            rec={"id":pid,"old_title":a.get("title_now",""),"title":merged["title"],
                 "subtitle":merged.get("subtitle",""),"author":merged.get("author",""),
                 "body_html":merged["body_html"],"figures":figs,"notes":merged.get("notes",""),
                 "content_len":len(merged["body_html"])}
            (Path(args.out)/f"{pid}.json").write_text(json.dumps(rec,indent=2,ensure_ascii=False))
            log(f"   -> {pid}.json  title='{merged['title'][:45]}'  figs={len(figs)}  {len(merged['body_html'])}B  notes={merged.get('notes','')[:60]}")
            summary.append({"id":pid,"old_title":rec["old_title"][:40],"new_title":rec["title"][:50],"figs":len(figs),"bytes":rec["content_len"]})
        time.sleep(0.3)
    (Path(args.out)/"_summary.json").write_text(json.dumps(summary,indent=2,ensure_ascii=False))
    log(f"\nDONE {len(summary)} article(s). tokens in={tot_in} out={tot_out}. Summary -> {Path(args.out)/'_summary.json'}")

if __name__=="__main__": main()
