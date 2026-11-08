import subprocess,json,uuid,time
from pathlib import Path
base=Path('/workspace/work/tests')
def wp(code):
 r=subprocess.run(['docker','exec','vsr-cli','wp','eval',code],capture_output=True,text=True,check=True)
 return r.stdout.strip()
def run(name):
 return subprocess.Popen(['docker','exec','vsr-cli','wp','eval-file','/tests/worker.php',name],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
def scenario(label,qty,op,amount,expected=None,same=False,core=False):
 id=int(wp("$p=new WC_Product_Simple();$p->set_name('Concurrent fixture');$p->set_status('publish');$p->set_manage_stock(true);$p->set_stock_quantity("+str(qty)+");$p->save();echo $p->get_id();"))
 key=str(uuid.uuid4()); a=dict(id=id,op=op,qty=amount,key=key,hold=True)
 if expected is not None:a['expected']=expected
 b=dict(a);b['hold']=False;b['key']=key if same else str(uuid.uuid4())
 if core:b.update(op='core',qty=2)
 (base/'a.json').write_text(json.dumps(a));(base/'b.json').write_text(json.dumps(b));(base/'locked').unlink(missing_ok=True)
 p1=run('a.json');deadline=time.monotonic()+30
 while not (base/'locked').exists():
  if p1.poll() is not None:raise RuntimeError(p1.communicate())
  if time.monotonic()>deadline:raise RuntimeError('barrier timeout')
  time.sleep(.02)
 p2=run('b.json');outs=[]
 for p in [p1,p2]:
  out,err=p.communicate(timeout=30)
  if p.returncode:raise RuntimeError(err+out)
  outs.append(json.loads(out))
 final=float(wp(f'echo get_post_meta({id},"_stock",true);'))
 errors=sum('error' in r for r in outs)
 if core:assert final==13 and errors==0,(final,outs)
 elif same:assert final==qty+amount and errors==0 and outs[1]['result']['replayed'],(final,outs)
 elif op=='set':assert final==amount and errors==1,(final,outs)
 else:assert final==qty-amount and errors==1,(final,outs)
 print('PASS:',label,'final stock',final)
scenario('concurrent exact updates reject stale writer',10,'set',7,10)
scenario('concurrent decreases cannot oversell via report',10,'decrease',8)
scenario('concurrent duplicate request applies once',10,'increase',4,same=True)
scenario('core WooCommerce decrement waits on same stock row',20,'set',15,20,core=True)
