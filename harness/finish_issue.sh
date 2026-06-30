set -e
TAG="$1"
SCR=/private/tmp/claude-501/-Users-thomaswatson-Desktop-mh-training-app/40c5a229-6dfd-4599-a1d2-fb9449b8e195/scratchpad
until ! pgrep -f mab_run_issue.py >/dev/null; do sleep 6; done
STAGE=$SCR/mab_stage; BUILD=$SCR/mab_bundle
git -C "$BUILD" rev-parse --is-inside-work-tree >/dev/null 2>&1 || { rm -rf "$BUILD"; gh repo clone twbroch/mab-bundle "$BUILD" -- -q; }
python3 - "$SCR" "$STAGE" "$BUILD" "$TAG" <<'PY'
import json,os,glob,re,shutil,sys
scr,stage,build,tag=sys.argv[1:5]
r=json.load(open(scr+"/report_%s.json"%tag))
ids=[a['id'] for a in r['applied']]
for i in ids:
    for f in glob.glob(scr+"/stage_%s/%d*"%(tag,i)): shutil.copy2(f, stage+"/"+os.path.basename(f))
BASE="https://raw.githubusercontent.com/twbroch/mab-bundle/main/"
arts=[]
for jp in sorted(glob.glob(stage+"/*.json")):
    d=json.load(open(jp)); figs=[]
    for f in d.get("figures",[]):
        fn=f.get("file");
        if not fn: continue
        n=int(re.search(r'_fig(\d+)\.',fn).group(1)) if re.search(r'_fig(\d+)\.',fn) else 0
        figs.append({"n":n,"file":fn,"caption":(f.get("caption") or "").strip()})
        p=os.path.join(stage,fn)
        if os.path.exists(p): shutil.copy2(p, build+"/"+fn)
    arts.append({"id":int(d["id"]),"title":(d.get("title") or "").strip(),"body_html":d["body_html"],"figures":figs})
json.dump({"base_url":BASE,"generated":"2026-06-26","articles":arts},open(build+"/manifest.json","w"),ensure_ascii=False,indent=1)
print("APPLY_IDS="+",".join(str(i) for i in ids))
print("APPLY",len(ids),"DEFER",len(r.get('deferred',[])),"MIS",len(r.get('misaligned',[])),"UNM",len(r['unmatched_posts']),"manifest",len(arts))
PY
git -C "$BUILD" add -A
git -C "$BUILD" -c user.email="hi@marathonhandbook.com" -c user.name="twbroch" commit -q -m "$TAG batch: applied set bundled" 2>&1 | tail -1
git -C "$BUILD" push -q origin main 2>&1 | tail -1
echo "$TAG EXTRACT+BUNDLE+PUSH DONE"
