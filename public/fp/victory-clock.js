(function(root){
 'use strict';
 function victoryClock(timezone='Asia/Jakarta',env={}){
  const doc=env.document||root.document,now=env.now||(()=>new Date()),schedule=env.setInterval||root.setInterval.bind(root),cancel=env.clearInterval||root.clearInterval.bind(root);
  return {hour:0,minute:0,second:0,date:'',timer:null,onVisibility:null,
   tick(){const instant=now(),parts=new Intl.DateTimeFormat('en-GB',{timeZone:timezone,hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(instant),values=Object.fromEntries(parts.map(p=>[p.type,p.value])),h=Number(values.hour),m=Number(values.minute),s=Number(values.second);this.hour=(h%12)*30+m/2+s/120;this.minute=m*6+s/10;this.second=s*6;this.date=new Intl.DateTimeFormat('id-ID',{timeZone:timezone,weekday:'long',day:'numeric',month:'long',year:'numeric'}).format(instant);},
   start(){if(this.timer!==null||doc.hidden)return;this.tick();this.timer=schedule(()=>this.tick(),1000);},
   stop(){if(this.timer!==null){cancel(this.timer);this.timer=null;}},
   init(){this.onVisibility=()=>doc.hidden?this.stop():this.start();doc.addEventListener('visibilitychange',this.onVisibility);this.tick();this.start();},
   destroy(){this.stop();doc.removeEventListener('visibilitychange',this.onVisibility);this.onVisibility=null;}
  };
 }
 if(typeof module==='object'&&module.exports)module.exports=victoryClock;else root.victoryClock=victoryClock;
})(globalThis);
