/* ===== AJAX 无刷新导航 + 丝滑过渡 ===== */
(function () {
  var main = document.querySelector('main');
  if (!main) return;
  var animating = false;

  // 提取一段 URL 用于高亮底栏
  function pathOf(url) {
    try { return new URL(url, location.origin).pathname; } catch (e) { return url; }
  }

  function highlightNav(url) {
    var p = pathOf(url);
    document.querySelectorAll('.bottom-nav .nav-item').forEach(function (el) {
      var href = el.getAttribute('href') || '/';
      var isActive = p === href || (href === '/' && p === '/');
      el.classList.toggle('active', isActive);
    });
  }

  // 切换动画：淡出 -> 换内容 -> 淡入
  function swap(contentHtml, title, finalUrl) {
    main.style.transition = 'opacity .16s ease, transform .16s ease';
    main.style.opacity = '0';
    main.style.transform = 'translateY(8px)';
    setTimeout(function () {
      main.innerHTML = contentHtml;
      if (title) document.title = title;
      highlightNav(finalUrl);
      window.scrollTo(0, 0);
      restoreDraft();
      // PJAX 换页后立即刷新消息浮球：此前只靠 30s 轮询，
      // 刚评论完回到列表页徽章不会变，用户以为没生效。
      if (window.__lmCheckNotif) window.__lmCheckNotif();
      requestAnimationFrame(function () {
        main.style.transition = 'opacity .38s cubic-bezier(.22,.61,.36,1), transform .38s cubic-bezier(.22,.61,.36,1)';
        main.style.opacity = '1';
        main.style.transform = 'translateY(0)';
      });
      animating = false;
    }, 160);
  }

  function navigate(url, push) {
    if (animating) return;
    var u = new URL(url, location.origin);
    if (u.origin !== location.origin) return;
    animating = true;
    fetch(u.href, { headers: { 'X-Requested-With': 'ajax' } })
      .then(function (r) {
        if (!r.ok) throw new Error(String(r.status));
        return r.text().then(function (t) { return { text: t, url: r.url || u.href }; });
      })
      .then(function (res) {
        var doc = new DOMParser().parseFromString(res.text, 'text/html');
        var newMain = doc.querySelector('main');
        if (!newMain) { location.href = u.href; return; } // 无 main 的页面（404 等）整页跳
        var title = doc.title || '';
        if (push) history.pushState({}, '', res.url);
        swap(newMain.innerHTML, title, res.url);
      })
      .catch(function (e) {
        animating = false;
        location.href = u.href; // 404 等异常整页加载
      });
  }

  // 编辑器：图片上传
  document.addEventListener('click', function (e) {
    if (e.target && e.target.id === 'upload-btn') {
      var fi = document.getElementById('upload-file');
      if (fi) fi.click();
    }
  });
  document.addEventListener('change', function (e) {
    var fi = document.getElementById('upload-file');
    if (!fi || e.target !== fi) return;
    var file = fi.files[0];
    if (!file) return;
    var fd = new FormData();
    fd.append('file', file);
    var csrfEl = document.querySelector('input[name="csrf"]');
    if (csrfEl) fd.append('csrf', csrfEl.value);
    var btn = document.getElementById('upload-btn');
    var old = btn ? btn.textContent : '';
    if (btn) { btn.textContent = '上传中…'; btn.disabled = true; }
    fetch('upload.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) {
          var ta = document.getElementById('md-input');
          if (ta) {
            var md = '![](' + res.url + ')';
            ta.value += (ta.value && !ta.value.endsWith('\n') ? '\n\n' : '\n') + md + '\n';
            ta.focus();
          }
        } else { alert(res.error || '上传失败'); }
      })
      .catch(function () { alert('上传失败，请检查网络'); })
      .then(function () { if (btn) { btn.textContent = old; btn.disabled = false; } fi.value = ''; });
  });

  // 表单提交（保存/发布）成功后清掉草稿，避免下次写新文章误恢复
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.action) return;
    if (form.action.indexOf('action=save') !== -1) {
      var key = 'draft_' + location.pathname + location.search;
      try { sessionStorage.removeItem(key); } catch (err) {}
    }
  });

  // 拦截站内链接
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a') : null;
    if (!a) return;
    if (a.target === '_blank' || a.hasAttribute('download')) return;
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;
    var u = new URL(a.href, location.origin);
    if (u.origin !== location.origin) return;
    e.preventDefault();
    navigate(u.href, true);
  });

  // 浏览器后退/前进
  window.addEventListener('popstate', function () {
    navigate(location.href, false);
  });

  // 编辑器：预览按钮（事件委托，AJAX 切换后依然有效）
  document.addEventListener('click', function (e) {
    if (e.target && e.target.id === 'preview-btn') {
      var input = document.getElementById('md-input');
      var box = document.getElementById('preview-box');
      var out = document.getElementById('preview-content');
      if (!input || !box || !out) return;
      var body = new URLSearchParams();
      body.append('content', input.value);
      fetch('preview.php', { method: 'POST', body: body, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } })
        .then(function (r) { return r.text(); })
        .then(function (html) { out.innerHTML = html; box.hidden = false; });
    }
  });

  // 编辑器：草稿自动保存（sessionStorage，写一半切走不丢）
  document.addEventListener('input', function (e) {
    var input = document.getElementById('md-input');
    if (!input || e.target !== input) return;
    var t = document.getElementById('post-title-input');
    var key = 'draft_' + location.pathname + location.search;
    try { sessionStorage.setItem(key, JSON.stringify({ title: t ? t.value : '', content: input.value })); } catch (err) {}
  });
  function restoreDraft() {
    var input = document.getElementById('md-input');
    if (!input) return;
    var t = document.getElementById('post-title-input');
    var key = 'draft_' + location.pathname + location.search;
    var raw = null;
    try { raw = sessionStorage.getItem(key); } catch (err) {}
    if (!raw) return;
    try {
      var d = JSON.parse(raw);
      if (t && t.value === '' && d.title) t.value = d.title;
      if (input.value === '' && d.content) input.value = d.content;
    } catch (err) {}
  }
  restoreDraft();
})();

/* ===== 消息中心浮球（登录用户可见，30s 轮询未读）===== */
(function () {
  if (document.querySelector('.msg-float')) return;
  var ball = document.createElement('a');
  ball.className = 'msg-float';
  ball.href = 'notifications.php';
  ball.title = '消息中心';
  ball.setAttribute('aria-label', '消息中心');
  ball.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg><span class="msg-badge" hidden></span>';
  document.body.appendChild(ball);
  var badge = ball.querySelector('.msg-badge');
  function check() {
    fetch('notifications.php?action=count', { headers: { 'X-Requested-With': 'ajax' } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.guest) { ball.remove(); return; }
        if (res.count > 0) {
          badge.textContent = res.count > 99 ? '99+' : String(res.count);
          badge.hidden = false;
          ball.classList.add('has-unread');
        } else {
          badge.hidden = true;
          ball.classList.remove('has-unread');
        }
      })
      .catch(function () {});
  }
  // 暴露给 PJAX 的 swap() 调用，实现换页即时刷新
  window.__lmCheckNotif = check;
  check();
  setInterval(check, 30000);
})();
