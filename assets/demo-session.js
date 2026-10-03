(() => {
 const banner=document.querySelector('[data-demo-reset]');if(!banner)return;
 let serverTime=Number(banner.dataset.demoServer)*1000,resetAt=Number(banner.dataset.demoReset)*1000,syncAt=performance.now(),expired=false;
 const hour=Math.floor(serverTime/3600000),prefix='schoolERP.demo.',key=prefix+hour+'.'+location.pathname+location.search;
 try{for(let i=localStorage.length-1;i>=0;i--){const n=localStorage.key(i);if(n?.startsWith(prefix)&&!n.startsWith(prefix+hour+'.'))localStorage.removeItem(n)}}catch{}
 const sidebar=document.getElementById('sidebar'),wrapper=document.getElementById('mainWrapper'),toggle=document.getElementById('toggleSidebar');
 if(sidebar&&matchMedia('(max-width:767px)').matches){sidebar.classList.add('collapsed');wrapper?.classList.add('expanded')}
 if(toggle){toggle.setAttribute('role','button');toggle.setAttribute('tabindex','0');toggle.setAttribute('aria-label','Toggle school navigation');toggle.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();toggle.click()}})}
 const safe=el=>el.name&&!el.disabled&&!/password|token|secret|csrf|captcha|verification/i.test(el.name)&&!['password','file','hidden','submit','button'].includes(el.type);
 document.querySelectorAll('form[method="post" i]').forEach((form,index)=>{
  if(form.querySelector('input[type=password]'))return;
  const label=document.createElement('label'),checkbox=document.createElement('input');checkbox.type='checkbox';
  label.style.cssText='display:block;font:12px system-ui;padding:8px;color:#475569';
  label.append(checkbox,' Keep this form draft in this browser until the hourly reset');form.append(label);
  const storageKey=key+'.form'+index;
  try{const draft=JSON.parse(localStorage.getItem(storageKey)||'null');if(draft&&draft.expires===resetAt){checkbox.checked=true;[...form.elements].filter(safe).forEach(el=>{if(!(el.name in draft.values))return;if(el.type==='checkbox'||el.type==='radio')el.checked=draft.values[el.name]===el.value;else el.value=draft.values[el.name]})}}catch{}
  function save(){try{if(!checkbox.checked||expired){localStorage.removeItem(storageKey);return}const values={};[...form.elements].filter(safe).forEach(el=>{if((el.type==='checkbox'||el.type==='radio')&&!el.checked)return;values[el.name]=el.value});localStorage.setItem(storageKey,JSON.stringify({expires:resetAt,values}))}catch{}}
  form.addEventListener('input',save);form.addEventListener('change',save);
 });
 function tick(){
  const remaining=Math.max(0,Math.ceil((resetAt-serverTime-(performance.now()-syncAt))/1000)),target=banner.querySelector('[data-demo-countdown]');
  if(target)target.textContent=Math.floor(remaining/60)+'m '+String(remaining%60).padStart(2,'0')+'s left';
  if(!remaining&&!expired){expired=true;try{for(let i=localStorage.length-1;i>=0;i--)if(localStorage.key(i)?.startsWith(prefix))localStorage.removeItem(localStorage.key(i))}catch{}
   document.querySelectorAll('form button,form input[type=submit]').forEach(el=>el.disabled=true);banner.textContent='The hourly demo reset has happened. Reloading your fresh school…';
   location.replace(location.pathname.startsWith('/admin')?'/admin/':location.pathname.startsWith('/teacher')?'/teacher/':location.pathname.startsWith('/student')?'/student/':'/');
  }
 }
 async function sync(){try{const data=await(await fetch('/demo/status.php',{cache:'no-store'})).json();serverTime=data.server_time*1000;syncAt=performance.now();if(Math.floor(serverTime/3600000)!==hour){resetAt=serverTime;tick()}}catch{}}
 setInterval(tick,1000);tick();setInterval(sync,30000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)sync()});
})();
