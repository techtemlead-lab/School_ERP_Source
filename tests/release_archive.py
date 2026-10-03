import pathlib,subprocess,tarfile,tempfile,io,sys
root=pathlib.Path(__file__).resolve().parents[1]
validator=root/'deployment/validate_archive.py'
with tempfile.TemporaryDirectory(prefix='school-erp-archive-test-') as folder:
 base=pathlib.Path(folder)
 archive=base/'release.tar.gz'
 archive.write_bytes(subprocess.check_output(['git','archive','--format=tar.gz','HEAD'],cwd=root))
 r=subprocess.run([sys.executable,str(validator),str(archive),str(base/'valid')],capture_output=True)
 assert r.returncode==0,r.stderr.decode()
 assert (base/'valid/config/runtime.php').is_file()
 print('PASS actual Git release archive validates without persistent data')
 for i,name in enumerate(['../escape.php','.env','uploads/student-secret.txt','logs/private.log','master/assets/uploads/client-file.pdf']):
  archive=base/('unsafe'+str(i)+'.tar.gz')
  with tarfile.open(archive,'w:gz') as t:
   item=tarfile.TarInfo(name);item.size=4;t.addfile(item,io.BytesIO(b'test'))
  r=subprocess.run([sys.executable,str(validator),str(archive),str(base/('bad'+str(i)))],capture_output=True)
  assert r.returncode!=0,name
  print('PASS unsafe/private archive entry rejected: '+name)
