import {boundsOf} from './venue-core.mjs';

// Bounded derived geometry only. Publishing and inventory validation remain server-side.
self.addEventListener('message',event=>{
  const{id,operation,seats=[]}=event.data||{};
  if(seats.length>2000){self.postMessage({id,error:'Tile limit exceeded'});return;}
  if(operation==='bounds')self.postMessage({id,bounds:boundsOf(seats)});
  else if(operation==='translate'){
    const dx=Number(event.data.dx)||0,dy=Number(event.data.dy)||0;
    self.postMessage({id,seats:seats.map(seat=>({...seat,x:Math.round(seat.x+dx),y:Math.round(seat.y+dy)}))});
  }else self.postMessage({id,error:'Unknown operation'});
});
