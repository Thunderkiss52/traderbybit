export const finite=(v:unknown,fallback='—')=>{
  if(v===null||v===undefined||v==='')return fallback;
  if(typeof v==='string'&&(v.includes('%')||(v.includes(',')&&!Number.isNaN(parseFloat(v.replace(/,/g,''))))))return v;
  return !Number.isFinite(Number(v))?fallback:new Intl.NumberFormat(undefined,{maximumFractionDigits:4}).format(Number(v));
};
export const formatMoney=(v:unknown,fallback='—')=>{if(v===null||v===undefined||v===''||!Number.isFinite(Number(v)))return fallback;return new Intl.NumberFormat(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v));};
export const formatPrice=(v:unknown,fallback='—',decimals=2)=>{if(v===null||v===undefined||v===''||!Number.isFinite(Number(v)))return fallback;return new Intl.NumberFormat(undefined,{minimumFractionDigits:decimals,maximumFractionDigits:decimals}).format(Number(v));};
export const formatPercent=(v:unknown,fallback='—',decimals=2)=>{if(v===null||v===undefined||v===''||!Number.isFinite(Number(v)))return fallback;return `${new Intl.NumberFormat(undefined,{minimumFractionDigits:decimals,maximumFractionDigits:decimals}).format(Number(v))}%`;};
export const formatSpread=(ask:unknown,bid:unknown,fallback='—')=>{if(!ask||!bid||!Number.isFinite(Number(ask))||!Number.isFinite(Number(bid)))return fallback;const diff=Number(ask)-Number(bid);return diff.toFixed(2);};
export const utcToLocal=(v:string,tz='local')=>new Intl.DateTimeFormat(undefined,{dateStyle:'medium',timeStyle:'short',timeZone:tz==='local'?undefined:tz}).format(new Date(v));
export const expired=(v:string,now=Date.now())=>new Date(v).getTime()<=now;
export const stale=(v:string,maxSeconds=300,now=Date.now())=>(now-new Date(v).getTime())/1000>maxSeconds;
export const pageParams=(page:number,perPage:number,filters:object)=>({page,per_page:perPage,...filters});
