#!/usr/bin/env python3
"""
mab_run_issue.py — full per-issue re-extraction harness for the Marathon & Beyond archive.

Given an issue PDF + the issue's WP posts (id/title/mab_pages), it:
  1. TOC pass: render front matter -> vision -> ordered {title,author,start_page} + auto OFFSET.
  2. Match TOC entries -> WP post IDs (fuzzy title + page proximity). Unmatched -> flagged.
  3. Extract each matched post at its TRUE page range (chunked vision, merged).
  4. QC gate: strip ad/artifact lines, drop ad/pull-quote figures, skip content-filter blocks.
  5. Write cleaned <id>.json (+ <id>_figN.png) to --stage, plus a per-issue report.

Then the existing bundle->push->import flow applies --stage. Decoupled from WP (BigScoots).

Usage:
  python3 mab_run_issue.py --pdf "...issue.pdf" --posts posts_v4i4.json \
      --stage /path/stage --report /path/report.json [--toc-scan 1-9] [--only-toc]
  posts.json = [{"id":905248,"title":"Santa Clarita Marathon","pages":"117-137"}, ...]
Env: ANTHROPIC_API_KEY ; MAB_MODEL (default claude-sonnet-4-6)
"""
import argparse, base64, json, os, re, subprocess, sys, tempfile, time, difflib
from pathlib import Path
import anthropic

DPI = 170
MAX_PAGES_PER_CALL = 4
MODEL = os.environ.get("MAB_MODEL", "claude-sonnet-4-6")
PACE = float(os.environ.get("MAB_PACE", "1.5"))

def log(*a): print(*a, file=sys.stderr, flush=True)
def b64(p): return base64.standard_b64encode(Path(p).read_bytes()).decode()

def render(pdf, lo, hi, outdir, tag="pg"):
    subprocess.run(["pdftoppm","-f",str(lo),"-l",str(hi),"-r",str(DPI),"-png",pdf,str(Path(outdir)/tag)],check=True)
    return sorted(Path(outdir).glob(tag+"-*.png"))

# ---------- TOC + offset ----------
TOC_TOOL = {"name":"emit_toc","description":"Return the issue's table of contents + page-number calibration.",
  "input_schema":{"type":"object","properties":{
    "entries":{"type":"array","items":{"type":"object","properties":{
      "title":{"type":"string"},"author":{"type":"string"},
      "start_page":{"type":"integer","description":"PRINTED start page from the contents listing."},
      "kind":{"type":"string","description":"feature|column|department|fiction|editorial|other"}},
      "required":["title","start_page"]}},
    "calibration":{"type":"array","description":"For each given image showing a printed folio, {image_index(1-based),printed_number}.",
      "items":{"type":"object","properties":{"image_index":{"type":"integer"},"printed_number":{"type":"integer"}},
      "required":["image_index","printed_number"]}},
    "notes":{"type":"string"}},"required":["entries"]}}
TOC_SYS=("You are reading the front matter of an issue of the running magazine Marathon & Beyond. "
  "Transcribe the FULL table of contents in printed order (title, byline if shown, printed start page) — "
  "features, columns, departments, fiction, editorial. Also, for offset calibration, report the printed "
  "page-number (folio) visible on any image, keyed by that image's 1-based position in the sequence given.")

def toc_pass(client, pdf, scan_lo=1, scan_hi=9):
    with tempfile.TemporaryDirectory() as td:
        imgs = render(pdf, scan_lo, scan_hi, td, "toc")
        content=[{"type":"image","source":{"type":"base64","media_type":"image/png","data":b64(p)}} for p in imgs]
        content.append({"type":"text","text":"Front-matter pages in order. Emit the full TOC + calibration via emit_toc."})
        msg=client.messages.create(model=MODEL,max_tokens=4000,system=TOC_SYS,tools=[TOC_TOOL],
            tool_choice={"type":"tool","name":"emit_toc"},messages=[{"role":"user","content":content}])
        toc=next(b.input for b in msg.content if b.type=="tool_use")
    # offset = pdf_page - printed_number ; image_index k == PDF page (scan_lo + k - 1)
    offsets=[]
    for c in toc.get("calibration",[]):
        try:
            pdfpage = scan_lo + int(c["image_index"]) - 1
            offsets.append(pdfpage - int(c["printed_number"]))
        except Exception: pass
    offset = max(set(offsets), key=offsets.count) if offsets else None  # mode
    ents=sorted([e for e in toc.get("entries",[]) if isinstance(e.get("start_page"),int)],key=lambda e:e["start_page"])
    for i,e in enumerate(ents):
        e["end_page"]=(ents[i+1]["start_page"]-1) if i+1<len(ents) else None
    return ents, offset, toc.get("notes",""), msg.usage

# ---------- offset anchor (robust; folio calibration is unreliable) ----------
ANCHOR_TOOL={"name":"emit_anchor","description":"Identify which image is the FIRST page of a named article.",
  "input_schema":{"type":"object","properties":{
    "image_index":{"type":"integer","description":"1-based index (in given order) of the image that is the FIRST page where this article begins (its headline appears). 0 if none."},
    "confidence":{"type":"number"}},"required":["image_index"]}}

def anchor_offset(client, pdf, entries, win=10):
    """One vision call: find a distinctive feature's first page in a window -> true offset (=k-1)."""
    feats=[e for e in entries if e.get("kind")=="feature" and e.get("start_page",0)>3 and len(e.get("title",""))>=10]
    cand=(sorted(feats,key=lambda e:-len(e["title"]))[:1] or sorted(entries,key=lambda e:-len(e.get("title","")))[:1])
    if not cand: return None,None
    e=cand[0]; sp=int(e["start_page"])
    with tempfile.TemporaryDirectory() as td:
        imgs=render(pdf,sp,sp+win-1,td,"anc")
        content=[{"type":"image","source":{"type":"base64","media_type":"image/png","data":b64(p)}} for p in imgs]
        content.append({"type":"text","text":f"These are consecutive scanned magazine pages. Which image (1-based) is the FIRST page of the article titled \"{e['title']}\""+(f" by {e['author']}" if e.get('author') else "")+"? Use emit_anchor (0 if absent)."})
        msg=client.messages.create(model=MODEL,max_tokens=300,system="You locate where a specific magazine article begins among scanned page images.",
            tools=[ANCHOR_TOOL],tool_choice={"type":"tool","name":"emit_anchor"},messages=[{"role":"user","content":content}])
        a=next(b.input for b in msg.content if b.type=="tool_use")
    k=int(a.get("image_index",0) or 0)
    return ((k-1) if k>=1 else None), e["title"]

# ---------- matching ----------
def norm(s): return re.sub(r"\s+"," ",re.sub(r"[^a-z0-9 ]"," ",(s or "").lower())).strip()
def toks(s): return set(norm(s).split())
def title_sim(a,b):
    na,nb=norm(a),norm(b)
    if not na or not nb: return 0.0
    if na in nb or nb in na: return 0.95
    ta,tb=toks(a),toks(b)
    jac=len(ta&tb)/len(ta|tb) if (ta|tb) else 0
    seq=difflib.SequenceMatcher(None,na,nb).ratio()
    return max(jac,seq)

def page_start(pages):
    m=re.match(r"\s*(\d+)",pages or ""); return int(m.group(1)) if m else None
def page_range(pages):
    m=re.match(r"\s*(\d+)\s*[-–]\s*(\d+)",pages or "")
    if m: return (int(m.group(1)),int(m.group(2)))
    s=page_start(pages); return (s,s) if s else None

def match_posts(posts, entries):
    """Greedy best title match per post; page proximity as tiebreaker. Returns matches + leftovers."""
    pairs=[]
    for pi,p in enumerate(posts):
        for ei,e in enumerate(entries):
            s=title_sim(p["title"],e["title"])
            ps,es=page_start(p.get("pages")),e.get("start_page")
            prox=1.0/(1+abs(ps-es)) if (ps and es) else 0
            pairs.append((s+0.15*prox, s, pi, ei))
    pairs.sort(reverse=True)
    used_p,used_e,matches=set(),set(),[]
    for score,s,pi,ei in pairs:
        if pi in used_p or ei in used_e: continue
        if s < 0.40: continue
        ps,es=page_start(posts[pi].get("pages")),entries[ei].get("start_page")
        gap=abs(ps-es) if (ps and es) else None
        if s < 0.55 and gap is not None and gap > 6: continue   # weak title + far page -> reject
        used_p.add(pi); used_e.add(ei)
        matches.append({"post":posts[pi],"entry":entries[ei],"score":round(s,3)})
    unmatched_posts=[posts[i] for i in range(len(posts)) if i not in used_p]
    unmatched_entries=[entries[i] for i in range(len(entries)) if i not in used_e]
    return matches, unmatched_posts, unmatched_entries

# ---------- article extraction ----------
EXTRACT_TOOL={"name":"emit_article","description":"Return the cleaned structured article.",
  "input_schema":{"type":"object","properties":{
    "title":{"type":"string"},"subtitle":{"type":"string"},"author":{"type":"string"},
    "body_html":{"type":"string","description":("Clean semantic HTML (<p>,<h2>/<h3>,<ul>/<li>,real <table>). "
      "Put [[FIGURE:n]] on its own line where each figure belongs (1-based, matching figures[]). EXCLUDE ads, "
      "order/subscription forms, addresses, running heads, page numbers, 'MIN READ', and any text from a DIFFERENT article.")},
    "figures":{"type":"array","items":{"type":"object","properties":{
      "page_index":{"type":"integer","description":"0-based index into THIS call's images."},
      "bbox":{"type":"array","items":{"type":"number"},"description":"[x0,y0,x1,y1] fractions 0..1."},
      "caption":{"type":"string"}},"required":["page_index","bbox"]},
      "description":"Photos/illustrations for THIS article only (NOT ads, NOT pull-quotes/text)."},
    "notes":{"type":"string"}},"required":["title","body_html","figures"]}}
EXTRACT_SYS=("You are digitizing scanned pages of Marathon & Beyond into a clean web archive. The images are the pages "
  "of ONE article in order. Reconstruct ONLY that article. Correct multi-column reading order. Reproduce tables as real "
  "HTML with accurate numbers. Preserve the author's words; fix only OCR damage; de-hyphenate line breaks. Ruthlessly drop "
  "ads, order forms, addresses, running heads, page numbers, and any text belonging to a neighbouring article. "
  "Figures are PHOTOS/ILLUSTRATIONS only — never capture an advertisement or a pull-quote as a figure.")

class ContentFiltered(Exception): pass

def vis_extract(client, images):
    content=[{"type":"image","source":{"type":"base64","media_type":"image/png","data":b64(p)}} for p in images]
    content.append({"type":"text","text":"Extract this article per emit_article. The images above are its pages in order."})
    last=None
    for attempt in range(6):
        try:
            msg=client.messages.create(model=MODEL,max_tokens=16000,system=EXTRACT_SYS,tools=[EXTRACT_TOOL],
                tool_choice={"type":"tool","name":"emit_article"},messages=[{"role":"user","content":content}])
            for b in msg.content:
                if b.type=="tool_use" and b.name=="emit_article": return b.input,msg.usage
            raise RuntimeError("no emit_article")
        except anthropic.BadRequestError as e:
            if "content filtering" in str(e).lower(): raise ContentFiltered(str(e)[:120])
            raise
        except anthropic.RateLimitError as e:
            w=60
            try: w=int(e.response.headers.get("retry-after","60"))+2
            except Exception: pass
            log(f"   rate-limited {w}s"); time.sleep(w); last=e
        except (anthropic.APIStatusError,anthropic.APIConnectionError) as e:
            log(f"   API {type(e).__name__}; retry 15s"); time.sleep(15); last=e
    raise last or RuntimeError("extract failed")

def crop(pdf,pdf_page,bbox,outpath):
    pw,ph=int(6*DPI),int(9*DPI); x0,y0,x1,y1=bbox
    x,y=max(0,int(x0*pw)),max(0,int(y0*ph)); w,h=max(1,int((x1-x0)*pw)),max(1,int((y1-y0)*ph))
    pre=str(outpath).rsplit(".",1)[0]
    subprocess.run(["pdftoppm","-f",str(pdf_page),"-l",str(pdf_page),"-r",str(DPI),"-x",str(x),"-y",str(y),
                    "-W",str(w),"-H",str(h),"-png","-singlefile",pdf,pre],check=True)
    return pre+".png"

def extract_article(client,pdf,plo,phi):
    with tempfile.TemporaryDirectory() as td:
        pages=render(pdf,plo,phi,td,"a")
        chunks=[pages[i:i+MAX_PAGES_PER_CALL] for i in range(0,len(pages),MAX_PAGES_PER_CALL)]
        merged=None; base=0; usage_in=usage_out=0
        for ch in chunks:
            art,u=vis_extract(client,ch); usage_in+=u.input_tokens; usage_out+=u.output_tokens
            if not isinstance(art.get("figures"),list): art["figures"]=[]   # model occasionally returns a non-list
            if not isinstance(art.get("body_html"),str): art["body_html"]=str(art.get("body_html") or "")
            for f in art.get("figures",[]): f["page_index"]=f.get("page_index",0)+base
            base+=len(ch)
            if merged is None: merged=art
            else:
                merged["body_html"]+="\n"+art.get("body_html","")
                merged.setdefault("figures",[]).extend(art.get("figures",[]))
                if art.get("notes"): merged["notes"]=(merged.get("notes","")+" "+art["notes"]).strip()
            time.sleep(PACE)
        return merged, usage_in, usage_out

# ---------- QC gate ----------
ART_LINE=re.compile(r"<p\b[^>]*>(?:(?!</p>).)*?(?:this (?:is|page)[^<]{0,80}advertisement|these pages contain only advertisement|subscribe (?:today|now)|newsstand price|use (?:the )?form below|payable to[^<]{0,40}marathon)(?:(?!</p>).)*?</p>", re.I|re.S)
def qc_clean(art, pid, outdir, pdf, plo):
    b=art.get("body_html","") or ""
    b=ART_LINE.sub("", b)                       # strip ad-artifact paragraphs anywhere
    b=re.split(r"<h[23][^>]*>\s*The Rest of the Pack\s*</h[23]>", b, 1, re.I)[0]  # cut recurring race-index appendix + trailing sub-ad
    body_norm=norm(re.sub(r"<[^>]+>"," ",b))
    figs_in=art.get("figures") if isinstance(art.get("figures"),list) else []
    # decide keep/drop; origN is the 1-based index that matches [[FIGURE:origN]] in the body
    kept=[]; dropped=[]
    for i,f in enumerate(figs_in,1):
        cap=(f.get("caption") or "").strip(); ncap=norm(cap)
        if re.search(r"advertis",cap,re.I): dropped.append({"n":i,"caption":cap[:50],"why":"ad"}); continue
        if ncap and len(ncap)>=12 and ncap[:48] in body_norm:
            dropped.append({"n":i,"caption":cap[:50],"why":"pullquote"}); continue
        kept.append((i,f))
    # crop kept figs, assign new sequential index k; build origN->k remap
    figs_out=[]; remap={}
    for k,(origN,f) in enumerate(kept,1):
        try:
            pg=plo+f.get("page_index",0)
            fp=crop(pdf,pg,f["bbox"],Path(outdir)/f"{pid}_fig{k}.png")
            figs_out.append({"file":os.path.basename(fp),"caption":f.get("caption","")})
            remap[origN]=k
        except Exception as e:
            dropped.append({"n":origN,"caption":(f.get('caption') or '')[:40],"why":"crop-fail:"+str(e)[:40]})
    # rewrite body placeholders: kept origN -> sentinel -> new k; dropped/orphan tokens removed
    def repl(mobj):
        n=int(mobj.group(1)); return ("[[FIGURE:%d]]"%remap[n]) if n in remap else ""
    b=re.sub(r"\[\[FIGURE:(\d+)\]\]",repl,b)
    b=re.sub(r"\n{3,}","\n\n",b).strip()
    return b, figs_out, dropped

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--pdf",required=True); ap.add_argument("--posts",required=True)
    ap.add_argument("--stage",required=True); ap.add_argument("--report",default="")
    ap.add_argument("--toc-scan",default="1-9"); ap.add_argument("--only-toc",action="store_true")
    ap.add_argument("--offset",type=int,default=None,help="override auto-detected offset")
    ap.add_argument("--only",default="",help="restrict to these post ids (csv)")
    args=ap.parse_args()
    posts=json.loads(Path(args.posts).read_text())
    keep={int(x) for x in args.only.split(",")} if args.only else None  # restrict EXTRACTION, not matching
    Path(args.stage).mkdir(parents=True,exist_ok=True)
    client=anthropic.Anthropic(api_key=os.environ["ANTHROPIC_API_KEY"])
    slo,shi=(int(x) for x in args.toc_scan.split("-"))
    ents,folio_off,tnotes,tu=toc_pass(client,args.pdf,slo,shi)
    anc_off,anc_title=anchor_offset(client,args.pdf,ents)   # robust; folio is a fallback
    offset = anc_off if anc_off is not None else folio_off
    if args.offset is not None: offset=args.offset
    log(f"TOC: {len(ents)} entries | offset anchor={anc_off} (via '{(anc_title or '')[:30]}') folio={folio_off} -> using {offset}")
    matches,un_posts,un_ents=match_posts(posts,ents)
    report={"pdf":os.path.basename(args.pdf),"offset":offset,"toc_entries":ents,"toc_notes":tnotes,
            "applied":[],"deferred":[],"misaligned":[],"unmatched_posts":un_posts,"missing_articles":[{"title":e["title"],"author":e.get("author",""),
            "pages":f"{e['start_page']}-{e.get('end_page')}","kind":e.get("kind","")} for e in un_ents],
            "skipped":[],"tokens_in":tu.input_tokens,"tokens_out":tu.output_tokens}
    if args.only_toc or offset is None:
        if offset is None: log("!! offset detection failed; rerun with --offset")
        Path(args.report or (args.stage+"/_report.json")).write_text(json.dumps(report,indent=1,ensure_ascii=False))
        print(json.dumps({"offset":offset,"matches":[(m['post']['id'],m['entry']['title'],m['entry']['start_page'],m['entry'].get('end_page'),m['score']) for m in matches],"unmatched_posts":[p['id'] for p in un_posts],"missing":len(un_ents)},indent=1))
        return
    STUB_MAX=800
    for m in matches:
        post=m["post"]; pid=int(post["id"]); e=m["entry"]
        if keep and pid not in keep: continue   # --only: matched on full set, extract subset
        # --- pre-extraction skips (save cost; protect drafts/stubs) ---
        st=post.get("st") or post.get("status") or "publish"
        clen=int(post.get("clen") or 0)
        if st!="publish":
            log(f"[{pid}] SKIP draft (status={st})"); report["skipped"].append({"id":pid,"title":post["title"],"reason":"draft:"+st}); continue
        if clen and clen<STUB_MAX:
            log(f"[{pid}] SKIP stub (clen={clen})"); report["skipped"].append({"id":pid,"title":post["title"],"reason":f"stub:{clen}"}); continue
        lo=e["start_page"]; hi=e.get("end_page") or lo
        plo,phi=lo+offset,hi+offset
        log(f"[{pid}] {e['title'][:40]!r} printed {lo}-{hi} -> PDF {plo}-{phi}")
        try:
            art,ti,to=extract_article(client,args.pdf,plo,phi)
        except ContentFiltered as cf:
            log(f"   SKIP content-filter: {cf}"); report["skipped"].append({"id":pid,"title":e["title"],"reason":"content-filter"}); continue
        report["tokens_in"]+=ti; report["tokens_out"]+=to
        got=art.get("title","") or ""
        vsim=title_sim(got, e["title"])
        if vsim < 0.5:
            log(f"   REJECT misaligned: got '{got[:40]}' vs expected '{e['title'][:40]}' (sim {vsim:.2f})")
            report.setdefault("misaligned",[]).append({"id":pid,"expected":e["title"],"got":got,"sim":round(vsim,3),"range":f"{lo}-{hi}"}); continue
        body,figs,dropped=qc_clean(art,pid,args.stage,args.pdf,plo)
        # --- safe/defer classification: title-verify (above) ensures correct article;
        #     orphan-safety = LENGTH (new content must be >=40% of current; over-merges are << ).
        #     mab_pages ranges are often wrong, so they are NOT used for the gate.
        if clen:
            ratio=len(body)/clen
            safe = ratio >= 0.40
            why = f"len {len(body)}/{clen} ratio={ratio:.2f}, true {lo}-{hi} vs cur {post.get('pages','')}"
        else:
            safe = True; why = f"no-clen, len {len(body)}"
        if not safe:
            log(f"   DEFER over-merge: {why}")
            report.setdefault("deferred",[]).append({"id":pid,"title":got,"true_range":f"{lo}-{hi}","cur_pages":post.get("pages",""),"new_len":len(body),"cur_clen":clen,"why":why})
            try: os.remove(args.stage+f"/{pid}.json")
            except Exception: pass
            for fg in figs:
                try: os.remove(args.stage+"/"+fg["file"])
                except Exception: pass
            continue
        rec={"id":pid,"old_title":post["title"],"title":got,"subtitle":art.get("subtitle",""),
             "author":art.get("author",""),"body_html":body,"figures":figs,"notes":art.get("notes","")}
        Path(args.stage+f"/{pid}.json").write_text(json.dumps(rec,ensure_ascii=False))
        report["applied"].append({"id":pid,"new_title":rec["title"],"range":f"{lo}-{hi}","score":m["score"],
                                  "figs":len(figs),"dropped_figs":dropped,"len":len(body)})
        log(f"   APPLY -> {pid}.json  '{rec['title'][:40]}'  figs={len(figs)} dropped={len(dropped)} {len(body)}B")
        time.sleep(0.2)
    Path(args.report or (args.stage+"/_report.json")).write_text(json.dumps(report,indent=1,ensure_ascii=False))
    log(f"\nDONE issue. APPLY={len(report['applied'])} deferred={len(report.get('deferred',[]))} "
        f"skipped={len(report['skipped'])} misaligned={len(report.get('misaligned',[]))} "
        f"unmatched={len(un_posts)} missing={len(un_ents)} tokens in={report['tokens_in']} out={report['tokens_out']}")
    print(f"report -> {args.report or (args.stage+'/_report.json')}")

if __name__=="__main__": main()
