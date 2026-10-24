// Integration-focused tests of the browser adapter's write queue, using a fake API.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const tick=()=>new Promise(r=>setImmediate(r));
async function main(){
 const cache=new Map(),pending=[],labels=[],problems=[];
 const ctx={Blob,console,setTimeout,clearTimeout,sessionStorage:{setItem:(k,v)=>cache.set(k,v),getItem:k=>cache.get(k),removeItem:k=>cache.delete(k)},document:{querySelectorAll:()=>[],getElementById:()=>null},window:{addEventListener:()=>{}},fetch:(url,opts)=>new Promise(resolve=>pending.push({url,opts,resolve})),toast:()=>{},esc:s=>String(s)};
 vm.createContext(ctx);vm.runInContext(fs.readFileSync('dist/server.js','utf8'),ctx);
 vm.runInContext("let state={ideas:[],settings:{name:'A'}}; account={id:7}; csrfToken='test-token'; showSyncProblem=e=>problems.push(e);",Object.assign(ctx,{problems}));
 assert.equal(vm.runInContext('queueServerSave()',ctx),true);assert.equal(pending.length,1);
 vm.runInContext("state.settings.name='B';queueServerSave();state.settings.name='C';queueServerSave()",ctx);
 assert.equal(pending.length,1,'writes must be serialized');
 assert.equal(JSON.parse(pending[0].opts.body).state.settings.name,'A','in-flight request uses an immutable snapshot');
 pending[0].resolve({ok:true,json:async()=>({revision:1})});await tick();
 assert.equal(pending.length,2);assert.equal(JSON.parse(pending[1].opts.body).revision,1);
 assert.equal(JSON.parse(pending[1].opts.body).state.settings.name,'C','pending changes are coalesced');
 pending[1].resolve({ok:true,json:async()=>({revision:2})});await tick();assert.equal(cache.size,0);
 assert.equal(vm.runInContext('syncLabel',ctx),'Saved to your account');
 vm.runInContext("state.settings.name='D';queueServerSave()",ctx);
 pending[2].resolve({ok:false,status:409,json:async()=>({error:'conflict'})});await tick();
 assert.equal(vm.runInContext('saveBlocked',ctx),true);assert.equal(problems[0].status,409);
 assert.equal(vm.runInContext('savePending.settings.name',ctx),'D');assert.equal(cache.size,1);
 vm.runInContext("state.settings.name='E';queueServerSave()",ctx);assert.equal(pending.length,3,'conflict does not silently overwrite remote data');
 const backup={ideas:[{id:'safe',title:'Idea',notes:'',category:'Business',stage:'Captured',links:[],attachments:[]}],settings:{}};
 ctx.backup=backup;vm.runInContext('validateImport(backup)',ctx);
 backup.ideas[0].id="x');alert(1)//";assert.throws(()=>vm.runInContext('validateImport(backup)',ctx));
 console.log('PASS: serialized saves, immutable snapshots, revision tracking, conflict recovery, import ID validation');
}
main().catch(e=>{console.error(e);process.exit(1)});
