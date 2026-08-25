(function(){
  function req(action,payload){
    const x=new XMLHttpRequest();x.open(payload===undefined?'GET':'POST','/api.php?action='+encodeURIComponent(action),false);x.setRequestHeader('Accept','application/json');
    if(payload!==undefined){x.setRequestHeader('Content-Type','application/json');x.setRequestHeader('X-CSRF-Token',window.APP_CSRF||'');x.send(JSON.stringify(payload));}else x.send();
    let r={ok:false,message:'Server request failed'};try{r=JSON.parse(x.responseText||'{}')}catch(e){r={ok:false,message:x.responseText||'Invalid server response'}}
    if(x.status===401&&action!=='login'){location.href='login.html';return r}return r;
  }
  window.DBApi={req};
})();