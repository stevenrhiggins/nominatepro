// ========= tiny safe helpers =========
const $     = (sel, root=document) => root.querySelector(sel);
const $$    = (sel, root=document) => Array.from(root.querySelectorAll(sel));
const on    = (el, ev, fn, opts)   => el && el.addEventListener(ev, fn, opts);
const has   = (el) => !!el;
const safe  = (fn) => { try { fn(); } catch(e){ console.warn(e); } };
const isFn  = (f) => typeof f === 'function';
const exists= (sel) => $(sel) !== null;

function parseLocalDateInput(el){
  if(!el || !el.value) return null;
  // Accepts yyyy-mm-dd or datetime-local; creates Date in local time
  const v = el.value.trim();
  // If no time, force midnight local
  const iso = v.length <= 10 ? `${v}T00:00` : v;
  const d = new Date(iso);
  return isNaN(d.getTime()) ? null : d;
}

// ========= j_query.js =========
function initSortableRows(sortOrder){
  if (!window.jQuery || !isFn(window.jQuery.fn?.sortable)) return;            // jQuery UI check
  if (!$('.row_position')) return;
  $('.row_position').sortable({
    delay: 50,
    stop: function(){
      const ids = $$('.row_position > tr').map(tr => tr.id).filter(Boolean);
      if (ids.length && isFn(updateDisplayOrder)) {
        updateDisplayOrder(ids, sortOrder);
      }
      setTimeout(() => location.reload(), 1000);
    }
  });
}

function updateDisplayOrder(orderData, sortOrder){
  if (!window.jQuery) return;
  jQuery.ajax({
    url: `/xhttp/update/${sortOrder}/display/order`,
    type: 'POST',
    data: { allData: orderData },
    success: (r)=>console.log('Update successful', r),
    error: (e)=>console.log('Error occurred', e)
  });
}

function initTabs(){
  const tabs = $$('#tabs li');
  const contents = $$('#tab-content div');
  if (!tabs.length || !contents.length) return;
  tabs.forEach(tab=>{
    on(tab,'click',()=>{
      const name = tab.dataset.tab;
      tabs.forEach(t=>t.classList.remove('is-active'));
      tab.classList.add('is-active');
      contents.forEach(c=>c.classList.remove('is-active'));
      const pane = document.querySelector(`div[data-content="${name}"]`);
      if (pane) pane.classList.add('is-active');
    });
  });
}

// ========= datatables.js =========
function initDataTables(){
  if (typeof window.DataTable !== 'function') return;
  const tables = $$('.data-table');
  if (!tables.length) return;
  tables.forEach(table => {
    try {
      new DataTable(table, { paging:false, searchable:false, sortable:false });
    } catch(e){ console.warn('DataTable init failed', e); }
  });
}

// ========= InitTiny.js =========
function initTiny(){
  if (!window.tinymce) return;
  if (!document.querySelector('textarea.tinyform')) return;
  window.tinymce.init({
    selector:'textarea.tinyform',
    setup(editor){ editor.on('change', ()=>editor.save()); },
    height:300,
    plugins: [
      'advlist','autolink','lists','link','image','charmap','print','preview','anchor','pagebreak',
      'searchreplace','wordcount','visualblocks','visualchars','code','fullscreen',
      'insertdatetime','media','contextmenu','paste','table','help'
    ],
    toolbar: 'formatselect | undo redo | styles | bold italic | alignleft aligncenter alignright alignjustify | ' +
             'bullist numlist outdent indent | link image | preview media fullscreen | ' +
             'forecolor backcolor emoticons | pastetext | help',
    paste_as_text: true
  });
}

// ========= client.js =========
function initClientSessionId(){
  const input = $('#session-id');
  if (!input) return;
  const sp = new URLSearchParams(location.search);
  if (sp.has('session_id')) input.setAttribute('value', sp.get('session_id'));
}

// ========= checkbox_radio_manager.js =========
function initResponseTypeManager(){
  const types = $$('.response-type');
  if (!types.length) return;

  const checkbox_values    = $('#checkbox-values');
  const checkbox_fu_trigger= $('#checkbox-fu-trigger');
  const radio_values       = $('#radio-values');
  const radio_fu_trigger   = $('#radio-fu-trigger');

  types.forEach((t)=>{
    on(t,'click',()=>{
      const val = t.value;
      const chkOpts = $('#checkbox-options');
      const radOpts = $('#radio-options');

      if (val === 'checkbox'){
        chkOpts && chkOpts.classList.remove('is-hidden');
        radOpts && radOpts.classList.add('is-hidden');

        if (checkbox_values){ checkbox_values.required = true; checkbox_values.placeholder = 'Required'; }
        if (radio_values){ radio_values.required = false; radio_values.value=''; radio_values.placeholder=''; }
        if (radio_fu_trigger){ radio_fu_trigger.value=''; }

      } else if (val === 'radio'){
        radOpts && radOpts.classList.remove('is-hidden');
        chkOpts && chkOpts.classList.add('is-hidden');

        if (radio_values){ radio_values.required = true; radio_values.placeholder='Required'; }
        if (checkbox_values){ checkbox_values.required = false; checkbox_values.value=''; checkbox_values.placeholder=''; }
        if (checkbox_fu_trigger){ checkbox_fu_trigger.value=''; }

      } else {
        radOpts && radOpts.classList.add('is-hidden');
        chkOpts && chkOpts.classList.add('is-hidden');

        if (checkbox_values){ checkbox_values.required=false; checkbox_values.value=''; checkbox_values.placeholder=''; }
        if (checkbox_fu_trigger){ checkbox_fu_trigger.value=''; }
        if (radio_values){ radio_values.required=false; radio_values.value=''; radio_values.placeholder=''; }
        if (radio_fu_trigger){ radio_fu_trigger.value=''; }
      }
    });
  });
}

window.followup_questions = function(type){
  const toggle = $(`#${type}-followup-questions`);
  const values = $(`#${type}-values`);
  if (!toggle || !values) return;

  if (toggle.checked){
    const select  = $(`#${type}-followup-question-trigger`);
    const selectWrap = $(`#${type}-followup-question-select`);
    const firstMsg= $(`#complete-${type}-values-first`);
    const hint    = $(`#${type}-response-question`);

    if (!values.value){
      values.classList.add('input_red_border');
      firstMsg && firstMsg.classList.remove('is-hidden');
      toggle.checked = false;
      select && select.setAttribute('required','');
      const text = $(`#${type}-follow-up-questions-text`);
      text && text.classList.add('is-hidden');
    } else {
      if (hint) hint.innerHTML = 'What response from your entry above will trigger the follow up question? You will be able to write the question once you\'ve submitted this form.';
      firstMsg && firstMsg.classList.add('is-hidden');
      selectWrap && selectWrap.classList.remove('is-hidden');
      select && select.setAttribute('required','required');

      const arr = values.value.split(',').map(s=>s.trim()).filter(Boolean);
      if (select){
        select.innerHTML = '';
        arr.forEach(v=>{
          const opt = document.createElement('option');
          opt.value = v; opt.textContent = v;
          select.appendChild(opt);
        });
      }
    }
  }
};

window.clear_trigger = function(type){
  const select = $(`#${type}-followup-question-trigger`);
  if (!select) return;
  select.innerHTML = '';
  const wrap = $(`#${type}-followup-question-select`);
  wrap && wrap.classList.add('is-hidden');
  const chk = $(`#${type}-followup-questions`);
  if (chk) chk.checked = false;
};

// ========= award_general_settings.js =========
function initAwardGeneral(){
  const notify_nominee = $('#notify-nominee');
  const nominee_view_nominator = $('#nominee-view-nominator');
  const self_nominate = $('#self-nominate');
  const other_nominate = $('#other-nominate');
  const hidden_nomination_action = $('#hidden-nomination-action');
  const submission_information = $('#submission-information');
  const require_nominee = $('#require-nominee');
  const require_nominee_label = $('#require-nominee-label');
  const nominee_label_link = $$('.nominee-label');
  const go_to_notify_nominee = $('#go-to-notify-nominee');
  const notify_nominee_label = $('#notify-nominee-label');

  if (!hidden_nomination_action) return; // nothing on this page

  function displayRequireNominee(){
    const val = hidden_nomination_action.value;
    if (val === 'both'){
      submission_information?.classList.remove('is-hidden');
      submission_information?.classList.add('is-block');
      require_nominee_label?.classList.add('has-text-weight-semibold');
      const t = $('#submission-information-text');
      if (t) t.textContent = "You have chosen to allow someone to self-nominate or nominate someone else.";
    } else if (val === 'self'){
      submission_information?.classList.remove('is-block');
      submission_information?.classList.add('is-hidden');
      require_nominee_label?.classList.remove('has-text-weight-semibold');
      if (require_nominee) require_nominee.checked = false;
    } else if (val === 'other'){
      submission_information?.classList.remove('is-hidden');
      submission_information?.classList.add('is-block');
      require_nominee_label?.classList.add('has-text-weight-semibold');
      const t = $('#submission-information-text');
      if (t) t.textContent = "You have chosen to allow someone to nominate someone else.";
    } else {
      submission_information?.classList.remove('is-block');
      submission_information?.classList.add('is-hidden');
      require_nominee_label?.classList.remove('has-text-weight-semibold');
    }
  }

  // initial draw
  safe(displayRequireNominee);

  on(go_to_notify_nominee,'click',()=> notify_nominee_label?.classList.add('has-text-weight-semibold'));
  nominee_label_link.forEach(a=> on(a,'click',()=> require_nominee_label?.classList.add('has-text-weight-semibold')));

  on(require_nominee,'click',()=>{
    if (!require_nominee) return;
    if (require_nominee.checked && notify_nominee && !notify_nominee.checked){
      // ensure at least consistent state
      notify_nominee.checked = false;
    }
    require_nominee_label?.classList.remove('has-text-weight-semibold');
  });
}

// ========= judge_settings.js =========
function initJudgeSettings(){
  const startEl = $('#judging-start-date');
  const endEl   = $('#judging-end-date');
  const startMsg= $('#judging-start-date-message');
  const endMsg  = $('#judging-end-date-message');

  if (!startEl || !endEl) return;

  const now = new Date();
  const draw = ()=>{
    const s = parseLocalDateInput(startEl);
    const e = parseLocalDateInput(endEl);

    // reset messages
    startMsg && startMsg.classList.add('is-hidden');
    endMsg && endMsg.classList.add('is-hidden');

    if (s && s < now){
      if (startMsg){ startMsg.classList.remove('is-hidden'); startMsg.innerText = 'The judging has already started so changing this date is NOT recommended.'; }
    }
    if (e && e < now){
      if (endMsg){ endMsg.classList.remove('is-hidden'); endMsg.innerText = 'The judging has ended so changing this date is NOT recommended.'; }
    }
    if (s && e && e < s){
      if (endMsg){ endMsg.classList.remove('is-hidden'); endMsg.innerText = 'The date you\'ve selected is earlier than the judging start date. Please fix this.'; }
    }
  };

  draw();
  on(startEl,'change',()=>{
    const s = parseLocalDateInput(startEl);
    const e = parseLocalDateInput(endEl);
    if (startMsg){
      if (!s || s < new Date()){ startMsg.classList.remove('is-hidden'); startMsg.innerText = 'The date you have selected is earlier than today.'; }
      else startMsg.classList.add('is-hidden');
    }
    if (endMsg){
      if (s && e && e < s){ endMsg.classList.remove('is-hidden'); endMsg.innerText = 'The date you have selected is earlier than the start date. Please fix this'; }
      else endMsg.classList.add('is-hidden');
    }
  });
  on(endEl,'change',()=>{
    const s = parseLocalDateInput(startEl);
    const e = parseLocalDateInput(endEl);
    if (!endMsg) return;
    if (s && e && e < s){ endMsg.classList.remove('is-hidden'); endMsg.innerText = 'The date you have selected is earlier than the start date. Please fix this'; }
    else endMsg.classList.add('is-hidden');
  });
}

// ========= fileupload.js =========
// drop in your bundle and call safe(initUploads)
function initUploads() {
  // --- helpers -------------------------------------------------------------
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function on(el, ev, cb) { if (el) el.addEventListener(ev, cb, false); }
  function getExt(name) {
    if (!name) return '';
    var i = name.lastIndexOf('.');
    return (i >= 0 ? name.slice(i + 1) : '').toLowerCase();
  }
  function formatBytes(bytes, decimals) {
    if (!isFinite(bytes)) return '';
    var k = 1024, dm = (decimals < 0 ? 0 : (decimals || 2));
    var sizes = ['Bytes','KB','MB','GB','TB','PB','EB','ZB','YB'];
    var i = Math.floor(Math.log(bytes) / Math.log(k));
    return (parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i]);
  }
  function setText(el, s) { if (el) el.textContent = s; }
  function setHtml(el, s) { if (el) el.innerHTML = s; }

  // --- config --------------------------------------------------------------
  var DEFAULT_DOC_EXTS  = ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt'];
  var DEFAULT_LOGO_EXTS = ['jpg','jpeg','png','gif','webp','svg'];
  var MAX_DOC_BYTES     = 1000000; // 1 MB
  var MAX_LOGO_BYTES    = 200000;  // 200 KB

  // --- candidate files -----------------------------------------------------
  function wireCandidateFiles() {
    var input = qs('#candidate-file');
    if (!input) return;

    var listEl = qs('#filelist');
    var warnEl = qs('#file-size-warning');
    var maxEl  = qs('#maximum-number-files');

    var allowed = DEFAULT_DOC_EXTS.slice();
    if (input.dataset && input.dataset.allowedExt) {
      allowed = input.dataset.allowedExt.split(',')
        .map(function (s) { return (s || '').trim().toLowerCase(); })
        .filter(Boolean);
    }

    var maxBytes = MAX_DOC_BYTES;
    if (input.dataset && input.dataset.maxBytes) {
      maxBytes = Number(input.dataset.maxBytes) || MAX_DOC_BYTES;
    }

    var maxCount = 0;
    if (maxEl) {
      var raw = (typeof maxEl.value !== 'undefined') ? maxEl.value : maxEl.textContent;
      maxCount = parseInt(raw || '0', 10) || 0;
    } else if (input.dataset && input.dataset.maxCount) {
      maxCount = parseInt(input.dataset.maxCount, 10) || 0;
    }

    on(input, 'change', function () {
      setText(warnEl, '');
      setHtml(listEl, '');

      var files = input.files || [];
      if (maxCount && files.length > maxCount) {
        setText(warnEl, 'You cannot upload more than ' + maxCount + ' file' + (maxCount > 1 ? 's' : '') + '.');
        return;
      }

      var items = [];
      var hadError = false;

      for (var i = 0; i < files.length; i++) {
        var f = files[i];
        var ext = getExt(f.name);
        if (allowed.indexOf(ext) === -1) {
          hadError = true;
          items.push('<li class="has-text-danger">❌ ' + f.name + ' — .' + ext + ' not allowed</li>');
          continue;
        }
        if (f.size > maxBytes) {
          hadError = true;
          items.push('<li class="has-text-danger">❌ ' + f.name + ' — ' + formatBytes(f.size) + ' exceeds ' + formatBytes(maxBytes) + '</li>');
          continue;
        }
        items.push('<li>📄 ' + f.name + ' <small>(.' + ext + ', ' + formatBytes(f.size) + ')</small></li>');
      }

      if (items.length && listEl) {
        setHtml(listEl, '<ol class="file-list-ol">' + items.join('') + '</ol>');
      }
      if (hadError && warnEl && !warnEl.textContent) {
        setText(warnEl, 'Some files were rejected. Please review the list above.');
      }
    });
  }

  // --- generic logo picker (sponsor/org/legacy) ----------------------------
  function wireLogoPicker(containerSelector) {
    var wrap = qs(containerSelector);
    if (!wrap) return;

    var input = qs('input[type="file"]', wrap) || qs('.file-input', wrap);
    if (!input) return;

    var nameNode = qs('.file-name', wrap);
    var warnNode = qs('.file-warning', wrap) || qs('#warningmsg');

    var allowed = DEFAULT_LOGO_EXTS.slice();
    if (wrap.dataset && wrap.dataset.allowedExt) {
      allowed = wrap.dataset.allowedExt.split(',')
        .map(function (s) { return (s || '').trim().toLowerCase(); })
        .filter(Boolean);
    } else if (input.accept) {
      allowed = input.accept.split(',')
        .map(function (s) { return s.replace('.', '').trim().toLowerCase(); })
        .filter(Boolean);
    }

    var maxBytes = MAX_LOGO_BYTES;
    if ((wrap.dataset && wrap.dataset.maxBytes) || (input.dataset && input.dataset.maxBytes)) {
      maxBytes = Number((wrap.dataset && wrap.dataset.maxBytes) || (input.dataset && input.dataset.maxBytes)) || MAX_LOGO_BYTES;
    }

    on(input, 'change', function () {
      var f = (input.files && input.files[0]) || null;
      if (!f) return;

      var ext = getExt(f.name);
      if (nameNode) nameNode.textContent = f.name;

      if (allowed.indexOf(ext) === -1) {
        setText(warnNode, 'The file is the wrong type.');
        return;
      }
      if (f.size > maxBytes) {
        setText(warnNode, 'The file is too large (max ' + formatBytes(maxBytes) + ').');
        return;
      }

      setText(warnNode, ''); // clear warning on success
    });
  }

  // wire everything
  wireCandidateFiles();
  wireLogoPicker('#sponsor-logo');
  wireLogoPicker('#organization-logo');
  wireLogoPicker('#logo'); // legacy container support
}


// ========= delete.js =========
function initDeleteButtons(){
  if (!window.Swal) return;
  const map = [
    ['.delete-nomination','nominations','nomination_slug'],
    ['.delete-section','sections','section_slug'],
    ['.delete-question','questions','question_slug'],
    ['.delete-fu-question','questions_follow_up','fu_slug'],
  ];
  map.forEach(([selector, table, dbColumn])=>{
    const buttons = $$(selector);
    if (!buttons.length) return;
    buttons.forEach(btn=>{
      on(btn,'click',()=>{
        const slug = btn.dataset.slug;
        if (!slug) return console.error(`Missing data-slug for ${selector}`);
        confirmAndDelete(table, dbColumn, slug);
      });
    });
  });

  function confirmAndDelete(table, dbcolumn, slug){
    Swal.fire({
      title:"",
      text:`Are you sure you want to delete this ${table==='questions_follow_up'?'follow-up question':table.slice(0,-1)}? This action cannot be undone.`,
      showCancelButton:true,
      confirmButtonColor:"#d33",
      confirmButtonText:"Yes, delete it!",
    }).then(res=>{
      if (!res.isConfirmed) return;
      const req = new XMLHttpRequest();
      req.open('DELETE', `/xhttp/${table}/delete/${dbcolumn}/${slug}`, true);
      req.onload = function(){
        if (this.status === 200){
          Swal.fire('', `The ${table==='questions_follow_up'?'follow-up question':table.slice(0,-1)} has been successfully deleted.`, 'success')
              .then(()=>location.reload());
        } else {
          Swal.fire('Error','There was an error deleting the record.','error');
        }
      };
      req.send();
    });
  }
}

// ========= miscellany from tail =========
function initDisplayJudging(){
  const use_judging = $('#use-judging');
  const display_judging = $('#display-judging');
  const required = $$('.required');
  if (!use_judging) return;
  if (use_judging.checked){
    required.forEach(f=> f.required = true);
    if (display_judging) display_judging.style.display = 'block';
  } else {
    const award_slug = $('#award_slug')?.value;
    if (!award_slug) return;
    const x = new XMLHttpRequest();
    x.open('GET', `/app/judging/off/${award_slug}`, true);
    x.onreadystatechange = function(){
      if (this.readyState===4 && this.status===200){
        required.forEach(f=> f.required = false);
        if (display_judging) display_judging.style.display = 'none';
      }
    };
    x.send();
  }
}

function initBulmaModals(){
  const modals = $$('.modal');
  if (!modals.length) return;
  const openModal = (el)=> el && el.classList.add('is-active');
  const closeModal= (el)=> el && el.classList.remove('is-active');
  const closeAll = ()=> modals.forEach(m=>closeModal(m));

  $$('.js-modal-trigger').forEach(tr=>{
    const id = tr.dataset.target; const target = $(`#${id}`);
    on(tr,'click',()=>openModal(target));
  });
  $$('.js-modal-mouseover-trigger').forEach(tr=>{
    const id = tr.dataset.target; const target = $(`#${id}`);
    on(tr,'mouseover',()=>openModal(target));
  });
  $$('.modal-background, .modal-close, .modal-card-head .delete, .modal-card-foot .button')
    .forEach(btn=>{
      const m = btn.closest('.modal');
      on(btn,'click',()=>closeModal(m));
    });
  on(document,'keydown',(e)=>{ if ((e||window.event).keyCode===27) closeAll(); });
}

window.openTab = function(evt, tabName){
  const hidden = $('#tab-settings'); if (hidden) hidden.value = tabName;
  $$('.content-tab').forEach(x=> x.style.display='none');
  $$('.tab').forEach(t=> t.classList.remove('is-active'));
  const pane = $(`#${tabName}`); if (pane) pane.style.display = 'block';
  if (evt?.currentTarget) evt.currentTarget.classList.add('is-active');
};

window.showPassword = function(id, showTextId, confirmTextId){
  const pswd = $(`#${id}`); if (!pswd) return;
  const showTxt = $(`#${showTextId}`);
  const confTxt = $(`#${confirmTextId}`);
  const isPwd = pswd.type === 'password';
  pswd.type = isPwd ? 'text' : 'password';
  if (id==='password' && showTxt) showTxt.innerText = isPwd ? 'Hide Password' : 'Show Password';
  if (id!=='password' && confTxt) confTxt.innerText = isPwd ? 'Hide Password' : 'Show Password';
};

window.display_awards = function(){
  const list = $('#awards-list');
  const arrow = $('#arrow');
  if (arrow){
    if (arrow.classList.contains('fa-caret-up')) arrow.classList.replace('fa-caret-up','fa-caret-down');
    else if (arrow.classList.contains('fa-caret-down')) arrow.classList.replace('fa-caret-down','fa-caret-up');
  }
  if (list) list.classList.toggle('is-hidden');
};

window.switchOnOff = async function(action, awardSlug){
  if (!window.Swal){ console.error('SweetAlert missing'); return; }
  const isOn = action === 'on' ? true : action === 'off' ? false : null;
  if (isOn === null){
    await Swal.fire({ icon:'error', title:'Invalid action', text:'Action must be "on" or "off".' });
    return;
  }
  const msg = isOn
    ? "This will turn the nomination on. The Nomination Start Date will be set to today's date and the Nomination End Date will be set to one month from today. You can adjust these dates afterward."
    : "This will turn the nomination off. The Nomination Start Date and End Date will both be set to today's date. You can adjust these dates afterward.";
  const { isConfirmed } = await Swal.fire({
    title:`Turn nomination ${action}?`,
    text:msg, showCancelButton:true, confirmButtonText:`Turn ${action}`,
    cancelButtonText:'Cancel', reverseButtons:true, allowOutsideClick:false, focusCancel:true
  });
  if (!isConfirmed){ await Swal.fire({ title:'No changes were made' }); return; }

  const url = `/app/${encodeURIComponent(awardSlug)}/${isOn ? 'on' : 'off'}/switch`;
  try {
    const res = await fetch(url, { method:'POST' });
    if (!res.ok) throw new Error(`Request failed with status ${res.status}`);
    await Swal.fire({ title:`Nomination turned ${action}.`, text:'Please check the Start and End Dates under “Important Dates.”' });
  } catch(err){
    console.error(err);
    await Swal.fire({ title:'Update failed', text: err.message || 'Something went wrong. Please try again.' });
  } finally {
    const reroute = `/app/${encodeURIComponent(awardSlug)}/important_dates/Important%20Dates/singleAward#settings`;
    location.assign(reroute);
  }
};

function initCheckboxAjax(){
  const selectors = $$('.chkbox');
  const award = $('#award_slug');
  if (!selectors.length || !award) return;
  selectors.forEach(s=>{
    on(s,'click',()=>{
      const column = s.value;
      const table = $('#table')?.value;
      if (!table) return;
      const x = new XMLHttpRequest();
      x.open('GET', `/app/setcheckbox/${award.value}/${table}/${column}`, true);
      x.onload = function(){ if (x.readyState===4 && x.status===200) console.log(x.responseText); };
      x.onerror = function(){ console.error('Request failed.'); };
      if (s.checked === false) x.send();
    });
  });
}

window.openSettings = function(){
  const e = $('#selectaward'); if (!e) return;
  const slug = e.options[e.selectedIndex]?.value;
  if (slug) window.location.href = `https://nominatepro.com/app/settings/${slug}/general_settings/General Settings/renderForm`;
};
window.openDivision = function(){
  const e = $('#division'); if (!e) return;
  if (e.value) window.location.href = `https://nominatepro.com/app/division/${e.value}/index`;
};

// password/username/phone utils (guarded)
window.checkUsername = function(id, errId){
  const input = $(`#${id}`), err = $(`#${errId}`);
  if (!input || !err) return;
  const regex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*\W)[A-Za-z\d\W]{8,}$/;
  if (regex.test(input.value)){
    err.innerText = "The username cannot be an email address.";
    input.value = "";
  } else err.innerText = "";
};

window.checkPasswordStrength = function(id, errId){
  const input = $(`#${id}`), err = $(`#${errId}`);
  if (!input || !err) return;
  const strong = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&]).{8,}$/;
  if ((input.value||'').length < 12){
    err.innerText = "The password must be at least 12 characters long."; input.value="";
  } else if (!strong.test(input.value)){
    err.innerText = "The password is not strong enough or doesn't meet the requirements."; input.value="";
  } else err.innerText = "";
};

window.comparePasswords = function(p1, p2, errId){
  const a = $(`#${p1}`), b = $(`#${p2}`), err = $(`#${errId}`);
  if (!a || !b || !err) return;
  err.innerText = "";
  if ((a.value||'') !== (b.value||'')){
    err.innerText = "The passwords don't match."; a.value=""; b.value="";
  }
};

window.formatPhoneNumber = function(id){
  const el = $(`#${id}`); if (!el) return;
  let v = String(el.value || '');
  if (v.length === 10) el.value = v.replace(/(\d{3})(\d{3})(\d{4})/, "$1-$2-$3");
};

// Likert / scale UI
function initLikert(){
  const scaleSelect = $('#numeric-scale');
  const likert = $('#use-likert');
  const container = $('#scale-container');
  const inputContainer = $('#input-container');

  if (scaleSelect){
    on(scaleSelect,'change',()=>{
      if (inputContainer) inputContainer.classList.remove('is-hidden');
      if (likert) likert.checked = false;
      if (container) container.classList.add('is-hidden');
      if (container) container.innerHTML = '';
    });
  }
  if (likert){
    on(likert,'click',()=>{
      if (!container) return;
      const n = parseInt(scaleSelect?.value || '0', 10) || 0;
      container.classList.remove('is-hidden');
      if (likert.checked){
        container.innerHTML = '';
        for (let i=1;i<=n;i++){
          const inp = document.createElement('input');
          inp.id = String(i);
          inp.type = 'text';
          inp.className = 'input mb-2';
          inp.placeholder = 'Enter the appropriate response anchor';
          inp.name = `responseAnchors[${i}]`;
          container.appendChild(inp);
        }
      } else {
        container.classList.add('is-hidden');
      }
    });
  }
}

// burger menu
function initBurger(){
  const burger = $('.navbar-burger');
  const menu = $('.navbar-menu');
  if (!burger || !menu) return;
  on(burger,'click',()=>{
    burger.classList.toggle('is-active');
    menu.classList.toggle('is-active');
  });
}

function initToolTips(opts = {}) {
  // Prevent double-binding if called again
  if (window.__npTipsInstalled) {
    if (window.NPTooltips?.setDefaults) window.NPTooltips.setDefaults(opts);
    return true;
  }

  const DEFAULTS = Object.assign({ selector: '[data-tooltip], [title]', delay: 150 }, opts);
  let active = null, showTimer = null, hideTimer = null, idSeq = 0;

  const $closest = (el, sel) => (el && el.closest) ? el.closest(sel) : null;

  const getText = (el) => {
    let t = el.getAttribute('data-tooltip');
    if (!t && el.hasAttribute('title')) {
      t = el.getAttribute('title');
      el.setAttribute('data-tooltip', t);   // move to data-tooltip
      el.removeAttribute('title');          // prevent native browser tooltip
    }
    return t || '';
  };

  const getPlacement = (el) => {
    const p = (el.getAttribute('data-placement') || 'top').toLowerCase();
    return ['top','right','bottom','left'].includes(p) ? p : 'top';
  };

  const getDelay = (el, def) => {
    const n = Number(el.getAttribute('data-delay'));
    return Number.isFinite(n) ? Math.max(0, n) : def;
  };

  const getColorClass = (el) => {
    const c = (el.getAttribute('data-color') || '').toLowerCase();
    const ok = ['primary','link','info','success','warning','danger','dark','black','light','white'];
    return ok.includes(c) ? `np-tip--${c}` : 'np-tip--dark';
  };

  const getSizeClass = (el) => {
    const s = (el.getAttribute('data-size') || '').toLowerCase();
    const ok = ['small','normal','medium','large'];
    return ok.includes(s) ? `np-tip--${s}` : '';
  };

  function createTipEl(text, colorCls, sizeCls) {
    const el = document.createElement('div');
    el.className = `np-tip ${colorCls} ${sizeCls}`.trim();
    el.setAttribute('role', 'tooltip');
    el.textContent = text;
    el.id = 'np-tip-' + (++idSeq);
    document.body.appendChild(el);
    return el;
  }

  function placeTip(target, tip, placement) {
    const r = target.getBoundingClientRect();
    const tRect = tip.getBoundingClientRect();
    const sx = window.scrollX || window.pageXOffset;
    const sy = window.scrollY || window.pageYOffset;
    const gap = 8;

    const pos = {
      top:    () => ({ top: r.top + sy - tRect.height - gap, left: r.left + sx + (r.width - tRect.width)/2 }),
      bottom: () => ({ top: r.bottom + sy + gap,            left: r.left + sx + (r.width - tRect.width)/2 }),
      left:   () => ({ top: r.top + sy + (r.height - tRect.height)/2, left: r.left + sx - tRect.width - gap }),
      right:  () => ({ top: r.top + sy + (r.height - tRect.height)/2, left: r.right + sx + gap }),
    };

    let { top, left } = pos[placement]();

    const outTop = top < sy;
    const outBottom = top + tRect.height > sy + window.innerHeight;
    const outLeft = left < sx;
    const outRight = left + tRect.width > sx + window.innerWidth;

    if (placement === 'top' && outTop) placement = 'bottom';
    else if (placement === 'bottom' && outBottom) placement = 'top';
    else if (placement === 'left' && outLeft) placement = 'right';
    else if (placement === 'right' && outRight) placement = 'left';

    ({ top, left } = pos[placement]());
    tip.style.top = `${Math.round(top)}px`;
    tip.style.left = `${Math.round(left)}px`;
    tip.dataset.arrow = placement;
  }

  function showTip(target) {
    const text = getText(target);
    if (!text) return;

    clearTimeout(hideTimer);
    clearTimeout(showTimer);
    const delay = getDelay(target, DEFAULTS.delay);

    showTimer = setTimeout(() => {
      if (active?.el) active.el.remove();
      const el = createTipEl(text, getColorClass(target), getSizeClass(target));
      placeTip(target, el, getPlacement(target));

      const prevId = target.getAttribute('aria-describedby');
      target.setAttribute('aria-describedby', el.id);
      active = { el, target, prevId };

      requestAnimationFrame(() => el.classList.add('np-tip--visible'));
    }, delay);
  }

  function hideTip(immediate = false) {
    clearTimeout(showTimer);
    if (!active?.el) return;
    const { el, target, prevId } = active;

    const done = () => {
      el.remove();
      if (prevId) target.setAttribute('aria-describedby', prevId);
      else target.removeAttribute('aria-describedby');
      active = null;
    };

    if (immediate) return done();

    clearTimeout(hideTimer);
    hideTimer = setTimeout(() => {
      el.classList.remove('np-tip--visible');
      el.addEventListener('transitionend', done, { once: true });
    }, 80);
  }

  // Delegated listeners (safe for dynamic DOM)
  const selector = DEFAULTS.selector;
  const onOver = (e) => {
    const t = $closest(e.target, selector);
    if (t) showTip(t);
  };
  const onFocus = (e) => {
    const t = $closest(e.target, selector);
    if (t) showTip(t);
  };
  const onOut = (e) => {
    if (!active) return;
    const leaving = e.target === active.target || active.el?.contains(e.target);
    if (leaving) hideTip(false);
  };
  const onScrollOrResize = () => {
    if (active?.el && active.target) placeTip(active.target, active.el, active.el.dataset.arrow || 'top');
  };

  document.addEventListener('mouseover', onOver);
  document.addEventListener('focusin', onFocus);
  document.addEventListener('mouseout', onOut);
  document.addEventListener('blur', onOut, true);
  window.addEventListener('scroll', onScrollOrResize, { passive: true });
  window.addEventListener('resize', onScrollOrResize);

  // Tiny public API
  window.NPTooltips = {
    hide: () => hideTip(true),
    setDefaults: (o = {}) => Object.assign(DEFAULTS, o),
  };

  window.__npTipsInstalled = true;
  return true;
}


// ========= boot =========
document.addEventListener('DOMContentLoaded', function(){
  safe(initTabs);
  safe(initDataTables);
  safe(initTiny);
  safe(initClientSessionId);
  safe(initResponseTypeManager);
  safe(initAwardGeneral);
  safe(initJudgeSettings);
  safe(initUploads);
  safe(initDeleteButtons);
  safe(initDisplayJudging);
  safe(initBulmaModals);
  safe(initCheckboxAjax);
  safe(initLikert);
  safe(initBurger);
  safe(initToolTips);
});
