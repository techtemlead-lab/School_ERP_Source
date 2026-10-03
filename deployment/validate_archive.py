import sys,tarfile,pathlib
archive=pathlib.Path(sys.argv[1]);target=pathlib.Path(sys.argv[2])
if archive.stat().st_size>40*1024*1024:raise SystemExit('Release archive too large')
with tarfile.open(archive,'r:gz') as tar:
 total=0
 for m in tar.getmembers():
  p=pathlib.PurePosixPath(m.name)
  if p.is_absolute() or '..' in p.parts or m.issym() or m.islnk() or not (m.isdir() or m.isfile()):raise SystemExit('Unsafe release entry')
  if any(x in {'.git','.env','__pycache__'} for x in p.parts) or m.name.startswith('deployment/.env'):raise SystemExit('Private release entry')
  if m.isfile() and m.name.startswith(('uploads/','logs/','master/assets/uploads/')) and p.name!='.htaccess':raise SystemExit('Persistent data cannot be released with code')
  total+=m.size
  if total>120*1024*1024:raise SystemExit('Expanded release too large')
 target.mkdir(mode=0o700,parents=True,exist_ok=False)
 tar.extractall(target,filter='data')
