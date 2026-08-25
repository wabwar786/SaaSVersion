(function(){
document.addEventListener('DOMContentLoaded',()=>{
  const u=window.RestaurantAccess?.current?.();
  if(!u)return;
  const n=document.getElementById('sideUserName'),r=document.getElementById('sideUserRole'),a=document.getElementById('sideAvatar');
  if(n)n.textContent=u.name;
  if(r)r.textContent=u.role||'User';
  if(a)a.textContent=(u.name||'U').split(/\s+/).slice(0,2).map(x=>x[0]).join('').toUpperCase();
  const allowed=new Set(u.modules||[]),admin=!!u.isAdmin;
  const map=Object.fromEntries((RestaurantAccess.MODULES||[]).map(m=>[m.href,m.key]));
  document.querySelectorAll('[data-module-href]').forEach(el=>{
    const key=map[el.getAttribute('href')];
    if(key&&!admin&&!allowed.has(key))el.style.display='none';
  });
});
})();
/* build: V17.1 build 2026-08-25 */
