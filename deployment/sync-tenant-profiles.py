#!/usr/bin/env python3
from pathlib import Path
import subprocess,json,os
base=Path('/opt/schoolmng');dc=['docker','compose','--env-file','runtime.env','-f','compose.yml']
r=subprocess.run(dc+['exec','-T','provisioner','php','cron/tenant_profile.php','tawhaaschool'],capture_output=True,cwd=base,timeout=20)
if r.returncode:raise SystemExit('Tenant profile synchronization failed; retaining previous profile')
row=json.loads(r.stdout);assert row['school_code']=='tawhaaschool'
folder=base/'shared/tenant-profiles/tawhaa';folder.mkdir(mode=0o755,exist_ok=True)
target=folder/'tenant.json';temporary=folder/'tenant.json.next'
with temporary.open('w',encoding='utf-8') as f:json.dump(row,f,ensure_ascii=False);f.flush();os.fsync(f.fileno())
temporary.chmod(0o644);temporary.replace(target)
