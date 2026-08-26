(function(){
  function $(s){ return document.querySelector(s); }

  function showError(message){
    var e=$('#error');
    if(!e)return;
    e.textContent=message;
    e.classList.add('show');
  }

  function submitLogin(){
    var email=$('#email');
    var password=$('#password');
    var btn=$('#loginBtn');

    if(!email || !password)return;

    var ev=(email.value||'').trim();
    var pv=password.value||'';

    if(!ev || !pv){
      showError('Email / Login and password are required');
      return;
    }

    if(btn){
      btn.disabled=true;
      btn.textContent='Signing in...';
    }

    /*
     * Normal top-level POST is intentional.
     * PHP writes the authenticated session before returning a 303 redirect
     * to the Dashboard. This is more reliable than a synchronous XHR login
     * followed immediately by location.href on local Windows PHP.
     */
    var form=document.createElement('form');
    form.method='POST';
    form.action='/login-submit.php';
    form.style.display='none';

    var e=document.createElement('input');
    e.type='hidden';e.name='email';e.value=ev;
    form.appendChild(e);

    var p=document.createElement('input');
    p.type='hidden';p.name='password';p.value=pv;
    form.appendChild(p);

    document.body.appendChild(form);
    form.submit();
  }

  document.addEventListener('DOMContentLoaded',function(){
    var btn=$('#loginBtn');
    if(btn){
      // Overwrite the original approved demo/login handler, not the design.
      btn.onclick=function(e){
        if(e)e.preventDefault();
        submitLogin();
        return false;
      };
    }

    var params=new URLSearchParams(location.search);
    var err=params.get('login_error');
    if(err==='invalid') showError('Invalid login or account not approved.');
    else if(err==='required') showError('Email / Login and password are required.');
    else if(err==='server') showError('Login service error. Please restart Restaurant Software and try again.');
    else if(err==='blocked') showError(params.get('reason')||'This business is suspended or expired.');
  });

  // Capture Enter before the original approved bubble-phase keydown handler.
  document.addEventListener('keydown',function(e){
    if(e.key==='Enter'){
      e.preventDefault();
      e.stopImmediatePropagation();
      submitLogin();
    }
  },true);
})();

/* build: V17.1 build 2026-08-25 */

/* ============================================================
   OFFLINE MODE: user ko type karne ke bajaye DROPDOWN se chunein.
   Sirf local (offline) node par chalta hai - cloud par yeh list
   kabhi expose nahi hoti (business isolation).
   ============================================================ */
(function(){
  'use strict';
  function req(a){
    try{
      var x=new XMLHttpRequest();x.open('GET','/api.php?action='+a,false);x.send();
      return JSON.parse(x.responseText||'{}');
    }catch(e){return {ok:false}}
  }
  function install(){
    var emailInput=document.querySelector('#email')||document.querySelector('input[name=email]');
    if(!emailInput||document.getElementById('userSelect'))return;
    var r=req('users-list');
    if(!r.ok||!r.users||!r.users.length)return;   /* cloud par yahin ruk jata hai */

    var sel=document.createElement('select');
    sel.id='userSelect';
    sel.className=emailInput.className;
    sel.style.cssText=(emailInput.getAttribute('style')||'')+';width:100%';
    sel.innerHTML='<option value="">-- Select user --</option>'+r.users.map(function(u){
      return '<option value="'+String(u.login).replace(/"/g,'&quot;')+'">'
        +String(u.name||u.login)+(u.role?(' - '+u.role):'')+'</option>';
    }).join('');

    emailInput.type='hidden';
    emailInput.parentNode.insertBefore(sel,emailInput);
    sel.onchange=function(){
      emailInput.value=this.value;
      var pw=document.querySelector('#password')||document.querySelector('input[type=password]');
      if(pw)pw.focus();
    };
    /* agar sirf ek hi user hai to pehle se select kar do */
    if(r.users.length===1){sel.selectedIndex=1;emailInput.value=r.users[0].login;}
    var lbl=sel.previousElementSibling;
    if(lbl&&lbl.tagName==='LABEL')lbl.textContent='User';
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install);
  else install();
})();
