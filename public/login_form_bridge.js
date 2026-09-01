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
  /* V89 — USER KA DROPDOWN HATA DIYA GAYA.
     Pehle offline par login screen sab users ki fehrist dikhati thi
     (naam aur role ke saath). Do wajah se yeh ghalat tha:
       - Har aane wale ko pata chal jata tha ke kaun kaun kaam karta hai
         aur kis ka kya darja hai. Aadha password khud hi de dena hai.
       - Malik ne kaha yeh nahi chahiye.
     Ab saada khana: username likhein, password likhein. Password bhool
     jayen to us computer par RESET_PASSWORD.bat hai. */
  function install(){
    var emailInput=document.querySelector('#email')||document.querySelector('input[name=email]');
    if(!emailInput)return;
    /* Purana dropdown kisi purane build se para ho to hata do. */
    var old=document.getElementById('userSelect');
    if(old&&old.parentNode)old.parentNode.removeChild(old);
    if(emailInput.type==='hidden')emailInput.type='text';

    /* Offline par sign-in username se hota hai, email lazmi nahi. */
    emailInput.setAttribute('autocomplete','username');
    emailInput.setAttribute('autocapitalize','off');
    if(emailInput.type==='email')emailInput.type='text';
    if(!emailInput.value)emailInput.placeholder='username';
    var lbl=emailInput.closest('label');
    var sp=lbl&&lbl.querySelector('span');
    if(sp)sp.textContent='Username';
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install);
  else install();
})();
