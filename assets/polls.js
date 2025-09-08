(function(){
    function qs(s,root){ return (root||document).querySelector(s); }
    function qsa(s,root){ return Array.prototype.slice.call((root||document).querySelectorAll(s)); }
  
    function votedCookieName(id){
      var pref = (window.WPPoll && WPPoll.cookie_prefix) || 'wpp_voted_';
      return pref + id;
    }
  
    function hasCookie(name){
      return document.cookie.split(';').some(function(c){ return c.trim().indexOf(name+'=')===0; });
    }
  
    function serializeChoices(form){
      var els = qsa('input.wpp__input:checked', form);
      return els.map(function(e){ return parseInt(e.value,10); }).filter(function(n){return !isNaN(n);});
    }
  
    function onSubmit(e){
      e.preventDefault();
      var form = e.currentTarget;
      var wrap = form.closest('.wpp');
      var pollId = parseInt(wrap.getAttribute('data-poll'),10);
      var nonce = form.getAttribute('data-nonce') || (window.WPPoll && WPPoll.nonce) || '';
  
      var choices = serializeChoices(form);
      if (!choices.length){ alert('Vyberte možnost.'); return; }
  
      var btn = qs('.wpp__btn', form);
      if (btn) { btn.disabled = true; btn.textContent = 'Odesílám…'; }
  
      var xhr = new XMLHttpRequest();
      var url = (window.WPPoll && WPPoll.ajax) || '/wp-admin/admin-ajax.php';
      var data = new FormData();
      data.append('action','wpp_vote');
      data.append('poll', String(pollId));
      data.append('nonce', nonce);
      choices.forEach(function(i){ data.append('choices[]', String(i)); });
  
      xhr.open('POST', url, true);
      xhr.onreadystatechange = function(){
        if (xhr.readyState === 4){
          if (btn) { btn.disabled = false; btn.textContent = 'Hlasovat'; }
          try{
            var res = JSON.parse(xhr.responseText || '{}');
            if (res.success){
              var results = document.createElement('div');
              results.innerHTML = res.data.results_html;
              var resultsEl = qs('.wpp__results', wrap);
              if (resultsEl) { resultsEl.replaceWith(results.firstElementChild); }
              else { wrap.appendChild(results.firstElementChild); }
              form.remove();
              document.cookie = votedCookieName(pollId) + '=1; max-age='+(365*24*3600)+'; path=/';
            } else {
              alert((res.data && res.data.msg) || 'Chyba hlasování.');
            }
          } catch(e){
            alert('Chyba spojení.');
          }
        }
      };
      xhr.send(data);
    }
  
    function bind(){
      qsa('.wpp__form').forEach(function(f){
        f.removeEventListener('submit', onSubmit);
        f.addEventListener('submit', onSubmit);
      });
    }
  
    if (document.readyState==='loading') document.addEventListener('DOMContentLoaded', bind);
    else bind();
  })();
  