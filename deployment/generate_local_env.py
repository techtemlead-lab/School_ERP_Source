from pathlib import Path
import secrets
p=Path(__file__).with_name('.env.local')
if not p.exists():
 p.write_text(''.join(k+'='+secrets.token_hex(24)+'\n' for k in ['LOCAL_DB_ROOT_PASSWORD','LOCAL_MASTER_PASSWORD','LOCAL_CONTROL_PASSWORD','LOCAL_TOKEN_SECRET','LOCAL_ADMIN_PASSWORD']),encoding='utf-8')
 p.chmod(0o600)
print('Local test environment is present; credentials remain private.')

existing=dict(line.split('=',1) for line in p.read_text().splitlines() if '=' in line)
if 'LOCAL_TENANT_PASSWORD' not in existing:
 with p.open('a',encoding='utf-8') as f:f.write('LOCAL_TENANT_PASSWORD='+secrets.token_hex(24)+'\n')
