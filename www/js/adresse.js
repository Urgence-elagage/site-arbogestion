/* Saisie d'adresse assistée (Base Adresse Nationale) : liste de propositions sous le champ.
   Insensible aux accents et aux majuscules (contrairement aux listes natives du navigateur). */
(function(){
  var css=document.createElement('style');
  css.textContent='.ban-box{position:relative}.ban-liste{position:absolute;left:0;right:0;top:100%;z-index:60;margin:4px 0 0;padding:4px;list-style:none;background:#fff;border:1px solid #cfd6cc;border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,.14);max-height:280px;overflow:auto}'
    +'.ban-liste li{padding:10px 12px;border-radius:7px;cursor:pointer;color:#1e3d33;line-height:1.3}.ban-liste li small{display:block;color:#5f655f;font-size:.85em}'
    +'.ban-liste li.actif,.ban-liste li:hover{background:#f1f5e0}';
  document.head.appendChild(css);
  window.autoAdresseBAN=function(el,choisir,opts){
    opts=opts||{};if(!el)return;
    el.setAttribute('autocomplete','off');el.removeAttribute('list');
    var box=document.createElement('div');box.className='ban-box';el.parentNode.insertBefore(box,el);box.appendChild(el);
    var ul=document.createElement('ul');ul.className='ban-liste';ul.hidden=true;ul.setAttribute('role','listbox');box.appendChild(ul);
    var res=[],idx=-1,t=null,n=0;
    function fermer(){ul.hidden=true;idx=-1}
    function prendre(i){var f=res[i];if(!f)return;fermer();choisir(f.properties,f.geometry.coordinates)}
    function afficher(){
      ul.innerHTML='';
      res.forEach(function(f,i){var p=f.properties,li=document.createElement('li');li.setAttribute('role','option');
        li.innerHTML='<b></b><small></small>';li.firstChild.textContent=p.name||p.label;li.lastChild.textContent=[p.postcode,p.city].filter(Boolean).join(' ')+(p.context?' · '+p.context.split(',').slice(1).join(',').trim():'');
        li.addEventListener('mousedown',function(e){e.preventDefault();prendre(i)});ul.appendChild(li)});
      ul.hidden=!res.length;idx=-1;
    }
    function chercher(q){var k=++n;
      fetch('https://api-adresse.data.gouv.fr/search/?limit=6&autocomplete=1&q='+encodeURIComponent(q)+(opts.type?'&type='+opts.type:''))
        .then(function(r){return r.json()}).then(function(d){if(k!==n)return;res=d.features||[];afficher()}).catch(function(){})}
    el.addEventListener('input',function(){if(opts.saisie)opts.saisie();clearTimeout(t);var v=el.value.trim();if(v.length<3){res=[];fermer();return}t=setTimeout(function(){chercher(v)},200)});
    el.addEventListener('keydown',function(e){if(ul.hidden)return;var li=ul.children;
      if(e.key==='ArrowDown'){e.preventDefault();idx=Math.min(idx+1,li.length-1)}
      else if(e.key==='ArrowUp'){e.preventDefault();idx=Math.max(idx-1,0)}
      else if(e.key==='Enter'){e.preventDefault();prendre(idx<0?0:idx);return}
      else if(e.key==='Escape'){fermer();return}else return;
      for(var i=0;i<li.length;i++)li[i].classList.toggle('actif',i===idx)});
    el.addEventListener('blur',function(){setTimeout(fermer,150)});
  };
})();
