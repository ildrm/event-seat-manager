import * as THREE from 'three';
import {OrbitControls} from 'three/addons/controls/OrbitControls.js';
import {boundsOf,previewScopeKey} from './venue-core.mjs';

/** Optional schematic view. Authority and selection stay in the shared controller. */
export function createPreview(host, initial, {onSelect, onError, label}) {
  const renderer=new THREE.WebGLRenderer({antialias:true,alpha:false});renderer.setPixelRatio(Math.min(devicePixelRatio||1,1.5));
  renderer.setClearColor('#f4f6fa');renderer.domElement.setAttribute('role','img');renderer.domElement.setAttribute('aria-label',label);
  host.replaceChildren(renderer.domElement);const scene=new THREE.Scene();
  const camera=new THREE.PerspectiveCamera(45,1,0.1,100000);const controls=new OrbitControls(camera,renderer.domElement);
  controls.enableDamping=false;controls.autoRotate=false;controls.minPolarAngle=0;controls.maxPolarAngle=Math.PI*0.49;
  scene.add(new THREE.HemisphereLight(0xffffff,0x50617c,2.6));const sunlight=new THREE.DirectionalLight(0xffffff,2.2);sunlight.position.set(10,30,15);scene.add(sunlight);
  let data=initial,group=new THREE.Group(),mesh=null,backMesh=null,geometrySignature='',scopeKey='',disposed=false,frame=null;scene.add(group);
  const materials=new Set(),geometries=new Set();
  const render=()=>{if(!disposed&&frame===null)frame=requestAnimationFrame(()=>{frame=null;renderer.render(scene,camera);});};
  const clear=()=>{group.traverse(node=>{if(node.isInstancedMesh)node.dispose();});for(const geometry of geometries)geometry.dispose();for(const material of materials)material.dispose();geometries.clear();materials.clear();scene.remove(group);group=new THREE.Group();scene.add(group);mesh=null;backMesh=null;};
  const material=(color)=>{const value=new THREE.MeshStandardMaterial({color,roughness:0.82,metalness:0});materials.add(value);return value;};
  const geometry=(...size)=>{const value=new THREE.BoxGeometry(...size);geometries.add(value);return value;};
  const object=(item,color)=>{let geo;if(item.type==='round_table'||item.shape==='ellipse'){geo=new THREE.CylinderGeometry(0.5,0.5,0.12,24);geo.scale((item.width||500)/1000,1,(item.height||500)/1000);geometries.add(geo);}else geo=geometry((item.width||500)/1000,0.12,(item.height||500)/1000);const shape=new THREE.Mesh(geo,material(color));shape.position.set((item.x+(item.width||500)/2)/1000,(Number(item.elevation)||0)/1000-0.05,(item.y+(item.height||500)/2)/1000);shape.rotation.y=-Number(item.rotation||0)*Math.PI/180000;group.add(shape);};
  const update=(next,fit=false)=>{
    if(disposed)return;const nextScope=previewScopeKey(next);fit=fit||nextScope!==scopeKey;scopeKey=nextScope;data=next;const seats=(data.seats||[]).slice(0,2000),selected=new Set(data.selected||[]);
    const signature=JSON.stringify([seats.map(s=>[s.id,s.x,s.y,s.elevation,s.rotation]),data.groups,data.objects,data.sections]);
    const color=new THREE.Color();
    if(mesh&&signature===geometrySignature&&!fit){seats.forEach((seat,index)=>{color.set(selected.has(`seat:${seat.id}`)||selected.has(`group:${seat.group_code}`)?'#2563eb':seat.available?'#6cbaa3':'#9aa8bd');mesh.setColorAt(index,color);backMesh.setColorAt(index,color);});mesh.instanceColor.needsUpdate=true;backMesh.instanceColor.needsUpdate=true;render();return;}
    clear();geometrySignature=signature;
    const locations=seats.length?seats:[...(data.sections||[]).map(s=>({...s.bounds,elevation:s.elevation}))];
    const bounds=boundsOf(locations,1000),width=bounds.width/1000,height=bounds.height/1000,cx=(bounds.x+bounds.width/2)/1000,cz=(bounds.y+bounds.height/2)/1000;
    object({x:bounds.x,y:bounds.y,width:bounds.width,height:bounds.height},'#e6ecf4');
    for(const item of data.objects||[])object(item,item.type==='field'?'#79ad94':'#bcc9dc');
    for(const table of data.groups||[])object(table,'#d4c4ad');
    if(!seats.length)for(const section of data.sections||[])if(section.bounds)object({...section.bounds,elevation:section.elevation},'#b5c9eb');
    if(seats.length){
      const seatMaterial=material('#d3dfea'),seatGeometry=geometry(0.38,0.35,0.38),backGeometry=geometry(0.38,0.55,0.08);
      mesh=new THREE.InstancedMesh(seatGeometry,seatMaterial,seats.length);const backs=backMesh=new THREE.InstancedMesh(backGeometry,seatMaterial,seats.length);mesh.instanceMatrix.setUsage(THREE.StaticDrawUsage);
      const transform=new THREE.Object3D();
      seats.forEach((seat,index)=>{
        transform.position.set((seat.x+200)/1000,seat.elevation/1000+0.4,(seat.y+200)/1000);transform.rotation.set(0,-seat.rotation*Math.PI/180000,0);transform.updateMatrix();mesh.setMatrixAt(index,transform.matrix);
        transform.translateZ(-0.16);transform.position.y+=0.23;transform.updateMatrix();backs.setMatrixAt(index,transform.matrix);
        const checked=selected.has(`seat:${seat.id}`)||selected.has(`group:${seat.group_code}`);color.set(checked?'#2563eb':seat.available?'#6cbaa3':'#9aa8bd');mesh.setColorAt(index,color);backs.setColorAt(index,color);
      });
      mesh.instanceMatrix.needsUpdate=true;mesh.instanceColor.needsUpdate=true;mesh.computeBoundingSphere();backs.computeBoundingSphere();group.add(mesh,backs);
    }
    if(fit){const size=Math.max(width,height,4);camera.position.set(cx+size*0.5,size*0.9,cz+size*0.9);controls.target.set(cx,0,cz);camera.near=0.1;camera.far=Math.max(1000,size*20);camera.updateProjectionMatrix();controls.update();}
    render();
  };
  const resize=new ResizeObserver(()=>{const width=Math.max(240,host.clientWidth),height=440;renderer.setSize(width,height);camera.aspect=width/height;camera.updateProjectionMatrix();render();});resize.observe(host);
  const raycaster=new THREE.Raycaster(),pointer=new THREE.Vector2();let down=null;
  const start=event=>{down={x:event.clientX,y:event.clientY};};
  const click=event=>{if(!mesh||!down||Math.hypot(event.clientX-down.x,event.clientY-down.y)>5)return;const box=renderer.domElement.getBoundingClientRect();pointer.set((event.clientX-box.left)/box.width*2-1,-(event.clientY-box.top)/box.height*2+1);raycaster.setFromCamera(pointer,camera);const hit=raycaster.intersectObject(mesh,false)[0];if(hit){const seat=data.seats[hit.instanceId];if(data.mode==='seat')onSelect(seat);else{const group=data.groups.find(g=>String(g.id)===String(seat.group_code));if(group)onSelect(group);}}};
  const lost=event=>{event.preventDefault();onError(new Error('3D context lost. Continue with the map or list.'));};
  renderer.domElement.addEventListener('pointerdown',start);renderer.domElement.addEventListener('pointerup',click);renderer.domElement.addEventListener('webglcontextlost',lost);controls.addEventListener('change',render);
  update(initial,true);
  return{update:next=>update(next),dispose(){if(disposed)return;disposed=true;if(frame!==null)cancelAnimationFrame(frame);resize.disconnect();controls.removeEventListener('change',render);controls.dispose();renderer.domElement.removeEventListener('pointerdown',start);renderer.domElement.removeEventListener('pointerup',click);renderer.domElement.removeEventListener('webglcontextlost',lost);clear();renderer.dispose();renderer.forceContextLoss();host.replaceChildren();}};
}
