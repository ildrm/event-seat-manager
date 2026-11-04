/* Execute the actual hold/attendee rebuild methods against a minimal native-form surface. */
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const{pathToFileURL}=require('node:url');

class FormNode {
  constructor(tag){this.tagName=tag.toUpperCase();this.nodeType=1;this.children=[];this.dataset={};this.value='';this.listeners=new Map();}
  append(...children){this.children.push(...children);}
  replaceChildren(...children){this.children=[...children];}
  addEventListener(name,callback){this.listeners.set(name,callback);}
  fire(name){this.listeners.get(name)?.();}
  reportValidity(){return true;}
  setAttribute(name,value){if(name.startsWith('data-'))this.dataset[name.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=String(value);else this[name]=value;}
  querySelector(selector){const value=/^option\[value="([^"]+)"\]$/.exec(selector);return value?this.children.find(child=>String(child.value)===value[1])||null:this.querySelectorAll(selector)[0]||null;}
  querySelectorAll(selector){const key=selector.slice(6,-1).replace(/-([a-z])/g,(_,c)=>c.toUpperCase()),result=[];const walk=node=>{for(const child of node.children||[]){if(Object.hasOwn(child.dataset||{},key))result.push(child);walk(child);}};walk(this);return result;}
}

(async()=>{
  const core=await import(pathToFileURL(path.join(__dirname,'../assets/src/venue-core.mjs')));
  const storage=new Map(),context={...core,URL,URLSearchParams,structuredClone,setTimeout,clearTimeout,window:{},document:{readyState:'loading',addEventListener(){},createElement:tag=>new FormNode(tag),createTextNode:text=>({nodeType:3,textContent:text})},sessionStorage:{getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value),removeItem:key=>storage.delete(key)},requestKey:()=> 'same-logical-request'};
  const source=fs.readFileSync(path.join(__dirname,'../assets/src/venue-app.mjs'),'utf8').replace(/^import .*;\n/gm,'').replace(/^export (?=class|function)/gm,'');
  vm.runInNewContext(`${source}\nglobalThis.TestVenueApp=VenueApp;`,context);
  const app=Object.create(context.TestVenueApp.prototype);
  Object.assign(app,{config:{context:'booking'},sessionId:77,t:(en)=>en,selection:{selected:new Map([['group:T1',{type:'table',id:'T1'}]]),signature:()=> 'group:T1',payload:()=>[{type:'group',id:'T1'}]},ticketModeFields:new FormNode('div'),attendeeFields:new FormNode('div'),tick(){},renderSelection(){},refreshAvailability:async()=>{},perform:async action=>action()});
  const hold=()=>({hold_token:'private',status:'active',server_time_utc:'2026-11-04 12:00:00',expires_at_utc:'2026-11-04 12:10:00',quote:{ticket_mode:'buyer_choice',lines:[{unit_type:'table',unit_id:'T1',group_code:'T1',seat_ids:[1,2],admissions:2}]}});
  app.api={request:async()=>hold()};
  app.acceptHold(hold(),'group:T1');
  const modes=()=>app.ticketModeFields.querySelectorAll('[data-group-code]');
  const names=()=>app.attendeeFields.querySelectorAll('[data-seat-id]');
  const input=(id,part)=>names().find(node=>node.dataset.seatId===String(id)&&node.dataset.part===part);
  modes()[0].value='group';input(1,'first_name').value='نرگس';input(1,'last_name').value='رضایی';input(2,'first_name').value='';input(2,'last_name').value='';
  const check=action=>{assert.equal(modes()[0].value,'group',`${action} preserves shared QR choice`);assert.equal(input(1,'first_name').value,'نرگس',`${action} preserves typed Unicode first name`);assert.equal(input(1,'last_name').value,'رضایی',`${action} preserves typed last name`);assert.equal(input(2,'first_name').value,'',`${action} preserves deliberately blank guest`);};
  await app.mutateHold('renew');check('renew');
  await app.resumeHold();check('Refresh');
  await app.resumeHold();check('BFCache hold revalidation');
  app.config={context:'cart',cartKey:'private-cart'};
  app.api.request=async()=>({session_id:77,hold:hold(),ticket_modes:{T1:'individual'},attendees:{1:{first_name:'Old',last_name:'Cart'},2:{first_name:'Should stay',last_name:'Cleared'}}});
  await app.resumeHold();check('cart Refresh');assert.equal(input(2,'last_name').value,'','stale cart defaults do not refill cleared names');
  const fresh=Object.create(context.TestVenueApp.prototype);Object.assign(fresh,app,{hold:null,holdSignature:null,ticketModeFields:new FormNode('div'),attendeeFields:new FormNode('div')});
  await fresh.resumeHold();assert.equal(fresh.ticketModeFields.querySelectorAll('[data-group-code]')[0].value,'individual','first cart view receives saved ticket mode');
  assert.equal(fresh.attendeeFields.querySelectorAll('[data-seat-id]').find(node=>node.dataset.seatId==='1'&&node.dataset.part==='first_name').value,'Old','first cart view receives saved guest names');
  const flush=async()=>{for(let i=0;i<8;i++)await Promise.resolve();};
  const overview=id=>({layout_id:id,revision:1,status:'published',capacity:2,sections:[{code:`L${id}`,label:`Layout ${id}`,level:0,capacity:2,tiles:[`L${id}-001`]}],categories:[],objects:[]});
  function sessionFixture(){
    const instance=Object.create(context.TestVenueApp.prototype),pending=new Map(),requests=[],patched=[];
    Object.assign(instance,{admin:true,config:{hallId:1,halls:[{id:1,name:'One',center_id:10},{id:2,name:'Two',center_id:20}],sessions:[]},sessionId:1,layoutId:0,scopeVersion:0,pendingQuote:0,selection:new core.SelectionController(2000),cache:new core.GeometryCache(),ticketMode:new FormNode('select'),entryMode:new FormNode('select'),hallSelect:new FormNode('select'),sectionSelect:new FormNode('select'),tileSelect:new FormNode('select'),levelSelect:new FormNode('select'),categorySelect:new FormNode('select'),saveState:new FormNode('span'),publishButton:new FormNode('button'),sessionSaveButton:new FormNode('button'),sessionPublishButton:new FormNode('button'),t:en=>en,renderSelection(){},renderer:{setOverview(sections){instance.drawn=sections.map(section=>section.code);},setDetail(){throw Error('Stale tile must not render');}},loadPricingForm(){instance.renderedPrice=instance.sessionConfig?.pricing?.sections?.A;},loadList:async()=>{},rememberLayoutVersion(){},syncDesignerURL(){},perform:async action=>action(),message(text){instance.lastMessage=text;}});
    instance.api={request:(route,options)=>{requests.push(route);if(options?.method==='PATCH'){patched.push({route,settings:options.body.settings});return Promise.resolve({settings:options.body.settings});}return new Promise(resolve=>pending.set(route,resolve));}};
    return{instance,pending,requests,patched};
  }
  const firstRace=sessionFixture(),s=firstRace.instance;
  const slowA=s.loadSessionSettings();s.sessionId=2;const fastB=s.loadSessionSettings();
  assert.equal(s.sessionSaveButton.disabled,true,'settings cannot be saved while the selected session is unresolved');
  firstRace.pending.get('admin/sessions/2/config')({session_id:2,hall_id:2,layout_id:202,settings:{pricing:{sections:{A:222}},ticket_mode:'group',group_entry_policy:'incremental'}});await flush();
  firstRace.pending.get('admin/layouts/202')(overview(202));await fastB;
  firstRace.pending.get('admin/sessions/1/config')({session_id:1,hall_id:1,layout_id:101,settings:{pricing:{sections:{A:111}},ticket_mode:'individual'}});await slowA;
  assert.equal(s.sessionConfigId,2);assert.equal(s.sessionConfig.pricing.sections.A,222);assert.equal(s.layoutId,202);assert.equal(s.config.hallId,2);assert.equal(s.hallSelect.value,'2');assert.equal(s.ticketMode.value,'group');assert.equal(s.renderedPrice,222);assert.equal(firstRace.requests.includes('admin/layouts/101'),false,'late A config cannot initiate its layout load');
  await s.saveSessionSettings();assert.equal(firstRace.patched[0].route,'admin/sessions/2/config');assert.equal(firstRace.patched[0].settings.pricing.sections.A,222,'saving B cannot use A prices');

  const secondRace=sessionFixture(),r=secondRace.instance;
  const requestA=r.loadSessionSettings();secondRace.pending.get('admin/sessions/1/config')({hall_id:1,layout_id:101,settings:{pricing:{sections:{A:111}}}});await flush();
  r.sessionId=2;const requestB=r.loadSessionSettings();
  secondRace.pending.get('admin/layouts/101')(overview(101));await requestA;
  assert.equal(r.overview,undefined,'A overview already in flight is discarded as soon as B is selected');assert.equal(r.sessionConfigPending,true,'A completion cannot unlock B settings');
  await r.saveSessionSettings();assert.equal(secondRace.patched.length,0,'pending B cannot be written with incomplete financial fields');
  secondRace.pending.get('admin/sessions/2/config')({hall_id:2,layout_id:202,settings:{pricing:{sections:{A:222}}}});await flush();secondRace.pending.get('admin/layouts/202')(overview(202));await requestB;
  assert.equal(r.overview.layout_id,202);assert.deepEqual(r.drawn,['L202']);assert.equal(r.sessionConfigPending,false);

  const tileRace=sessionFixture(),tr=tileRace.instance;tr.layoutId=101;
  const oldTile=tr.openTile('L101-001');tr.sessionId=2;const newSession=tr.loadSessionSettings();
  tileRace.pending.get('admin/layouts/101/tiles/L101-001')({seats:[{id:1,x:0,y:0}],groups:[],objects:[]});await oldTile;
  assert.equal(tr.cache.count,0,'a stale tile cannot contaminate the new layout cache');assert.equal(tr.tile,undefined);
  tileRace.pending.get('admin/sessions/2/config')({hall_id:2,layout_id:202,settings:{}});await flush();tileRace.pending.get('admin/layouts/202')(overview(202));await newSession;

  let destination='',replacements=0;
  context.window.location={href:'https://example.test/wp-admin/admin.php?page=pesm-venue-designer&hall_id=1&layout_id=10&session_id=9&job_id=75&hold=keep#designer',assign(url){destination=url;}};
  context.window.history={state:{keep:'state'},replaceState(state,_title,url){assert.equal(state.keep,'state');replacements++;context.window.location.href=url;}};
  const designer=Object.create(context.TestVenueApp.prototype);
  Object.assign(designer,{admin:true,config:{hallId:1,halls:[{id:1,venue_layout_id:10},{id:2,venue_layout_id:null,current_layout_id:55}]},layoutId:10,sessionId:9,selection:new core.SelectionController(2000),layoutVersions:new Map([[1,[{id:11,version_no:2,status:'draft',seat_count:42},{id:10,version_no:1,status:'published',seat_count:40}]]]),layoutVersionSelect:new FormNode('select'),hallSelect:new FormNode('select'),t:en=>en,renderSelection(){},perform:async action=>action(),message(){},loadOverview:async function(){this.overview={status:'draft',capacity:42};this.rememberLayoutVersion();},progress:new FormNode('progress'),jobLabel:new FormNode('span'),resumeJobButton:new FormNode('button'),active:true});
  designer.renderLayoutVersions();assert.equal(designer.layoutVersionSelect.children.some(node=>node.value===11&&node.textContent.includes('Draft')),true,'saved unpublished drafts appear in the hall version picker');
  designer.layoutVersionSelect.value='11';await designer.selectLayoutVersion();
  let canonical=new URL(context.window.location.href);assert.equal(canonical.searchParams.get('layout_id'),'11');assert.equal(canonical.searchParams.has('session_id'),false,'choosing a draft removes a conflicting old session binding');assert.equal(canonical.searchParams.get('job_id'),'75');assert.equal(canonical.searchParams.get('hold'),'keep');assert.equal(canonical.hash,'#designer');
  await designer.watchJob({id:75,kind:'generate',status:'complete',layout_id:12});canonical=new URL(context.window.location.href);assert.equal(canonical.searchParams.get('layout_id'),'12');assert.ok(designer.layoutVersions.get(1).some(version=>version.id===12),'a generated draft is immediately selectable');
  await designer.watchJob({id:76,kind:'import',status:'complete',layout_id:13});assert.equal(new URL(context.window.location.href).searchParams.get('layout_id'),'13','imported drafts also survive a reload through their canonical URL');
  designer.hallSelect.value='2';designer.changeHall();const nextHall=new URL(destination);assert.equal(nextHall.searchParams.get('hall_id'),'2');assert.equal(nextHall.searchParams.has('layout_id'),false,'a legacy current ID is not reused when venue_layout_id is explicitly null');assert.equal(nextHall.searchParams.get('job_id'),'75');assert.equal(nextHall.searchParams.get('hold'),'keep');
  designer.pendingCommand={};designer.layoutVersionSelect.value='10';await designer.selectLayoutVersion();assert.equal(designer.layoutId,13,'pending save cannot be abandoned by version selection');
  designer.admin=false;designer.syncDesignerURL();assert.equal(replacements,3,'public booking/cart contexts never mutate their URLs');

  const expired=Object.create(context.TestVenueApp.prototype),calls=[];
  const selected=new core.SelectionController();selected.toggle({type:'seat',id:1,code:'A1'});selected.quote={total_minor:100};
  Object.assign(expired,{config:{context:'booking'},admin:false,sessionId:7,overview:{},scopeVersion:0,pendingQuote:0,selection:selected,hold:{expires_at_utc:'2000-01-01 00:00:00'},holdSignature:selected.signature(),ownSeatIds:new Set([1]),availability:new Map(),tileCode:'T1',tile:{seats:[{id:1}],groups:[]},timerLabel:new FormNode('span'),ticketModeFields:new FormNode('div'),attendeeFields:new FormNode('div'),t:en=>en,renderer:{schedule(){}},updateListSelection(){},renderSelection(){this.canReserve=Boolean(!this.hold&&this.selection.selected.size&&this.selection.quote);},message(){},perform:async action=>action()});
  const freshQuote={total_minor:250,admissions:1,currency:'USD',price_decimals:2,lines:[{unit_type:'seat',unit_id:1,admissions:1,seat_ids:[1]}]};
  expired.api={request:async(route,options)=>{calls.push(route);if(route.includes('/availability'))return{snapshot:true,items:[{seat_id:1,status:'available'}],groups:[]};if(route.endsWith('/quote'))return freshQuote;if(route.endsWith('/holds')&&options.method==='POST')return{hold_token:'renewed-private',expires_at_utc:'2099-01-01 00:00:00',quote:freshQuote};throw Error(route);}};
  const availability=expired.refreshAvailability.bind(expired);let recovered;expired.refreshAvailability=()=>recovered=availability();
  expired.tick();await recovered;
  assert.equal(expired.hold,null);assert.equal(expired.selection.selected.size,1,'expiry retains the customer selection');assert.equal(expired.selection.quote.total_minor,250,'expiry refresh obtains a fresh authoritative price');assert.equal(expired.canReserve,true,'Hold is available again without toggling a selected seat');
  await expired.acquire();assert.equal(expired.hold.hold_token,'renewed-private');assert.ok(calls.includes('sessions/7/quote')&&calls.includes('sessions/7/holds'),'retained places can be quoted and reserved again');

  const generator=Object.create(context.TestVenueApp.prototype),generated=[];
  Object.assign(generator,{config:{centerId:10,halls:[{id:1,center_id:10}]},hallSelect:new FormNode('select'),workflow:new FormNode('select'),capacityInput:new FormNode('input'),presetDetails:new FormNode('div'),t:en=>en,fa:false,perform:async action=>action(),watchJob:async()=>{},message(text){throw Error(text);},preset:{id:'multi-tier',min_capacity:1,max_capacity:150000,defaults:{sections:4,levels:2,blocks:3,rows:10,columns:20},controls:['sections','levels','blocks','rows','columns']}});
  generator.hallSelect.value='1';generator.workflow.value='exact';generator.capacityInput.value='1';generator.capacityInput.closest=()=>({hidden:false});
  generator.api={request:async(_route,options)=>{generated.push(options.body.payload.parameters);return{id:90};}};
  generator.renderPresetFields();await generator.generate();
  assert.equal(generated[0].capacity,1);for(const key of ['sections','levels','blocks'])assert.equal(Object.hasOwn(generated[0],key),false,`exact capacity1 omits untouched ${key} recipe default`);assert.equal(generated[0].columns,20,'non-structural controls retain their recipe value');
  const sectionInput=generator.parameterInputs.get('sections');sectionInput.value='5';sectionInput.fire('input');
  generator.workflow.value='advanced';generator.renderPresetFields();assert.equal(generator.parameterInputs.get('sections').value,'5','edited values survive workflow field rerender');await generator.generate();
  assert.equal(generated[1].sections,5);assert.equal(generated[1].levels,2);assert.equal(generated[1].blocks,3);assert.equal(Object.hasOwn(generated[1],'capacity'),false,'advanced arrangement sends explicit controls and calculates capacity');
  generator.workflow.value='exact';generator.renderPresetFields();await generator.generate();assert.equal(generated[2].sections,5,'an explicitly incompatible user choice reaches strict server validation unchanged');assert.equal(Object.hasOwn(generated[2],'levels'),false);assert.equal(Object.hasOwn(generated[2],'blocks'),false);
  generator.preset={...generator.preset,id:'other-recipe'};generator.renderPresetFields();await generator.generate();assert.equal(generator.touchedParameters.size,0,'changing preset resets touched structure');assert.equal(Object.hasOwn(generated[3],'sections'),false);assert.equal(generator.parameterInputs.get('sections').value,4,'new preset resets previous parameter edits');
  console.log('PASS: actual hold forms, reversed session/config/overview/tile races, draft/version URLs, expiry recovery and exact/advanced generator payloads.');
})().catch(error=>{console.error(error);process.exitCode=1;});
