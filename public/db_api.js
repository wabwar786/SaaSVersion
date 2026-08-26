(function(){
  function post(action,payload,token){
    const x=new XMLHttpRequest();
    x.open(payload===undefined?'GET':'POST','/api.php?action='+encodeURIComponent(action),false);
    x.setRequestHeader('Accept','application/json');
    if(payload!==undefined){
      x.setRequestHeader('Content-Type','application/json');
      x.setRequestHeader('X-CSRF-Token',token||'');
      x.send(JSON.stringify(payload));
    } else x.send();
    let r={ok:false,message:'Server request failed'};
    try{r=JSON.parse(x.responseText||'{}')}catch(e){
      /* Raw HTML/text aaya (PHP fatal, Apache error page). Status aur
         asli matn dikhao - "Request failed" jaisa be-maani message nahi. */
      const clean=String(x.responseText||'').replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim();
      r={ok:false,message:'Server returned HTTP '+x.status+(clean?(': '+clean.slice(0,200)):' with an empty response')};
    }
    return {res:r,status:x.status};
  }

  /* V62 — CSRF AUTO-RECOVERY.
     Cloud container restart hone par server ki session file chali jati hai
     magar browser mein khula purana tab wahi purana token bhejta rehta hai.
     Natija: har save/delete "Invalid CSRF token" (aur Apache use 500 bana
     kar dikhata tha). Ab aisi soorat mein client naya token le kar EK dafa
     khud dobara koshish karta hai; user ko kuch karna nahi parta. */
  function refreshToken(){
    try{
      const out=post('csrf-token');
      if(out.res&&out.res.ok&&out.res.token){window.APP_CSRF=out.res.token;return out.res.token}
    }catch(e){}
    return null;
  }

  function req(action,payload){
    let out=post(action,payload,window.APP_CSRF||'');
    if(payload!==undefined && out.res && out.res.ok===false && out.res.csrf){
      const t=refreshToken();
      if(t) out=post(action,payload,t);
    }
    if(out.status===401&&action!=='login'){location.href='login.html';return out.res}
    return out.res;
  }

  window.DBApi={req:req,refreshToken:refreshToken};
})();
/* build: V62 build 2026-08-26 */
