def dedent(x):
    return '\n'.join(l[1:] if l.startswith('\t') else l for l in x.split('\n'))
import json
lines=open('/root/.claude/projects/-home-claude/d8400bf6-1e44-5cc9-9149-15526d5d53b8.jsonl').read().splitlines()
for l in reversed(lines):
    if 'admin_post_nry_page_editable' in l:
        d=json.loads(l)
        c=d.get('message',{}).get('content')
        if isinstance(c,list):
            for x in c:
                if x.get('type')=='tool_use':
                    cmd=x['input'].get('command','')
                    if 'page_editable' in cmd:
                        print(repr(cmd[:200])); print(repr(cmd[-120:]))
                        script=cmd[cmd.index("<<'E'\n")+6:cmd.rindex("\nE\n")]
                        script=script.replace("""        assert s.count(a)==1, (p,a[:70],s.count(a))""","""        if s.count(a)!=1:
            a,b=dedent(a),dedent(b)
        if s.count(a)!=1:
            a,b=dedent(a),dedent(b)
        assert s.count(a)==1, (p,a[:70],s.count(a))""")
                        script="def dedent(x):\n    return '\\n'.join(l[1:] if l.startswith('\\t') else l for l in x.split('\\n'))\n"+script
                        open('patch_admin.py','w').write(script)
                        raise SystemExit