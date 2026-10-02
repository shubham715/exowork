import React,{useState,useEffect,useRef,useId}from'react';import{X,ChevronRight,Search,SlidersHorizontal,Download}from'lucide-react';
export const Status=({children,tone})=><span className={'status '+(tone||String(children).toLowerCase().replaceAll(' ','-'))}><i/>{children}</span>;
export const Stat=({icon:Icon,label,value,meta,tone='blue'})=><article className="stat-card"><span className={'stat-icon '+tone}><Icon/></span><div><label>{label}</label><strong>{value}</strong><small>{meta}</small></div></article>;
export const PageHead=({kicker='OPERATIONS WORKSPACE',title,sub,children})=><div className="page-head"><div><span>{kicker}</span><h1>{title}</h1><p>{sub}</p></div><div className="page-actions">{children}</div></div>;
export const Panel=({title,sub,action,children,className=''})=><section className={'panel '+className}><header><div><h3>{title}</h3>{sub&&<p>{sub}</p>}</div>{action}</header><div className="panel-body">{children}</div></section>;
export function Modal({open,onClose,title,sub,children,footer,className=''}){
  const dialog=useRef(null),close=useRef(onClose),titleId=useId(),descriptionId=useId();
  close.current=onClose;
  useEffect(()=>{
    if(!open)return;
    const trigger=document.activeElement,previousOverflow=document.body.style.overflow;
    document.body.style.overflow='hidden';
    const focusable=()=>Array.from(dialog.current?.querySelectorAll('button:not(:disabled),a[href],input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex="0"]')||[]).filter(el=>el.getClientRects().length);
    (focusable()[0]||dialog.current)?.focus();
    const handleKey=e=>{
      const dialogs=document.querySelectorAll('[role="dialog"]');
      if(dialogs[dialogs.length-1]!==dialog.current)return;
      if(e.key==='Escape'){e.preventDefault();close.current();return}
      if(e.key!=='Tab')return;
      const items=focusable(),first=items[0],last=items[items.length-1];
      if(!first){e.preventDefault();dialog.current?.focus();return}
      if(e.shiftKey&&(document.activeElement===first||!dialog.current.contains(document.activeElement))){e.preventDefault();last.focus()}
      else if(!e.shiftKey&&(document.activeElement===last||!dialog.current.contains(document.activeElement))){e.preventDefault();first.focus()}
    };
    document.addEventListener('keydown',handleKey);
    return()=>{document.removeEventListener('keydown',handleKey);document.body.style.overflow=previousOverflow;if(trigger?.isConnected)trigger.focus()};
  },[open]);
  if(!open)return null;
  return <div className="modal-back" onMouseDown={e=>e.target===e.currentTarget&&onClose()}><div ref={dialog} tabIndex={-1} className={`modal ui-modal ${className}`} role="dialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={sub?descriptionId:undefined}><header><div><h2 id={titleId}>{title}</h2>{sub&&<p id={descriptionId}>{sub}</p>}</div><button type="button" aria-label="Close dialog" onClick={onClose}><X/></button></header><div className="modal-body">{children}</div>{footer&&<footer>{footer}</footer>}</div></div>
}

export const Filters=({search,setSearch,children,onExport})=><div className="filterbar"><div className="search-box"><Search/><input value={search} onChange={e=>setSearch(e.target.value)} placeholder="Search by name, ID, mobile or role…"/></div>{children}<button className="btn ghost"><SlidersHorizontal/>More filters</button>{onExport&&<button className="btn ghost" onClick={onExport}><Download/>Export</button>}</div>;
export const Empty=({title,sub,action})=><div className="empty"><div>◎</div><h3>{title}</h3><p>{sub}</p>{action}</div>;
export function Tabs({tabs,active,setActive}){return <div className="tabs">{tabs.map(x=><button key={x} className={active===x?'active':''} onClick={()=>setActive(x)}>{x}</button>)}</div>}
export const Next=({children})=><button className="text-btn">{children}<ChevronRight/></button>;
