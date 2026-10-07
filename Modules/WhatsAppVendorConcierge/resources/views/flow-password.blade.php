<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create your password</title>
<style nonce="{{ $nonce }}">body{font:16px system-ui,sans-serif;background:#f5f8f6;color:#17251c;margin:0;padding:24px}main{max-width:420px;margin:5vh auto;background:white;padding:24px;border-radius:16px}label{display:block;margin:16px 0}input,button{box-sizing:border-box;width:100%;font:inherit;padding:12px;border:1px solid #b8cabe;border-radius:8px}button{background:#087b3a;color:white;cursor:pointer}button:disabled{opacity:.6}p{line-height:1.5}#result{padding:12px;background:#f0f6f1}</style></head>
<body><main><h1>Create your vendor password</h1><p>Creating a password does not activate or approve your application.</p>
<p id="result" role="status" aria-live="polite">Use at least 8 characters, uppercase and lowercase letters, a number and a symbol.</p><noscript>JavaScript is required to securely read this private setup link. Please open it in your browser.</noscript>
<form id="setup" method="post">@csrf<label>Password <input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
<label>Confirm password <input type="password" name="password_confirmation" required minlength="8" maxlength="72" autocomplete="new-password"></label><button type="submit">Create password</button></form>
<script nonce="{{ $nonce }}">
(()=>{let token=new URLSearchParams(location.hash.slice(1)).get('setup')||'';history.replaceState(null,'',location.pathname);const form=document.getElementById('setup'),result=document.getElementById('result'),button=form.querySelector('button');
if(!/^[a-f0-9]{64}$/.test(token)){form.hidden=true;result.textContent='Open your latest private setup link from WhatsApp.';return;}
form.addEventListener('submit',async e=>{e.preventDefault();button.disabled=true;const data=new FormData(form);data.append('setup_token',token);
try{const r=await fetch(location.pathname,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json'},body:data});const body=await r.json();result.textContent=body.message||'Please try again later.';if(r.ok){token='';form.reset();form.hidden=true;}else if(r.status===410){token='';form.hidden=true;}}
catch(e){result.textContent='The connection failed. Please try again.';}finally{button.disabled=false;}});})();
</script></main></body></html>
