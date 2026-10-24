/* Same-origin PHP API adapter. No credentials or database secrets belong here. */
let account=null,csrfToken='',serverRevision=0,savePending=null,saveRunning=false,saveBlocked=false,syncLabel='Connecting…',registrationOpen=true;
const API='api/index.php?route=';
async function apiRequest(route,method='GET',bodyValue){
  let response;
  try{response=await fetch(API+route,{method,credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:bodyValue===undefined?undefined:JSON.stringify(bodyValue)})}catch{throw Object.assign(new Error('Cannot reach the server. Your pending changes remain in this tab. Retry when connected.'),{status:0})}
  let data;try{data=await response.json()}catch{throw new Error('The PHP API is unavailable. Serve this app with PHP and configure MySQL using README.md.')}
  if(!response.ok)throw Object.assign(new Error(data.error||'Request failed.'),{status:response.status});return data;
}
function updateSync(label){syncLabel=label;document.querySelectorAll('[data-sync]').forEach(el=>el.textContent=label)}
function recoveryKey(){return account?'rimbo-pending-'+account.id:null}
function queueServerSave(){
  if(!account){toast('Sign in before saving.');return false}
  let snapshot;try{snapshot=JSON.stringify(state)}catch{toast('Unable to prepare this change.');return false}
  if(new Blob([snapshot]).size>12000000){toast('Your workspace exceeds 12 MB. Remove large attachments before saving.');return false}
  savePending=JSON.parse(snapshot);
  try{sessionStorage.setItem(recoveryKey(),JSON.stringify({revision:serverRevision,state:savePending}))}catch{toast('Recovery storage is full. Keep this tab open until the server confirms saving.')}
  updateSync(saveBlocked?'Save needs attention':'Saving…');
  if(!saveBlocked)void drainSaves();
  return true;
}
async function drainSaves(){
  if(saveRunning||saveBlocked||!savePending)return;
  saveRunning=true;
  while(savePending&&!saveBlocked){
    let snapshot=savePending;savePending=null;
    try{
      const result=await apiRequest('workspace','PUT',{revision:serverRevision,state:snapshot});serverRevision=result.revision;
      if(!savePending){try{sessionStorage.removeItem(recoveryKey())}catch{}updateSync('Saved to your account')}
      else {try{sessionStorage.setItem(recoveryKey(),JSON.stringify({revision:serverRevision,state:savePending}))}catch{}}
    }catch(error){savePending=savePending||snapshot;saveBlocked=true;updateSync('Not saved — review');showSyncProblem(error);}
  }
  saveRunning=false;
}
function showSyncProblem(error){
  let container=document.getElementById('sync-problem');if(!container){container=document.createElement('div');container.id='sync-problem';document.body.appendChild(container)}
  container.innerHTML=`<strong>Changes have not been saved</strong><p>${esc(error.message)}</p><div><button class="secondary" onclick="exportPending()">Export pending changes</button>${error.status!==409&&error.status!==401&&error.status!==403?'<button class="primary" onclick="retrySync()">Retry save</button>':''}<button class="secondary" onclick="confirmReload()">Reload account</button></div>`;
}
function exportPending(){downloadFile('rimbo-pending-changes.json',JSON.stringify(savePending||state,null,2),'application/json')}
function retrySync(){saveBlocked=false;document.getElementById('sync-problem')?.remove();updateSync('Saving…');void drainSaves()}
function confirmReload(){modal(`${modalHead('Reload the latest account data?')}<p class="sub">Export your pending changes first. Reloading replaces the unsaved workspace in this tab with the server copy.</p><div class="modal-actions"><button class="secondary" onclick="exportPending()">Export pending changes</button><button class="primary" onclick="reloadAccount()">Reload account</button></div>`)}
function reloadAccount(){try{sessionStorage.removeItem(recoveryKey())}catch{}savePending=null;saveBlocked=false;location.reload()}
async function bootstrapServer(){
  document.getElementById('app').innerHTML='<div class="auth-shell"><div class="card"><h2>Opening your space…</h2><p class="sub">Connecting securely to your account.</p></div></div>';
  try{let s=await apiRequest('session');csrfToken=s.csrf;account=s.user;registrationOpen=s.registration_open;if(account)await loadAccount();else authScreen('login')}
  catch(e){document.getElementById('app').innerHTML=`<div class="auth-shell"><div class="card"><h2>Unable to connect</h2><p class="sub">${esc(e.message)}</p><button class="primary" onclick="bootstrapServer()">Try again</button></div></div>`}
}
function authScreen(mode='login',message=''){
  document.getElementById('overlay').innerHTML='';
  document.getElementById('app').innerHTML=`<div class="auth-shell"><div class="auth-card card"><div class="brand">${icon('bulb')}Rimbo Notes</div><h1>${mode==='register'?'A home for your ideas':'Welcome back'}</h1><p class="sub">Capture a thought. Come back to it anywhere.</p><form id="auth-form" onsubmit="event.preventDefault();submitAuth('${mode}',this)">${mode==='register'?'<div class="field"><label for="auth-name">Your name</label><input id="auth-name" name="name" autocomplete="name" maxlength="100" required></div>':''}<div class="field"><label for="auth-email">Email</label><input id="auth-email" name="email" type="email" autocomplete="email" maxlength="254" required></div><div class="field"><label for="auth-password">Password${mode==='register'?' (12–72 bytes)':''}</label><input id="auth-password" name="password" type="password" autocomplete="${mode==='register'?'new-password':'current-password'}" ${mode==='register'?'minlength="12"':''} required></div><p id="auth-error" role="alert">${esc(message)}</p><button class="primary" type="submit" style="width:100%">${mode==='register'?'Create account':'Sign in'}</button></form>${registrationOpen?`<button class="link" style="margin-top:20px" onclick="authScreen('${mode==='register'?'login':'register'}')">${mode==='register'?'Already have an account? Sign in':'New here? Create an account'}</button>`:''}<p class="sub" style="margin-top:25px">Your ideas are private to your account.</p></div></div>`;
}
async function submitAuth(mode,form){let button=form.querySelector('button[type=submit]');button.disabled=true;document.getElementById('auth-error').textContent='';try{let result=await apiRequest(mode,'POST',Object.fromEntries(new FormData(form)));account=result.user;csrfToken=result.csrf;await loadAccount()}catch(e){document.getElementById('auth-error').textContent=e.message;button.disabled=false}}
async function loadAccount(){
  const result=await apiRequest('workspace');serverRevision=result.revision;state=result.state;page='home';savePending=null;saveBlocked=false;updateSync('Saved to your account');render();
  let recovery;try{recovery=JSON.parse(sessionStorage.getItem(recoveryKey()))}catch{}
  if(recovery){validateImport(recovery.state);state=recovery.state;savePending=recovery.state;saveBlocked=true;updateSync('Not saved — review');render();showSyncProblem({status:409,message:'This tab has changes from a previous interrupted save. Export them before reloading, then use Settings → Restore a backup to import them deliberately.'})}
}
async function signOut(){
  if(savePending||saveRunning){await drainSaves();if(savePending||saveRunning||saveBlocked){toast('Resolve or export pending changes before signing out.');return}}
  try{await apiRequest('logout','POST',{});account=null;state={ideas:[],settings:{}};document.getElementById('sync-problem')?.remove();await bootstrapServer()}catch(e){toast(e.message)}
}
window.addEventListener('beforeunload',e=>{if(savePending||saveRunning){e.preventDefault();e.returnValue=''}});
// Refresh only when safe; never overwrite an open editor or pending changes.
async function refreshAccount(){if(savePending||saveRunning||saveBlocked){toast('Wait for your changes to save first.');return}if(page==='detail'){toast('Save your note and leave the editor before refreshing.');return}try{await loadAccount();toast('Latest account data loaded.')}catch(e){toast(e.message)}}
function validateImport(d){
  if(!d||!Array.isArray(d.ideas)||d.ideas.length>500||!d.settings||typeof d.settings!=='object')throw Error('Invalid backup');
  const safeId=v=>typeof v==='string'&&/^[a-zA-Z0-9_-]{1,100}$/.test(v);
  const safeData=v=>typeof v==='string'&&/^data:(image\/(png|jpeg|webp|gif)|audio\/(webm|ogg|mp4|mpeg|wav)|application\/(pdf|octet-stream)|text\/plain)(;codecs=[a-z0-9,.-]+)?;base64,[a-zA-Z0-9+/=]+$/i.test(v);
  let seen=new Set();
  for(const i of d.ideas){
    if(!i||!safeId(i.id)||seen.has(i.id)||typeof i.title!=='string'||!i.title.trim()||typeof i.notes!=='string')throw Error('Invalid idea');seen.add(i.id);
    if(!['Unsorted','Business','Design','Personal'].includes(i.category)||!['Captured','Exploring','Trying','Completed'].includes(i.stage))throw Error('Invalid category or stage');
    if(i.links&&(!Array.isArray(i.links)||!i.links.every(safeId)))throw Error('Invalid links');
    if(i.cover&&!(/^https:\/\/images\.unsplash\.com\/[a-zA-Z0-9_/?=&%.+-]+$/.test(i.cover)||safeData(i.cover)))throw Error('Invalid cover');
    if(i.audio&&!safeData(i.audio))throw Error('Invalid audio');
    if(i.attachments&&(!Array.isArray(i.attachments)||i.attachments.some(a=>!a||!safeId(a.id)||typeof a.name!=='string'||typeof a.type!=='string'||!safeData(a.data))))throw Error('Invalid attachment');
    if(i.history&&(!Array.isArray(i.history)||i.history.some(h=>!h||typeof h.text!=='string'||!Number.isFinite(h.at))))throw Error('Invalid history');
  }
}
